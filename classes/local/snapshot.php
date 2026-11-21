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
 * Snapshot of every enrolment: dates, access, activity progress, certificate, grade and situation.
 *
 * Situations (in order of precedence):
 *  - certified: has a certificate (mod_customcert) in the course;
 *  - completed: 100% of the tracked activities, without certificate;
 *  - notstarted: never accessed the course;
 *  - ended: the enrolment/course ended without completion;
 *  - inactive: no access for N days while the course is running;
 *  - attention: progress below the expected pace for the elapsed time;
 *  - ontrack: everyone else.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot {
    /** @var string[] Situations, in display order. */
    const SITUATIONS = ['ontrack', 'attention', 'inactive', 'notstarted', 'ended', 'completed', 'certified'];

    /**
     * Rebuild the snapshot table and the menu counters.
     *
     * @param \progress_trace|null $trace
     * @return int Number of enrolments in the snapshot
     */
    public static function build(?\progress_trace $trace = null): int {
        global $DB;
        $now = time();
        $inactivedays = max(1, (int)get_config('local_ceduanalytics', 'inactivedays') ?: 14);
        $margin = (float)(get_config('local_ceduanalytics', 'pacemargin') ?: 25);

        $courses = $DB->get_records_select('course', 'id <> ?', [SITEID], '', 'id, startdate, enddate');

        $cmtotal = $DB->get_records_sql_menu("
            SELECT course, COUNT(1)
              FROM {course_modules}
             WHERE completion > 0 AND deletioninprogress = 0 AND visible = 1
          GROUP BY course");

        // Enrolments, merged per user and course.
        $enrol = [];
        $rs = $DB->get_recordset_sql("
            SELECT ue.id, ue.userid, e.courseid, e.enrol, e.status AS estatus, ue.status, ue.timestart, ue.timeend,
                   ue.timecreated
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
             WHERE e.courseid <> :siteid", ['siteid' => SITEID]);
        foreach ($rs as $r) {
            $k = $r->userid . '|' . $r->courseid;
            if (!isset($enrol[$k])) {
                $enrol[$k] = ['userid' => (int)$r->userid, 'courseid' => (int)$r->courseid, 'active' => 0, 'methods' => [],
                    'timeenrolled' => 0, 'timestart' => 0, 'timeend' => -1];
            }
            $e = &$enrol[$k];
            $e['methods'][$r->enrol] = $r->enrol;
            if ((int)$r->status === 0 && (int)$r->estatus === 0) {
                $e['active'] = 1;
            }
            $created = (int)$r->timecreated ?: (int)$r->timestart;
            if ($created && (!$e['timeenrolled'] || $created < $e['timeenrolled'])) {
                $e['timeenrolled'] = $created;
            }
            if ((int)$r->timestart && (!$e['timestart'] || $r->timestart < $e['timestart'])) {
                $e['timestart'] = (int)$r->timestart;
            }
            // Any enrolment without end date means no end.
            $end = (int)$r->timeend;
            $e['timeend'] = ($e['timeend'] === 0 || $end === 0) ? 0 : max($e['timeend'], $end);
            unset($e);
        }
        $rs->close();

        $roles = [];
        $rs = $DB->get_recordset_sql("
            SELECT ra.id, ra.userid, ctx.instanceid AS courseid, r.shortname
              FROM {role_assignments} ra
              JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :lvl
              JOIN {role} r ON r.id = ra.roleid", ['lvl' => CONTEXT_COURSE]);
        foreach ($rs as $r) {
            $roles[$r->userid . '|' . $r->courseid][$r->shortname] = $r->shortname;
        }
        $rs->close();

        $done = [];
        $rs = $DB->get_recordset_sql("
            SELECT cmc.userid, cm.course, COUNT(1) AS done, MAX(cmc.timemodified) AS lastdone
              FROM {course_modules_completion} cmc
              JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
             WHERE cm.completion > 0 AND cm.deletioninprogress = 0 AND cm.visible = 1 AND cmc.completionstate IN (1, 2)
          GROUP BY cmc.userid, cm.course");
        foreach ($rs as $r) {
            $done[$r->userid . '|' . $r->course] = [(int)$r->done, (int)$r->lastdone];
        }
        $rs->close();

        $certs = [];
        if ($DB->get_manager()->table_exists('customcert_issues')) {
            $rs = $DB->get_recordset_sql("
                SELECT ci.userid, c.course, MIN(ci.timecreated) AS issued
                  FROM {customcert_issues} ci
                  JOIN {customcert} c ON c.id = ci.customcertid
              GROUP BY ci.userid, c.course");
            foreach ($rs as $r) {
                $certs[$r->userid . '|' . $r->course] = (int)$r->issued;
            }
            $rs->close();
        }

        $completed = [];
        $rs = $DB->get_recordset_select(
            'course_completions',
            'timecompleted IS NOT NULL',
            null,
            '',
            'id, userid, course, timecompleted'
        );
        foreach ($rs as $r) {
            $completed[$r->userid . '|' . $r->course] = (int)$r->timecompleted;
        }
        $rs->close();

        $grades = [];
        $rs = $DB->get_recordset_sql("
            SELECT gg.id, gg.userid, gi.courseid, gg.finalgrade, gi.grademax, gi.grademin
              FROM {grade_grades} gg
              JOIN {grade_items} gi ON gi.id = gg.itemid AND gi.itemtype = 'course'
             WHERE gg.finalgrade IS NOT NULL");
        foreach ($rs as $r) {
            $range = (float)$r->grademax - (float)$r->grademin;
            if ($range > 0) {
                $grades[$r->userid . '|' . $r->courseid] = round(((float)$r->finalgrade - (float)$r->grademin) / $range * 100, 2);
            }
        }
        $rs->close();

        $lastaccess = [];
        $rs = $DB->get_recordset('user_lastaccess', null, '', 'id, userid, courseid, timeaccess');
        foreach ($rs as $r) {
            $lastaccess[$r->userid . '|' . $r->courseid] = (int)$r->timeaccess;
        }
        $rs->close();

        // First and last access from the standard log (aggregated). The log is also used for the last access
        // when user_lastaccess has no row: Moodle deletes that row on unenrolment, so re-enrolled people
        // who did access the course would otherwise look like they never did.
        $firstaccess = [];
        $loglast = [];
        $rs = $DB->get_recordset_sql("
            SELECT userid, courseid, MIN(firstts) AS firstts, MAX(lastts) AS lastts
              FROM {local_ceduanalytics_daily}
             WHERE courseid <> :siteid
          GROUP BY userid, courseid", ['siteid' => SITEID]);
        foreach ($rs as $r) {
            $firstaccess[$r->userid . '|' . $r->courseid] = (int)$r->firstts;
            $loglast[$r->userid . '|' . $r->courseid] = (int)$r->lastts;
        }
        $rs->close();

        // Build the rows.
        $rows = [];
        foreach ($enrol as $k => $e) {
            $course = $courses[$e['courseid']] ?? null;
            if (!$course) {
                continue;
            }
            $userroles = $roles[$k] ?? [];
            $total = (int)($cmtotal[$e['courseid']] ?? 0);
            [$cmdone, $lastdone] = $done[$k] ?? [0, 0];
            $cmdone = min($cmdone, $total);
            $progress = $total > 0 ? round($cmdone / $total * 100, 2) : 0;
            $last = $lastaccess[$k] ?? ($loglast[$k] ?? 0);
            $first = $firstaccess[$k] ?? 0;
            if (!$first && $last) {
                $first = $last;
            }
            $row = (object)[
                'userid' => $e['userid'],
                'courseid' => $e['courseid'],
                'isstudent' => isset($userroles['student']) ? 1 : 0,
                'roles' => \core_text::substr(implode(',', $userroles), 0, 255),
                'enrolactive' => $e['active'],
                'enrolmethods' => \core_text::substr(implode(',', $e['methods']), 0, 255),
                'timeenrolled' => $e['timeenrolled'],
                'timestart' => $e['timestart'],
                'timeend' => max(0, $e['timeend']),
                'firstaccess' => $first,
                'lastaccess' => $last,
                'cmtotal' => $total,
                'cmdone' => $cmdone,
                'progress' => $progress,
                'timelastdone' => $lastdone,
                'timecertified' => $certs[$k] ?? 0,
                'timecompleted' => $completed[$k] ?? 0,
                'gradepct' => $grades[$k] ?? null,
                'timemodified' => $now,
            ];
            [$row->situation, $row->reasons] = self::situation($row, $course, $now, $inactivedays, $margin);
            $rows[$k] = $row;
        }

        // Upsert.
        $transaction = $DB->start_delegated_transaction();
        $existing = [];
        $rs = $DB->get_recordset('local_ceduanalytics_enrolstate', null, '', 'id, userid, courseid');
        foreach ($rs as $r) {
            $existing[$r->userid . '|' . $r->courseid] = (int)$r->id;
        }
        $rs->close();
        $inserts = [];
        foreach ($rows as $k => $row) {
            if (isset($existing[$k])) {
                $row->id = $existing[$k];
                $DB->update_record('local_ceduanalytics_enrolstate', $row);
                unset($existing[$k]);
            } else {
                $inserts[] = $row;
            }
        }
        if ($inserts) {
            $DB->insert_records('local_ceduanalytics_enrolstate', $inserts);
        }
        foreach (array_chunk(array_values($existing), 500) as $chunk) {
            $DB->delete_records_list('local_ceduanalytics_enrolstate', 'id', $chunk);
        }
        self::update_counters();
        set_config('lastsnapshot', $now, 'local_ceduanalytics');
        $transaction->allow_commit();
        \cache::make('local_ceduanalytics', 'indicators')->purge();

        if ($trace) {
            $trace->output('Snapshot: ' . count($rows) . ' enrolments.');
        }
        return count($rows);
    }

    /**
     * Situation of an enrolment and the reasons behind it.
     *
     * @param \stdClass $row
     * @param \stdClass $course
     * @param int $now
     * @param int $inactivedays
     * @param float $margin Percentage points below the expected pace
     * @return string[] [situation, comma separated reasons]
     */
    public static function situation(\stdClass $row, \stdClass $course, int $now, int $inactivedays, float $margin): array {
        if ($row->timecertified) {
            return ['certified', ''];
        }
        if ($row->cmtotal > 0 && $row->cmdone >= $row->cmtotal) {
            return ['completed', 'nocertificate'];
        }
        if (!$row->lastaccess) {
            return ['notstarted', 'neveraccessed'];
        }
        $start = $row->timestart ?: ((int)$course->startdate ?: $row->timeenrolled);
        $end = $row->timeend ?: (int)$course->enddate;
        if ($end && $end < $now) {
            return ['ended', 'endedincomplete'];
        }
        if ($row->lastaccess < $now - $inactivedays * DAYSECS) {
            return ['inactive', 'noaccess'];
        }
        if ($end && $start && $end > $start && $row->cmtotal > 0) {
            $expected = min(100, max(0, ($now - $start) / ($end - $start) * 100));
            if ($row->progress < $expected - $margin) {
                return ['attention', 'belowpace'];
            }
        }
        return ['ontrack', ''];
    }

    /**
     * Menu counters (kept in config so pages never compute them).
     */
    public static function update_counters(): void {
        global $DB;
        $support = support::count_open();
        $grading = 0;
        if ($DB->get_manager()->table_exists('assign_submission')) {
            $grading = (int)$DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {assign_submission} s
             LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                       AND g.attemptnumber = s.attemptnumber
                 WHERE s.latest = 1 AND s.status = 'submitted'
                   AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0 OR g.timemodified < s.timemodified)");
        }
        $recent = 0;
        foreach (contacts::conversations()['unanswered'] as $u) {
            $recent += $u['last'] >= time() - 30 * DAYSECS ? 1 : 0;
        }
        $critical = count(array_filter(alerts::all(), fn($a) => $a['severity'] === 'critical'));
        set_config(
            'counters',
            json_encode(['support' => $support, 'team' => $grading + $recent, 'alerts' => $critical]),
            'local_ceduanalytics'
        );
    }

    /**
     * Current menu counters.
     *
     * @return array id => count
     */
    public static function counters(): array {
        return json_decode((string)get_config('local_ceduanalytics', 'counters'), true) ?: [];
    }
}
