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
 * Favourite views: a page with its filters and state, saved by each user.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class savedviews {
    /**
     * Handle the save/delete forms (called from index.php before any output).
     *
     * @param page\base $page
     */
    public static function process(page\base $page): void {
        global $DB, $USER;
        $action = optional_param('viewaction', '', PARAM_ALPHA);
        if ($action === '' || !confirm_sesskey()) {
            return;
        }
        if ($action === 'save') {
            $name = trim(optional_param('viewname', '', PARAM_TEXT));
            if ($name !== '') {
                $params = $page->url()->params();
                unset($params['p']);
                $DB->insert_record('local_ceduanalytics_savedview', (object)[
                    'userid' => $USER->id,
                    'name' => \core_text::substr($name, 0, 255),
                    'pageid' => $page->id(),
                    'params' => json_encode($params),
                    'timecreated' => time(),
                ]);
                redirect(
                    $page->url(),
                    get_string('views_saved', 'local_ceduanalytics', s($name)),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            }
        } else if ($action === 'delete') {
            $DB->delete_records('local_ceduanalytics_savedview', ['id' => optional_param('viewid', 0, PARAM_INT),
                'userid' => $USER->id]);
            redirect(
                $page->url(),
                get_string('views_deleted', 'local_ceduanalytics'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        redirect($page->url());
    }

    /**
     * Views of the current user, for the template.
     *
     * @param string $currenturl
     * @return array
     */
    public static function for_template(string $currenturl): array {
        global $DB, $USER;
        $out = [];
        foreach ($DB->get_records('local_ceduanalytics_savedview', ['userid' => $USER->id], 'name') as $v) {
            $params = json_decode((string)$v->params, true) ?: [];
            $url = navigation::url($v->pageid, $params)->out(false);
            $out[] = [
                'id' => $v->id,
                'name' => $v->name,
                'page' => get_string('page_' . $v->pageid, 'local_ceduanalytics'),
                'url' => $url,
                'current' => $url === $currenturl,
            ];
        }
        return $out;
    }
}
