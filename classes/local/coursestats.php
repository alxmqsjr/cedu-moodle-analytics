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
 * Per-course statistics, status (future/running/ended) and health with its reasons.
 *
 * Course statistics cover the whole life of the course (all student enrolments), filtered by
 * the course and user filters; only "active in the period" depends on the period.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursestats {
    /** @var int Days without access for a teacher to be considered not active. */
    const TEACHER_ACTIVE_DAYS = 14;

    /** @var string[] Funnel stages. */
    const STAGES = ['enrolled', 'accessed', 'p25', 'p50', 'p75', 'p100', 'certified'];

    /**
     * Statistics per course.
     *
     * @param scope $scope
     * @return array courseid => stats
     */
    public static function by_course(scope $scope): array {
        return $scope->cached('bycourse', function () use ($scope) {
            $out = [];
            $rows = $scope->enrolments('e.id, e.courseid, e.situation, e.progress, e.timecertified, e.gradepct, e.lastaccess,
                e.timeend, e.enrolactive, e.timeenrolled, e.firstaccess', '', [], '', false);
            foreach ($rows as $r) {
                if (!isset($out[$r->courseid])) {
                    $out[$r->courseid] = self::empty_stats();
                }
                $s = &$out[$r->courseid];
                self::add($s, $r);
                $s['maxtimeend'] = max($s['maxtimeend'], (int)$r->timeend);
                $s['openenrol'] = $s['openenrol'] || (int)$r->timeend === 0;
                unset($s);
            }
            foreach ($scope->daily('d.courseid, COUNT(DISTINCT d.userid) AS n', 'd.courseid') as $r) {
                if (isset($out[$r->courseid])) {
                    $out[$r->courseid]['activeperiod'] = (int)$r->n;
                }
            }
            return $out;
        });
    }

    /**
     * Empty statistics.
     *
     * @return array
     */
    public static function empty_stats(): array {
        return ['enrolled' => 0, 'accessed' => 0, 'p25' => 0, 'p50' => 0, 'p75' => 0, 'p100' => 0, 'certified' => 0,
            'completed' => 0, 'notstarted' => 0, 'inactive' => 0, 'attention' => 0, 'ended' => 0, 'ontrack' => 0,
            'progresssum' => 0.0, 'gradesum' => 0.0, 'gradecount' => 0, 'maxtimeend' => 0, 'openenrol' => false,
            'activeperiod' => 0, 'certdays' => []];
    }

    /**
     * Add one enrolment to a statistics array.
     *
     * @param array $s
     * @param \stdClass $r enrolment row
     */
    public static function add(array &$s, \stdClass $r): void {
        $s['enrolled']++;
        $p = (float)$r->progress;
        if ($r->lastaccess > 0) {
            $s['accessed']++;
        }
        foreach ([25 => 'p25', 50 => 'p50', 75 => 'p75', 100 => 'p100'] as $limit => $key) {
            if ($p >= $limit || $r->timecertified) {
                $s[$key]++;
            }
        }
        if ($r->timecertified) {
            $s['certified']++;
            $start = max((int)$r->timeenrolled, (int)($r->firstaccess ?? 0));
            if ($start && $r->timecertified >= $start) {
                $s['certdays'][] = ($r->timecertified - $start) / DAYSECS;
            }
        }
        // Certified enrolments are already counted from the certificate date above.
        if ($r->situation !== 'certified' && isset($s[$r->situation])) {
            $s[$r->situation]++;
        }
        $s['progresssum'] += $p;
        if ($r->gradepct !== null) {
            $s['gradesum'] += (float)$r->gradepct;
            $s['gradecount']++;
        }
    }

    /**
     * Teachers of the given courses with their last access to the course.
     *
     * @param int[] $courseids
     * @return array courseid => list of ['userid', 'name', 'role', 'lastaccess']
     */
    public static function teachers(array $courseids): array {
        global $DB;
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $namefields = implode(', ', array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields()));
        $rows = $DB->get_records_sql("
            SELECT ra.id, ctx.instanceid AS courseid, u.id AS userid, r.shortname, la.timeaccess, $namefields
              FROM {role_assignments} ra
              JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = " . CONTEXT_COURSE . "
              JOIN {role} r ON r.id = ra.roleid AND r.shortname IN ('editingteacher', 'teacher')
              JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
         LEFT JOIN {user_lastaccess} la ON la.userid = u.id AND la.courseid = ctx.instanceid
             WHERE ctx.instanceid $insql", $params);
        $out = [];
        foreach ($rows as $r) {
            $key = $r->courseid . '|' . $r->userid;
            if (isset($out[$r->courseid][$key])) {
                $out[$r->courseid][$key]['role'] .= ', ' . $r->shortname;
                continue;
            }
            $out[$r->courseid][$key] = ['userid' => (int)$r->userid, 'name' => fullname($r), 'role' => $r->shortname,
                'lastaccess' => (int)$r->timeaccess];
        }
        return array_map('array_values', $out);
    }

    /**
     * Status of a course: future, running, ended or nodates.
     *
     * The end is the course end date or, without it, the latest enrolment end (when every enrolment ends).
     *
     * @param \stdClass $course with startdate, enddate
     * @param array|null $stats
     * @return string
     */
    public static function status(\stdClass $course, ?array $stats): string {
        $now = time();
        $end = (int)$course->enddate;
        if (!$end && $stats && !$stats['openenrol'] && $stats['maxtimeend']) {
            $end = $stats['maxtimeend'];
        }
        if ($course->startdate > $now) {
            return 'future';
        }
        if ($end && $end < $now) {
            return 'ended';
        }
        return $end ? 'running' : 'noend';
    }

    /**
     * Health of a course and the reasons.
     *
     * Running courses: share of students who never accessed, inactive students, teacher activity.
     * Ended courses: certification rate and share who never accessed.
     *
     * @param string $type course type (track, standalone, model, empty)
     * @param string $status
     * @param array|null $s stats
     * @param array $teachers
     * @return array ['level' => good|attention|critical|na, 'reasons' => string[]]
     */
    public static function health(string $type, string $status, ?array $s, array $teachers): array {
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        if ($type === 'model') {
            return ['level' => 'na', 'reasons' => [$str('health_model')]];
        }
        if (!$s || !$s['enrolled']) {
            return ['level' => 'na', 'reasons' => [$str('health_nostudents')]];
        }
        if ($status === 'future') {
            return ['level' => 'na', 'reasons' => [$str('health_future')]];
        }
        $reasons = [];
        $score = 0;
        $never = $s['notstarted'] / $s['enrolled'] * 100;
        if ($never >= 50) {
            $score += 2;
            $reasons[] = $str('health_never', fmt::percentnum($never));
        } else if ($never >= 25) {
            $score += 1;
            $reasons[] = $str('health_never', fmt::percentnum($never));
        }
        if ($status === 'ended') {
            $rate = $s['certified'] / $s['enrolled'] * 100;
            if ($rate < 30) {
                $score += 2;
                $reasons[] = $str('health_certlow', fmt::percentnum($rate));
            } else if ($rate < 60) {
                $score += 1;
                $reasons[] = $str('health_certmid', fmt::percentnum($rate));
            } else {
                $reasons[] = $str('health_certgood', fmt::percentnum($rate));
            }
        } else {
            $behind = $s['inactive'] + $s['attention'];
            $pct = $behind / $s['enrolled'] * 100;
            if ($pct >= 40) {
                $score += 2;
                $reasons[] = $str('health_behind', fmt::percentnum($pct));
            } else if ($pct >= 20) {
                $score += 1;
                $reasons[] = $str('health_behind', fmt::percentnum($pct));
            }
            $recent = array_filter($teachers, fn($t) => $t['lastaccess'] >= time() - self::TEACHER_ACTIVE_DAYS * DAYSECS);
            if (!$teachers) {
                $score += 1;
                $reasons[] = $str('health_noteacher');
            } else if (!$recent) {
                $score += 1;
                $reasons[] = $str('health_teacherinactive', self::TEACHER_ACTIVE_DAYS);
            }
        }
        $level = $score >= 3 ? 'critical' : ($score >= 1 ? 'attention' : 'good');
        if (!$reasons) {
            $reasons[] = $str('health_ok');
        }
        return ['level' => $level, 'reasons' => $reasons];
    }

    /**
     * Activities of a course in order, with completion, opening and where students stop.
     *
     * "Stopped here" = students who did not finish (no certificate and progress below 100%) whose
     * furthest completed tracked activity, in course order, is this one.
     *
     * @param int $courseid
     * @param \stdClass[] $students userid => enrolment row (progress, timecertified)
     * @return array ['beforefirst' => int, 'students' => int, 'rows' => list of [section, cm, completed, opened, stopped]]
     */
    public static function activity_stats(int $courseid, array $students): array {
        global $DB;
        $modinfo = get_fast_modinfo($courseid, -1);
        $course = $modinfo->get_course();
        $ordered = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->deletioninprogress) {
                    $ordered[] = [$section, $cm];
                }
            }
        }
        $done = [];
        $opened = [];
        if ($students && $ordered) {
            foreach (array_chunk(array_keys($students), 1000) as $chunk) {
                [$uin, $uparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'u');
                $rs = $DB->get_recordset_sql(
                    "SELECT cmc.id, cmc.coursemoduleid, cmc.userid
                        FROM {course_modules_completion} cmc
                        JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                       WHERE cm.course = :course AND cmc.completionstate IN (1, 2) AND cmc.userid $uin",
                    ['course' => $courseid] + $uparams
                );
                foreach ($rs as $r) {
                    $done[(int)$r->coursemoduleid][(int)$r->userid] = true;
                }
                $rs->close();
                $rs = $DB->get_recordset_sql("SELECT id, cmid, userid FROM {local_ceduanalytics_cmview}
                       WHERE courseid = :course AND userid $uin", ['course' => $courseid] + $uparams);
                foreach ($rs as $r) {
                    $opened[(int)$r->cmid][(int)$r->userid] = true;
                }
                $rs->close();
            }
        }
        $stopped = [];
        $beforefirst = 0;
        foreach ($students as $uid => $r) {
            if ($r->timecertified || (float)$r->progress >= 100) {
                continue;
            }
            $last = null;
            foreach ($ordered as [$section, $cm]) {
                if ($cm->completion && !empty($done[$cm->id][$uid])) {
                    $last = $cm->id;
                }
            }
            if ($last === null) {
                $beforefirst++;
            } else {
                $stopped[$last] = ($stopped[$last] ?? 0) + 1;
            }
        }
        $rows = [];
        foreach ($ordered as [$section, $cm]) {
            $rows[] = [
                'section' => get_section_name($course, $section),
                'cm' => $cm,
                'completed' => count($done[$cm->id] ?? []),
                'opened' => count($opened[$cm->id] ?? []),
                'stopped' => $stopped[$cm->id] ?? 0,
            ];
        }
        return ['beforefirst' => $beforefirst, 'students' => count($students), 'rows' => $rows];
    }

    /**
     * Median of a list.
     *
     * @param float[] $values
     * @return float|null
     */
    public static function median(array $values): ?float {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);
        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /**
     * Health badge HTML.
     *
     * @param string $level
     * @return string
     */
    public static function health_badge(string $level): string {
        return \html_writer::span(
            get_string('health_' . $level . '_label', 'local_ceduanalytics'),
            'cea-badge cea-health cea-health-' . $level
        );
    }

    /**
     * Status badge HTML.
     *
     * @param string $status
     * @return string
     */
    public static function status_badge(string $status): string {
        return \html_writer::span(get_string('cstatus_' . $status, 'local_ceduanalytics'), 'cea-badge cea-cstatus-' . $status);
    }

    /**
     * Funnel context for templates/funnel.mustache.
     *
     * @param array $s stats
     * @param callable|null $urlfor stage => url
     * @return array
     */
    public static function funnel(array $s, ?callable $urlfor = null): array {
        $stages = [];
        $prev = null;
        foreach (self::STAGES as $stage) {
            $n = (int)$s[$stage];
            $stages[] = [
                'label' => get_string('funnel_' . $stage, 'local_ceduanalytics'),
                'value' => fmt::int($n),
                'pct' => fmt::pct($n, $s['enrolled']),
                'width' => $s['enrolled'] ? round($n / $s['enrolled'] * 100, 1) : 0,
                'loss' => $prev !== null && $prev > $n ? get_string(
                    'funnel_loss',
                    'local_ceduanalytics',
                    fmt::int($prev - $n)
                ) : '',
                'url' => $urlfor ? $urlfor($stage) : '',
            ];
            $prev = $n;
        }
        return ['stages' => $stages, 'hasdata' => $s['enrolled'] > 0];
    }

    /**
     * Course link to the course panel.
     *
     * @param int $courseid
     * @param string $name formatted name
     * @param array $params filter params
     * @return string
     */
    public static function link(int $courseid, string $name, array $params = []): string {
        return \html_writer::link(navigation::url('course', ['id' => $courseid] + $params), s($name));
    }
}
