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

namespace local_ceduanalytics;

use core\hook\output\before_footer_html_generation;

/**
 * Hook callbacks.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /** @var string[] Page types where embedded videos are tracked. */
    const PAGETYPES = ['mod-page-view', 'mod-book-view'];

    /**
     * Load the video tracking script on page and book views (logged-in users only).
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE;
        // Magic property: empty($PAGE->cm) would always be true, so copy it first.
        $cm = $PAGE->cm;
        if (
            during_initial_install() || !isloggedin() || isguestuser() || !$cm
                || !in_array($PAGE->pagetype, self::PAGETYPES) || !local\videos::enabled()
        ) {
            return;
        }
        $PAGE->requires->js_call_amd('local_ceduanalytics/videotrack', 'init', [(int)$cm->id]);
    }

    /**
     * Link to the dashboard in the "Manage" menu that the Stream theme adds to the primary navigation.
     *
     * Runs after the theme callback (lower priority). With another theme, or without that menu, nothing is added: the
     * dashboard is still reached through Site administration.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        if (
            during_initial_install() || !isloggedin() || isguestuser()
                || !get_string_manager()->string_exists('management', 'theme_stream')
                || !has_capability('local/ceduanalytics:view', \context_system::instance())
        ) {
            return;
        }
        $label = get_string('management', 'theme_stream');
        $menu = null;
        foreach ($hook->get_primaryview()->children as $child) {
            if ((string)$child->text === $label) {
                $menu = $child;
                break;
            }
        }
        if (!$menu) {
            return;
        }
        $node = \navigation_node::create(
            get_string('pluginname', 'local_ceduanalytics'),
            new \moodle_url('/local/ceduanalytics/index.php'),
            \navigation_node::TYPE_ROOTNODE,
            null,
            'local_ceduanalytics'
        );
        // Before the item that starts with a separator (the theme settings), so it stays with the management links.
        $before = null;
        foreach ($menu->children as $child) {
            if (!empty($child->preceedwithhr)) {
                $before = $child->key;
                break;
            }
        }
        $menu->add_node($node, $before);
    }
}
