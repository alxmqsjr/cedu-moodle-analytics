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

/**
 * Export a page dataset (CSV or XLSX) with the active filters.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ceduanalytics\local\filters;
use local_ceduanalytics\local\navigation;
use local_ceduanalytics\local\page\base;

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ceduanalytics:view', $context);
require_capability('local/ceduanalytics:export', $context);

// The function format_string() and friends need the page context; without it Moodle prints a debugging notice,
// which would break the download.
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/ceduanalytics/export.php'));

$pageid = required_param('p', PARAM_ALPHA);
$datasetid = required_param('ds', PARAM_ALPHA);
$format = required_param('format', PARAM_ALPHA);
if (!in_array($format, ['csv', 'excel'])) {
    throw new moodle_exception('invalidparameter', 'debug');
}

$def = navigation::page($pageid);
$page = base::create($def, filters::from_request());
if ($cap = $page->capability()) {
    require_capability($cap, $context);
}
// Every list of the page in one spreadsheet, one sheet per list.
if ($datasetid === 'all') {
    require_once($CFG->libdir . '/excellib.class.php');
    $title = get_string('page_' . $pageid, 'local_ceduanalytics');
    $workbook = new MoodleExcelWorkbook(clean_filename('cedu-moodle-analytics_' . $pageid . '_' . date('Ymd-Hi')));
    $bold = $workbook->add_format(['bold' => 1]);
    $used = [];
    foreach ($page->all_datasets() as $id => $label) {
        $dataset = $page->find_dataset($id);
        if (!$dataset) {
            continue;
        }
        // Sheet names: up to 31 characters, without : \ / ? * [ ], and unique.
        $name = core_text::substr(trim(preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $label)), 0, 28);
        $base = $name;
        for ($i = 2; isset($used[core_text::strtolower($name)]); $i++) {
            $name = $base . ' ' . $i;
        }
        $used[core_text::strtolower($name)] = true;
        $sheet = $workbook->add_worksheet($name);
        $col = 0;
        foreach ($dataset->export_columns() as $header) {
            $sheet->write_string(0, $col++, $header, $bold);
        }
        $row = 1;
        foreach ($dataset->export_rows() as $values) {
            foreach (array_values($values) as $c => $value) {
                if (is_int($value) || is_float($value)) {
                    $sheet->write_number($row, $c, $value);
                } else {
                    $sheet->write_string($row, $c, (string)$value);
                }
            }
            $row++;
        }
        if ($row === 1) {
            $sheet->write_string(1, 0, $dataset->emptytext);
        }
    }
    $workbook->close();
    die();
}

if (!array_key_exists($datasetid, $page->all_datasets()) || !($dataset = $page->find_dataset($datasetid))) {
    throw new moodle_exception('invalidparameter', 'debug');
}

$filename = clean_filename('cedu-moodle-analytics_' . $pageid . '_' . $datasetid . '_' . date('Ymd-Hi'));
\core\dataformat::download_data($filename, $format, array_values($dataset->export_columns()), $dataset->export_rows());
