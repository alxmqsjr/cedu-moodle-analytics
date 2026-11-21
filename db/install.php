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
 * Post-install: default rules and mappings, course lines, and the historical load.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Install hook.
 *
 * @return bool
 */
function xmldb_local_ceduanalytics_install() {
    \local_ceduanalytics\local\attributes::seed_rules();
    \local_ceduanalytics\local\attributes::seed_field_mapping();
    if (get_config('local_ceduanalytics', 'modelcategories') === false) {
        set_config(
            'modelcategories',
            implode(',', \local_ceduanalytics\local\courses::detect_model_categories()),
            'local_ceduanalytics'
        );
    }
    \local_ceduanalytics\local\courses::apply_suggestions();

    // The historical load runs in the background (cron), never during the install request.
    \core\task\manager::queue_adhoc_task(new \local_ceduanalytics\task\backfill(), true);
    return true;
}
