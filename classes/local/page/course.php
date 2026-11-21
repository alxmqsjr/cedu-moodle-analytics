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
use local_ceduanalytics\local\courses as catalogue;
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\filters;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;
use local_ceduanalytics\local\scope;
use local_ceduanalytics\local\snapshot;

/**
 * Course panel (detail page): how a course is doing, where students stop, who teaches it.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course extends base {
    /** @var \stdClass catalogue record */
    protected $course;
    /** @var filters filters restricted to this course */
    protected $cfilters;

    /**
     * Constructor.
     *
     * @param array $def
     * @param filters $filters
     */
    public function __construct(array $def, filters $filters) {
        parent::__construct($def, $filters);
        $id = required_param('id', PARAM_INT);
        $all = catalogue::get_all();
        if (!isset($all[$id])) {
            throw new \moodle_exception('invalidcourseid');
        }
        $this->course = $all[$id];
        $this->cfilters = clone $filters;
        $this->cfilters->course = $id;
        $this->cfilters->category = 0;
        $this->cfilters->line = 0;
    }

    /**
     * State.
     *
     * @return array
     */
    public function state_params(): array {
        return ['id' => $this->course->id];
    }

    /**
     * Title.
     *
     * @return string
     */
    public function title(): string {
        return fmt::text($this->course->fullname);
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return ['activities' => get_string('course_activities', 'local_ceduanalytics'),
            'teachers' => get_string('course_teachers', 'local_ceduanalytics')];
    }

    /**
     * Statistics of this course (whole life, user filters applied).
     *
     * @return array
     */
    protected function stats(): array {
        $stats = coursestats::by_course(new scope($this->cfilters));
        return $stats[$this->course->id] ?? coursestats::empty_stats();
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        global $DB;
        $c = $this->course;
        $s = $this->stats();
        $n = $s['enrolled'];
        $teachers = coursestats::teachers([$c->id])[$c->id] ?? [];
        $status = coursestats::status($c, $s);
        $health = coursestats::health($c->type, $status, $s, $teachers);
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $students = fn(array $extra = []) => $this->link('students', ['course' => $c->id, 'view' => 'enrolments'] + $extra);

        $median = coursestats::median($s['certdays']);
        $kpis = [
            $this->kpi('c_students', fmt::int($n), null, $students()),
            $this->kpi(
                'c_accessed',
                fmt::pct($s['accessed'], $n),
                null,
                null,
                '',
                fmt::lowconf($n),
                $str('arr_people', fmt::int($s['accessed']))
            ),
            $this->kpi(
                'c_never',
                fmt::int($s['notstarted']),
                null,
                $students(['situation' => 'notstarted']),
                '',
                false,
                fmt::pct($s['notstarted'], $n)
            ),
            $this->kpi(
                'c_certified',
                fmt::int($s['certified']),
                null,
                $students(['situation' => 'certified']),
                '',
                false,
                fmt::pct($s['certified'], $n)
            ),
            $this->kpi('c_completed', fmt::int($s['completed']), null, $students(['situation' => 'completed'])),
            $this->kpi('c_progress', $n ? fmt::percent($s['progresssum'] / $n) : '—', null, null, '', fmt::lowconf($n)),
            $this->kpi(
                'c_grade',
                $s['gradecount'] ? fmt::percent($s['gradesum'] / $s['gradecount']) : '—',
                null,
                null,
                '',
                fmt::lowconf($s['gradecount'])
            ),
            $this->kpi(
                'c_certdays',
                $median === null ? '—' : $str('arr_days', fmt::dec($median)),
                null,
                null,
                '',
                fmt::lowconf(count($s['certdays']))
            ),
        ];

        $sitbars = [];
        foreach (snapshot::SITUATIONS as $sit) {
            $sitbars[] = ['label' => $str('situation_' . $sit), 'value' => $s[$sit === 'certified' ? 'certified' : $sit] ?? 0,
                'url' => $students(['situation' => $sit])->out(false)];
        }

        // Activity over the whole course life (or the period, when narrower).
        $range = [$c->startdate ?: indicators::first_day(), time()];
        $timeline = indicators::timeline(new scope($this->cfilters, $range));
        $chart = array_sum($timeline['active']) + array_sum($timeline['enrolments']) ? charts::line($timeline['labels'], [
            $str('series_active') => $timeline['active'],
            $str('series_newenrol') => $timeline['enrolments'],
        ], false, [charts::GREEN, charts::ORANGE]) : '';

        $line = $c->linename !== null ? fmt::text($c->linename) . ($c->edition ? ' · ' . $c->edition : '') : $str('cfg_noline');
        $criteria = $DB->count_records('course_completion_criteria', ['course' => $c->id]);
        return $this->render('course', [
            'fullname' => fmt::text($c->fullname),
            'shortname' => fmt::text($c->shortname),
            'category' => fmt::text((string)$c->categoryname),
            'typebadge' => \html_writer::span($str('coursetype_' . $c->type), 'cea-badge cea-type-' . $c->type),
            'statusbadge' => coursestats::status_badge($status),
            'healthbadge' => coursestats::health_badge($health['level']),
            'reasons' => $health['reasons'],
            'line' => $line,
            'start' => fmt::date((int)$c->startdate),
            'end' => $c->enddate ? fmt::date((int)$c->enddate) : $str('noenddate'),
            'visible' => (bool)$c->visible,
            'nocriteria' => !$criteria,
            'links' => [
                ['label' => $str('cl_open'), 'icon' => 'fa-arrow-up-right-from-square',
                    'url' => (new \moodle_url('/course/view.php', ['id' => $c->id]))->out(false)],
                ['label' => $str('cl_participants'), 'icon' => 'fa-users',
                    'url' => (new \moodle_url('/user/index.php', ['id' => $c->id]))->out(false)],
                ['label' => $str('cl_grades'), 'icon' => 'fa-table',
                    'url' => (new \moodle_url('/grade/report/grader/index.php', ['id' => $c->id]))->out(false)],
                ['label' => $str('cl_progress'), 'icon' => 'fa-list-check',
                    'url' => (new \moodle_url('/report/progress/index.php', ['course' => $c->id]))->out(false)],
                ['label' => $str('cl_students'), 'icon' => 'fa-user-graduate', 'url' => $students()->out(false)],
            ],
            'kpis' => $kpis,
            'funnel' => coursestats::funnel($s),
            'sitbars' => charts::bars($sitbars, $n),
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state('fa-chart-line', $str('empty_activity_title'), $str('empty_activity_text')),
            'activities' => $this->get_dataset('activities')->export_for_template(),
            'teachers' => $this->get_dataset('teachers')->export_for_template(),
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
        $c = $this->course;
        if ($id === 'teachers') {
            $ds = new dataset('teachers', $str('course_teachers'), [
                'name' => $str('col_teacher'), 'role' => $str('col_roles'), 'lastaccess' => $str('col_lastaccesscourse'),
            ]);
            foreach (coursestats::teachers([$c->id])[$c->id] ?? [] as $t) {
                $ds->add(['name' => $t['name'], 'role' => $t['role'],
                    'lastaccess' => fmt::date($t['lastaccess']) . ' (' . fmt::ago($t['lastaccess']) . ')',
                    '_html' => ['name' => \html_writer::link(
                        new \moodle_url('/user/profile.php', ['id' => $t['userid']]),
                        s($t['name'])
                    ) . \local_ceduanalytics\local\people::dual_mark($t['userid'], 'teacher')]]);
            }
            $ds->emptytext = $str('noteachers');
            return $ds;
        }
        if ($id !== 'activities') {
            return null;
        }
        $ds = new dataset('activities', $str('course_activities'), [
            'section' => $str('col_section'), 'activity' => $str('col_activity'), 'type' => $str('col_modtype'),
            'tracked' => $str('col_tracked'), 'completed' => $str('col_completedby'), 'opened' => $str('col_openedby'),
            'stopped' => $str('col_stoppedhere'),
        ], ['completed', 'opened', 'stopped']);

        // Students of the course (user filters apply).
        $scope = new scope($this->cfilters);
        $students = [];
        foreach ($scope->enrolments('e.id, e.userid, e.progress, e.timecertified', '', [], '', false) as $r) {
            $students[(int)$r->userid] = $r;
        }
        $n = count($students);

        $a = coursestats::activity_stats($c->id, $students);
        $ds->add(['section' => '—', 'activity' => $str('stopped_beforefirst'), 'type' => '', 'tracked' => '', 'completed' => '',
            'opened' => '', 'stopped' => $a['beforefirst'], '_class' => 'cea-row-note']);
        foreach ($a['rows'] as $row) {
            $cm = $row['cm'];
            $ds->add([
                'section' => $row['section'], 'activity' => fmt::plain($cm->get_formatted_name()),
                    'type' => (string)$cm->get_module_type_name(),
                'tracked' => $cm->completion ? get_string('yes') : get_string('no'),
                'completed' => $cm->completion ? $row['completed'] . ' (' . fmt::pct($row['completed'], $n) . ')' : '—',
                'opened' => $row['opened'] . ' (' . fmt::pct($row['opened'], $n) . ')',
                'stopped' => $row['stopped'],
                '_html' => [
                    'activity' => \html_writer::link(
                        $cm->url ?? new \moodle_url('/course/view.php', ['id' => $c->id]),
                        s(fmt::plain($cm->get_formatted_name()))
                    ) . ($cm->visible ? '' : ' ' .
                        \html_writer::span($str('visible_no'), 'cea-badge cea-badge-soft')),
                    'stopped' => $row['stopped'] ? \html_writer::span((string)$row['stopped'], 'cea-stopped') : '0',
                ],
            ]);
        }
        $ds->emptytext = $str('course_noactivities');
        return $ds;
    }
}
