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
 * Full (historical) processing: whole log, snapshot and attributes. Queued at install time.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill extends \core\task\adhoc_task {
    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_backfill', 'local_ceduanalytics');
    }

    /**
     * Run everything until the log is fully processed.
     */
    public function execute(): void {
        $trace = new \text_progress_trace();
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        (new \local_ceduanalytics\local\aggregator())->run(0, $trace);
        \local_ceduanalytics\local\attributes::derive($trace);
        \local_ceduanalytics\local\courses::apply_suggestions();
        \local_ceduanalytics\local\snapshot::build($trace);
        set_config('lastbackfill', time(), 'local_ceduanalytics');
    }
}
