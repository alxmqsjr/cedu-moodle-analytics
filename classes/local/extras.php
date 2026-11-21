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
 * Additional data blocks of the Moodle database shown in the pages (each is a table with its own export):
 * SCORM, quiz and activity grades, question statistics, views recorded for completion, storage per course,
 * groups, cohorts, app devices, sessions, configuration changes, privacy requests, forums, notifications,
 * grade changes, read status of messages, Moodle analytics predictions and Moodle surveys.
 *
 * Strings: x_<id> (title), x_<id>_help (what it shows), x_<id>_empty (when there are no rows).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extras {
    /** @var array Page id => block ids shown at the end of that page. */
    const PAGES = [
        'students' => ['groups'],
        'support' => ['analytics'],
        'assessments' => ['quizgrades', 'activitygrades', 'quizstats', 'gradehistory', 'scormscores'],
        'materials' => ['scorm', 'viewed', 'storage'],
        'feedback' => ['surveys'],
        'team' => ['readstatus', 'forums', 'notifications'],
        'audience' => ['cohorts', 'devices'],
        'security' => ['sessions', 'configlog', 'privacy'],
    ];

    /**
     * Block ids of a page.
     *
     * @param string $pageid
     * @return string[]
     */
    public static function blocks(string $pageid): array {
        return self::PAGES[$pageid] ?? [];
    }

    /**
     * Build a block.
     *
     * @param string $id
     * @param filters $filters
     * @return dataset|null
     */
    public static function dataset(string $id, filters $filters): ?dataset {
        $method = 'build_' . $id;
        if (!method_exists(self::class, $method)) {
            return null;
        }
        $scope = new scope($filters);
        $ds = self::$method($scope, $filters);
        $ds->emptytext = get_string('x_' . $id . '_empty', 'local_ceduanalytics');
        return $ds;
    }

    /**
     * String helper.
     *
     * @param string $key
     * @param mixed $a
     * @return string
     */
    protected static function s(string $key, $a = null): string {
        return get_string($key, 'local_ceduanalytics', $a);
    }

    /**
     * New dataset for a block.
     *
     * @param string $id
     * @param array $columns
     * @param string[] $numeric
     * @return dataset
     */
    protected static function ds(string $id, array $columns, array $numeric = []): dataset {
        $labels = [];
        foreach ($columns as $col => $key) {
            $labels[$col] = self::s($key);
        }
        return new dataset($id, self::s('x_' . $id), $labels, $numeric);
    }

    /**
     * SQL condition on a course id column for the course filters.
     *
     * @param scope $scope
     * @param string $column
     * @return array [sql, params] ('1 = 1' when no course filter)
     */
    protected static function coursewhere(scope $scope, string $column): array {
        global $DB;
        $ids = $scope->course_ids();
        if ($ids === null) {
            return ['1 = 1', []];
        }
        if (!$ids) {
            return ['1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'xc');
        return ["$column $insql", $params];
    }

    /**
     * Students (role student) in scope: userid => true.
     *
     * @param scope $scope
     * @return array
     */
    protected static function students(scope $scope): array {
        $out = [];
        foreach ($scope->enrolments('DISTINCT e.userid', '', [], '', false) as $r) {
            $out[(int)$r->userid] = true;
        }
        return $out;
    }

    /**
     * Course name (cached).
     *
     * @param int $courseid
     * @return string
     */
    protected static function coursename(int $courseid): string {
        global $DB;
        static $names = null;
        if ($names === null) {
            // All course names in one query (the course table is small), formatted when first used.
            $names = $DB->get_records_menu('course', null, '', 'id, fullname');
        }
        if (!isset($names[$courseid])) {
            return '';
        }
        if (!is_array($names[$courseid])) {
            $names[$courseid] = [fmt::text((string)$names[$courseid])];
        }
        return $names[$courseid][0];
    }

    /**
     * SCORM packages: who attempted, completed and passed (cmi.core.lesson_status / cmi.completion_status).
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_scorm(scope $scope): dataset {
        global $DB;
        $ds = self::ds('scorm', ['course' => 'col_course', 'activity' => 'col_activity', 'students' => 'col_attempted',
            'completed' => 'col_completed', 'passed' => 'col_passed', 'incomplete' => 'col_incomplete',
            'avgtime' => 'col_avgtime'], ['students', 'completed', 'passed', 'incomplete']);
        if (!$DB->get_manager()->table_exists('scorm_attempt')) {
            return $ds;
        }
        [$cw, $cp] = self::coursewhere($scope, 's.course');
        $students = self::students($scope);
        $rs = $DB->get_recordset_sql("
            SELECT v.id, s.id AS scormid, s.course, s.name, a.userid, e.element, v.value
              FROM {scorm} s
              JOIN {scorm_attempt} a ON a.scormid = s.id
              JOIN {scorm_scoes_value} v ON v.attemptid = a.id
              JOIN {scorm_element} e ON e.id = v.elementid
             WHERE $cw AND e.element IN ('cmi.core.lesson_status', 'cmi.completion_status', 'cmi.success_status',
                   'cmi.core.total_time', 'cmi.total_time')", $cp);
        $agg = [];
        foreach ($rs as $r) {
            if (!isset($students[(int)$r->userid])) {
                continue;
            }
            $k = (int)$r->scormid;
            $agg[$k] = $agg[$k] ?? ['course' => (int)$r->course, 'name' => $r->name, 'users' => [], 'completed' => [],
                'passed' => [], 'secs' => []];
            $a = &$agg[$k];
            $a['users'][$r->userid] = true;
            $v = strtolower(trim((string)$r->value));
            if (in_array($r->element, ['cmi.core.lesson_status', 'cmi.completion_status', 'cmi.success_status'])) {
                if (in_array($v, ['completed', 'passed'])) {
                    $a['completed'][$r->userid] = true;
                }
                if ($v === 'passed') {
                    $a['passed'][$r->userid] = true;
                }
            } else {
                $a['secs'][$r->userid] = max($a['secs'][$r->userid] ?? 0, self::duration((string)$r->value));
            }
            unset($a);
        }
        $rs->close();
        uasort($agg, fn($a, $b) => count($b['users']) <=> count($a['users']));
        foreach ($agg as $a) {
            $n = count($a['users']);
            $avg = $a['secs'] ? array_sum($a['secs']) / count($a['secs']) : 0;
            $ds->add(['course' => self::coursename($a['course']), 'activity' => fmt::text($a['name']), 'students' => $n,
                'completed' => count($a['completed']), 'passed' => count($a['passed']),
                'incomplete' => $n - count($a['completed']), 'avgtime' => $avg ? contacts::duration($avg) : '—']);
        }
        return $ds;
    }

    /**
     * SCORM time string (HH:MM:SS.ss or PT#H#M#S) in seconds.
     *
     * @param string $value
     * @return int
     */
    protected static function duration(string $value): int {
        if (preg_match('/^(\d+):(\d{2}):(\d{2})/', $value, $m)) {
            return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
        }
        if (preg_match('/^P(?:T(?:(\d+)H)?(?:(\d+)M)?(?:([\d.]+)S)?)?$/', $value, $m)) {
            return (int)($m[1] ?? 0) * 3600 + (int)($m[2] ?? 0) * 60 + (int)($m[3] ?? 0);
        }
        return 0;
    }

    /**
     * SCORM scores (cmi.core.score.raw / cmi.score.raw) per package.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_scormscores(scope $scope): dataset {
        global $DB;
        $ds = self::ds('scormscores', ['course' => 'col_course', 'activity' => 'col_activity', 'students' => 'col_withscore',
            'avg' => 'col_avgscore', 'min' => 'col_min', 'max' => 'col_max'], ['students', 'avg', 'min', 'max']);
        if (!$DB->get_manager()->table_exists('scorm_attempt')) {
            return $ds;
        }
        [$cw, $cp] = self::coursewhere($scope, 's.course');
        $students = self::students($scope);
        $rs = $DB->get_recordset_sql("
            SELECT v.id, s.id AS scormid, s.course, s.name, a.userid, v.value
              FROM {scorm} s
              JOIN {scorm_attempt} a ON a.scormid = s.id
              JOIN {scorm_scoes_value} v ON v.attemptid = a.id
              JOIN {scorm_element} e ON e.id = v.elementid
             WHERE $cw AND e.element IN ('cmi.core.score.raw', 'cmi.score.raw', 'cmi.score.scaled')", $cp);
        $agg = [];
        foreach ($rs as $r) {
            if (!isset($students[(int)$r->userid]) || !is_numeric($r->value)) {
                continue;
            }
            $k = (int)$r->scormid;
            $agg[$k] = $agg[$k] ?? ['course' => (int)$r->course, 'name' => $r->name, 'scores' => []];
            $agg[$k]['scores'][$r->userid] = max($agg[$k]['scores'][$r->userid] ?? 0, (float)$r->value);
        }
        $rs->close();
        foreach ($agg as $a) {
            $sc = $a['scores'];
            $ds->add(['course' => self::coursename($a['course']), 'activity' => fmt::text($a['name']), 'students' => count($sc),
                'avg' => fmt::dec(array_sum($sc) / count($sc)), 'min' => fmt::dec(min($sc)), 'max' => fmt::dec(max($sc))]);
        }
        return $ds;
    }

    /**
     * Final grade of each quiz (quiz_grades), students in scope.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_quizgrades(scope $scope): dataset {
        global $DB;
        $ds = self::ds('quizgrades', ['course' => 'col_course', 'activity' => 'col_activity', 'students' => 'col_gradedstudents',
            'avg' => 'col_avgpct', 'median' => 'col_medianpct', 'low' => 'col_below50'], ['students', 'avg', 'median', 'low']);
        [$cw, $cp] = self::coursewhere($scope, 'q.course');
        $students = self::students($scope);
        $rs = $DB->get_recordset_sql("
            SELECT g.id, q.id AS quizid, q.course, q.name, q.grade AS maxgrade, g.userid, g.grade
              FROM {quiz} q
              JOIN {quiz_grades} g ON g.quiz = q.id
             WHERE $cw AND q.grade > 0", $cp);
        $agg = [];
        foreach ($rs as $r) {
            if (!isset($students[(int)$r->userid])) {
                continue;
            }
            $agg[$r->quizid] = $agg[$r->quizid] ?? ['course' => (int)$r->course, 'name' => $r->name, 'pcts' => []];
            $agg[$r->quizid]['pcts'][] = (float)$r->grade / (float)$r->maxgrade * 100;
        }
        $rs->close();
        uasort($agg, fn($a, $b) => count($b['pcts']) <=> count($a['pcts']));
        foreach ($agg as $a) {
            $p = $a['pcts'];
            $ds->add(['course' => self::coursename($a['course']), 'activity' => fmt::text($a['name']), 'students' => count($p),
                'avg' => fmt::percent(array_sum($p) / count($p)), 'median' => fmt::percent(contacts::median($p)),
                'low' => count(array_filter($p, fn($v) => $v < 50))]);
        }
        return $ds;
    }

    /**
     * Grades of every graded activity (grade items of modules).
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_activitygrades(scope $scope): dataset {
        global $DB;
        $ds = self::ds('activitygrades', ['course' => 'col_course', 'activity' => 'col_activity', 'type' => 'col_modtype',
            'students' => 'col_gradedstudents', 'avg' => 'col_avgpct', 'pass' => 'col_gradepass'], ['students', 'avg']);
        [$cw, $cp] = self::coursewhere($scope, 'gi.courseid');
        $students = self::students($scope);
        $rs = $DB->get_recordset_sql("
            SELECT gg.id, gi.id AS itemid, gi.courseid, gi.itemname, gi.itemmodule, gi.grademax, gi.grademin, gi.gradepass,
                   gg.userid, gg.finalgrade
              FROM {grade_items} gi
              JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.finalgrade IS NOT NULL
             WHERE gi.itemtype = 'mod' AND gi.grademax > gi.grademin AND $cw", $cp);
        $agg = [];
        foreach ($rs as $r) {
            if (!isset($students[(int)$r->userid])) {
                continue;
            }
            $agg[$r->itemid] = $agg[$r->itemid] ?? ['course' => (int)$r->courseid, 'name' => $r->itemname,
                'module' => $r->itemmodule, 'pass' => (float)$r->gradepass, 'pcts' => []];
            $range = (float)$r->grademax - (float)$r->grademin;
            $agg[$r->itemid]['pcts'][] = ((float)$r->finalgrade - (float)$r->grademin) / $range * 100;
        }
        $rs->close();
        uasort($agg, fn($a, $b) => count($b['pcts']) <=> count($a['pcts']));
        foreach ($agg as $a) {
            $p = $a['pcts'];
            $ds->add(['course' => self::coursename($a['course']), 'activity' => fmt::text((string)$a['name']),
                'type' => get_string('modulename', $a['module']), 'students' => count($p),
                'avg' => fmt::percent(array_sum($p) / count($p)), 'pass' => $a['pass'] > 0 ? fmt::dec($a['pass']) : '—']);
        }
        return $ds;
    }

    /**
     * Question statistics computed by Moodle (latest calculation of each question).
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_quizstats(scope $scope): dataset {
        global $DB;
        $ds = self::ds('quizstats', ['question' => 'col_question', 'attempts' => 'col_answers', 'facility' => 'col_facility',
            'discrimination' => 'col_discrimination'], ['attempts', 'facility', 'discrimination']);
        $rows = $DB->get_records_sql("
            SELECT s.id, s.questionid, s.s, s.facility, s.discriminationindex, s.timemodified, q.name
              FROM {question_statistics} s
              JOIN {question} q ON q.id = s.questionid
             WHERE s.slot IS NOT NULL AND s.subquestion = 0 AND s.variant IS NULL
          ORDER BY s.timemodified DESC");
        $seen = [];
        foreach ($rows as $r) {
            $key = br::normalise($r->name);
            if (isset($seen[$key])) {
                $seen[$key]['s'] += (int)$r->s;
                continue;
            }
            $seen[$key] = ['name' => fmt::text($r->name), 's' => (int)$r->s, 'facility' => $r->facility,
                'discrimination' => $r->discriminationindex];
        }
        uasort($seen, fn($a, $b) => (float)$a['facility'] <=> (float)$b['facility']);
        foreach ($seen as $q) {
            $ds->add(['question' => $q['name'], 'attempts' => $q['s'],
                'facility' => $q['facility'] === null ? '—' : fmt::percent((float)$q['facility'] * 100),
                'discrimination' => $q['discrimination'] === null ? '—' : fmt::percent((float)$q['discrimination'])]);
        }
        return $ds;
    }

    /**
     * Grade changes recorded in the grade history (updates after the first grade), per course.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_gradehistory(scope $scope): dataset {
        global $DB;
        $ds = self::ds('gradehistory', ['course' => 'col_course', 'changes' => 'col_gradechanges', 'students' => 'col_students',
            'editors' => 'col_editors', 'last' => 'col_lastchange'], ['changes', 'students', 'editors']);
        [$cw, $cp] = self::coursewhere($scope, 'gi.courseid');
        $rows = $DB->get_records_sql("
            SELECT gi.courseid, COUNT(1) AS changes, COUNT(DISTINCT h.userid) AS students,
                   COUNT(DISTINCT h.loggeduser) AS editors, MAX(h.timemodified) AS last
              FROM {grade_grades_history} h
              JOIN {grade_items} gi ON gi.id = h.itemid
             WHERE h.action = 2 AND h.loggeduser > 0 AND h.loggeduser <> h.userid
                   AND h.timemodified >= :from AND h.timemodified < :to AND $cw
          GROUP BY gi.courseid
          ORDER BY COUNT(1) DESC", ['from' => $scope->from, 'to' => $scope->to] + $cp);
        foreach ($rows as $r) {
            $ds->add(['course' => self::coursename((int)$r->courseid), 'changes' => (int)$r->changes,
                'students' => (int)$r->students,
                'editors' => (int)$r->editors, 'last' => fmt::datetime((int)$r->last)]);
        }
        return $ds;
    }

    /**
     * Views recorded for activity completion (course_modules_viewed, Moodle 4.4+), per activity.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_viewed(scope $scope): dataset {
        global $DB;
        $ds = self::ds(
            'viewed',
            ['course' => 'col_course', 'activity' => 'col_activity', 'students' => 'col_viewedby'],
            ['students']
        );
        if (!$DB->get_manager()->table_exists('course_modules_viewed')) {
            return $ds;
        }
        [$cw, $cp] = self::coursewhere($scope, 'cm.course');
        $students = self::students($scope);
        $rs = $DB->get_recordset_sql("
            SELECT v.id, v.coursemoduleid, v.userid, cm.course
              FROM {course_modules_viewed} v
              JOIN {course_modules} cm ON cm.id = v.coursemoduleid
             WHERE $cw", $cp);
        $agg = [];
        foreach ($rs as $r) {
            if (isset($students[(int)$r->userid])) {
                $agg[$r->coursemoduleid]['course'] = (int)$r->course;
                $agg[$r->coursemoduleid]['users'][$r->userid] = true;
            }
        }
        $rs->close();
        uasort($agg, fn($a, $b) => count($b['users']) <=> count($a['users']));
        // One structure per course: Moodle keeps only 10 in memory, and the rows mix courses.
        $modinfos = [];
        foreach ($agg as $cmid => $a) {
            $modinfos[$a['course']] = $modinfos[$a['course']] ?? get_fast_modinfo($a['course'], -1);
            $cm = $modinfos[$a['course']]->get_cm($cmid);
            $ds->add(['course' => self::coursename($a['course']), 'activity' => fmt::plain($cm->get_formatted_name()),
                'students' => count($a['users'])]);
        }
        return $ds;
    }

    /**
     * Files per course (course, its activities and blocks), in the course filters.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_storage(scope $scope): dataset {
        global $DB;
        $ds = self::ds('storage', ['course' => 'col_course', 'files' => 'col_files', 'size' => 'col_size',
            'largest' => 'col_largestcomponent'], ['files', 'size']);
        $filter = $scope->course_ids();
        // Course context id => course id, then every file context is mapped to the course in its path.
        $coursectx = $DB->get_records_menu('context', ['contextlevel' => CONTEXT_COURSE], '', 'id, instanceid');
        $rows = $DB->get_recordset_sql("
            SELECT f.contextid, ctx.path, f.component, COUNT(1) AS n, SUM(f.filesize) AS bytes
              FROM {files} f
              JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel IN (:lc, :lm, :lb)
             WHERE f.filename <> '.'
          GROUP BY f.contextid, ctx.path, f.component", ['lc' => CONTEXT_COURSE, 'lm' => CONTEXT_MODULE,
            'lb' => CONTEXT_BLOCK]);
        $agg = [];
        foreach ($rows as $r) {
            $courseid = 0;
            foreach (explode('/', trim((string)$r->path, '/')) as $ctxid) {
                if (isset($coursectx[$ctxid])) {
                    $courseid = (int)$coursectx[$ctxid];
                }
            }
            if (!$courseid || $courseid === SITEID || ($filter !== null && !in_array($courseid, $filter))) {
                continue;
            }
            $a = &$agg[$courseid];
            $a = $a ?? ['n' => 0, 'bytes' => 0.0, 'components' => []];
            $a['n'] += (int)$r->n;
            $a['bytes'] += (float)$r->bytes;
            $a['components'][$r->component] = ($a['components'][$r->component] ?? 0) + (float)$r->bytes;
            unset($a);
        }
        $rows->close();
        uasort($agg, fn($a, $b) => $b['bytes'] <=> $a['bytes']);
        foreach ($agg as $cid => $a) {
            arsort($a['components']);
            $largest = array_key_first($a['components']);
            $ds->add(['course' => self::coursename((int)$cid), 'files' => $a['n'], 'size' => fmt::size($a['bytes']),
                'largest' => names::component($largest) . ' — ' . fmt::size($a['components'][$largest])]);
        }
        return $ds;
    }

    /**
     * Groups of the courses with their students.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_groups(scope $scope): dataset {
        global $DB;
        $ds = self::ds(
            'groups',
            ['course' => 'col_course', 'group' => 'col_moodlegroup', 'students' => 'col_students',
            'certified' => 'col_certified', 'notstarted' => 'situation_notstarted', 'inactive' => 'situation_inactive'],
            ['students', 'certified', 'notstarted', 'inactive']
        );
        [$cw, $cp] = self::coursewhere($scope, 'g.courseid');
        $rs = $DB->get_recordset_sql("
            SELECT gm.id, g.id AS groupid, g.courseid, g.name, e.situation, e.timecertified
              FROM {groups} g
              JOIN {groups_members} gm ON gm.groupid = g.id
              JOIN {local_ceduanalytics_enrolstate} e ON e.userid = gm.userid AND e.courseid = g.courseid AND e.isstudent = 1
             WHERE $cw", $cp);
        $agg = [];
        foreach ($rs as $r) {
            $a = &$agg[$r->groupid];
            $a = $a ?? ['course' => (int)$r->courseid, 'name' => $r->name, 'n' => 0, 'cert' => 0, 'never' => 0, 'inactive' => 0];
            $a['n']++;
            $a['cert'] += $r->timecertified ? 1 : 0;
            $a['never'] += $r->situation === 'notstarted' ? 1 : 0;
            $a['inactive'] += $r->situation === 'inactive' ? 1 : 0;
            unset($a);
        }
        $rs->close();
        foreach ($agg as $a) {
            $ds->add(['course' => self::coursename($a['course']), 'group' => fmt::text($a['name']), 'students' => $a['n'],
                'certified' => $a['cert'], 'notstarted' => $a['never'], 'inactive' => $a['inactive']]);
        }
        return $ds;
    }

    /**
     * Cohorts and how their members are doing.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_cohorts(scope $scope): dataset {
        global $DB;
        $ds = self::ds('cohorts', ['cohort' => 'col_cohort', 'members' => 'col_members', 'students' => 'col_studentsinscope',
            'certified' => 'col_certstudents'], ['members', 'students', 'certified']);
        $students = [];
        foreach ($scope->enrolments('e.id, e.userid, e.timecertified') as $r) {
            $students[$r->userid] = ($students[$r->userid] ?? false) || $r->timecertified;
        }
        $rs = $DB->get_recordset_sql("SELECT cm.id, c.id AS cohortid, c.name, cm.userid
            FROM {cohort} c LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id ORDER BY c.name");
        $agg = [];
        foreach ($rs as $r) {
            $a = &$agg[$r->cohortid];
            $a = $a ?? ['name' => $r->name, 'members' => 0, 'students' => 0, 'cert' => 0];
            if ($r->userid) {
                $a['members']++;
                if (isset($students[$r->userid])) {
                    $a['students']++;
                    $a['cert'] += $students[$r->userid] ? 1 : 0;
                }
            }
            unset($a);
        }
        $rs->close();
        foreach ($agg as $a) {
            $ds->add(['cohort' => fmt::text($a['name']), 'members' => $a['members'], 'students' => $a['students'],
                'certified' => $a['cert']]);
        }
        return $ds;
    }

    /**
     * Mobile app: devices by platform and people with an app token.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_devices(scope $scope): dataset {
        global $DB;
        $ds = self::ds('devices', ['platform' => 'col_platform', 'people' => 'col_people', 'students' => 'col_studentsinscope',
            'devices' => 'col_devices', 'last' => 'col_lastuse'], ['people', 'students', 'devices']);
        $students = self::students($scope);
        $agg = [];
        foreach ($DB->get_records('user_devices', null, '', 'id, userid, platform, timemodified') as $d) {
            $p = names::platform($d->platform);
            $a = &$agg[$p];
            $a = $a ?? ['people' => [], 'devices' => 0, 'last' => 0];
            $a['people'][$d->userid] = true;
            $a['devices']++;
            $a['last'] = max($a['last'], (int)$d->timemodified);
            unset($a);
        }
        $tokens = $DB->get_records_sql(
            "
            SELECT t.id, t.userid, t.lastaccess
              FROM {external_tokens} t
              JOIN {external_services} s ON s.id = t.externalserviceid AND s.shortname = :app",
            ['app' => MOODLE_OFFICIAL_MOBILE_SERVICE]
        );
        foreach ($tokens as $t) {
            $a = &$agg[self::s('x_devices_token')];
            $a = $a ?? ['people' => [], 'devices' => 0, 'last' => 0];
            $a['people'][$t->userid] = true;
            $a['devices']++;
            $a['last'] = max($a['last'], (int)$t->lastaccess);
            unset($a);
        }
        foreach ($agg as $platform => $a) {
            $ds->add(['platform' => (string)$platform, 'people' => count($a['people']),
                'students' => count(array_intersect_key($a['people'], $students)), 'devices' => $a['devices'],
                'last' => $a['last'] ? fmt::date($a['last']) : '—']);
        }
        return $ds;
    }

    /**
     * Sessions: logged-in people online now, anonymous sessions and sessions by age.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_sessions(scope $scope): dataset {
        global $DB;
        $ds = self::ds(
            'sessions',
            ['group' => 'col_sessiongroup', 'sessions' => 'col_sessions', 'people' => 'col_people'],
            ['sessions', 'people']
        );
        $now = time();
        $groups = [
            'online' => ['userid > 0 AND timemodified >= :t1', ['t1' => $now - 5 * MINSECS]],
            'today' => ['userid > 0 AND timemodified >= :t1', ['t1' => $now - DAYSECS]],
            'users' => ['userid > 0', []],
            'anonymous' => ['userid = 0', []],
        ];
        foreach ($groups as $key => [$where, $params]) {
            $r = $DB->get_record_sql(
                "SELECT COUNT(1) AS n, COUNT(DISTINCT userid) AS people FROM {sessions} WHERE $where",
                $params
            );
            $ds->add(['group' => self::s('x_sessions_' . $key), 'sessions' => (int)$r->n,
                'people' => $key === 'anonymous' ? '—' : (int)$r->people]);
        }
        return $ds;
    }

    /**
     * Configuration changes in the period (config_log), with secret values masked.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_configlog(scope $scope): dataset {
        global $DB;
        $ds = self::ds('configlog', ['date' => 'col_date', 'user' => 'col_user', 'setting' => 'col_setting',
            'old' => 'col_oldvalue', 'new' => 'col_newvalue']);
        $rows = $DB->get_records_select(
            'config_log',
            'timemodified >= :from AND timemodified < :to',
            ['from' => $scope->from, 'to' => $scope->to],
            'timemodified DESC',
            'id, userid, timemodified, plugin, name, value, oldvalue',
            0,
            300
        );
        people::preload(array_column($rows, 'userid'));
        $users = [];
        foreach ($rows as $r) {
            if (!isset($users[$r->userid])) {
                $u = $r->userid ? people::get($r->userid) : null;
                $users[$r->userid] = $u ? fullname($u) : self::s('x_configlog_system');
            }
            // Moodle already logs password fields as "********"; other secrets are hidden by name.
            $secret = preg_match('/(password|passwd|secret|token|apikey|api_key|privatekey|salt|(^|_)key$)/i', $r->name);
            $short = fn($v) => $secret ? '••••••' : \core_text::substr(trim(strip_tags((string)$v)), 0, 80);
            $ds->add(['date' => fmt::datetime((int)$r->timemodified), 'user' => $users[$r->userid],
                'setting' => adminlabels::label($r->plugin, $r->name), 'old' => $short($r->oldvalue),
                'new' => $short($r->value)]);
        }
        return $ds;
    }

    /**
     * Privacy (LGPD) requests of the Moodle data privacy tool.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_privacy(scope $scope): dataset {
        global $DB;
        $ds = self::ds('privacy', ['created' => 'col_date', 'type' => 'col_requesttype', 'user' => 'col_user',
            'status' => 'col_status', 'updated' => 'col_lastchange']);
        if (!$DB->get_manager()->table_exists('tool_dataprivacy_request')) {
            return $ds;
        }
        $types = [1 => 'x_privacy_export', 2 => 'x_privacy_delete', 3 => 'x_privacy_others'];
        $statuses = [0 => 'pending', 1 => 'awaitingapproval', 2 => 'approved', 3 => 'processing', 4 => 'complete',
            5 => 'cancelled', 6 => 'rejected', 7 => 'expired', 8 => 'downloadready', 9 => 'deleted'];
        $requests = $DB->get_records(
            'tool_dataprivacy_request',
            null,
            'timecreated DESC',
            'id, type, userid, status, timecreated, timemodified'
        );
        people::preload(array_column($requests, 'userid'));
        foreach ($requests as $r) {
            $u = people::get($r->userid);
            $ds->add(['created' => fmt::date((int)$r->timecreated), 'type' => self::s($types[$r->type] ?? 'x_privacy_others'),
                'user' => $u ? fullname($u) : get_string('deleteduser', 'local_ceduanalytics'),
                'status' => self::s('x_privacy_status_' . ($statuses[$r->status] ?? 'pending')),
                'updated' => fmt::date((int)$r->timemodified)]);
        }
        return $ds;
    }

    /**
     * Forum discussions started by students with no reply from the team.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_forums(scope $scope): dataset {
        global $DB;
        $ds = self::ds('forums', ['course' => 'col_course', 'forum' => 'col_activity', 'discussion' => 'col_discussion',
            'student' => 'col_student', 'posts' => 'col_posts', 'started' => 'col_date'], ['posts']);
        [$cw, $cp] = self::coursewhere($scope, 'd.course');
        $staff = contacts::staff_ids();
        $rs = $DB->get_recordset_sql("
            SELECT p.id, p.discussion, p.userid, d.course, d.name, d.userid AS starter, d.timemodified, f.name AS forumname, f.type
              FROM {forum_discussions} d
              JOIN {forum} f ON f.id = d.forum
              JOIN {forum_posts} p ON p.discussion = d.id AND p.deleted = 0
             WHERE f.type <> 'news' AND $cw", $cp);
        $agg = [];
        foreach ($rs as $r) {
            $a = &$agg[$r->discussion];
            $a = $a ?? ['course' => (int)$r->course, 'forum' => $r->forumname, 'name' => $r->name, 'starter' => (int)$r->starter,
                'posts' => 0, 'staff' => false, 'time' => (int)$r->timemodified];
            $a['posts']++;
            $a['staff'] = $a['staff'] || isset($staff[(int)$r->userid]);
            unset($a);
        }
        $rs->close();
        people::preload(array_column($agg, 'starter'));
        foreach ($agg as $a) {
            if ($a['staff'] || isset($staff[$a['starter']])) {
                continue;
            }
            $u = people::get($a['starter']);
            $ds->add(['course' => self::coursename($a['course']), 'forum' => fmt::text($a['forum']),
                'discussion' => fmt::text($a['name']), 'student' => $u ? fullname($u) : '', 'email' => $u ? $u->email : '',
                'posts' => $a['posts'], 'started' => fmt::date($a['time']),
                '_html' => ['student' => $u ? indicators::student_link((int)$a['starter'], fullname($u)) : '']]);
        }
        $ds->columns += ['email' => self::s('col_email')];
        return $ds;
    }

    /**
     * Notifications Moodle sent to students in the period, by type.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_notifications(scope $scope): dataset {
        global $DB;
        $ds = self::ds('notifications', ['type' => 'col_notificationtype', 'sent' => 'col_sent', 'people' => 'col_people',
            'read' => 'col_readpct'], ['sent', 'people', 'read']);
        $students = self::students($scope);
        $rs = $DB->get_recordset_select(
            'notifications',
            'timecreated >= :from AND timecreated < :to',
            ['from' => $scope->from, 'to' => $scope->to],
            '',
            'id, useridto, component, eventtype, timeread'
        );
        $agg = [];
        foreach ($rs as $r) {
            if (!isset($students[(int)$r->useridto])) {
                continue;
            }
            $k = names::notification($r->component, $r->eventtype);
            $a = &$agg[$k];
            $a = $a ?? ['sent' => 0, 'people' => [], 'read' => 0];
            $a['sent']++;
            $a['people'][$r->useridto] = true;
            $a['read'] += $r->timeread ? 1 : 0;
            unset($a);
        }
        $rs->close();
        uasort($agg, fn($a, $b) => $b['sent'] <=> $a['sent']);
        foreach ($agg as $k => $a) {
            $ds->add(['type' => $k, 'sent' => $a['sent'], 'people' => count($a['people']), 'read' => fmt::pct(
                $a['read'],
                $a['sent']
            )]);
        }
        return $ds;
    }

    /**
     * Read status of the unanswered student messages (whether the team member has read them).
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_readstatus(scope $scope): dataset {
        global $DB;
        $ds = self::ds('readstatus', ['student' => 'col_student', 'staff' => 'col_recipient', 'waiting' => 'col_waitingmsgs',
            'read' => 'col_readbyteam', 'last' => 'col_lastmessage'], ['waiting']);
        $unanswered = contacts::conversations()['unanswered'];
        people::preload(array_merge(array_column($unanswered, 'studentid'), array_column($unanswered, 'staffid')));
        // Last message of each student in each conversation, and which of them the team member has read (2 queries).
        $lastids = [];
        $readids = [];
        if ($unanswered) {
            [$insql, $params] = $DB->get_in_or_equal(array_unique(array_column($unanswered, 'convid')));
            $rs = $DB->get_recordset_sql("SELECT conversationid, useridfrom, MAX(id) AS lastid
                  FROM {messages} WHERE conversationid $insql GROUP BY conversationid, useridfrom", $params);
            foreach ($rs as $r) {
                $lastids[$r->conversationid . '|' . $r->useridfrom] = (int)$r->lastid;
            }
            $rs->close();
            if ($lastids) {
                [$insql, $params] = $DB->get_in_or_equal(array_values($lastids));
                $rs = $DB->get_recordset_select(
                    'message_user_actions',
                    "messageid $insql AND action = 1",
                    $params,
                    '',
                    'id, messageid, userid'
                );
                foreach ($rs as $r) {
                    $readids[$r->messageid . '|' . $r->userid] = true;
                }
                $rs->close();
            }
        }
        foreach ($unanswered as $u) {
            $lastid = $lastids[$u['convid'] . '|' . $u['studentid']] ?? 0;
            $read = $lastid && isset($readids[$lastid . '|' . $u['staffid']]);
            $student = people::get($u['studentid']);
            $staff = people::get($u['staffid']);
            $ds->add(['student' => $student ? fullname($student) : '', 'email' => $student ? $student->email : '',
                'staff' => $staff ? fullname($staff) : '', 'waiting' => $u['count'],
                'read' => $read ? self::s('yes_') : self::s('no_'), 'last' => fmt::datetime($u['last']),
                '_html' => [
                    'student' => $student ? indicators::student_link((int)$u['studentid'], fullname($student)) : '',
                    'staff' => $staff ? s(fullname($staff)) . people::dual_mark((int)$u['staffid'], 'teacher') : '',
                ],
                '_class' => $read ? '' : 'cea-row-high']);
        }
        $ds->columns += ['email' => self::s('col_email')];
        return $ds;
    }

    /**
     * Moodle analytics predictions (students at risk models) compared with the inactivity queue of the dashboard.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_analytics(scope $scope): dataset {
        global $DB;
        $ds = self::ds('analytics', ['model' => 'col_model', 'predictions' => 'col_predictions', 'people' => 'col_people',
            'inqueue' => 'col_inqueue', 'last' => 'col_lastchange'], ['predictions', 'people', 'inqueue']);
        if (!$DB->get_manager()->table_exists('analytics_predictions')) {
            return $ds;
        }
        $queue = [];
        $now = time();
        $inactivedays = max(1, (int)get_config('local_ceduanalytics', 'inactivedays') ?: 14);
        foreach (
            $DB->get_recordset_sql('SELECT ' . support::fields() . ' FROM {local_ceduanalytics_enrolstate} e
                JOIN {course} c ON c.id = e.courseid WHERE e.isstudent = 1 AND e.enrolactive = 1') as $e
        ) {
            if (support::classify($e, $now, $inactivedays)) {
                $queue[(int)$e->userid] = true;
            }
        }
        $rows = $DB->get_records_sql("
            SELECT p.id, m.target, m.name, p.sampleid, p.timecreated
              FROM {analytics_predictions} p
              JOIN {analytics_models} m ON m.id = p.modelid");
        $agg = [];
        foreach ($rows as $r) {
            $label = $r->name ?: (class_exists($r->target) ? $r->target::get_name()->out() : $r->target);
            $a = &$agg[(string)$label];
            $a = $a ?? ['n' => 0, 'samples' => [], 'last' => 0, 'target' => $r->target];
            $a['n']++;
            $a['samples'][$r->sampleid] = true;
            $a['last'] = max($a['last'], (int)$r->timecreated);
            unset($a);
        }
        foreach ($agg as $label => $a) {
            // Student targets use user enrolments as samples.
            $users = [];
            if (strpos($a['target'], 'no_teaching') === false) {
                $ids = array_keys($a['samples']);
                foreach (array_chunk($ids, 500) as $chunk) {
                    [$insql, $params] = $DB->get_in_or_equal($chunk);
                    foreach ($DB->get_fieldset_select('user_enrolments', 'userid', "id $insql", $params) as $uid) {
                        $users[(int)$uid] = true;
                    }
                }
            }
            $ds->add(['model' => (string)$label, 'predictions' => $a['n'], 'people' => $users ? count($users) : '—',
                'inqueue' => $users ? count(array_intersect_key($users, $queue)) : '—', 'last' => fmt::date($a['last'])]);
        }
        return $ds;
    }

    /**
     * Moodle surveys (mod_survey) with their responses.
     *
     * @param scope $scope
     * @return dataset
     */
    protected static function build_surveys(scope $scope): dataset {
        global $DB;
        $ds = self::ds('surveys', ['course' => 'col_course', 'activity' => 'col_activity', 'people' => 'col_respondents',
            'answers' => 'col_answers'], ['people', 'answers']);
        if (!$DB->get_manager()->table_exists('survey')) {
            return $ds;
        }
        [$cw, $cp] = self::coursewhere($scope, 's.course');
        $rows = $DB->get_records_sql("
            SELECT s.id, s.course, s.name, COUNT(a.id) AS answers, COUNT(DISTINCT a.userid) AS people
              FROM {survey} s
         LEFT JOIN {survey_answers} a ON a.survey = s.id
             WHERE s.course > 0 AND $cw
          GROUP BY s.id, s.course, s.name", $cp);
        foreach ($rows as $r) {
            $ds->add(['course' => self::coursename((int)$r->course), 'activity' => fmt::text($r->name),
                'people' => (int)$r->people, 'answers' => (int)$r->answers]);
        }
        return $ds;
    }
}
