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

use local_ceduanalytics\local\aggregator;
use local_ceduanalytics\local\attributes;
use local_ceduanalytics\local\br;
use local_ceduanalytics\local\courses;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\snapshot;

/**
 * Dashboard configuration: courses and course lines, audience segments, data processing.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config extends base {
    /** @var string[] Tabs. */
    const TABS = ['courses', 'segments', 'data'];

    /** @var string Current tab. */
    protected $tab;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $tab = optional_param('tab', 'courses', PARAM_ALPHA);
        $this->tab = in_array($tab, self::TABS) ? $tab : 'courses';
    }

    /**
     * Keep the tab in links.
     *
     * @return array
     */
    public function state_params(): array {
        return ['tab' => $this->tab];
    }

    /**
     * Exportable datasets of the current tab.
     *
     * @return array
     */
    public function datasets(): array {
        switch ($this->tab) {
            case 'courses':
                return ['courses' => get_string('cfg_courses_table', 'local_ceduanalytics')];
            case 'segments':
                return [
                    'segments' => get_string('cfg_segments_distribution', 'local_ceduanalytics'),
                    'unclassified' => get_string('cfg_segments_unclassified', 'local_ceduanalytics'),
                ];
            default:
                return ['coverage' => get_string('cfg_data_coverage', 'local_ceduanalytics')];
        }
    }

    /**
     * Handle the forms.
     */
    public function process_actions(): void {
        $action = optional_param('action', '', PARAM_ALPHA);
        if ($action === '' || !confirm_sesskey()) {
            return;
        }
        $message = '';
        switch ($action) {
            case 'savelines':
                $this->save_lines();
                $message = get_string('cfg_saved', 'local_ceduanalytics');
                break;
            case 'saverules':
                $this->save_rules();
                attributes::derive();
                $message = get_string('cfg_rules_saved', 'local_ceduanalytics');
                break;
            case 'process':
                $this->process_now();
                $message = get_string('cfg_process_done', 'local_ceduanalytics');
                break;
            case 'backfill':
                \core\task\manager::queue_adhoc_task(new \local_ceduanalytics\task\backfill(), true);
                $message = get_string('cfg_backfill_queued', 'local_ceduanalytics');
                break;
        }
        redirect($this->url(), $message, null, \core\output\notification::NOTIFY_SUCCESS);
    }

    /**
     * Save course lines and course assignments.
     */
    protected function save_lines(): void {
        global $DB;
        $now = time();
        $names = optional_param_array('linename', [], PARAM_TEXT);
        $kinds = optional_param_array('linekind', [], PARAM_ALPHA);
        foreach ($names as $id => $name) {
            $name = trim($name);
            if ($name === '' || !$DB->record_exists('local_ceduanalytics_courseline', ['id' => $id])) {
                continue;
            }
            $kind = in_array($kinds[$id] ?? '', courses::KINDS) ? $kinds[$id] : 'standalone';
            $DB->update_record('local_ceduanalytics_courseline', (object)['id' => $id, 'name' => $name, 'kind' => $kind,
                'timemodified' => $now]);
        }
        $newname = trim(optional_param('newline', '', PARAM_TEXT));
        if ($newname !== '') {
            $DB->insert_record('local_ceduanalytics_courseline', (object)['name' => $newname,
                'kind' => optional_param('newlinekind', 'standalone', PARAM_ALPHA), 'sortorder' => 999,
                'timecreated' => $now, 'timemodified' => $now]);
        }
        $lines = optional_param_array('courseline', [], PARAM_INT);
        $editions = optional_param_array('courseedition', [], PARAM_TEXT);
        $current = $DB->get_records('local_ceduanalytics_linecourse', null, '', 'courseid, id, lineid, edition');
        foreach ($lines as $courseid => $lineid) {
            $edition = \core_text::substr(trim($editions[$courseid] ?? ''), 0, 50);
            $rec = $current[$courseid] ?? null;
            if ($rec && (int)$rec->lineid === $lineid && $rec->edition === $edition) {
                continue;
            }
            $row = (object)['lineid' => $lineid, 'courseid' => $courseid, 'edition' => $edition, 'ismanual' => 1,
                'timemodified' => $now];
            if ($rec) {
                $row->id = $rec->id;
                $DB->update_record('local_ceduanalytics_linecourse', $row);
            } else if ($DB->record_exists('course', ['id' => $courseid])) {
                $DB->insert_record('local_ceduanalytics_linecourse', $row);
            }
        }
        courses::delete_empty_lines();
        \cache::make('local_ceduanalytics', 'indicators')->purge();
    }

    /**
     * Save segment rules.
     */
    protected function save_rules(): void {
        global $DB;
        $patterns = optional_param_array('rulepattern', [], PARAM_TEXT);
        $segments = optional_param_array('rulesegment', [], PARAM_ALPHA);
        $orders = optional_param_array('ruleorder', [], PARAM_INT);
        $deletes = optional_param_array('ruledelete', [], PARAM_BOOL);
        foreach ($patterns as $id => $pattern) {
            if (!$DB->record_exists('local_ceduanalytics_segrule', ['id' => $id])) {
                continue;
            }
            if (!empty($deletes[$id]) || trim($pattern) === '') {
                $DB->delete_records('local_ceduanalytics_segrule', ['id' => $id]);
                continue;
            }
            $segment = in_array($segments[$id] ?? '', attributes::SEGMENTS) ? $segments[$id] : 'other';
            $DB->update_record('local_ceduanalytics_segrule', (object)['id' => $id, 'pattern' => trim($pattern),
                'segment' => $segment, 'sortorder' => (int)($orders[$id] ?? 0)]);
        }
        $new = trim(optional_param('newpattern', '', PARAM_TEXT));
        $newsegment = optional_param('newsegment', '', PARAM_ALPHA);
        if ($new !== '' && in_array($newsegment, attributes::SEGMENTS)) {
            $DB->insert_record('local_ceduanalytics_segrule', (object)['pattern' => $new, 'segment' => $newsegment,
                'sortorder' => optional_param('neworder', 1000, PARAM_INT)]);
        }
    }

    /**
     * Process pending data now (small amounts only; larger loads are queued).
     */
    protected function process_now(): void {
        \core_php_time_limit::raise(300);
        if (aggregator::pending() > 100000) {
            \core\task\manager::queue_adhoc_task(new \local_ceduanalytics\task\backfill(), true);
            return;
        }
        (new aggregator())->run(100000);
        attributes::derive();
        courses::apply_suggestions();
        snapshot::build();
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        global $OUTPUT;
        $tabs = [];
        foreach (self::TABS as $t) {
            $tabs[] = ['label' => get_string('cfg_tab_' . $t, 'local_ceduanalytics'),
                'url' => \local_ceduanalytics\local\navigation::url('config', ['tab' => $t])->out(false),
                'active' => $t === $this->tab];
        }
        $method = 'content_' . $this->tab;
        return $OUTPUT->render_from_template('local_ceduanalytics/tabs', ['tabs' => $tabs]) . $this->$method();
    }

    /**
     * Label of a course type.
     *
     * @param string $type
     * @return string
     */
    protected static function type_badge(string $type): string {
        return \html_writer::span(get_string('coursetype_' . $type, 'local_ceduanalytics'), 'cea-badge cea-type-' . $type);
    }

    /**
     * Course lines with their editions (cards shown on the Editions page).
     *
     * @param int $lineid Only this line (0 = all)
     * @return array
     */
    public static function line_cards(int $lineid = 0): array {
        $all = courses::get_all();
        $lines = courses::get_lines();
        if ($lineid) {
            $lines = array_intersect_key($lines, [$lineid => true]);
        }
        $linecards = [];
        foreach ($lines as $l) {
            $editions = [];
            foreach ($all as $c) {
                if ((int)$c->lineid === (int)$l->id) {
                    $editions[] = [
                        'edition' => $c->edition !== '' ? $c->edition : '—',
                        'name' => fmt::text($c->fullname),
                        'url' => (new \moodle_url('/course/view.php', ['id' => $c->id]))->out(false),
                        'students' => $c->students,
                        'certified' => $c->certified,
                        'visible' => (bool)$c->visible,
                        'startdate' => $c->startdate ? userdate($c->startdate, get_string(
                            'strftimedatefullshort',
                            'core_langconfig'
                        )) : '',
                    ];
                }
            }
            usort($editions, fn($a, $b) => strnatcmp($a['edition'], $b['edition']));
            $linecards[] = ['name' => fmt::text($l->name), 'kind' => get_string('linekind_' . $l->kind, 'local_ceduanalytics'),
                'kindclass' => $l->kind, 'editions' => $editions, 'count' => count($editions)];
        }
        return $linecards;
    }

    /**
     * Courses tab.
     *
     * @return string
     */
    protected function content_courses(): string {
        global $OUTPUT;
        $all = courses::get_all();
        $lines = courses::get_lines();

        $bytype = array_fill_keys(['track', 'standalone', 'model', 'empty'], 0);
        $withcriteria = 0;
        foreach ($all as $c) {
            $bytype[$c->type]++;
            $withcriteria += $c->hascriteria ? 1 : 0;
        }
        $stats = [['value' => count($all), 'label' => get_string('cfg_courses_total', 'local_ceduanalytics')]];
        foreach ($bytype as $type => $n) {
            $stats[] = ['value' => $n, 'label' => get_string('coursetype_' . $type . '_plural', 'local_ceduanalytics'),
                'type' => $type];
        }

        // Edit form rows.
        $lineoptions = [['value' => 0, 'label' => get_string('cfg_noline', 'local_ceduanalytics')]];
        foreach ($lines as $l) {
            $lineoptions[] = ['value' => $l->id, 'label' => fmt::text($l->name)];
        }
        $courserows = [];
        foreach ($all as $c) {
            $opts = array_map(fn($o) => $o + ['selected' => (int)$o['value'] === (int)$c->lineid], $lineoptions);
            $courserows[] = ['id' => $c->id, 'name' => fmt::text($c->fullname), 'typebadge' => self::type_badge($c->type),
                'options' => $opts, 'edition' => $c->edition, 'manual' => !empty($c->ismanual)];
        }
        $lineedit = [];
        foreach ($lines as $l) {
            $kinds = [];
            foreach (courses::KINDS as $k) {
                $kinds[] = ['value' => $k, 'label' => get_string('linekind_' . $k, 'local_ceduanalytics'),
                    'selected' => $l->kind === $k];
            }
            $lineedit[] = ['id' => $l->id, 'name' => $l->name, 'kinds' => $kinds, 'courses' => $l->courses];
        }
        $newkinds = [];
        foreach (courses::KINDS as $k) {
            $newkinds[] = ['value' => $k, 'label' => get_string('linekind_' . $k, 'local_ceduanalytics')];
        }

        return $OUTPUT->render_from_template('local_ceduanalytics/config_courses', [
            'nocriteria' => $withcriteria === 0,
            'criteriacount' => $withcriteria,
            'stats' => $stats,

            'table' => $this->get_dataset('courses')->export_for_template(),
            'formaction' => $this->url()->out(false),
            'sesskey' => sesskey(),
            'courserows' => $courserows,
            'lineedit' => $lineedit,
            'newkinds' => $newkinds,
        ]);
    }

    /**
     * Segments tab.
     *
     * @return string
     */
    protected function content_segments(): string {
        global $OUTPUT, $DB;
        $rules = $DB->get_records('local_ceduanalytics_segrule', null, 'sortorder, id');
        $segmentoptions = function ($selected) {
            $out = [];
            foreach (attributes::SEGMENTS as $s) {
                if ($s === 'notinformed') {
                    continue;
                }
                $out[] = ['value' => $s, 'label' => get_string('segment_' . $s, 'local_ceduanalytics'),
                    'selected' => $s === $selected];
            }
            return $out;
        };
        $rulerows = [];
        foreach ($rules as $r) {
            $rulerows[] = ['id' => $r->id, 'pattern' => $r->pattern, 'order' => $r->sortorder,
                'options' => $segmentoptions($r->segment)];
        }
        $dist = $this->get_dataset('segments');
        $unclassified = $this->get_dataset('unclassified');
        return $OUTPUT->render_from_template('local_ceduanalytics/config_segments', [
            'distribution' => $dist->export_for_template(),
            'unclassified' => $unclassified->export_for_template(),
            'rules' => $rulerows,
            'newoptions' => $segmentoptions(''),
            'formaction' => $this->url()->out(false),
            'sesskey' => sesskey(),
            'lastrun' => ($t = get_config('local_ceduanalytics', 'lastattributes')) ? userdate($t) : get_string('never'),
        ]);
    }

    /**
     * Data processing tab.
     *
     * @return string
     */
    protected function content_data(): string {
        global $OUTPUT, $DB;
        $maxid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}');
        $logtotal = $DB->count_records('logstore_standard_log');
        $pending = aggregator::pending();
        $fmt = fn($key) => ($t = get_config('local_ceduanalytics', $key)) ? userdate($t) : get_string('never');
        $range = $DB->get_record_sql('SELECT MIN(day) AS mind, MAX(day) AS maxd FROM {local_ceduanalytics_daily}');
        $queued = $DB->count_records('task_adhoc', ['classname' => '\\local_ceduanalytics\\task\\backfill']);

        $tables = [];
        foreach (['daily', 'hourly', 'cmview', 'security', 'enrolstate', 'userattr', 'courseline', 'linecourse', 'segrule'] as $t) {
            $tables[] = ['name' => get_string('table_' . $t, 'local_ceduanalytics'), 'table' => 'local_ceduanalytics_' . $t,
                'rows' => number_format($DB->count_records('local_ceduanalytics_' . $t), 0, ',', '.')];
        }
        $tasks = [];
        foreach (['aggregate_log', 'build_snapshot', 'derive_attributes'] as $t) {
            $rec = $DB->get_record('task_scheduled', ['classname' => '\\local_ceduanalytics\\task\\' . $t]);
            $tasks[] = [
                'name' => get_string('task_' . str_replace('_', '', $t), 'local_ceduanalytics'),
                'last' => $rec && $rec->lastruntime ? userdate($rec->lastruntime) : get_string('never'),
                'next' => $rec && $rec->nextruntime ? userdate($rec->nextruntime) : '—',
                'failing' => $rec && $rec->faildelay > 0,
                'disabled' => $rec && $rec->disabled,
            ];
        }
        return $OUTPUT->render_from_template('local_ceduanalytics/config_data', [
            'stats' => [
                ['value' => number_format($logtotal, 0, ',', '.'), 'label' => get_string(
                    'cfg_data_logrows',
                    'local_ceduanalytics'
                )],
                ['value' => number_format($pending, 0, ',', '.'), 'label' => get_string('cfg_data_pending', 'local_ceduanalytics'),
                    'warn' => $pending > 0],
                ['value' => $range && $range->mind ? userdate($range->mind, get_string(
                    'strftimedatefullshort',
                    'core_langconfig'
                )) .
                    ' – ' . userdate($range->maxd, get_string('strftimedatefullshort', 'core_langconfig')) : '—',
                    'label' => get_string('cfg_data_period', 'local_ceduanalytics')],
            ],
            'watermark' => number_format((int)get_config('local_ceduanalytics', aggregator::WATERMARK), 0, ',', '.'),
            'maxid' => number_format($maxid, 0, ',', '.'),
            'lastaggregation' => $fmt('lastaggregation'),
            'lastsnapshot' => $fmt('lastsnapshot'),
            'lastattributes' => $fmt('lastattributes'),
            'lastbackfill' => $fmt('lastbackfill'),
            'queued' => $queued > 0,
            'tasks' => $tasks,
            'tables' => $tables,
            'coverage' => $this->get_dataset('coverage')->export_for_template(),
            'formaction' => $this->url()->out(false),
            'sesskey' => sesskey(),
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
        $datefmt = get_string('strftimedatefullshort', 'core_langconfig');
        switch ($id) {
            case 'courses':
                $ds = new dataset('courses', $s('cfg_courses_table'), [
                    'id' => 'ID', 'course' => $s('col_course'), 'shortname' => $s('col_shortname'),
                    'category' => $s('col_category'), 'visible' => $s('col_visible'), 'type' => $s('col_type'),
                    'line' => $s('col_line'), 'edition' => $s('col_edition'), 'start' => $s('col_start'), 'end' => $s('col_end'),
                    'enrolled' => $s('col_enrolled'), 'students' => $s('col_students'), 'notstarted' => $s('col_notstarted'),
                    'certified' => $s('col_certified'), 'completednocert' => $s('col_completednocert'),
                    'criteria' => $s('col_criteria'),
                ], ['id', 'enrolled', 'students', 'notstarted', 'certified', 'completednocert']);
                foreach (courses::get_all() as $c) {
                    $name = fmt::text($c->fullname);
                    $ds->add([
                        'id' => $c->id, 'course' => $name, 'shortname' => fmt::text($c->shortname),
                        'category' => fmt::text((string)$c->categoryname),
                        'visible' => $c->visible ? get_string('yes') : get_string('no'),
                        'type' => $s('coursetype_' . $c->type),
                        'line' => $c->linename !== null ? fmt::text($c->linename) : $s('cfg_noline'),
                        'edition' => (string)$c->edition,
                        'start' => $c->startdate ? userdate($c->startdate, $datefmt) : '',
                        'end' => $c->enddate ? userdate($c->enddate, $datefmt) : $s('noenddate'),
                        'enrolled' => $c->enrolled, 'students' => $c->students, 'notstarted' => $c->notstarted,
                        'certified' => $c->certified, 'completednocert' => $c->completednocert,
                        'criteria' => $c->hascriteria ? get_string('yes') : get_string('no'),
                        '_html' => [
                            'course' => \html_writer::link(new \moodle_url('/course/view.php', ['id' => $c->id]), s($name)),
                            'type' => self::type_badge($c->type),
                            'visible' => $c->visible ? $s('visible_yes') : \html_writer::span($s('visible_no'), 'cea-muted'),
                            'criteria' => $c->hascriteria ? get_string('yes') : \html_writer::span(
                                get_string('no'),
                                'cea-warn-text'
                            ),
                        ],
                    ]);
                }
                return $ds;

            case 'segments':
                $ds = new dataset('segments', $s('cfg_segments_distribution'), [
                    'segment' => $s('col_segment'), 'users' => $s('col_users'), 'pct' => '%',
                        'students' => $s('col_withstudentrole'),
                ], ['users', 'pct', 'students']);
                $total = $DB->count_records('local_ceduanalytics_userattr');
                $rows = $DB->get_records_sql("
                    SELECT a.segment, COUNT(1) AS users,
                           SUM(CASE WHEN EXISTS (SELECT 1 FROM {local_ceduanalytics_enrolstate} e
                                                  WHERE e.userid = a.userid AND e.isstudent = 1) THEN 1 ELSE 0 END) AS students
                      FROM {local_ceduanalytics_userattr} a
                  GROUP BY a.segment");
                foreach (attributes::SEGMENTS as $seg) {
                    $n = (int)($rows[$seg]->users ?? 0);
                    $ds->add(['segment' => $s('segment_' . $seg), 'users' => $n,
                        'pct' => $total ? fmt::percentnum($n / $total * 100) : '0',
                        'students' => (int)($rows[$seg]->students ?? 0)]);
                }
                $ds->emptytext = $s('empty_segments');
                return $ds;

            case 'unclassified':
                $ds = new dataset('unclassified', $s('cfg_segments_unclassified'), [
                    'jobtitle' => $s('col_jobtitle'), 'users' => $s('col_users'),
                ], ['users']);
                $counts = [];
                $rs = $DB->get_recordset('local_ceduanalytics_userattr', ['segment' => 'other'], '', 'id, jobtitle');
                foreach ($rs as $r) {
                    $k = br::normalise($r->jobtitle);
                    $counts[$k] = ($counts[$k] ?? 0) + 1;
                }
                $rs->close();
                arsort($counts);
                foreach ($counts as $title => $n) {
                    $ds->add(['jobtitle' => $title, 'users' => $n]);
                }
                $ds->emptytext = $s('empty_unclassified');
                return $ds;

            case 'coverage':
                $ds = new dataset('coverage', $s('cfg_data_coverage'), [
                    'source' => $s('col_ufsource'), 'users' => $s('col_users'), 'pct' => '%',
                ], ['users', 'pct']);
                $total = $DB->count_records('local_ceduanalytics_userattr');
                $rows = $DB->get_records_sql_menu(
                    'SELECT ufsource, COUNT(1) FROM {local_ceduanalytics_userattr} GROUP BY ufsource'
                );
                foreach (['declared', 'inep', 'phone', 'city', ''] as $src) {
                    $n = (int)($rows[$src] ?? 0);
                    $ds->add(['source' => $s('ufsource_' . ($src ?: 'none')), 'users' => $n,
                        'pct' => $total ? fmt::percentnum($n / $total * 100) : '0']);
                }
                return $ds;
        }
        return null;
    }
}
