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
 * First access: who came in and who never accessed, among the enrolments created in the period.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class arrivals extends base {
    /** @var array|null */
    protected $cache = null;

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'never' => get_string('arr_never_list', 'local_ceduanalytics'),
            'courses' => get_string('arr_bycourse', 'local_ceduanalytics'),
            'origin' => get_string('arr_byorigin', 'local_ceduanalytics'),
        ];
    }

    /**
     * Enrolments created in the period (arrivals), with days until the first access.
     *
     * @param scope|null $scope
     * @return \stdClass[]
     */
    protected function arrival_rows(?scope $scope = null): array {
        $scope = $scope ?? new scope($this->filters);
        $rows = $scope->enrolments(
            'e.id, e.userid, e.courseid, e.timeenrolled, e.timestart, e.timeend, e.firstaccess,
            e.lastaccess, e.situation, e.enrolmethods, e.enrolactive, c.fullname, c.enddate AS courseend, u.auth, u.email,
            u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
            'e.timeenrolled >= :afrom AND e.timeenrolled < :ato',
            ['afrom' => $scope->from, 'ato' => $scope->to]
        );
        foreach ($rows as $r) {
            $start = max((int)$r->timeenrolled, (int)$r->timestart);
            $r->accessed = $r->lastaccess > 0;
            $r->daysto = $r->accessed && $r->firstaccess ? max(0, ($r->firstaccess - $start) / DAYSECS) : null;
        }
        return $rows;
    }

    /**
     * Median.
     *
     * @param float[] $values
     * @return float|null
     */
    protected static function median(array $values): ?float {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);
        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /**
     * Key figures of a set of arrivals.
     *
     * @param \stdClass[] $rows
     * @return array
     */
    protected static function figures(array $rows): array {
        $accessed = array_filter($rows, fn($r) => $r->accessed);
        $days = array_values(array_filter(array_map(fn($r) => $r->daysto, $accessed), fn($d) => $d !== null));
        $neverstudents = [];
        foreach ($rows as $r) {
            if (!$r->accessed) {
                $neverstudents[$r->userid] = true;
            }
        }
        return ['total' => count($rows), 'accessed' => count($accessed), 'never' => count($rows) - count($accessed),
            'neverstudents' => count($neverstudents), 'median' => self::median($days), 'withdays' => count($days)];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $scope = new scope($this->filters);
        $rows = $this->arrival_rows($scope);
        $f = self::figures($rows);
        $prev = $scope->previous();
        $pf = $prev ? self::figures($this->arrival_rows($prev)) : null;

        $kpis = [
            $this->kpi('arr_total', fmt::int($f['total']), $pf ? fmt::delta($f['total'], $pf['total']) : null),
            $this->kpi(
                'arr_accessed',
                fmt::pct($f['accessed'], $f['total']),
                $pf && $pf['total'] && $f['total'] ? fmt::delta(
                    $f['accessed'] / $f['total'] * 100,
                    $pf['accessed'] / $pf['total'] * 100,
                    true,
                    true
                ) : null,
                null,
                '',
                fmt::lowconf($f['total']),
                get_string('arr_people', 'local_ceduanalytics', fmt::int($f['accessed']))
            ),
            $this->kpi(
                'arr_never',
                fmt::int($f['neverstudents']),
                $pf ? fmt::delta($f['neverstudents'], $pf['neverstudents'], false) : null,
                $this->link('students', ['situation' => 'notstarted']),
                '',
                false,
                get_string('arr_never_note', 'local_ceduanalytics', fmt::int($f['never']))
            ),
            $this->kpi(
                'arr_median',
                $f['median'] === null ? '—' : get_string('arr_days', 'local_ceduanalytics', fmt::dec($f['median'])),
                $pf && $pf['median'] !== null && $f['median'] !== null ? fmt::delta($f['median'], $pf['median'], false) : null,
                null,
                '',
                fmt::lowconf($f['withdays'])
            ),
        ];

        // Enrolments per month: accessed × never accessed.
        $from = $scope->from ?: ($rows ? min(array_map(fn($r) => (int)$r->timeenrolled, $rows)) : time());
        $buckets = charts::buckets($from, $scope->to, 'month');
        $starts = array_keys($buckets);
        $acc = array_fill_keys($starts, 0);
        $nev = array_fill_keys($starts, 0);
        foreach ($rows as $r) {
            $b = charts::bucket_of((int)$r->timeenrolled, $starts);
            if ($b !== null) {
                $r->accessed ? $acc[$b]++ : $nev[$b]++;
            }
        }
        $chart = $rows ? charts::bar(array_values($buckets), [
            get_string('series_accessed', 'local_ceduanalytics') => array_values($acc),
            get_string('series_never', 'local_ceduanalytics') => array_values($nev),
        ], true, false, [charts::GREEN, charts::ORANGE]) : '';

        return $this->render('arrivals', [
            'kpis' => $kpis,
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state(
                'fa-door-open',
                get_string('arr_empty_title', 'local_ceduanalytics'),
                get_string('arr_empty_text', 'local_ceduanalytics')
            ),
            'courses' => $this->get_dataset('courses')->export_for_template(),
            'origin' => $this->get_dataset('origin')->export_for_template(),
            'never' => $this->get_dataset('never')->export_for_template(),
        ]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $this->cache = $this->cache ?? $this->arrival_rows();
        $rows = $this->cache;
        $now = time();
        if ($id === 'never') {
            $ds = new dataset('never', $s('arr_never_list'), [
                'student' => $s('col_student'), 'email' => $s('col_email'), 'course' => $s('col_course'),
                'enrolled' => $s('col_enrolled_on'), 'days' => $s('col_dayssince'), 'coursestatus' => $s('col_coursestatus'),
                'methods' => $s('col_methods'),
            ], ['days']);
            $never = array_values(array_filter($rows, fn($r) => !$r->accessed));
            $sortkey = fn($r) => [\core_text::strtolower(fullname($r)), (int)$r->userid, $r->fullname];
            usort($never, fn($a, $b) => $sortkey($a) <=> $sortkey($b));
            foreach ($never as $r) {
                $end = $r->timeend ?: (int)$r->courseend;
                $name = fullname($r);
                $course = fmt::text($r->fullname);
                $ds->add([
                    'student' => $name, 'email' => $r->email, 'course' => $course, 'enrolled' => fmt::date((int)$r->timeenrolled),
                    'days' => fmt::int(floor(($now - $r->timeenrolled) / DAYSECS)),
                    'coursestatus' => $end && $end < $now ? $s('coursestatus_ended') : $s('coursestatus_running'),
                    'methods' => $r->enrolmethods,
                    '_html' => ['student' => indicators::student_link($r->userid, $name)],
                    '_mergekey' => (int)$r->userid,
                ]);
            }
            $ds->merge(['student']);
            $ds->emptytext = $s('arr_never_empty');
            return $ds;
        }
        if ($id === 'courses') {
            $ds = new dataset('courses', $s('arr_bycourse'), [
                'course' => $s('col_course'), 'total' => $s('col_arrivals'), 'accessed' => $s('col_accessed'),
                'never' => $s('col_notstarted'), 'neverpct' => $s('col_neverpct'), 'median' => $s('col_mediandays'),
            ], ['total', 'accessed', 'never', 'neverpct', 'median']);
            $by = [];
            foreach ($rows as $r) {
                $by[$r->courseid]['name'] = fmt::text($r->fullname);
                $by[$r->courseid]['rows'][] = $r;
            }
            uasort($by, fn($a, $b) => count($b['rows']) <=> count($a['rows']));
            foreach ($by as $cid => $c) {
                $f = self::figures($c['rows']);
                $ds->add([
                    'course' => $c['name'], 'total' => $f['total'], 'accessed' => $f['accessed'], 'never' => $f['never'],
                    'neverpct' => fmt::pct($f['never'], $f['total']),
                    'median' => $f['median'] === null ? '—' : fmt::dec($f['median']),
                    '_html' => ['course' => \html_writer::link(
                        $this->link('students', ['course' => $cid, 'view' => 'enrolments']),
                        s($c['name'])
                    )],
                ]);
            }
            $ds->emptytext = $s('arr_empty_text');
            return $ds;
        }
        if ($id === 'origin') {
            $ds = new dataset('origin', $s('arr_byorigin'), [
                'origin' => $s('col_origin'), 'total' => $s('col_arrivals'), 'accessed' => $s('col_accessed'),
                'neverpct' => $s('col_neverpct'), 'median' => $s('col_mediandays'),
            ], ['total', 'accessed', 'neverpct', 'median']);
            $by = [];
            foreach ($rows as $r) {
                $authname = get_string_manager()->string_exists('pluginname', 'auth_' . $r->auth) ?
                    get_string('pluginname', 'auth_' . $r->auth) : $r->auth;
                $key = $authname . ' · ' . $r->enrolmethods;
                $by[$key][] = $r;
            }
            ksort($by);
            foreach ($by as $key => $list) {
                $f = self::figures($list);
                $ds->add(['origin' => $key, 'total' => $f['total'], 'accessed' => $f['accessed'],
                    'neverpct' => fmt::pct($f['never'], $f['total']),
                        'median' => $f['median'] === null ? '—' : fmt::dec($f['median'])]);
            }
            $ds->emptytext = $s('arr_empty_text');
            return $ds;
        }
        return null;
    }
}
