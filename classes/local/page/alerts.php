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

use local_ceduanalytics\local\alerts as registry;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;

/**
 * Platform alerts: every problem found, by category and severity.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class alerts extends base {
    /** @var string */
    protected $category;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $category = optional_param('category', '', PARAM_ALPHA);
        $this->category = in_array($category, registry::CATEGORIES) ? $category : '';
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        return array_filter(['category' => $this->category]);
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return ['alerts' => get_string('al_alerts', 'local_ceduanalytics')];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $all = registry::all();
        $counts = array_count_values(array_column($all, 'severity'));
        $kpis = [];
        foreach (['critical', 'warning', 'info'] as $sev) {
            $kpis[] = $this->kpi('al_' . $sev, fmt::int((int)($counts[$sev] ?? 0)));
        }
        $chips = [['label' => $s('all_female'), 'count' => fmt::int(count($all)), 'active' => $this->category === '',
            'url' => $this->url(['category' => ''])->out(false), 'key' => 'all']];
        $groups = [];
        foreach (registry::CATEGORIES as $cat) {
            $items = array_values(array_filter($all, fn($a) => $a['category'] === $cat));
            $chips[] = ['label' => $s('al_cat_' . $cat), 'count' => fmt::int(count($items)), 'key' => $cat,
                'active' => $this->category === $cat, 'url' => $this->url(['category' => $cat])->out(false)];
            if ($this->category !== '' && $this->category !== $cat) {
                continue;
            }
            foreach ($items as &$it) {
                $it['icon'] = ['critical' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation',
                    'info' => 'fa-circle-info', 'ok' => 'fa-circle-check'][$it['severity']];
                $it['severitylabel'] = $s('severity_' . $it['severity']);
            }
            unset($it);
            $groups[] = ['name' => $s('al_cat_' . $cat), 'help' => $s('al_cat_' . $cat . '_help'), 'items' => $items,
                'hasitems' => !empty($items)];
        }
        return $this->render('alerts', ['kpis' => $kpis, 'chips' => $chips, 'groups' => $groups]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        if ($id !== 'alerts') {
            return null;
        }
        $ds = new dataset('alerts', $s('al_alerts'), ['severity' => $s('col_severity'), 'category' => $s('col_category'),
            'title' => $s('col_alert'), 'text' => $s('col_detail'), 'fix' => $s('col_where')]);
        foreach (registry::all() as $a) {
            $ds->add(['severity' => $s('severity_' . $a['severity']), 'category' => $s('al_cat_' . $a['category']),
                'title' => $a['title'], 'text' => $a['text'], 'fix' => $a['fixurl'] ?: $a['pageurl']]);
        }
        return $ds;
    }
}
