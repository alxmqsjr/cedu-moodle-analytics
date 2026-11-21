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

namespace local_ceduanalytics\local;

/**
 * A tabular dataset: shown as a table on the page and exported as CSV/XLSX with the same filters.
 *
 * Each row is an array column => value. Values are exported as they are; on screen, an optional
 * "_html" map (column => HTML) replaces the plain value, e.g. for links.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dataset {
    /** @var string */
    public $id;
    /** @var string */
    public $title;
    /** @var array column => label */
    public $columns = [];
    /** @var string[] Numeric columns (right aligned) */
    public $numeric = [];
    /** @var array[] */
    public $rows = [];
    /** @var string[] Columns shown on screen only (e.g. selection checkboxes) */
    public $screenonly = [];
    /** @var string[] Columns merged across rows with the same _mergekey */
    public $mergecols = [];
    /** @var string[] Columns that hold the name of a person (the e-mail is shown under it) */
    const PERSONCOLS = ['student', 'teacher', 'name'];
    /** @var string Text shown when there are no rows */
    public $emptytext = '';

    /**
     * Constructor.
     *
     * @param string $id
     * @param string $title
     * @param array $columns
     * @param string[] $numeric
     */
    public function __construct(string $id, string $title, array $columns, array $numeric = []) {
        $this->id = $id;
        $this->title = $title;
        $this->columns = $columns;
        $this->numeric = $numeric;
        $this->emptytext = get_string('empty_generic', 'local_ceduanalytics');
    }

    /**
     * Add a row.
     *
     * Plain values are stored as text: entities from format_string() or get_formatted_name() ("&amp;") are
     * decoded, so the screen (which escapes them again) and the exported files show the original characters.
     *
     * @param array $row column => value (and optional '_html' => [column => html], '_class' => css class)
     */
    public function add(array $row): void {
        foreach ($row as $col => $value) {
            if (is_string($value) && $col[0] !== '_' && strpos($value, '&') !== false) {
                $row[$col] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $this->rows[] = $row;
    }

    /**
     * Columns exported (all but the screen-only ones).
     *
     * @return array column => label
     */
    public function export_columns(): array {
        return array_diff_key($this->columns, array_flip($this->screenonly));
    }

    /**
     * Rows as ordered lists, for the dataformat API.
     *
     * @return \Generator
     */
    public function export_rows(): \Generator {
        foreach ($this->rows as $row) {
            $out = [];
            foreach (array_keys($this->export_columns()) as $col) {
                $out[] = $row[$col] ?? '';
            }
            yield $out;
        }
    }

    /**
     * Template context for templates/table.mustache.
     *
     * On screen, the e-mail column is shown under the name of the person (student, teacher or name column),
     * in a smaller font, to save horizontal space; exports keep the columns separate. Rows can share the first
     * cells (rowspan) when merge() was called.
     *
     * @return array
     */
    public function export_for_template(): array {
        $person = null;
        foreach (self::PERSONCOLS as $col) {
            if (isset($this->columns[$col]) && isset($this->columns['email'])) {
                $person = $col;
                break;
            }
        }
        $hidden = $person ? ['email'] : [];
        $columns = array_diff_key($this->columns, array_flip($hidden));
        $headers = [];
        foreach ($columns as $col => $label) {
            $headers[] = ['label' => $label, 'numeric' => in_array($col, $this->numeric)];
        }
        // Rowspans of merged columns: consecutive rows with the same merge key.
        $spans = [];
        if ($this->mergecols) {
            $n = count($this->rows);
            for ($i = 0; $i < $n; $i++) {
                $key = $this->rows[$i]['_mergekey'] ?? null;
                $j = $i;
                while ($key !== null && $j + 1 < $n && ($this->rows[$j + 1]['_mergekey'] ?? null) === $key) {
                    $j++;
                }
                $spans[$i] = $j - $i + 1;
                for ($k = $i + 1; $k <= $j; $k++) {
                    $spans[$k] = 0;
                }
                $i = $j;
            }
        }
        $rows = [];
        foreach ($this->rows as $index => $row) {
            $cells = [];
            foreach (array_keys($columns) as $col) {
                $merged = in_array($col, $this->mergecols);
                if ($merged && ($spans[$index] ?? 1) === 0) {
                    continue;
                }
                $html = $row['_html'][$col] ?? s((string)($row[$col] ?? ''));
                if ($col === $person && (string)($row['email'] ?? '') !== '') {
                    $html .= \html_writer::empty_tag('br') . \html_writer::span(s($row['email']), 'cea-email');
                }
                $cells[] = [
                    'html' => $html,
                    'numeric' => in_array($col, $this->numeric),
                    'rowspan' => $merged && ($spans[$index] ?? 1) > 1 ? $spans[$index] : 0,
                ];
            }
            $rows[] = ['cells' => $cells, 'class' => $row['_class'] ?? ''];
        }
        $groups = $this->mergecols ? count(array_filter($spans, fn($s) => $s > 0)) : count($rows);
        return [
            'id' => $this->id,
            'title' => $this->title,
            'headers' => $headers,
            'rows' => $rows,
            'hasrows' => !empty($rows),
            'count' => count($rows),
            'sortable' => !$this->mergecols,
            'emptytext' => $this->emptytext,
            'groups' => $groups,
        ];
    }

    /**
     * Merge the given columns of consecutive rows that share the same '_mergekey' (e.g. one student with
     * several enrolments). Rows must already be ordered by that key; merged tables are not sortable.
     *
     * @param string[] $columns
     */
    public function merge(array $columns): void {
        $this->mergecols = $columns;
    }
}
