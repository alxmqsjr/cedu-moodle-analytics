<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ceduanalytics\local;

/**
 * Playback of embedded YouTube videos.
 *
 * The browser marks each 5-second part of the video it played (a bitmap sent as hex). The server joins
 * the parts of every session with OR, so "watched" is the share of the video really played, not the time
 * spent on the page, and watching the same part twice does not count twice.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class videos {
    /** @var int Seconds per part of the bitmap. */
    const BUCKET = 5;

    /** @var float Share of the video that counts as "watched". */
    const COMPLETE = 0.9;

    /** @var int Longest video tracked, in seconds (4 hours). */
    const MAXDURATION = 14400;

    /**
     * Whether tracking is on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool)get_config('local_ceduanalytics', 'videotracking');
    }

    /**
     * OR of two hex bitmaps.
     *
     * @param string $a
     * @param string $b
     * @return string hex
     */
    public static function merge(string $a, string $b): string {
        $a = self::bin($a);
        $b = self::bin($b);
        $len = max(strlen($a), strlen($b));
        return bin2hex(str_pad($a, $len, "\0") | str_pad($b, $len, "\0"));
    }

    /**
     * Number of parts set in a hex bitmap.
     *
     * @param string $hex
     * @return int
     */
    public static function count(string $hex): int {
        $n = 0;
        foreach (str_split(self::bin($hex)) as $byte) {
            $v = ord($byte);
            for (; $v; $v >>= 1) {
                $n += $v & 1;
            }
        }
        return $n;
    }

    /**
     * Binary string of a hex bitmap (invalid input gives an empty bitmap).
     *
     * @param string $hex
     * @return string
     */
    protected static function bin(string $hex): string {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $hex));
        if (strlen($hex) % 2) {
            $hex .= '0';
        }
        return $hex === '' ? '' : (string)hex2bin($hex);
    }

    /**
     * Record progress reported by the browser.
     *
     * @param int $userid
     * @param \cm_info $cm
     * @param string $videoid
     * @param int $duration Seconds (0 if unknown)
     * @param string $segments Hex bitmap of this session
     * @param int $plays New play starts since the last report
     * @param int $linkclicks New clicks on links since the last report
     * @return \stdClass the row
     */
    public static function record(
        int $userid,
        \cm_info $cm,
        string $videoid,
        int $duration,
        string $segments,
        int $plays,
        int $linkclicks
    ): \stdClass {
        global $DB;
        $now = time();
        $duration = max(0, min(self::MAXDURATION, $duration));
        $maxbytes = (int)ceil(self::MAXDURATION / self::BUCKET / 8);
        $segments = substr(bin2hex(self::bin($segments)), 0, $maxbytes * 2);
        $row = $DB->get_record('local_ceduanalytics_video', ['userid' => $userid, 'cmid' => $cm->id, 'videoid' => $videoid]);
        if (!$row) {
            $row = (object)['userid' => $userid, 'courseid' => $cm->course, 'cmid' => $cm->id, 'videoid' => $videoid,
                'duration' => 0, 'segments' => '', 'watchedsecs' => 0, 'plays' => 0, 'linkclicks' => 0, 'timefirst' => $now];
        }
        $row->duration = max((int)$row->duration, $duration);
        $row->segments = self::merge((string)$row->segments, $segments);
        $watched = self::count($row->segments) * self::BUCKET;
        $row->watchedsecs = $row->duration ? min($row->duration, $watched) : $watched;
        $row->plays = (int)$row->plays + max(0, min(50, $plays));
        $row->linkclicks = (int)$row->linkclicks + max(0, min(50, $linkclicks));
        $row->timelast = $now;
        if (empty($row->id)) {
            try {
                $row->id = $DB->insert_record('local_ceduanalytics_video', $row);
            } catch (\dml_write_exception $e) {
                // Another request inserted the same row in the meantime: retry as an update.
                $existing = $DB->get_record(
                    'local_ceduanalytics_video',
                    ['userid' => $userid, 'cmid' => $cm->id, 'videoid' => $videoid],
                    'id',
                    MUST_EXIST
                );
                $row->id = $existing->id;
                $DB->update_record('local_ceduanalytics_video', $row);
            }
        } else {
            $DB->update_record('local_ceduanalytics_video', $row);
        }
        return $row;
    }

    /**
     * Playback per activity and video, for students in scope.
     *
     * @param scope $scope
     * @return array "cmid|videoid" => ['cmid', 'videoid', 'duration', 'players', 'plays', 'linkclicks', 'pctsum', 'completed']
     */
    public static function stats(scope $scope): array {
        global $DB;
        [$where, $params] = $scope->enrol_where(false);
        $rs = $DB->get_recordset_sql("
            SELECT v.id, v.cmid, v.videoid, v.duration, v.watchedsecs, v.plays, v.linkclicks
              FROM {local_ceduanalytics_video} v
              JOIN {local_ceduanalytics_enrolstate} e ON e.userid = v.userid AND e.courseid = v.courseid
              JOIN {course} c ON c.id = e.courseid
              JOIN {user} u ON u.id = e.userid AND u.deleted = 0
         LEFT JOIN {local_ceduanalytics_userattr} ua ON ua.userid = e.userid
             WHERE $where", $params);
        $out = [];
        foreach ($rs as $r) {
            $k = $r->cmid . '|' . $r->videoid;
            $out[$k] = $out[$k] ?? ['cmid' => (int)$r->cmid, 'videoid' => $r->videoid, 'duration' => 0, 'players' => 0,
                'plays' => 0, 'linkclicks' => 0, 'pctsum' => 0.0, 'completed' => 0, 'clickers' => 0];
            $o = &$out[$k];
            $o['duration'] = max($o['duration'], (int)$r->duration);
            $o['plays'] += (int)$r->plays;
            $o['linkclicks'] += (int)$r->linkclicks;
            $o['clickers'] += $r->linkclicks ? 1 : 0;
            if ((int)$r->plays > 0 || (int)$r->watchedsecs > 0) {
                $o['players']++;
                $pct = $r->duration ? min(1, $r->watchedsecs / $r->duration) : 0;
                $o['pctsum'] += $pct;
                $o['completed'] += $pct >= self::COMPLETE ? 1 : 0;
            }
            unset($o);
        }
        $rs->close();
        return $out;
    }
}
