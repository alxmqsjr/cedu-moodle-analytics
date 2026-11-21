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
use local_ceduanalytics\local\brmap;
use local_ceduanalytics\local\charts;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * Map of Brazil: where the students are, how the state (UF) is known, and results by state and region.
 *
 * The map is a choropleth over the official IBGE mesh of states (embedded, see brmap). Small states get
 * their label outside, with a leader line.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class map extends base {
    /** @var array UF => [x, y] of labels drawn outside small states, with a leader line. */
    const CALLOUTS = [
        'RN' => [612, 168], 'PB' => [612, 192], 'PE' => [612, 216], 'AL' => [612, 240], 'SE' => [612, 264],
        'ES' => [560, 398], 'RJ' => [530, 462], 'DF' => [440, 312],
    ];

    /** @var int ViewBox width: the IBGE map plus room for the callouts on the east coast. */
    const VIEWWIDTH = 660;

    /** @var string[] Regions in display order. */
    const REGIONS = ['N', 'NE', 'CO', 'SE', 'S'];

    /** @var string[] Metrics the map can show. */
    const METRICS = ['students', 'certified', 'rate'];

    /** @var string */
    protected $metric;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $metric = optional_param('metric', 'students', PARAM_ALPHA);
        $this->metric = in_array($metric, self::METRICS) ? $metric : 'students';
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        return $this->metric === 'students' ? [] : ['metric' => $this->metric];
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'states' => get_string('map_bystate', 'local_ceduanalytics'),
            'regions' => get_string('map_byregion', 'local_ceduanalytics'),
            'cities' => get_string('map_bycity', 'local_ceduanalytics'),
        ];
    }

    /**
     * Students in scope, one row per user, with UF, source, city and results.
     *
     * @return array userid => ['uf', 'source', 'city', 'enrolments', 'certified']
     */
    protected function users(): array {
        $scope = new scope($this->filters);
        return $scope->cached('mapusers', function () use ($scope) {
            $users = [];
            foreach ($scope->enrolments('e.id, e.userid, e.timecertified, ua.uf, ua.ufsource, ua.city') as $r) {
                $uid = (int)$r->userid;
                if (!isset($users[$uid])) {
                    $users[$uid] = ['uf' => (string)$r->uf, 'source' => (string)$r->ufsource, 'city' => trim((string)$r->city),
                        'enrolments' => 0, 'certified' => 0];
                }
                $users[$uid]['enrolments']++;
                $users[$uid]['certified'] += $r->timecertified ? 1 : 0;
            }
            return $users;
        });
    }

    /**
     * Totals per UF.
     *
     * @return array uf => ['students', 'declared', 'inferred', 'enrolments', 'certified', 'certstudents', 'cities' => []]
     */
    protected function by_state(): array {
        $out = [];
        foreach ($this->users() as $u) {
            $uf = $u['uf'];
            if (!isset($out[$uf])) {
                $out[$uf] = ['students' => 0, 'declared' => 0, 'inferred' => 0, 'enrolments' => 0, 'certified' => 0,
                    'certstudents' => 0, 'cities' => []];
            }
            $s = &$out[$uf];
            $s['students']++;
            if ($uf !== '') {
                $s[$u['source'] === 'declared' ? 'declared' : 'inferred']++;
            }
            $s['enrolments'] += $u['enrolments'];
            $s['certified'] += $u['certified'];
            $s['certstudents'] += $u['certified'] ? 1 : 0;
            if ($u['city'] !== '') {
                $s['cities'][br::normalise($u['city'])] = true;
            }
            unset($s);
        }
        return $out;
    }

    /**
     * Value of the current metric for a state.
     *
     * @param array $s
     * @return float
     */
    protected function value(array $s): float {
        switch ($this->metric) {
            case 'certified':
                return $s['certstudents'];
            case 'rate':
                return $s['enrolments'] ? $s['certified'] / $s['enrolments'] * 100 : 0;
        }
        return $s['students'];
    }

    /**
     * Formatted value of the current metric.
     *
     * @param array|null $s
     * @return string
     */
    protected function valuetext(?array $s): string {
        if (!$s) {
            return '0';
        }
        return $this->metric === 'rate' ? fmt::percent($this->value($s)) : fmt::int($this->value($s));
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $users = $this->users();
        $states = $this->by_state();
        $total = count($users);
        $unknown = $states['']['students'] ?? 0;
        $known = $total - $unknown;
        $declared = 0;
        $sources = [];
        $cities = [];
        foreach ($users as $u) {
            $src = $u['uf'] === '' ? 'none' : ($u['source'] ?: 'none');
            $sources[$src] = ($sources[$src] ?? 0) + 1;
            $declared += $src === 'declared' ? 1 : 0;
            if ($u['city'] !== '' && $u['uf'] !== '') {
                $cities[br::normalise($u['city']) . '|' . $u['uf']] = true;
            }
        }
        $kpis = [
            $this->kpi(
                'map_coverage',
                $total ? fmt::pct($known, $total) : '—',
                null,
                null,
                '',
                false,
                $s('map_coverage_note', ['known' => fmt::int($known), 'total' => fmt::int($total)])
            ),
            $this->kpi(
                'map_declared',
                $total ? fmt::pct($declared, $total) : '—',
                null,
                null,
                '',
                false,
                $s('map_declared_note', fmt::int($declared))
            ),
            $this->kpi(
                'map_inferred',
                $total ? fmt::pct($known - $declared, $total) : '—',
                null,
                null,
                '',
                false,
                $s('map_inferred_note', fmt::int($known - $declared))
            ),
            $this->kpi('map_cities', fmt::int(count($cities))),
        ];

        // Choropleth over the IBGE mesh.
        $max = 0.0;
        foreach (array_keys(brmap::PATHS) as $uf) {
            if (isset($states[$uf])) {
                $max = max($max, $this->value($states[$uf]));
            }
        }
        $shapes = [];
        $labels = [];
        foreach (brmap::PATHS as $uf => $d) {
            $st = $states[$uf] ?? null;
            $v = $st ? $this->value($st) : 0;
            $level = ($v > 0 && $max > 0) ? max(1, (int)ceil(sqrt($v / $max) * 5)) : 0;
            $lowconf = $st && $this->metric === 'rate' && fmt::lowconf($st['enrolments']);
            $value = $this->valuetext($st) . ($lowconf ? '*' : '');
            $shapes[] = [
                'uf' => $uf,
                'd' => $d,
                'level' => $level,
                'title' => br::state_name($uf) . ' (' . $uf . '): ' . $s('map_tile_title', [
                    'students' => fmt::int($st['students'] ?? 0),
                    'cert' => fmt::int($st['certstudents'] ?? 0),
                    'rate' => $st && $st['enrolments'] ? fmt::pct($st['certified'], $st['enrolments']) : '—',
                ]),
                'url' => $this->link('students', ['uf' => $uf])->out(false),
            ];
            [$x, $y] = brmap::LABELS[$uf];
            $callout = self::CALLOUTS[$uf] ?? null;
            $labels[] = [
                'uf' => $uf,
                'value' => $value,
                'x' => $callout ? $callout[0] : $x,
                'y' => $callout ? $callout[1] : $y,
                'iscallout' => (bool)$callout,
                'x1' => $x, 'y1' => $y,
                'x2' => $callout ? $callout[0] - 4 : $x, 'y2' => $callout ? $callout[1] - 4 : $y,
                'dark' => !$callout && $level >= 4,
                'vy' => ($callout ? $callout[1] : $y) + 13,
            ];
        }
        $metrics = [];
        foreach (self::METRICS as $m) {
            $metrics[] = ['id' => $m, 'label' => $s('map_metric_' . $m), 'active' => $m === $this->metric,
                'url' => $this->url(['metric' => $m])->out(false)];
        }
        $legend = [];
        for ($i = 0; $i <= 5; $i++) {
            $legend[] = ['level' => $i];
        }

        // UF source bars.
        $items = [];
        foreach (['declared', 'city', 'inep', 'phone', 'none'] as $src) {
            $items[] = ['label' => $s('ufsource_' . $src), 'value' => (int)($sources[$src] ?? 0), 'muted' => $src === 'none',
                'url' => $src === 'none' ? $this->link('students', ['uf' => 'NONE'])->out(false) : ''];
        }

        return $this->render('map', [
            'kpis' => $kpis,
            'metrics' => $metrics,
            'shapes' => $shapes,
            'labels' => $labels,
            'width' => self::VIEWWIDTH,
            'height' => brmap::HEIGHT,
            'legend' => $legend,
            'maxtext' => $this->metric === 'rate' ? fmt::percent($max) : fmt::int($max),
            'metriclabel' => $s('map_metric_' . $this->metric),
            'ratenote' => $this->metric === 'rate',
            'sources' => charts::bars($items, $total, 'blue'),
            'unknownnote' => $s('map_unknown_note', fmt::int($unknown)),
            'states' => $this->get_dataset('states')->export_for_template(),
            'regions' => $this->get_dataset('regions')->export_for_template(),
            'cities' => $this->get_dataset('cities')->export_for_template(),
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
        $states = $this->by_state();
        if ($id === 'states') {
            $ds = new dataset('states', $s('map_bystate'), [
                'uf' => $s('col_uf'), 'state' => $s('col_state'), 'region' => $s('col_region'), 'students' => $s('col_students'),
                'declared' => $s('col_ufdeclared'), 'inferred' => $s('col_ufinferred'), 'cities' => $s('col_cities'),
                'enrolments' => $s('col_enrolments'), 'certified' => $s('col_certified'), 'rate' => $s('col_certrate'),
            ], ['students', 'declared', 'inferred', 'cities', 'enrolments', 'certified', 'rate']);
            uasort($states, fn($a, $b) => $b['students'] <=> $a['students']);
            foreach ($states as $uf => $st) {
                $label = $uf === '' ? $s('uf_none') : $uf;
                $rate = $st['enrolments'] ? fmt::pct($st['certified'], $st['enrolments']) : '—';
                $low = fmt::lowconf($st['enrolments']);
                $ds->add([
                    'uf' => $label, 'state' => $uf === '' ? '' : br::state_name($uf),
                    'region' => $uf === '' ? '' : $s('region_' . br::region($uf)), 'students' => $st['students'],
                    'declared' => $st['declared'], 'inferred' => $st['inferred'], 'cities' => count($st['cities']),
                    'enrolments' => $st['enrolments'], 'certified' => $st['certified'], 'rate' => $rate,
                    '_html' => [
                        'uf' => \html_writer::link($this->link('students', ['uf' => $uf === '' ? 'NONE' : $uf]), s($label)),
                        'rate' => s($rate) . ($low ? ' ' . \html_writer::span(
                            '*',
                            'cea-inferred',
                            ['title' => $s('lowconf_help')]
                        ) : ''),
                    ],
                    '_class' => $uf === '' ? 'cea-row-muted' : '',
                ]);
            }
            return $ds;
        }
        if ($id === 'regions') {
            $ds = new dataset('regions', $s('map_byregion'), [
                'region' => $s('col_region'), 'states' => $s('col_states'), 'students' => $s('col_students'),
                'enrolments' => $s('col_enrolments'), 'certified' => $s('col_certified'), 'rate' => $s('col_certrate'),
            ], ['states', 'students', 'enrolments', 'certified', 'rate']);
            $agg = [];
            foreach ($states as $uf => $st) {
                $r = $uf === '' ? '' : br::region($uf);
                $agg[$r] = $agg[$r] ?? ['states' => 0, 'students' => 0, 'enrolments' => 0, 'certified' => 0];
                $agg[$r]['states'] += $uf === '' ? 0 : 1;
                foreach (['students', 'enrolments', 'certified'] as $k) {
                    $agg[$r][$k] += $st[$k];
                }
            }
            foreach (array_merge(self::REGIONS, ['']) as $r) {
                if (!isset($agg[$r]) && $r === '') {
                    continue;
                }
                $a = $agg[$r] ?? ['states' => 0, 'students' => 0, 'enrolments' => 0, 'certified' => 0];
                $ds->add([
                    'region' => $r === '' ? $s('uf_none') : $s('region_' . $r), 'states' => $a['states'],
                    'students' => $a['students'], 'enrolments' => $a['enrolments'], 'certified' => $a['certified'],
                    'rate' => $a['enrolments'] ? fmt::pct($a['certified'], $a['enrolments']) : '—',
                    '_class' => $r === '' ? 'cea-row-muted' : '',
                ]);
            }
            return $ds;
        }
        if ($id === 'cities') {
            $ds = new dataset('cities', $s('map_bycity'), [
                'city' => $s('col_city'), 'uf' => $s('col_uf'), 'students' => $s('col_students'),
                'certified' => $s('col_certstudents'),
            ], ['students', 'certified']);
            $agg = [];
            foreach ($this->users() as $u) {
                if ($u['city'] === '') {
                    continue;
                }
                $k = br::normalise($u['city']) . '|' . $u['uf'];
                $agg[$k] = $agg[$k] ?? ['city' => $u['city'], 'uf' => $u['uf'], 'students' => 0, 'certified' => 0];
                $agg[$k]['students']++;
                $agg[$k]['certified'] += $u['certified'] ? 1 : 0;
            }
            uasort($agg, fn($a, $b) => $b['students'] <=> $a['students']);
            foreach ($agg as $a) {
                $ds->add(['city' => $a['city'], 'uf' => $a['uf'] ?: '—', 'students' => $a['students'],
                    'certified' => $a['certified']]);
            }
            $ds->emptytext = $s('map_nocities');
            return $ds;
        }
        return null;
    }
}
