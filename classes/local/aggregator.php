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
 * Incremental aggregation of the standard log into the plugin tables.
 *
 * Reads logstore_standard_log by primary key, from the last processed id (watermark),
 * in batches, and accumulates: activity per user/course/day, events per course/day/hour,
 * views per course module and user, and failed logins. Pages never read the log.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class aggregator {
    /** @var int Rows read per batch (one transaction per batch). */
    const BATCH = 20000;

    /** @var int Maximum gap between two events still counted as active time (seconds). */
    const SESSION_GAP = 1800;

    /** @var string Config name of the watermark. */
    const WATERMARK = 'logwatermark';

    /** @var array Cache of UTC offsets by hour bucket. */
    protected $offsets = [];

    /** @var \DateTimeZone */
    protected $tz;

    /** @var array userid => last event timestamp (for active time). */
    protected $lastts = [];

    /** @var int */
    protected $guestid;

    /**
     * Constructor.
     */
    public function __construct() {
        global $CFG;
        $this->tz = new \DateTimeZone(\core_date::get_server_timezone());
        $this->guestid = (int)($CFG->siteguest ?? 0);
    }

    /**
     * Process pending log rows.
     *
     * @param int $maxrows Stop after this many rows (0 = no limit).
     * @param \progress_trace|null $trace
     * @return int Number of log rows read.
     */
    public function run(int $maxrows = 200000, ?\progress_trace $trace = null): int {
        global $DB;
        $total = 0;
        while (true) {
            $watermark = (int)get_config('local_ceduanalytics', self::WATERMARK);
            $limit = self::BATCH;
            if ($maxrows > 0) {
                $limit = min($limit, $maxrows - $total);
                if ($limit <= 0) {
                    break;
                }
            }
            $rs = $DB->get_recordset_select(
                'logstore_standard_log',
                'id > ?',
                [$watermark],
                'id ASC',
                'id, eventname, crud, contextlevel, contextinstanceid, userid, courseid, origin, ip, other, timecreated',
                0,
                $limit
            );
            $acc = ['daily' => [], 'hourly' => [], 'cmview' => [], 'security' => []];
            $read = 0;
            $lastid = $watermark;
            foreach ($rs as $row) {
                $read++;
                $lastid = (int)$row->id;
                $this->accumulate($row, $acc);
            }
            $rs->close();
            if ($read === 0) {
                break;
            }
            $transaction = $DB->start_delegated_transaction();
            $this->flush($acc);
            set_config(self::WATERMARK, $lastid, 'local_ceduanalytics');
            $transaction->allow_commit();
            $total += $read;
            if ($trace) {
                $trace->output("Log: {$read} rows processed (up to id {$lastid}).");
            }
            if ($read < $limit) {
                break;
            }
        }
        set_config('lastaggregation', time(), 'local_ceduanalytics');
        if ($total > 0) {
            \cache::make('local_ceduanalytics', 'indicators')->purge();
        }
        return $total;
    }

    /**
     * Local midnight and hour of a timestamp.
     *
     * @param int $ts
     * @return int[] [day timestamp, hour]
     */
    protected function day_hour(int $ts): array {
        $bucket = intdiv($ts, 3600);
        if (!isset($this->offsets[$bucket])) {
            $this->offsets[$bucket] = $this->tz->getOffset(new \DateTime('@' . $ts));
        }
        $local = $ts + $this->offsets[$bucket];
        $secs = (($local % 86400) + 86400) % 86400;
        return [$ts - $secs, intdiv($secs, 3600)];
    }

    /**
     * Accumulate one log row.
     *
     * @param \stdClass $row
     * @param array $acc
     */
    protected function accumulate(\stdClass $row, array &$acc): void {
        $ts = (int)$row->timecreated;
        [$day, $hour] = $this->day_hour($ts);

        if ($row->eventname === '\\core\\event\\user_login_failed') {
            $other = json_decode((string)$row->other, true);
            $username = \core_text::substr(trim((string)($other['username'] ?? '')), 0, 100);
            $reason = (int)($other['reason'] ?? 0);
            $ip = \core_text::substr((string)$row->ip, 0, 45);
            $k = $day . '|' . $ip . '|' . $username . '|' . $reason;
            if (!isset($acc['security'][$k])) {
                $acc['security'][$k] = ['day' => $day, 'ip' => $ip, 'username' => $username, 'reason' => $reason,
                    'attempts' => 0, 'lastts' => 0];
            }
            $acc['security'][$k]['attempts']++;
            $acc['security'][$k]['lastts'] = max($acc['security'][$k]['lastts'], $ts);
            return;
        }

        $userid = (int)$row->userid;
        if ($userid <= 0 || $userid === $this->guestid) {
            return;
        }
        if ($row->origin === 'cli' || $row->origin === 'restore') {
            return;
        }
        $courseid = (int)$row->courseid ?: SITEID;
        $isws = $row->origin === 'ws';

        // Active time: gap to the previous event of the same user, if short enough.
        if (!array_key_exists($userid, $this->lastts)) {
            $this->lastts[$userid] = $this->previous_ts($userid);
        }
        $gap = $ts - $this->lastts[$userid];
        $active = ($gap > 0 && $gap <= self::SESSION_GAP) ? $gap : 0;
        $this->lastts[$userid] = max($this->lastts[$userid], $ts);

        $k = $userid . '|' . $courseid . '|' . $day;
        if (!isset($acc['daily'][$k])) {
            $acc['daily'][$k] = ['userid' => $userid, 'courseid' => $courseid, 'day' => $day, 'events' => 0, 'views' => 0,
                'writes' => 0, 'wsevents' => 0, 'logins' => 0, 'activesecs' => 0, 'firstts' => $ts, 'lastts' => $ts];
        }
        $d = &$acc['daily'][$k];
        $d['events']++;
        if ($row->crud === 'r') {
            $d['views']++;
        } else {
            $d['writes']++;
        }
        if ($isws) {
            $d['wsevents']++;
        }
        if ($row->eventname === '\\core\\event\\user_loggedin') {
            $d['logins']++;
        }
        $d['activesecs'] += $active;
        $d['firstts'] = min($d['firstts'], $ts);
        $d['lastts'] = max($d['lastts'], $ts);
        unset($d);

        $k = $courseid . '|' . $day . '|' . $hour;
        if (!isset($acc['hourly'][$k])) {
            $acc['hourly'][$k] = ['courseid' => $courseid, 'day' => $day, 'hour' => $hour, 'events' => 0, 'wsevents' => 0];
        }
        $acc['hourly'][$k]['events']++;
        if ($isws) {
            $acc['hourly'][$k]['wsevents']++;
        }

        if ((int)$row->contextlevel === CONTEXT_MODULE && $row->crud === 'r' && $courseid !== SITEID) {
            $cmid = (int)$row->contextinstanceid;
            $k = $cmid . '|' . $userid;
            if (!isset($acc['cmview'][$k])) {
                $acc['cmview'][$k] = ['cmid' => $cmid, 'courseid' => $courseid, 'userid' => $userid, 'views' => 0,
                    'firstview' => $ts, 'lastview' => $ts];
            }
            $acc['cmview'][$k]['views']++;
            $acc['cmview'][$k]['firstview'] = min($acc['cmview'][$k]['firstview'], $ts);
            $acc['cmview'][$k]['lastview'] = max($acc['cmview'][$k]['lastview'], $ts);
        }
    }

    /**
     * Last aggregated event time of a user (to continue active-time estimation across batches).
     *
     * @param int $userid
     * @return int
     */
    protected function previous_ts(int $userid): int {
        global $DB;
        return (int)$DB->get_field_sql('SELECT MAX(lastts) FROM {local_ceduanalytics_daily} WHERE userid = ?', [$userid]);
    }

    /**
     * Write the accumulated batch: add to existing rows, insert new ones.
     *
     * @param array $acc
     */
    protected function flush(array $acc): void {
        $this->merge(
            'local_ceduanalytics_daily',
            $acc['daily'],
            ['userid', 'courseid', 'day'],
            ['events', 'views', 'writes', 'wsevents', 'logins', 'activesecs'],
            ['firstts' => 'min', 'lastts' => 'max'],
            'day'
        );
        $this->merge(
            'local_ceduanalytics_hourly',
            $acc['hourly'],
            ['courseid', 'day', 'hour'],
            ['events', 'wsevents'],
            [],
            'day'
        );
        $this->merge(
            'local_ceduanalytics_cmview',
            $acc['cmview'],
            ['cmid', 'userid'],
            ['views'],
            ['firstview' => 'min', 'lastview' => 'max'],
            'cmid'
        );
        $this->merge(
            'local_ceduanalytics_security',
            $acc['security'],
            ['day', 'ip', 'username', 'reason'],
            ['attempts'],
            ['lastts' => 'max'],
            'day'
        );
    }

    /**
     * Upsert accumulated rows into a table.
     *
     * @param string $table
     * @param array $rows key => row array
     * @param string[] $keyfields Fields forming the natural key
     * @param string[] $sumfields Fields to add up
     * @param array $minmax field => 'min'|'max'
     * @param string $rangefield Field used to load the existing rows in a few queries
     */
    protected function merge(
        string $table,
        array $rows,
        array $keyfields,
        array $sumfields,
        array $minmax,
        string $rangefield
    ): void {
        global $DB;
        if (!$rows) {
            return;
        }
        $values = array_values(array_unique(array_column($rows, $rangefield)));
        $existing = [];
        foreach (array_chunk($values, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $rs = $DB->get_recordset_select($table, "$rangefield $insql", $params);
            foreach ($rs as $rec) {
                $k = implode('|', array_map(fn($f) => $rec->$f, $keyfields));
                $existing[$k] = $rec;
            }
            $rs->close();
        }
        $inserts = [];
        foreach ($rows as $row) {
            $k = implode('|', array_map(fn($f) => $row[$f], $keyfields));
            if (isset($existing[$k])) {
                $rec = $existing[$k];
                foreach ($sumfields as $f) {
                    $rec->$f = (int)$rec->$f + $row[$f];
                }
                foreach ($minmax as $f => $op) {
                    $rec->$f = $op === 'min' ? min((int)$rec->$f, $row[$f]) : max((int)$rec->$f, $row[$f]);
                }
                $DB->update_record($table, $rec);
            } else {
                $inserts[] = $row;
            }
        }
        if ($inserts) {
            $DB->insert_records($table, $inserts);
        }
    }

    /**
     * Number of log rows not yet aggregated.
     *
     * @return int
     */
    public static function pending(): int {
        global $DB;
        $watermark = (int)get_config('local_ceduanalytics', self::WATERMARK);
        return $DB->count_records_select('logstore_standard_log', 'id > ?', [$watermark]);
    }
}
