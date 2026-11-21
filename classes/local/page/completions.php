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
use local_ceduanalytics\local\indicators;
use local_ceduanalytics\local\scope;

/**
 * Certificates and completions: who completed and was certified?
 *
 * Two separate measures: issued certificates (mod_customcert) and students with 100% of the tracked
 * activities and no certificate. Moodle course completion is shown too (it is not configured).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completions extends base {
    /** @var array|null */
    protected $rows = null;

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'certificates' => get_string('comp_certlist', 'local_ceduanalytics'),
            'nocert' => get_string('comp_nocertlist', 'local_ceduanalytics'),
            'courses' => get_string('comp_bycourse', 'local_ceduanalytics'),
        ];
    }

    /**
     * Enrolments in scope.
     *
     * @param scope|null $scope
     * @return \stdClass[]
     */
    protected function rows(?scope $scope = null): array {
        if ($scope === null && $this->rows !== null) {
            return $this->rows;
        }
        $rows = ($scope ?? new scope($this->filters))->enrolments('e.*, c.fullname, u.email, u.firstname, u.lastname,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename');
        if ($scope === null) {
            $this->rows = $rows;
        }
        return $rows;
    }

    /**
     * Figures of a set of enrolments within [from, to).
     *
     * @param \stdClass[] $rows
     * @param int $from
     * @param int $to
     * @return array
     */
    protected static function figures(array $rows, int $from, int $to): array {
        $f = ['enrolments' => count($rows), 'certperiod' => 0, 'certall' => 0, 'people' => [], 'completed' => 0,
            'moodle' => 0, 'days' => []];
        foreach ($rows as $r) {
            if ($r->timecertified) {
                $f['certall']++;
                if ($r->timecertified >= $from && $r->timecertified < $to) {
                    $f['certperiod']++;
                    $f['people'][$r->userid] = true;
                    $start = max((int)$r->timeenrolled, (int)$r->firstaccess);
                    if ($start && $r->timecertified >= $start) {
                        $f['days'][] = ($r->timecertified - $start) / DAYSECS;
                    }
                }
            }
            if (self::completed_in($r, $from, $to)) {
                $f['completed']++;
            }
            if ($r->timecompleted) {
                $f['moodle']++;
            }
        }
        $f['people'] = count($f['people']);
        return $f;
    }

    /**
     * Whether the enrolment reached 100% of the tracked activities without a certificate inside the period.
     *
     * The date is the completion of the last tracked activity; an enrolment that crosses the period but reached 100%
     * before it does not count. Without that date (activities completed before the log was kept) it counts.
     *
     * @param \stdClass $r
     * @param int $from
     * @param int $to
     * @return bool
     */
    protected static function completed_in(\stdClass $r, int $from, int $to): bool {
        if ($r->situation !== 'completed') {
            return false;
        }
        $done = (int)$r->timelastdone;
        return !$done || ($done >= $from && $done < $to);
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        global $DB;
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $scope = new scope($this->filters);
        $f = self::figures($this->rows(), $scope->from, $scope->to);
        $prev = $scope->previous();
        $p = $prev ? self::figures($this->rows($prev), $prev->from, $prev->to) : null;
        $median = coursestats::median($f['days']);
        $rate = $f['enrolments'] ? $f['certall'] / $f['enrolments'] * 100 : null;
        $prate = $p && $p['enrolments'] ? $p['certall'] / $p['enrolments'] * 100 : null;
        $students = fn($sit) => $this->link('students', ['situation' => $sit, 'view' => 'enrolments']);

        $kpis = [
            $this->kpi(
                'certified',
                fmt::int($f['certperiod']),
                $p ? fmt::delta($f['certperiod'], $p['certperiod']) : null,
                $students('certified')
            ),
            $this->kpi('comp_people', fmt::int($f['people']), $p ? fmt::delta($f['people'], $p['people']) : null),
            $this->kpi(
                'certrate',
                $rate === null ? '—' : fmt::percent($rate),
                $rate !== null && $prate !== null ? fmt::delta($rate, $prate, true, true) : null,
                null,
                '',
                fmt::lowconf($f['enrolments']),
                $str('kpi_of_enrolments', fmt::int($f['enrolments']))
            ),
            $this->kpi('comp_nocert', fmt::int($f['completed']), null, $students('completed')),
            $this->kpi(
                'comp_days',
                $median === null ? '—' : $str('arr_days', fmt::dec($median)),
                $median !== null && $p && ($pm = coursestats::median($p['days'])) !== null ? fmt::delta($median, $pm, false) : null,
                null,
                '',
                fmt::lowconf(count($f['days']))
            ),
            $this->kpi('comp_moodle', fmt::int($f['moodle'])),
        ];

        // Certificates per month.
        $from = $scope->from ?: indicators::first_day();
        $buckets = charts::buckets($from, $scope->to, 'month');
        $starts = array_keys($buckets);
        $counts = array_fill_keys($starts, 0);
        foreach ($this->rows() as $r) {
            if ($r->timecertified >= $from && $r->timecertified < $scope->to) {
                $b = charts::bucket_of((int)$r->timecertified, $starts);
                if ($b !== null) {
                    $counts[$b]++;
                }
            }
        }
        $chart = array_sum($counts) ? charts::bar(
            array_values($buckets),
            [$str('series_certificates') => array_values($counts)],
            false,
            false,
            [charts::GREEN]
        ) : '';
        $criteria = $DB->count_records_sql('SELECT COUNT(DISTINCT course) FROM {course_completion_criteria}');

        return $this->render('completions', [
            'nocriteria' => !$criteria,
            'kpis' => $kpis,
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state('fa-award', $str('comp_empty_title'), $str('comp_empty_text')),
            'courses' => $this->get_dataset('courses')->export_for_template(),
            'certificates' => $this->get_dataset('certificates')->export_for_template(),
            'nocert' => $this->get_dataset('nocert')->export_for_template(),
        ]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $scope = new scope($this->filters);
        $rows = $this->rows();
        if ($id === 'courses') {
            $ds = new dataset('courses', $str('comp_bycourse'), [
                'course' => $str('col_course'), 'enrolled' => $str('col_students'), 'certperiod' => $str('col_certperiod'),
                'certall' => $str('col_certified'), 'rate' => $str('col_certrate'), 'nocert' => $str('col_completednocert'),
                'days' => $str('col_certdays'),
            ], ['enrolled', 'certperiod', 'certall', 'rate', 'nocert', 'days']);
            $by = [];
            foreach ($rows as $r) {
                $by[$r->courseid]['name'] = fmt::text($r->fullname);
                $by[$r->courseid]['rows'][] = $r;
            }
            foreach ($by as $cid => $c) {
                $f = self::figures($c['rows'], $scope->from, $scope->to);
                $median = coursestats::median($f['days']);
                $ds->add([
                    'course' => $c['name'], 'enrolled' => $f['enrolments'], 'certperiod' => $f['certperiod'],
                    'certall' => $f['certall'], 'rate' => fmt::pct($f['certall'], $f['enrolments']), 'nocert' => $f['completed'],
                    'days' => $median === null ? '—' : fmt::dec($median),
                    '_html' => ['course' => coursestats::link($cid, $c['name'], $this->filters->params())],
                ]);
            }
            usort($ds->rows, fn($a, $b) => $b['certall'] <=> $a['certall']);
            $ds->emptytext = $str('empty_students');
            return $ds;
        }
        if ($id === 'certificates') {
            $ds = new dataset('certificates', $str('comp_certlist'), [
                'student' => $str('col_student'), 'email' => $str('col_email'), 'course' => $str('col_course'),
                'date' => $str('col_certified_on'), 'days' => $str('col_certdays'), 'grade' => $str('col_grade'),
            ], ['days', 'grade']);
            $list = array_filter($rows, fn($r) => $r->timecertified >= $scope->from && $r->timecertified < $scope->to);
            usort($list, fn($a, $b) => $b->timecertified <=> $a->timecertified);
            foreach ($list as $r) {
                $name = fullname($r);
                $start = max((int)$r->timeenrolled, (int)$r->firstaccess);
                $ds->add([
                    'student' => $name, 'email' => $r->email, 'course' => fmt::text($r->fullname),
                    'date' => fmt::date((int)$r->timecertified),
                    'days' => $start && $r->timecertified >= $start ? fmt::dec(($r->timecertified - $start) / DAYSECS) : '—',
                    'grade' => $r->gradepct === null ? '—' : fmt::percent($r->gradepct),
                    '_html' => ['student' => indicators::student_link($r->userid, $name)],
                ]);
            }
            $ds->emptytext = $str('comp_empty_text');
            return $ds;
        }
        if ($id === 'nocert') {
            $ds = new dataset('nocert', $str('comp_nocertlist'), [
                'student' => $str('col_student'), 'email' => $str('col_email'), 'course' => $str('col_course'),
                'lastdone' => $str('col_lastdone'), 'grade' => $str('col_grade'),
            ], ['grade']);
            foreach ($rows as $r) {
                if (!self::completed_in($r, $scope->from, $scope->to)) {
                    continue;
                }
                $name = fullname($r);
                $ds->add([
                    'student' => $name, 'email' => $r->email, 'course' => fmt::text($r->fullname),
                    'lastdone' => fmt::date((int)$r->timelastdone),
                    'grade' => $r->gradepct === null ? '—' : fmt::percent($r->gradepct),
                    '_html' => ['student' => indicators::student_link($r->userid, $name),
                        'course' => coursestats::link((int)$r->courseid, fmt::text($r->fullname), $this->filters->params())],
                ]);
            }
            $ds->emptytext = $str('comp_nocert_empty');
            return $ds;
        }
        return null;
    }
}
