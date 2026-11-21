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

/**
 * Page not yet delivered: explains what it will show and in which phase.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholder extends base {
    /**
     * Content.
     *
     * @return string
     */
    public function content(): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('local_ceduanalytics/empty_state', [
            'icon' => $this->def['icon'],
            'title' => get_string('soon_title', 'local_ceduanalytics', $this->def['phase']),
            'text' => get_string('page_' . $this->id() . '_desc', 'local_ceduanalytics'),
            'note' => get_string('soon_note', 'local_ceduanalytics'),
            'large' => true,
        ]);
    }
}
