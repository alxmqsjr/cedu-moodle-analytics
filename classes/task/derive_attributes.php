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

namespace local_ceduanalytics\task;

/**
 * Derive user attributes (state, city, segment) and assign new courses to course lines.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class derive_attributes extends \core\task\scheduled_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_deriveattributes', 'local_ceduanalytics');
    }

    /**
     * Run.
     */
    public function execute(): void {
        $trace = new \text_progress_trace();
        \local_ceduanalytics\local\attributes::derive($trace);
        $n = \local_ceduanalytics\local\courses::apply_suggestions();
        $trace->output("Course lines: {$n} courses assigned.");
        // Readable names of the settings, for the list of configuration changes (once a day).
        try {
            \local_ceduanalytics\local\adminlabels::build($trace);
        } catch (\Throwable $e) {
            $trace->output('Setting names not built: ' . $e->getMessage());
        }
    }
}
