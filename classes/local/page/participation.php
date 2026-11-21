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
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;
use local_ceduanalytics\local\scope;

/**
 * Participation: are students present and active?
 *
 * Active time is estimated from the gaps between consecutive events (up to 30 minutes).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class participation extends base {
    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'courses' => get_string('part_bycourse', 'local_ceduanalytics'),
            'students' => get_string('part_bystudent', 'local_ceduanalytics'),
        ];
    }

    /**
     * Totals for a scope.
     *
     * @param scope $scope
     * @return array
     */
    protected static function totals(scope $scope): array {
        $r = $scope->daily('COUNT(DISTINCT d.userid) AS users, SUM(d.events) AS events, SUM(d.wsevents) AS ws,
            SUM(d.views) AS views, SUM(d.writes) AS writes, SUM(d.activesecs) AS secs, COUNT(1) AS userdays')[0] ?? null;
        return ['users' => (int)($r->users ?? 0), 'events' => (int)($r->events ?? 0), 'ws' => (int)($r->ws ?? 0),
            'views' => (int)($r->views ?? 0), 'writes' => (int)($r->writes ?? 0),
            'secs' => (int)($r->secs ?? 0), 'userdays' => (int)($r->userdays ?? 0)];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        global $DB;
        $scope = new scope($this->filters);
        $t = self::totals($scope);
        $prev = $scope->previous();
        $p = $prev ? self::totals($prev) : null;
        $timeline = indicators::timeline($scope);

        $kpis = [
            $this->kpi(
                'part_active',
                fmt::int($t['users']),
                $p ? fmt::delta($t['users'], $p['users']) : null,
                $this->link('students'),
                charts::sparkline($timeline['active'], '#43b000')
            ),
            $this->kpi(
                'part_days',
                $t['users'] ? fmt::dec($t['userdays'] / $t['users']) : '—',
                $p && $p['users'] && $t['users'] ? fmt::delta($t['userdays'] / $t['users'], $p['userdays'] / $p['users']) : null,
                null,
                '',
                fmt::lowconf($t['users'])
            ),
            $this->kpi(
                'part_hours',
                fmt::hours($t['secs']),
                $p ? fmt::delta($t['secs'], $p['secs']) : null,
                null,
                charts::sparkline($timeline['hours'], charts::ORANGE)
            ),
            $this->kpi(
                'part_hoursper',
                $t['users'] ? fmt::hours((int)($t['secs'] / $t['users'])) : '—',
                $p && $p['users'] && $t['users'] ? fmt::delta($t['secs'] / $t['users'], $p['secs'] / $p['users']) : null,
                null,
                '',
                fmt::lowconf($t['users'])
            ),
            $this->kpi(
                'part_app',
                fmt::pct($t['ws'], $t['events']),
                null,
                null,
                '',
                false,
                get_string('part_events_app', 'local_ceduanalytics', fmt::int($t['ws']))
            ),
            $this->kpi(
                'part_web',
                fmt::pct($t['events'] - $t['ws'], $t['events']),
                null,
                null,
                '',
                false,
                get_string('part_events_web', 'local_ceduanalytics', fmt::int($t['events'] - $t['ws']))
            ),
        ];

        $haschart = array_sum($timeline['active']) > 0;
        $chart = $haschart ? charts::line($timeline['labels'], [
            get_string('series_active', 'local_ceduanalytics') => $timeline['active'],
            get_string('series_hours', 'local_ceduanalytics') => $timeline['hours'],
        ], false, [charts::GREEN, charts::ORANGE]) : '';

        // Heatmap from the hourly table (course filters only; it has no user dimension).
        $where = 'h.day >= :hfrom AND h.day < :hto AND h.courseid <> :site';
        $params = ['hfrom' => $scope->from, 'hto' => $scope->to, 'site' => SITEID];
        $courses = $scope->course_ids();
        if ($courses !== null) {
            if ($courses) {
                [$insql, $inparams] = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED, 'hc');
                $where .= " AND h.courseid $insql";
                $params += $inparams;
            } else {
                $where .= ' AND 1 = 0';
            }
        }
        $grid = [];
        $tz = \core_date::get_server_timezone_object();
        $rs = $DB->get_recordset_sql(
            "SELECT h.id, h.day, h.hour, h.events FROM {local_ceduanalytics_hourly} h WHERE $where",
            $params
        );
        foreach ($rs as $r) {
            $dow = (int)(new \DateTime('@' . ($r->day + 12 * HOURSECS)))->setTimezone($tz)->format('w');
            $grid[$dow][(int)$r->hour] = ($grid[$dow][(int)$r->hour] ?? 0) + (int)$r->events;
        }
        $rs->close();
        $heat = charts::heatmap($grid);
        $usersfiltered = $this->filters->uf !== '' || $this->filters->segment !== '';

        $device = charts::bars([
            ['label' => get_string('device_web', 'local_ceduanalytics'), 'value' => $t['events'] - $t['ws']],
            ['label' => get_string('device_app', 'local_ceduanalytics'), 'value' => $t['ws']],
        ], $t['events'], 'green');

        return $this->render('participation', [
            'kpis' => $kpis,
            'chart' => $chart,
            'haschart' => $haschart,
            'chartempty' => $this->empty_state(
                'fa-chart-line',
                get_string('empty_activity_title', 'local_ceduanalytics'),
                get_string('empty_activity_text', 'local_ceduanalytics')
            ),
            'unitlabel' => get_string('unit_' . $timeline['unit'], 'local_ceduanalytics'),
            'heatmap' => $heat,
            'heatnote' => $usersfiltered ? get_string('part_heat_userfilter', 'local_ceduanalytics') : '',
            'device' => $device,
            'hasdevice' => $t['events'] > 0,
            'devicetotal' => fmt::int($t['events']),
            'deviceviews' => fmt::int($t['views']) . ' (' . fmt::pct($t['views'], $t['events']) . ')',
            'devicewrites' => fmt::int($t['writes']) . ' (' . fmt::pct($t['writes'], $t['events']) . ')',
            'courses' => $this->get_dataset('courses')->export_for_template(),
            'students' => $this->get_dataset('students')->export_for_template(),
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
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $scope = new scope($this->filters);
        if ($id === 'courses') {
            $ds = new dataset('courses', $s('part_bycourse'), [
                'course' => $s('col_course'), 'users' => $s('col_activestudents'), 'days' => $s('col_avgdays'),
                'hours' => $s('col_hours'), 'hoursper' => $s('col_hoursper'), 'events' => $s('col_events'), 'app' => $s('col_app'),
            ], ['users', 'days', 'hours', 'hoursper', 'events', 'app']);
            $rows = $scope->daily('d.courseid, COUNT(DISTINCT d.userid) AS users, COUNT(1) AS userdays, SUM(d.activesecs) AS secs,
                SUM(d.events) AS events, SUM(d.wsevents) AS ws', 'd.courseid');
            usort($rows, fn($a, $b) => $b->secs <=> $a->secs);
            $names = $rows ? $DB->get_records_list('course', 'id', array_column($rows, 'courseid'), '', 'id, fullname') : [];
            foreach ($rows as $r) {
                $name = fmt::text($names[$r->courseid]->fullname ?? '');
                $ds->add([
                    'course' => $name, 'users' => (int)$r->users, 'days' => fmt::dec($r->userdays / max(1, $r->users)),
                    'hours' => fmt::hours((int)$r->secs), 'hoursper' => fmt::hours((int)($r->secs / max(1, $r->users))),
                    'events' => fmt::int($r->events), 'app' => fmt::pct($r->ws, $r->events),
                    '_html' => ['course' => \html_writer::link($this->link('participation', ['course' => $r->courseid]), s($name))],
                ]);
            }
            $ds->emptytext = $s('empty_activity_text');
            return $ds;
        }
        if ($id === 'students') {
            $ds = new dataset('students', $s('part_bystudent'), [
                'student' => $s('col_student'), 'email' => $s('col_email'), 'courses' => $s('col_courses'),
                'days' => $s('col_activedays'), 'hours' => $s('col_hours'), 'events' => $s('col_events'), 'app' => $s('col_app'),
                'last' => $s('col_lastactivity'),
            ], ['courses', 'days', 'hours', 'events', 'app']);
            $rows = $scope->daily('d.userid, COUNT(DISTINCT d.courseid) AS courses, COUNT(DISTINCT d.day) AS days,
                SUM(d.activesecs) AS secs, SUM(d.events) AS events, SUM(d.wsevents) AS ws, MAX(d.lastts) AS last', 'd.userid');
            usort($rows, fn($a, $b) => $b->secs <=> $a->secs);
            $users = $rows ? $DB->get_records_list(
                'user',
                'id',
                array_column($rows, 'userid'),
                '',
                'id, email, ' . implode(', ', \core_user\fields::get_name_fields())
            ) : [];
            foreach ($rows as $r) {
                $u = $users[$r->userid] ?? null;
                if (!$u) {
                    continue;
                }
                $name = fullname($u);
                $ds->add([
                    'student' => $name, 'email' => $u->email, 'courses' => (int)$r->courses, 'days' => (int)$r->days,
                    'hours' => fmt::hours((int)$r->secs), 'events' => fmt::int($r->events), 'app' => fmt::pct($r->ws, $r->events),
                    'last' => fmt::date((int)$r->last),
                    '_html' => ['student' => indicators::student_link($r->userid, $name)],
                ]);
            }
            $ds->emptytext = $s('empty_activity_text');
            return $ds;
        }
        return null;
    }
}
