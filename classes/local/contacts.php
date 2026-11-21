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
 * Team and contacts: who is staff, contacts made with students, replies, pending grading.
 *
 * Only metadata is read: sender, recipient, conversation and time of messages; author, student,
 * course and time of notes. The content of messages and notes is never read.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class contacts {
    /** @var string[] Course roles that make someone part of the teaching team. */
    const TEAMROLES = ['editingteacher', 'teacher', 'manager'];

    /** @var int[]|null */
    protected static $staff = null;

    /**
     * Staff: anyone with a team role in some course or category, managers and site administrators.
     *
     * Used to tell staff messages from student messages.
     *
     * @return int[] userid => userid
     */
    public static function staff_ids(): array {
        global $DB, $CFG;
        if (self::$staff === null) {
            [$insql, $params] = $DB->get_in_or_equal(array_merge(self::TEAMROLES, ['coursecreator']), SQL_PARAMS_NAMED);
            $ids = $DB->get_fieldset_sql("
                SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE r.shortname $insql", $params);
            foreach (explode(',', (string)$CFG->siteadmins) as $id) {
                if ((int)$id) {
                    $ids[] = (int)$id;
                }
            }
            $ids = array_map('intval', $ids);
            self::$staff = array_combine($ids, $ids) ?: [];
        }
        return self::$staff;
    }

    /**
     * Members of the team: users with a team role in a course, and the site administrators.
     *
     * @return array userid => ['userid', 'name', 'roles' => [], 'courses' => [courseid => roles], 'lastaccess', 'email',
     *     'suspended']
     */
    public static function team(): array {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(self::TEAMROLES, SQL_PARAMS_NAMED);
        $namefields = implode(', ', array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields()));
        $rs = $DB->get_recordset_sql("
            SELECT ra.id, ra.userid, ctx.instanceid AS courseid, r.shortname, u.email, u.lastaccess, u.suspended, $namefields
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
              JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :lvl
              JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
             WHERE r.shortname $insql AND ctx.instanceid <> :siteid
          ORDER BY u.firstname, u.lastname", $params + ['lvl' => CONTEXT_COURSE, 'siteid' => SITEID]);
        $out = [];
        foreach ($rs as $r) {
            $uid = (int)$r->userid;
            if (!isset($out[$uid])) {
                $out[$uid] = ['userid' => $uid, 'name' => fullname($r), 'email' => $r->email, 'roles' => [],
                    'courses' => [], 'lastaccess' => (int)$r->lastaccess, 'suspended' => (int)$r->suspended];
            }
            $out[$uid]['roles'][$r->shortname] = $r->shortname;
            $out[$uid]['courses'][(int)$r->courseid][$r->shortname] = $r->shortname;
        }
        $rs->close();
        // Site administrators are part of the team even without a course role.
        global $CFG;
        foreach (array_filter(array_map('intval', explode(',', (string)$CFG->siteadmins))) as $uid) {
            $u = people::get($uid);
            if (!$u || $u->deleted) {
                continue;
            }
            if (!isset($out[$uid])) {
                $out[$uid] = ['userid' => $uid, 'name' => fullname($u), 'email' => $u->email, 'roles' => [],
                    'courses' => [], 'lastaccess' => (int)$u->lastaccess, 'suspended' => (int)$u->suspended];
            }
            $out[$uid]['roles']['siteadmin'] = 'siteadmin';
        }
        \core_collator::asort_array_of_arrays_by_key($out, 'name');
        return $out;
    }

    /**
     * Contacts of staff with students (metadata only), within a time range.
     *
     * A message counts when a staff member writes in a private conversation with a non-staff user.
     * A note counts when a staff member writes a note (core_notes) about a non-staff user.
     *
     * @param int $from
     * @param int $to
     * @param int[]|null $students Restrict to these students (null = all)
     * @return array[] list of ['kind', 'staffid', 'studentid', 'courseid', 'time']
     */
    public static function events(int $from, int $to, ?array $students = null): array {
        global $DB;
        $staff = self::staff_ids();
        $out = [];
        $rs = $DB->get_recordset_sql(
            "
            SELECT m.id, m.useridfrom, mcm.userid AS otherid, m.timecreated
              FROM {messages} m
              JOIN {message_conversations} c ON c.id = m.conversationid AND c.type = :type
              JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid AND mcm.userid <> m.useridfrom
             WHERE m.timecreated >= :from AND m.timecreated < :to",
            ['type' => \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL, 'from' => $from, 'to' => $to]
        );
        foreach ($rs as $r) {
            if (!isset($staff[(int)$r->useridfrom]) || isset($staff[(int)$r->otherid])) {
                continue;
            }
            if ($students !== null && !isset($students[(int)$r->otherid])) {
                continue;
            }
            $out[] = ['kind' => 'message', 'staffid' => (int)$r->useridfrom, 'studentid' => (int)$r->otherid, 'courseid' => 0,
                'time' => (int)$r->timecreated];
        }
        $rs->close();
        $rs = $DB->get_recordset_sql("
            SELECT p.id, p.usermodified, p.userid, p.courseid, p.created
              FROM {post} p
             WHERE p.module = 'notes' AND p.created >= :from AND p.created < :to", ['from' => $from, 'to' => $to]);
        foreach ($rs as $r) {
            if (isset($staff[(int)$r->userid]) || ($students !== null && !isset($students[(int)$r->userid]))) {
                continue;
            }
            $out[] = ['kind' => 'note', 'staffid' => (int)$r->usermodified, 'studentid' => (int)$r->userid,
                'courseid' => (int)$r->courseid, 'time' => (int)$r->created];
        }
        $rs->close();
        return $out;
    }

    /**
     * Last staff contact per student (message or note), all time.
     *
     * @return array studentid => ['time', 'kind', 'staffid']
     */
    public static function last_contacts(): array {
        $last = [];
        foreach (self::events(0, time() + DAYSECS) as $e) {
            if (!isset($last[$e['studentid']]) || $e['time'] > $last[$e['studentid']]['time']) {
                $last[$e['studentid']] = ['time' => $e['time'], 'kind' => $e['kind'], 'staffid' => $e['staffid']];
            }
        }
        return $last;
    }

    /**
     * Effectiveness of contacts: did the student come back or progress within N days?
     *
     * Contacts are grouped in episodes (one per student, kind and day). An episode is "at risk" when the
     * student had no course access in the 7 days before it. Returned: any course access after the contact
     * and up to N days later. Progressed: an activity completed in the same window.
     *
     * @param array[] $events From events()
     * @param int|null $days Window (defaults to the setting effectivenessdays)
     * @return array ['days', 'all' => stats, 'atrisk' => stats, 'bykind' => [kind => stats], 'bystaff' => [id => stats]]
     *     where stats = ['episodes', 'returned', 'progressed']
     */
    public static function effectiveness(array $events, ?int $days = null): array {
        global $DB;
        $days = $days ?? max(1, (int)get_config('local_ceduanalytics', 'effectivenessdays') ?: 7);
        $window = $days * DAYSECS;
        $empty = ['episodes' => 0, 'returned' => 0, 'progressed' => 0];
        $result = ['days' => $days, 'all' => $empty, 'atrisk' => $empty, 'bykind' => [], 'bystaff' => []];
        if (!$events) {
            return $result;
        }
        $episodes = [];
        foreach ($events as $e) {
            $key = $e['studentid'] . '|' . $e['kind'] . '|' . date('Ymd', $e['time']);
            if (!isset($episodes[$key]) || $e['time'] < $episodes[$key]['time']) {
                $episodes[$key] = $e;
            }
        }
        $students = array_unique(array_column($episodes, 'studentid'));
        $times = array_column($episodes, 'time');
        $min = min($times) - 7 * DAYSECS;
        $max = max($times) + $window;

        $access = [];
        $done = [];
        foreach (array_chunk($students, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $rs = $DB->get_recordset_sql(
                "
                SELECT id, userid, firstts, lastts
                  FROM {local_ceduanalytics_daily}
                 WHERE userid $insql AND courseid <> :siteid AND lastts >= :min AND firstts <= :max",
                $params + ['siteid' => SITEID, 'min' => $min, 'max' => $max]
            );
            foreach ($rs as $r) {
                $access[(int)$r->userid][] = [(int)$r->firstts, (int)$r->lastts];
            }
            $rs->close();
            $rs = $DB->get_recordset_sql(
                "
                SELECT id, userid, timemodified
                  FROM {course_modules_completion}
                 WHERE userid $insql AND completionstate IN (1, 2) AND timemodified >= :min AND timemodified <= :max",
                $params + ['min' => $min, 'max' => $max]
            );
            foreach ($rs as $r) {
                $done[(int)$r->userid][] = (int)$r->timemodified;
            }
            $rs->close();
        }

        $add = function (array &$stats, bool $returned, bool $progressed) {
            $stats['episodes']++;
            $stats['returned'] += $returned ? 1 : 0;
            $stats['progressed'] += $progressed ? 1 : 0;
        };
        foreach ($episodes as $e) {
            $t = $e['time'];
            $returned = false;
            $before = false;
            foreach ($access[$e['studentid']] ?? [] as [$first, $last]) {
                if ($last > $t && $first <= $t + $window) {
                    $returned = true;
                }
                if ($first < $t && $last >= $t - 7 * DAYSECS) {
                    $before = true;
                }
            }
            $progressed = false;
            foreach ($done[$e['studentid']] ?? [] as $ts) {
                if ($ts > $t && $ts <= $t + $window) {
                    $progressed = true;
                    break;
                }
            }
            $add($result['all'], $returned, $progressed);
            if (!$before) {
                $add($result['atrisk'], $returned, $progressed);
            }
            $result['bykind'][$e['kind']] = $result['bykind'][$e['kind']] ?? $empty;
            $add($result['bykind'][$e['kind']], $returned, $progressed);
            $result['bystaff'][$e['staffid']] = $result['bystaff'][$e['staffid']] ?? $empty;
            $add($result['bystaff'][$e['staffid']], $returned, $progressed);
        }
        return $result;
    }

    /**
     * Private conversations between a student and a staff member.
     *
     * Returns, per conversation, the unanswered student messages (sent after the last staff message)
     * and the reply times (from the first unanswered student message to the staff reply).
     *
     * @return array ['unanswered' => list of ['convid', 'studentid', 'staffid', 'since', 'last', 'count'],
     *     'replies' => list of ['staffid', 'studentid', 'time', 'secs']]
     */
    public static function conversations(): array {
        global $DB;
        $staff = self::staff_ids();
        $members = [];
        $rs = $DB->get_recordset_sql(
            "
            SELECT mcm.id, mcm.conversationid, mcm.userid
              FROM {message_conversation_members} mcm
              JOIN {message_conversations} c ON c.id = mcm.conversationid AND c.type = :type",
            ['type' => \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL]
        );
        foreach ($rs as $r) {
            $members[(int)$r->conversationid][] = (int)$r->userid;
        }
        $rs->close();
        // Keep conversations with exactly one staff member and one student.
        $pairs = [];
        foreach ($members as $convid => $ids) {
            if (count($ids) !== 2) {
                continue;
            }
            [$a, $b] = $ids;
            if (isset($staff[$a]) && !isset($staff[$b])) {
                $pairs[$convid] = [$a, $b];
            } else if (isset($staff[$b]) && !isset($staff[$a])) {
                $pairs[$convid] = [$b, $a];
            }
        }
        $out = ['unanswered' => [], 'replies' => []];
        if (!$pairs) {
            return $out;
        }
        $state = [];
        $rs = $DB->get_recordset_sql("
            SELECT m.id, m.conversationid, m.useridfrom, m.timecreated
              FROM {messages} m
              JOIN {message_conversations} c ON c.id = m.conversationid AND c.type = :type
          ORDER BY m.conversationid, m.timecreated, m.id", ['type' => \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL]);
        foreach ($rs as $r) {
            $convid = (int)$r->conversationid;
            if (!isset($pairs[$convid])) {
                continue;
            }
            [$staffid, $studentid] = $pairs[$convid];
            $s = &$state[$convid];
            if ((int)$r->useridfrom === $studentid) {
                if (empty($s['since'])) {
                    $s['since'] = (int)$r->timecreated;
                    $s['count'] = 0;
                }
                $s['count']++;
                $s['last'] = (int)$r->timecreated;
            } else {
                if (!empty($s['since'])) {
                    $out['replies'][] = ['staffid' => $staffid, 'studentid' => $studentid, 'time' => (int)$r->timecreated,
                        'secs' => (int)$r->timecreated - $s['since']];
                }
                $s['since'] = 0;
                $s['count'] = 0;
            }
            unset($s);
        }
        $rs->close();
        foreach ($state as $convid => $s) {
            if (!empty($s['since'])) {
                [$staffid, $studentid] = $pairs[$convid];
                $out['unanswered'][] = ['convid' => $convid, 'studentid' => $studentid, 'staffid' => $staffid,
                    'since' => $s['since'], 'last' => $s['last'], 'count' => $s['count']];
            }
        }
        usort($out['unanswered'], fn($a, $b) => $b['last'] <=> $a['last']);
        return $out;
    }

    /**
     * Assignment submissions waiting for grading, per assignment.
     *
     * @param int[]|null $courseids
     * @return array[] list of ['cmid', 'courseid', 'name', 'waiting', 'oldest']
     */
    public static function pending_grading(?array $courseids = null): array {
        global $DB;
        if (!$DB->get_manager()->table_exists('assign_submission')) {
            return [];
        }
        $where = '';
        $params = [];
        if ($courseids !== null) {
            if (!$courseids) {
                return [];
            }
            [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $where = "AND a.course $insql";
        }
        $rows = $DB->get_records_sql("
            SELECT cm.id AS cmid, a.course, a.name, COUNT(1) AS waiting, MIN(s.timemodified) AS oldest
              FROM {assign_submission} s
              JOIN {assign} a ON a.id = s.assignment
              JOIN {modules} md ON md.name = 'assign'
              JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = md.id
         LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
             WHERE s.latest = 1 AND s.status = 'submitted'
                   AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0 OR g.timemodified < s.timemodified) $where
          GROUP BY cm.id, a.course, a.name
          ORDER BY MIN(s.timemodified)", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['cmid' => (int)$r->cmid, 'courseid' => (int)$r->course, 'name' => $r->name,
                'waiting' => (int)$r->waiting, 'oldest' => (int)$r->oldest];
        }
        return $out;
    }

    /**
     * Median of a list of numbers.
     *
     * @param array $values
     * @return float|null
     */
    public static function median(array $values): ?float {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float)$values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Human duration (minutes, hours or days).
     *
     * @param float|null $secs
     * @return string
     */
    public static function duration(?float $secs): string {
        if ($secs === null) {
            return '—';
        }
        if ($secs < HOURSECS) {
            return get_string('dur_minutes', 'local_ceduanalytics', fmt::int(max(1, round($secs / MINSECS))));
        }
        if ($secs < 2 * DAYSECS) {
            return get_string('dur_hours', 'local_ceduanalytics', fmt::dec($secs / HOURSECS));
        }
        return get_string('dur_days', 'local_ceduanalytics', fmt::dec($secs / DAYSECS));
    }

    /**
     * Link to a teacher profile page.
     *
     * @param int $userid
     * @param string $name
     * @param array $params
     * @return string
     */
    public static function teacher_link(int $userid, string $name, array $params = []): string {
        return \html_writer::link(navigation::url('teacher', ['id' => $userid] + $params), s($name)) .
            people::dual_mark($userid, 'teacher');
    }
}
