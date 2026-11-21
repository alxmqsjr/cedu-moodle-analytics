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
 * Cedu Moodle Analytics: dashboard entry point (router).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_ceduanalytics\local\filters;
use local_ceduanalytics\local\navigation;
use local_ceduanalytics\local\page\base;
use local_ceduanalytics\local\shell;

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/ceduanalytics:view', $context);

$def = navigation::page(optional_param('p', navigation::DEFAULT, PARAM_ALPHA));
$filters = filters::from_request();
$page = base::create($def, $filters);
if ($cap = $page->capability()) {
    require_capability($cap, $context);
}

$PAGE->set_context($context);
$PAGE->set_url($page->url());
$PAGE->set_pagelayout('embedded');
$PAGE->set_title($page->title() . ' · ' . get_string('pluginname', 'local_ceduanalytics'));
$PAGE->add_body_class('local-ceduanalytics-body');

\local_ceduanalytics\local\savedviews::process($page);
$page->process_actions();

echo shell::render($page, $filters);
