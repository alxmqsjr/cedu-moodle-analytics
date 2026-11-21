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

use local_ceduanalytics\local\br;
use local_ceduanalytics\local\charts;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;
use local_ceduanalytics\local\scope;
use local_ceduanalytics\local\snapshot;

/**
 * Home: what is happening and what needs attention.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class home extends base {
    /** @var int Fewer active students than this in the last 14 days = between cycles. */
    const OFFSEASON_THRESHOLD = 5;

    /** @var int Days ahead for the upcoming milestones. */
    const MILESTONE_DAYS = 60;

    /**
     * Exportable datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'courses' => get_string('home_courses', 'local_ceduanalytics'),
            'milestones' => get_string('home_milestones', 'local_ceduanalytics'),
        ];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $scope = new scope($this->filters);
        $prev = $scope->previous();
        $s = indicators::summary($scope);
        $p = $prev ? indicators::summary($prev) : null;
        $timeline = indicators::timeline($scope);

        $recent = indicators::recent_active(14);
        $offseason = $recent < self::OFFSEASON_THRESHOLD;
        $lastbusy = indicators::last_busy_day(self::OFFSEASON_THRESHOLD);

        $kpis = [
            $this->kpi(
                'students',
                fmt::int($s['students']),
                $p ? fmt::delta($s['students'], $p['students']) : null,
                $this->link('students')
            ),
            $this->kpi(
                'active',
                fmt::int($s['active']),
                $p ? fmt::delta($s['active'], $p['active']) : null,
                $this->link('participation'),
                charts::sparkline($timeline['active'], '#43b000')
            ),
            $this->kpi(
                'newenrol',
                fmt::int($s['newenrol']),
                $p ? fmt::delta($s['newenrol'], $p['newenrol']) : null,
                $this->link('arrivals'),
                charts::sparkline($timeline['enrolments'], '#0096ff')
            ),
            $this->kpi(
                'certified',
                fmt::int($s['certified']),
                $p ? fmt::delta($s['certified'], $p['certified']) : null,
                $this->link('students', ['situation' => 'certified', 'view' => 'enrolments'])
            ),
            $this->kpi(
                'certrate',
                $s['certrate'] === null ? '—' : fmt::percent($s['certrate']),
                $p && $p['certrate'] !== null && $s['certrate'] !== null ? fmt::delta(
                    $s['certrate'],
                    $p['certrate'],
                    true,
                    true
                ) : null,
                $this->link('students', ['situation' => 'certified', 'view' => 'enrolments']),
                '',
                fmt::lowconf($s['enrolments']),
                get_string('kpi_of_enrolments', 'local_ceduanalytics', fmt::int($s['enrolments']))
            ),
            $this->kpi(
                'notstarted',
                fmt::int($s['situations']['notstarted']),
                $p ? fmt::delta($s['situations']['notstarted'], $p['situations']['notstarted'], false) : null,
                $this->link('students', ['situation' => 'notstarted', 'view' => 'enrolments']),
                '',
                false,
                fmt::pct($s['situations']['notstarted'], $s['enrolments']) . ' ' . get_string(
                    'kpi_of_enrolments_short',
                    'local_ceduanalytics'
                )
            ),
            $this->kpi('support', fmt::int($s['support']), null, $this->link('students', ['situation' => 'inactive',
                'view' => 'enrolments'])),
            $this->kpi(
                'grade',
                $s['gradeavg'] === null ? '—' : fmt::percent($s['gradeavg']),
                $p && $p['gradeavg'] !== null && $s['gradeavg'] !== null ? fmt::delta(
                    $s['gradeavg'],
                    $p['gradeavg'],
                    true,
                    true
                ) : null,
                null,
                '',
                fmt::lowconf($s['gradecount']),
                get_string('kpi_grades_count', 'local_ceduanalytics', fmt::int($s['gradecount']))
            ),
        ];

        $chart = array_sum($timeline['active']) + array_sum($timeline['enrolments']) > 0 ? charts::line($timeline['labels'], [
            get_string('series_active', 'local_ceduanalytics') => $timeline['active'],
            get_string('series_newenrol', 'local_ceduanalytics') => $timeline['enrolments'],
        ], false, [charts::GREEN, charts::ORANGE]) : '';

        $ufbars = $this->uf_bars($scope);
        $milestones = $this->get_dataset('milestones');

        return $this->render('home', [
            'offseason' => $offseason,
            'offseasontext' => get_string('home_offseason_text', 'local_ceduanalytics', [
                'recent' => fmt::int($recent),
                'lastbusy' => $lastbusy ? fmt::date($lastbusy) : get_string('never'),
            ]),
            'comparelabel' => $prev ? get_string(
                'home_compare',
                'local_ceduanalytics',
                ['from' => fmt::date($prev->from), 'to' => fmt::date($prev->to - 1)]
            ) : get_string('home_nocompare', 'local_ceduanalytics'),
            'kpis' => $kpis,
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state(
                'fa-chart-line',
                get_string('empty_activity_title', 'local_ceduanalytics'),
                get_string('empty_activity_text', 'local_ceduanalytics')
            ),
            'unitlabel' => get_string('unit_' . $timeline['unit'], 'local_ceduanalytics'),
            'attention' => $this->attention($s),
            'milestones' => $milestones->export_for_template(),
            'ufbars' => $ufbars,
            'courses' => $this->get_dataset('courses')->export_for_template(),
            'remobilise' => $this->remobilise($scope),
        ]);
    }

    /**
     * "Needs your attention" items. Items without pending work are shown as OK.
     *
     * @param array $s summary
     * @return array
     */
    protected function attention(array $s): array {
        global $DB, $CFG;
        $items = [];
        $add = function (string $key, int $count, \moodle_url $url, string $level = 'warn', $a = null) use (&$items) {
            $items[] = [
                'count' => fmt::int($count),
                'text' => get_string('att_' . $key, 'local_ceduanalytics', $a ?? fmt::int($count)),
                'url' => $url->out(false),
                'level' => $count > 0 ? $level : 'ok',
                'ok' => $count === 0,
                'icon' => $count > 0
                    ? ($level === 'danger' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation')
                    : 'fa-circle-check',
            ];
        };
        $now = time();

        // Students needing support (active enrolments, attention or inactive).
        $add('support', (int)$s['support'], $this->link('students', ['situation' => 'inactive', 'view' => 'enrolments']));

        // Never accessed a course that is still running.
        $notstarted = (int)$DB->count_records_sql("
            SELECT COUNT(1)
              FROM {local_ceduanalytics_enrolstate} e
              JOIN {course} c ON c.id = e.courseid
             WHERE e.isstudent = 1 AND e.enrolactive = 1 AND e.situation = 'notstarted'
               AND ((CASE WHEN e.timeend > 0 THEN e.timeend ELSE c.enddate END) = 0
                    OR (CASE WHEN e.timeend > 0 THEN e.timeend ELSE c.enddate END) > :now)", ['now' => $now]);
        $add('notstarted', $notstarted, $this->link('students', ['situation' => 'notstarted', 'view' => 'enrolments']));

        // Enrolments ending within 14 days without certificate.
        $expiring = (int)$DB->count_records_select(
            'local_ceduanalytics_enrolstate',
            'isstudent = 1 AND timecertified = 0 AND timeend > :now AND timeend < :limit',
            ['now' => $now, 'limit' => $now + 14 * DAYSECS]
        );
        $add('expiring', $expiring, $this->link('students', ['view' => 'enrolments']));

        // 100% of activities without certificate.
        $add('completed', (int)$s['situations']['completed'], $this->link('students', ['situation' => 'completed',
            'view' => 'enrolments']));

        // Pending grading.
        $counters = snapshot::counters();
        $add('grading', (int)($counters['team'] ?? 0), $this->link('team'));

        // Course completion not configured.
        $criteria = $DB->count_records_sql('SELECT COUNT(DISTINCT course) FROM {course_completion_criteria}');
        $courses = $DB->count_records_select('course', 'id <> ?', [SITEID]);
        $add('nocriteria', $criteria ? 0 : $courses, $this->link('config'), 'info', fmt::int($courses));

        // Failed logins in the last 30 days and account lockout.
        $failed = (int)$DB->get_field_sql(
            'SELECT SUM(attempts) FROM {local_ceduanalytics_security} WHERE day >= ?',
            [$now - 30 * DAYSECS]
        );
        $add('failedlogins', $failed, $this->link('security'), 'danger');
        $add('lockout', empty($CFG->lockoutthreshold) ? 1 : 0, new \moodle_url(
            '/admin/settings.php',
            ['section' => 'sitepolicies']
        ), 'danger');

        // Pending first, then OK.
        usort($items, fn($a, $b) => (int)$a['ok'] <=> (int)$b['ok']);
        return $items;
    }

    /**
     * Students per state (bars), with coverage.
     *
     * @param scope $scope
     * @return array
     */
    protected function uf_bars(scope $scope): array {
        $byuf = [];
        $users = [];
        foreach ($scope->enrolments('e.id, e.userid, ua.uf') as $r) {
            if (isset($users[$r->userid])) {
                continue;
            }
            $users[$r->userid] = true;
            $uf = $r->uf ?: '';
            $byuf[$uf] = ($byuf[$uf] ?? 0) + 1;
        }
        $total = count($users);
        $unknown = $byuf[''] ?? 0;
        unset($byuf['']);
        arsort($byuf);
        $items = [];
        // Every UF with students gets its own bar (no "other UFs" group).
        foreach ($byuf as $uf => $n) {
            $items[] = ['label' => $uf . ' — ' . br::state_name($uf), 'value' => $n,
                'url' => $this->link('students', ['uf' => $uf])->out(false)];
        }
        if ($unknown) {
            $items[] = ['label' => get_string('uf_none', 'local_ceduanalytics'), 'value' => $unknown,
                'url' => $this->link('students', ['uf' => 'NONE'])->out(false), 'muted' => true];
        }
        return charts::bars($items, $total, 'blue') + [
            'coverage' => get_string('home_ufcoverage', 'local_ceduanalytics', [
                'known' => fmt::pct($total - $unknown, $total), 'states' => count($byuf)]),
        ];
    }

    /**
     * Remobilisation base: people the team can invite to the next cycle.
     *
     * @param scope $scope
     * @return array
     */
    protected function remobilise(scope $scope): array {
        $byuser = [];
        foreach ($scope->enrolments('e.id, e.userid, e.situation, e.timecertified') as $r) {
            $byuser[$r->userid][] = $r;
        }
        $onecert = 0;
        $never = 0;
        $ended = 0;
        foreach ($byuser as $rows) {
            $certs = count(array_filter($rows, fn($r) => $r->timecertified > 0));
            if ($certs >= 1 && count($rows) === 1) {
                $onecert++;
            }
            if (count(array_filter($rows, fn($r) => $r->situation === 'notstarted')) === count($rows)) {
                $never++;
            }
            if (count(array_filter($rows, fn($r) => $r->situation === 'ended')) > 0) {
                $ended++;
            }
        }
        return [
            ['value' => fmt::int($onecert), 'label' => get_string('remob_onecert', 'local_ceduanalytics'),
                'url' => $this->link('students', ['situation' => 'certified'])->out(false)],
            ['value' => fmt::int($never), 'label' => get_string('remob_never', 'local_ceduanalytics'),
                'url' => $this->link('students', ['situation' => 'notstarted'])->out(false)],
            ['value' => fmt::int($ended), 'label' => get_string('remob_ended', 'local_ceduanalytics'),
                'url' => $this->link('students', ['situation' => 'ended'])->out(false)],
        ];
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        global $DB;
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        if ($id === 'milestones') {
            $ds = new dataset('milestones', $s('home_milestones'), [
                'date' => $s('col_date'), 'what' => $s('col_milestone'), 'course' => $s('col_course'), 'detail' => $s('col_detail'),
            ]);
            $now = time();
            $limit = $now + self::MILESTONE_DAYS * DAYSECS;
            $rows = [];
            foreach (
                $DB->get_records_select('course', 'id <> :site AND ((startdate >= :n1 AND startdate < :l1) OR
                    (enddate >= :n2 AND enddate < :l2))', ['site' => SITEID, 'n1' => $now, 'l1' => $limit, 'n2' => $now,
                    'l2' => $limit], '', 'id, fullname, startdate, enddate') as $c
            ) {
                if ($c->startdate >= $now && $c->startdate < $limit) {
                    $rows[] = [$c->startdate, $s('ms_coursestart'), $c, ''];
                }
                if ($c->enddate >= $now && $c->enddate < $limit) {
                    $rows[] = [$c->enddate, $s('ms_courseend'), $c, ''];
                }
            }
            $ends = $DB->get_records_sql("
                SELECT e.courseid, e.timeend, COUNT(1) AS n, MAX(c.fullname) AS fullname
                  FROM {local_ceduanalytics_enrolstate} e JOIN {course} c ON c.id = e.courseid
                 WHERE e.isstudent = 1 AND e.timeend >= :now AND e.timeend < :limit
              GROUP BY e.courseid, e.timeend", ['now' => $now, 'limit' => $limit]);
            foreach ($ends as $e) {
                $rows[] = [(int)$e->timeend, $s('ms_enrolend'), (object)['id' => $e->courseid, 'fullname' => $e->fullname],
                    $s('ms_students', fmt::int($e->n))];
            }
            if ($DB->get_manager()->table_exists('assign')) {
                foreach (
                    $DB->get_records_sql("SELECT a.id, a.name, a.duedate, a.course, c.fullname
                        FROM {assign} a JOIN {course} c ON c.id = a.course
                       WHERE a.duedate >= :now AND a.duedate < :limit", ['now' => $now, 'limit' => $limit]) as $a
                ) {
                    $rows[] = [(int)$a->duedate, $s('ms_assigndue'), (object)['id' => $a->course, 'fullname' => $a->fullname],
                        fmt::text($a->name)];
                }
            }
            usort($rows, fn($a, $b) => $a[0] <=> $b[0]);
            foreach ($rows as [$ts, $what, $course, $detail]) {
                $name = fmt::text($course->fullname);
                $ds->add(['date' => fmt::date($ts), 'what' => $what, 'course' => $name, 'detail' => $detail,
                    '_html' => ['course' => \html_writer::link($this->link('students', ['course' => $course->id]), s($name))]]);
            }
            // Nothing ahead: say when the last milestone was.
            $last = (int)$DB->get_field_sql('SELECT MAX(enddate) FROM {course} WHERE enddate > 0 AND enddate < ?', [$now]);
            $ds->emptytext = $s('home_milestones_empty', ['days' => self::MILESTONE_DAYS,
                'last' => $last ? fmt::date($last) : get_string('never')]);
            return $ds;
        }
        if ($id === 'courses') {
            $scope = new scope($this->filters);
            $ds = new dataset('courses', $s('home_courses'), [
                'course' => $s('col_course'), 'students' => $s('col_students'), 'active' => $s('col_activeperiod'),
                'notstarted' => $s('col_notstarted'), 'certified' => $s('col_certified'), 'progress' => $s('col_avgprogress'),
            ], ['students', 'active', 'notstarted', 'certified', 'progress']);
            $bycourse = [];
            foreach ($scope->enrolments('e.id, e.courseid, e.situation, e.timecertified, e.progress, c.fullname') as $r) {
                $c = &$bycourse[$r->courseid];
                $c['name'] = $c['name'] ?? fmt::text($r->fullname);
                $c['students'] = ($c['students'] ?? 0) + 1;
                $c['notstarted'] = ($c['notstarted'] ?? 0) + ($r->situation === 'notstarted' ? 1 : 0);
                $c['certified'] = ($c['certified'] ?? 0) + ($r->timecertified ? 1 : 0);
                $c['progress'] = ($c['progress'] ?? 0) + (float)$r->progress;
                unset($c);
            }
            $active = [];
            foreach ($scope->daily('d.courseid, COUNT(DISTINCT d.userid) AS n', 'd.courseid') as $r) {
                $active[$r->courseid] = (int)$r->n;
            }
            uasort($bycourse, fn($a, $b) => $b['students'] <=> $a['students']);
            foreach ($bycourse as $cid => $c) {
                $ds->add([
                    'course' => $c['name'], 'students' => $c['students'], 'active' => $active[$cid] ?? 0,
                    'notstarted' => $c['notstarted'] . ' (' . fmt::pct($c['notstarted'], $c['students']) . ')',
                    'certified' => $c['certified'], 'progress' => fmt::percent($c['progress'] / $c['students']),
                    '_html' => ['course' => \local_ceduanalytics\local\coursestats::link(
                        $cid,
                        $c['name'],
                        $this->filters->params()
                    )],
                ]);
            }
            $ds->emptytext = $s('empty_courses_scope');
            return $ds;
        }
        return null;
    }
}
