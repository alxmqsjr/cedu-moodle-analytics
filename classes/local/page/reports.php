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
use local_ceduanalytics\local\navigation;
use local_ceduanalytics\local\scope;

/**
 * Report centre: catalogue of every exportable list of the dashboard, and the printable executive bulletin.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reports extends base {
    /** @var bool */
    protected $bulletin;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $this->bulletin = optional_param('view', '', PARAM_ALPHA) === 'bulletin';
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        return $this->bulletin ? ['view' => 'bulletin'] : [];
    }

    /**
     * Title.
     *
     * @return string
     */
    public function title(): string {
        return $this->bulletin ? get_string('rep_bulletin', 'local_ceduanalytics') : parent::title();
    }

    /**
     * Question the page answers.
     *
     * @return string
     */
    public function question(): string {
        return $this->bulletin ? get_string('rep_bulletin_q', 'local_ceduanalytics') : parent::question();
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return $this->bulletin ? ['bcourses' => get_string('rep_b_courses', 'local_ceduanalytics')]
            : ['catalogue' => get_string('rep_catalogue', 'local_ceduanalytics')];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        return $this->bulletin ? $this->bulletin_content() : $this->catalogue_content();
    }

    /**
     * Catalogue entries: every dataset of every page.
     *
     * @return array[] list of ['group', 'page', 'pageid', 'id', 'label', 'url', 'csv', 'xlsx']
     */
    protected function entries(): array {
        $out = [];
        $context = \context_system::instance();
        foreach (navigation::pages() as $id => $def) {
            if (
                $id === $this->id() || !empty($def['hidden']) || empty($def['class'])
                    || (!empty($def['capability']) && !has_capability($def['capability'], $context))
            ) {
                continue;
            }
            $page = base::create(['id' => $id] + $def, $this->filters);
            $lists = $page->all_datasets();
            if (!$lists) {
                continue;
            }
            // The filters each page uses, so the spreadsheet matches what the page shows.
            $params = array_intersect_key($this->filters->params(), array_flip(array_merge(
                $def['filters'] ?? [],
                in_array('period', $def['filters'] ?? []) ? ['from', 'to'] : []
            )));
            $out[] = [
                'group' => get_string('group_' . $def['group'], 'local_ceduanalytics'),
                'page' => get_string('page_' . $id, 'local_ceduanalytics'),
                'pageid' => $id,
                'icon' => $def['icon'],
                'url' => navigation::url($id, $this->filters->params())->out(false),
                'lists' => array_map(fn($l) => ['label' => $l], array_values($lists)),
                'count' => count($lists),
                'downloadlabel' => get_string(
                    count($lists) === 1 ? 'rep_download_one' : 'rep_download',
                    'local_ceduanalytics',
                    count($lists)
                ),
                'xlsx' => (new \moodle_url('/local/ceduanalytics/export.php', ['p' => $id, 'ds' => 'all', 'format' => 'excel']
                    + $params))->out(false),
            ];
        }
        return $out;
    }

    /**
     * Catalogue view.
     *
     * @return string
     */
    protected function catalogue_content(): string {
        $entries = $this->entries();
        return $this->render('reports', [
            'bulletinurl' => $this->url(['view' => 'bulletin'])->out(false),
            'periodlabel' => $this->filters->period_label(),
            'pages' => $entries,
            'count' => array_sum(array_column($entries, 'count')),
            'spreadsheets' => count($entries),
            'canexport' => has_capability('local/ceduanalytics:export', \context_system::instance()),
        ]);
    }

    /**
     * Executive bulletin: one printable page with the summary of the period.
     *
     * @return string
     */
    protected function bulletin_content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $scope = new scope($this->filters);
        $prev = $scope->previous();
        $sum = indicators::summary($scope);
        $p = $prev ? indicators::summary($prev) : null;
        $num = function (string $key, $value, $prevvalue, bool $pct = false, bool $higherisbetter = true) use ($s) {
            $text = $value === null ? '—' : ($pct ? fmt::percent($value) : fmt::int($value));
            $delta = ($prevvalue !== null && $value !== null) ? fmt::delta(
                (float)$value,
                (float)$prevvalue,
                $higherisbetter,
                $pct
            ) : null;
            return ['label' => $s('kpi_' . $key), 'value' => $text, 'delta' => $delta, 'hasdelta' => (bool)$delta,
                'prev' => $prevvalue === null ? '' : ($pct ? fmt::percent($prevvalue) : fmt::int($prevvalue))];
        };
        $numbers = [
            $num('students', $sum['students'], $p['students'] ?? null),
            $num('active', $sum['active'], $p['active'] ?? null),
            $num('newenrol', $sum['newenrol'], $p['newenrol'] ?? null),
            $num('certified', $sum['certified'], $p['certified'] ?? null),
            $num('certrate', $sum['certrate'], $p['certrate'] ?? null, true),
            $num('notstarted', $sum['situations']['notstarted'], $p['situations']['notstarted'] ?? null, false, false),
        ];
        $timeline = indicators::timeline($scope);
        $chart = array_sum($timeline['active']) + array_sum($timeline['enrolments']) > 0 ? charts::line($timeline['labels'], [
            $s('series_active') => $timeline['active'],
            $s('series_newenrol') => $timeline['enrolments'],
        ], false, [charts::GREEN, charts::ORANGE]) : '';

        // Reach.
        $ufs = [];
        $segs = [];
        $users = [];
        foreach ($scope->enrolments('e.id, e.userid, ua.uf, ua.segment') as $r) {
            if (isset($users[$r->userid])) {
                continue;
            }
            $users[$r->userid] = true;
            $uf = $r->uf ?: '';
            $ufs[$uf] = ($ufs[$uf] ?? 0) + 1;
            $seg = $r->segment ?: 'notinformed';
            $segs[$seg] = ($segs[$seg] ?? 0) + 1;
        }
        $unknown = $ufs[''] ?? 0;
        unset($ufs['']);
        arsort($ufs);
        arsort($segs);
        $ufitems = [];
        foreach (array_slice($ufs, 0, 8, true) as $uf => $n) {
            $ufitems[] = ['label' => $uf . ' — ' . br::state_name($uf), 'value' => $n];
        }
        $segitems = [];
        foreach ($segs as $seg => $n) {
            $segitems[] = ['label' => $s('segment_' . $seg), 'value' => $n, 'muted' => in_array($seg, ['other', 'notinformed'])];
        }

        $active = $this->filters->export_for_template(navigation::page('home')['filters'], 'home');

        return $this->render('bulletin', [
            'sitename' => fmt::text($GLOBALS['SITE']->fullname),
            'logourl' => local_ceduanalytics_get_logo_url(),
            'periodlabel' => $this->filters->period_label(),
            'compare' => $prev ? $s('home_compare', ['from' => fmt::date($prev->from), 'to' => fmt::date($prev->to - 1)])
                : $s('home_nocompare'),
            'filters' => $active['active'] ?? [],
            'hasfilters' => !empty($active['active']),
            'generated' => $s('rep_b_generated', ['date' => fmt::datetime(time()), 'user' => fullname($GLOBALS['USER'])]),
            'numbers' => $numbers,
            'chart' => $chart,
            'haschart' => $chart !== '',
            'ufs' => charts::bars($ufitems, count($users), 'blue'),
            'ufnote' => $s('rep_b_ufnote', ['states' => fmt::int(count($ufs)), 'unknown' => fmt::int($unknown)]),
            'segments' => charts::bars($segitems, count($users), 'green'),
            'courses' => $this->get_dataset('bcourses')->export_for_template(),
            'backurl' => $this->url(['view' => ''])->out(false),
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
        if ($id === 'catalogue') {
            $ds = new dataset('catalogue', $s('rep_catalogue'), ['group' => $s('col_menugroup'), 'page' => $s('col_page'),
                'list' => $s('col_list'), 'xlsx' => $s('col_spreadsheet')]);
            foreach ($this->entries() as $e) {
                foreach ($e['lists'] as $l) {
                    $ds->add(['group' => $e['group'], 'page' => $e['page'], 'list' => $l['label'], 'xlsx' => $e['xlsx']]);
                }
            }
            return $ds;
        }
        if ($id === 'bcourses') {
            $scope = new scope($this->filters);
            $agg = [];
            foreach ($scope->enrolments('e.id, e.courseid, e.timecertified, e.situation, c.fullname') as $r) {
                $a = &$agg[$r->courseid];
                $a = $a ?? ['name' => fmt::text($r->fullname), 'n' => 0, 'cert' => 0, 'notstarted' => 0];
                $a['n']++;
                $a['cert'] += $r->timecertified ? 1 : 0;
                $a['notstarted'] += $r->situation === 'notstarted' ? 1 : 0;
                unset($a);
            }
            uasort($agg, fn($a, $b) => $b['n'] <=> $a['n']);
            $ds = new dataset(
                'bcourses',
                $s('rep_b_courses'),
                ['course' => $s('col_course'), 'enrolments' => $s('col_enrolments'),
                'certified' => $s('col_certified'), 'rate' => $s('col_certrate'), 'notstarted' => $s('col_notstarted_pct')],
                ['enrolments', 'certified', 'rate', 'notstarted']
            );
            foreach ($agg as $a) {
                $ds->add(['course' => $a['name'], 'enrolments' => $a['n'], 'certified' => $a['cert'],
                    'rate' => fmt::pct($a['cert'], $a['n']), 'notstarted' => fmt::pct($a['notstarted'], $a['n'])]);
            }
            $ds->emptytext = $s('empty_students');
            return $ds;
        }
        return null;
    }
}
