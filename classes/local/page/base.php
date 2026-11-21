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

use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\filters;
use local_ceduanalytics\local\navigation;

/**
 * Base class of a dashboard page.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /** @var array Page definition from navigation::pages() */
    protected $def;
    /** @var filters */
    protected $filters;

    /**
     * Constructor.
     *
     * @param array $def
     * @param filters $filters
     */
    public function __construct(array $def, filters $filters) {
        $this->def = $def;
        $this->filters = $filters;
    }

    /**
     * Create the page object for a definition.
     *
     * @param array $def
     * @param filters $filters
     * @return base
     */
    public static function create(array $def, filters $filters): base {
        $class = $def['class'] ?? placeholder::class;
        return new $class($def, $filters);
    }

    /**
     * Page id.
     *
     * @return string
     */
    public function id(): string {
        return $this->def['id'];
    }

    /**
     * Title.
     *
     * @return string
     */
    public function title(): string {
        return get_string('page_' . $this->id(), 'local_ceduanalytics');
    }

    /**
     * Question the page answers.
     *
     * @return string
     */
    public function question(): string {
        return get_string('page_' . $this->id() . '_q', 'local_ceduanalytics');
    }

    /**
     * Group label (first breadcrumb).
     *
     * @return string
     */
    public function group(): string {
        return get_string('group_' . $this->def['group'], 'local_ceduanalytics');
    }

    /**
     * Capability required (beyond local/ceduanalytics:view).
     *
     * @return string|null
     */
    public function capability(): ?string {
        return $this->def['capability'] ?? null;
    }

    /**
     * Filters the page uses.
     *
     * @return string[]
     */
    public function filter_keys(): array {
        return $this->def['filters'] ?? [];
    }

    /**
     * Extra URL parameters that identify the page state (e.g. tab), kept in export links.
     *
     * @return array
     */
    public function state_params(): array {
        return [];
    }

    /**
     * URL of this page with the current filters and state.
     *
     * @param array $extra
     * @return \moodle_url
     */
    public function url(array $extra = []): \moodle_url {
        // The requested values win over the current state (e.g. the "Upcoming" tab while showing "Past").
        return navigation::url($this->id(), $extra + $this->state_params() + $this->filters->params());
    }

    /**
     * Exportable datasets of the current view.
     *
     * @return array id => label
     */
    public function datasets(): array {
        return [];
    }

    /**
     * Build a dataset.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        return null;
    }

    /**
     * Additional data blocks shown at the end of the page (see local\extras).
     *
     * @return string[]
     */
    public function extra_blocks(): array {
        return \local_ceduanalytics\local\extras::blocks($this->id());
    }

    /**
     * Every exportable dataset: the page lists and its additional blocks.
     *
     * @return array id => label
     */
    public function all_datasets(): array {
        $out = $this->datasets();
        foreach ($this->extra_blocks() as $id) {
            $out[$id] = get_string('x_' . $id, 'local_ceduanalytics');
        }
        return $out;
    }

    /**
     * A dataset of the page or one of its additional blocks.
     *
     * @param string $id
     * @return dataset|null
     */
    public function find_dataset(string $id): ?dataset {
        if (in_array($id, $this->extra_blocks())) {
            return \local_ceduanalytics\local\extras::dataset($id, $this->filters);
        }
        return $this->get_dataset($id);
    }

    /**
     * HTML of the additional blocks (one card with a table each).
     *
     * @return string
     */
    public function extras_html(): string {
        $html = '';
        foreach ($this->extra_blocks() as $id) {
            $html .= $this->before_extra($id);
            $ds = \local_ceduanalytics\local\extras::dataset($id, $this->filters);
            if (!$ds) {
                continue;
            }
            $html .= $this->render('extra', [
                'id' => $id,
                'title' => $ds->title,
                'help' => get_string('x_' . $id . '_help', 'local_ceduanalytics'),
                'table' => $ds->export_for_template(),
            ]);
        }
        return $html;
    }

    /**
     * Page block placed right before an extra block (none by default).
     *
     * @param string $id Extra block id
     * @return string HTML
     */
    protected function before_extra(string $id): string {
        return '';
    }

    /**
     * Handle POST actions (called before any output). May redirect.
     */
    public function process_actions(): void {
    }

    /**
     * Page body HTML.
     *
     * @return string
     */
    abstract public function content(): string;

    /**
     * Hidden pages (detail pages reached by link or search) are not in the menu.
     *
     * @return bool
     */
    public function is_hidden(): bool {
        return !empty($this->def['hidden']);
    }

    /**
     * KPI tile context (templates/kpis.mustache).
     *
     * Strings: kpi_<key> (label) and kpi_<key>_help (what it measures).
     *
     * @param string $key
     * @param string $value Formatted value
     * @param array|null $delta From fmt::delta()
     * @param \moodle_url|null $url Where the number leads (the list behind it)
     * @param string $spark Sparkline SVG
     * @param bool $lowconf Base below the minimum number of cases
     * @param string $note Small text under the value
     * @return array
     */
    protected function kpi(
        string $key,
        string $value,
        ?array $delta = null,
        ?\moodle_url $url = null,
        string $spark = '',
        bool $lowconf = false,
        string $note = ''
    ): array {
        return [
            'key' => $key,
            'label' => get_string('kpi_' . $key, 'local_ceduanalytics'),
            'help' => get_string('kpi_' . $key . '_help', 'local_ceduanalytics'),
            'value' => $value,
            'delta' => $delta,
            'hasdelta' => !empty($delta),
            'url' => $url ? $url->out(false) : '',
            'spark' => $spark,
            'lowconf' => $lowconf,
            'note' => $note,
        ];
    }

    /**
     * URL of another page keeping the current filters.
     *
     * @param string $pageid
     * @param array $extra
     * @return \moodle_url
     */
    protected function link(string $pageid, array $extra = []): \moodle_url {
        return navigation::url($pageid, $extra + $this->filters->params());
    }

    /**
     * Render a template of the plugin.
     *
     * @param string $template
     * @param array $context
     * @return string
     */
    protected function render(string $template, array $context): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_ceduanalytics/' . $template, $context);
    }

    /**
     * Empty state HTML.
     *
     * @param string $icon
     * @param string $title
     * @param string $text
     * @return string
     */
    protected function empty_state(string $icon, string $title, string $text): string {
        return $this->render('empty_state', ['icon' => $icon, 'title' => $title, 'text' => $text]);
    }
}
