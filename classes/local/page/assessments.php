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

namespace local_ceduanalytics\local\page;

use local_ceduanalytics\local\charts;
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * Assessments and questions: are students learning?
 *
 * Quiz attempts of students finished in the period; questions are grouped by name and type so that the
 * same question copied into several editions is analysed together.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessments extends base {
    /** @var int Minimum attempts for a question to be ranked. */
    const MINATTEMPTS = 10;

    /**
     * "Questions with most errors" sits right above Moodle's own question statistics (an extra block).
     *
     * @param string $id Extra block about to be rendered
     * @return string HTML
     */
    protected function before_extra(string $id): string {
        if ($id !== 'quizstats') {
            return '';
        }
        return $this->render('assessments_questions', [
            'questions' => $this->get_dataset('questions')->export_for_template(),
            'minattempts' => self::MINATTEMPTS,
        ]);
    }
    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'questions' => get_string('as_questions', 'local_ceduanalytics'),
            'quizzes' => get_string('as_quizzes', 'local_ceduanalytics'),
            'assigns' => get_string('as_assigns', 'local_ceduanalytics'),
        ];
    }

    /**
     * Common SQL conditions for quiz attempts of students in scope (aliases quiza, q, e, ua).
     *
     * @param scope $scope
     * @return array [where, params]
     */
    protected function attempt_where(scope $scope): array {
        global $DB;
        $where = ["quiza.preview = 0", "quiza.state = 'finished'", 'quiza.timefinish >= :afrom', 'quiza.timefinish < :ato'];
        $params = ['afrom' => $scope->from, 'ato' => $scope->to];
        $courses = $scope->course_ids();
        if ($courses !== null) {
            if (!$courses) {
                return ['1 = 0', []];
            }
            [$in, $inparams] = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED, 'ac');
            $where[] = "q.course $in";
            $params += $inparams;
        }
        [$uw, $up] = $scope->user_where();
        if ($uw !== '') {
            $where[] = $uw;
            $params += $up;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * FROM clause for attempts.
     *
     * @return string
     */
    protected static function attempt_from(): string {
        return '{quiz_attempts} quiza
                JOIN {quiz} q ON q.id = quiza.quiz
                JOIN {local_ceduanalytics_enrolstate} e ON e.userid = quiza.userid AND e.courseid = q.course AND e.isstudent = 1
           LEFT JOIN {local_ceduanalytics_userattr} ua ON ua.userid = quiza.userid';
    }

    /**
     * Per-quiz figures.
     *
     * @param scope $scope
     * @return array quizid => figures
     */
    protected function quizzes(scope $scope): array {
        global $DB;
        return $scope->cached('quizzes', function () use ($scope, $DB) {
            [$where, $params] = $this->attempt_where($scope);
            $sql = "SELECT quiza.id, quiza.quiz, quiza.userid, quiza.sumgrades, q.sumgrades AS qsum, q.name, q.course, c.fullname
                      FROM " . self::attempt_from() . "
                      JOIN {course} c ON c.id = q.course
                     WHERE $where";
            $out = [];
            $rs = $DB->get_recordset_sql($sql, $params);
            foreach ($rs as $r) {
                $qid = (int)$r->quiz;
                if (!isset($out[$qid])) {
                    $out[$qid] = ['name' => fmt::text($r->name), 'course' => fmt::text($r->fullname),
                        'courseid' => (int)$r->course, 'attempts' => 0, 'best' => []];
                }
                $out[$qid]['attempts']++;
                $pct = (float)$r->qsum > 0 ? (float)$r->sumgrades / (float)$r->qsum * 100 : null;
                if ($pct !== null) {
                    $out[$qid]['best'][$r->userid] = max($out[$qid]['best'][$r->userid] ?? 0, $pct);
                }
                $out[$qid]['users'][$r->userid] = true;
            }
            $rs->close();
            return $out;
        });
    }

    /**
     * Questions grouped by name and type, with the average final fraction of the last step.
     *
     * @param scope $scope
     * @return array
     */
    protected function questions(scope $scope): array {
        global $DB;
        return $scope->cached('questions', function () use ($scope, $DB) {
            [$where, $params] = $this->attempt_where($scope);
            $sql = "SELECT " . $DB->sql_concat('qa.questionid', "'|'", 'q.course') . " AS k, qa.questionid, q.course,
                           COUNT(1) AS n, SUM(COALESCE(st.fraction, 0)) AS fsum,
                           SUM(CASE WHEN st.fraction >= 0.999 THEN 1 ELSE 0 END) AS correct,
                           COUNT(DISTINCT q.id) AS quizzes
                      FROM " . self::attempt_from() . "
                      JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                      JOIN {question_attempt_steps} st ON st.questionattemptid = qa.id
                      JOIN (SELECT questionattemptid, MAX(sequencenumber) AS ms
                              FROM {question_attempt_steps} GROUP BY questionattemptid) m
                           ON m.questionattemptid = st.questionattemptid AND m.ms = st.sequencenumber
                     WHERE $where
                  GROUP BY qa.questionid, q.course";
            $byid = $DB->get_records_sql($sql, $params);
            if (!$byid) {
                return [];
            }
            $questions = $DB->get_records_list(
                'question',
                'id',
                array_unique(array_column($byid, 'questionid')),
                '',
                'id, name, qtype, questiontext'
            );
            $courses = $DB->get_records_list('course', 'id', array_unique(array_column($byid, 'course')), '', 'id, fullname');
            $groups = [];
            foreach ($byid as $r) {
                $qid = (int)$r->questionid;
                $q = $questions[$qid] ?? null;
                if (!$q) {
                    continue;
                }
                $key = \core_text::strtolower(trim($q->name)) . '|' . $q->qtype;
                if (!isset($groups[$key])) {
                    $groups[$key] = ['name' => fmt::text($q->name), 'qtype' => $q->qtype,
                        'text' => shorten_text(trim(html_to_text($q->questiontext, 0, false)), 160),
                        'n' => 0, 'fsum' => 0.0, 'correct' => 0, 'versions' => 0, 'qids' => [], 'courses' => []];
                }
                $groups[$key]['n'] += (int)$r->n;
                $groups[$key]['fsum'] += (float)$r->fsum;
                $groups[$key]['correct'] += (int)$r->correct;
                $groups[$key]['qids'][$qid] = true;
                $groups[$key]['versions'] = count($groups[$key]['qids']);
                if (isset($courses[$r->course])) {
                    $groups[$key]['courses'][(int)$r->course] = fmt::text($courses[$r->course]->fullname);
                }
            }
            foreach ($groups as &$g) {
                asort($g['courses']);
                unset($g['qids']);
            }
            unset($g);
            return array_values($groups);
        });
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $scope = new scope($this->filters);
        $threshold = (float)(get_config('local_ceduanalytics', 'passthreshold') ?: 70);
        $quizzes = $this->quizzes($scope);
        $attempts = 0;
        $pairs = 0;
        $bestsum = 0.0;
        $bestn = 0;
        $above = 0;
        foreach ($quizzes as $q) {
            $attempts += $q['attempts'];
            $pairs += count($q['users'] ?? []);
            foreach ($q['best'] as $b) {
                $bestsum += $b;
                $bestn++;
                $above += $b >= $threshold ? 1 : 0;
            }
        }
        // Course grade distribution (enrolments in scope with a grade).
        $bins = array_fill(0, 10, 0);
        $graded = 0;
        $gradeabove = 0;
        foreach ($scope->enrolments('e.id, e.gradepct', 'e.gradepct IS NOT NULL') as $r) {
            $bins[min(9, (int)floor((float)$r->gradepct / 10))]++;
            $graded++;
            $gradeabove += (float)$r->gradepct >= $threshold ? 1 : 0;
        }
        $labels = [];
        for ($i = 0; $i < 10; $i++) {
            $labels[] = ($i * 10) . '–' . ($i === 9 ? 100 : $i * 10 + 9) . '%';
        }

        $kpis = [
            $this->kpi('as_quizzes', fmt::int(count($quizzes))),
            $this->kpi('as_attempts', fmt::int($attempts)),
            $this->kpi('as_perstudent', $pairs ? fmt::dec($attempts / $pairs) : '—', null, null, '', fmt::lowconf($pairs)),
            $this->kpi('as_best', $bestn ? fmt::percent($bestsum / $bestn) : '—', null, null, '', fmt::lowconf($bestn)),
            $this->kpi(
                'as_above',
                fmt::pct($above, $bestn),
                null,
                null,
                '',
                fmt::lowconf($bestn),
                $str('as_threshold', fmt::dec($threshold, 0))
            ),
            $this->kpi(
                'as_coursegrade',
                fmt::pct($gradeabove, $graded),
                null,
                null,
                '',
                fmt::lowconf($graded),
                $str('kpi_grades_count', fmt::int($graded))
            ),
        ];
        $chart = $graded ? charts::bar($labels, [$str('series_enrolments') => $bins], false, false, [charts::BLUE]) : '';

        return $this->render('assessments', [
            'kpis' => $kpis,
            'thresholdnote' => $str('as_thresholdnote', fmt::dec($threshold, 0)),
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state('fa-list-check', $str('as_empty_title'), $str('as_empty_text')),
            'quizzes' => $this->get_dataset('quizzes')->export_for_template(),
            'assigns' => $this->get_dataset('assigns')->export_for_template(),
            'minattempts' => self::MINATTEMPTS,
        ]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        global $DB;
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $scope = new scope($this->filters);
        $threshold = (float)(get_config('local_ceduanalytics', 'passthreshold') ?: 70);
        if ($id === 'questions') {
            $ds = new dataset('questions', $str('as_questions'), [
                'question' => $str('col_question'), 'text' => $str('col_questiontext'), 'courses' => $str('col_questioncourses'),
                'qtype' => $str('col_qtype'), 'attempts' => $str('col_answers'), 'correct' => $str('col_correct'),
                'score' => $str('col_avgscore'), 'versions' => $str('col_versions'),
            ], ['attempts', 'correct', 'score', 'versions']);
            $list = array_filter($this->questions($scope), fn($q) => $q['n'] >= self::MINATTEMPTS);
            usort($list, fn($a, $b) => ($a['fsum'] / $a['n']) <=> ($b['fsum'] / $b['n']));
            foreach (array_slice($list, 0, 40) as $q) {
                $ds->add([
                    'question' => $q['name'], 'text' => $q['text'], 'courses' => implode('; ', $q['courses']),
                    'qtype' => get_string('pluginname', 'qtype_' . $q['qtype']), 'attempts' => $q['n'],
                    'correct' => fmt::pct($q['correct'], $q['n']), 'score' => fmt::percent($q['fsum'] / $q['n'] * 100),
                    'versions' => $q['versions'],
                    '_html' => ['courses' => \html_writer::alist(array_map(
                        fn($cid, $name) => coursestats::link($cid, $name, $this->filters->params()),
                        array_keys($q['courses']),
                        $q['courses']
                    ), ['class' => 'cea-reasons'])],
                ]);
            }
            $ds->emptytext = $str('as_empty_text');
            return $ds;
        }
        if ($id === 'quizzes') {
            $ds = new dataset('quizzes', $str('as_quizzes'), [
                'course' => $str('col_course'), 'quiz' => $str('col_quiz'), 'students' => $str('col_students'),
                'attempts' => $str('col_attempts'), 'perstudent' => $str('col_perstudent'), 'best' => $str('col_bestavg'),
                'above' => $str('col_above'),
            ], ['students', 'attempts', 'perstudent', 'best', 'above']);
            foreach ($this->quizzes($scope) as $q) {
                $users = count($q['users'] ?? []);
                $best = $q['best'];
                $above = count(array_filter($best, fn($b) => $b >= $threshold));
                $ds->add([
                    'course' => $q['course'], 'quiz' => $q['name'], 'students' => $users, 'attempts' => $q['attempts'],
                    'perstudent' => $users ? fmt::dec($q['attempts'] / $users) : '—',
                    'best' => $best ? fmt::percent(array_sum($best) / count($best)) : '—',
                    'above' => fmt::pct($above, count($best)),
                    '_html' => ['course' => coursestats::link($q['courseid'], $q['course'], $this->filters->params())],
                ]);
            }
            usort($ds->rows, fn($a, $b) => strcmp($a['course'], $b['course']) ?: strnatcmp($a['quiz'], $b['quiz']));
            $ds->emptytext = $str('as_empty_text');
            return $ds;
        }
        if ($id === 'assigns') {
            $ds = new dataset('assigns', $str('as_assigns'), [
                'course' => $str('col_course'), 'assign' => $str('col_assign'), 'due' => $str('col_due'),
                'submitted' => $str('col_submitted'), 'graded' => $str('col_graded'), 'pending' => $str('col_pending'),
            ], ['submitted', 'graded', 'pending']);
            if (!$DB->get_manager()->table_exists('assign')) {
                return $ds;
            }
            $where = '';
            $params = [];
            $courses = $scope->course_ids();
            if ($courses !== null) {
                if (!$courses) {
                    return $ds;
                }
                [$in, $params] = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED, 'asc');
                $where = "WHERE a.course $in";
            }
            $rows = $DB->get_records_sql("
                SELECT a.id, a.name, a.duedate, a.course, c.fullname, cm.id AS cmid,
                       (SELECT COUNT(1) FROM {assign_submission} s WHERE s.assignment = a.id AND s.latest = 1
                           AND s.status = 'submitted') AS submitted,
                       (SELECT COUNT(1) FROM {assign_submission} s
                          LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                               AND g.attemptnumber = s.attemptnumber
                         WHERE s.assignment = a.id AND s.latest = 1 AND s.status = 'submitted'
                           AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0 OR g.timemodified < s.timemodified)) AS pending
                  FROM {assign} a
                  JOIN {course} c ON c.id = a.course
                  JOIN {modules} m ON m.name = 'assign'
                  JOIN {course_modules} cm ON cm.instance = a.id AND cm.module = m.id
                  $where
              ORDER BY c.fullname, a.name", $params);
            foreach ($rows as $a) {
                $name = fmt::text($a->name);
                $ds->add([
                    'course' => fmt::text($a->fullname), 'assign' => $name,
                    'due' => $a->duedate ? fmt::date((int)$a->duedate) : $str('nodue'),
                    'submitted' => (int)$a->submitted, 'graded' => (int)$a->submitted - (int)$a->pending,
                    'pending' => (int)$a->pending,
                    '_html' => [
                        'assign' => \html_writer::link(new \moodle_url('/mod/assign/view.php', ['id' => $a->cmid,
                            'action' => 'grading']), s($name)),
                        'pending' => $a->pending ? \html_writer::span((string)$a->pending, 'cea-stopped') : '0',
                    ],
                ]);
            }
            $ds->emptytext = $str('as_noassigns');
            return $ds;
        }
        return null;
    }
}
