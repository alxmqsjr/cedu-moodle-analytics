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

use local_ceduanalytics\local\page\base;

/**
 * Dashboard shell: side menu, top bar, page header, filters and footer around the page content.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shell {
    /**
     * Render a whole page (header, shell, footer).
     *
     * @param base $page
     * @param filters $filters
     * @return string
     */
    public static function render(base $page, filters $filters): string {
        // The shell is built by render_page(); the filter panel by filter_context().
        return self::render_page($page, $filters);
    }

    /**
     * Filter panel context. Applying or clearing the filters keeps the state of the page (e.g. the course of the
     * course panel, the tab or the view), and applying keeps the active filters that this page does not show.
     *
     * @param base $page
     * @param filters $filters
     * @return array
     */
    protected static function filter_context(base $page, filters $filters): array {
        $ctx = $filters->export_for_template($page->filter_keys(), $page->id());
        if (empty($ctx['hasfilters'])) {
            return $ctx;
        }
        $keys = $page->filter_keys();
        if (in_array('period', $keys)) {
            $keys = array_merge($keys, ['from', 'to']);
        }
        $hidden = [];
        foreach ($page->state_params() as $name => $value) {
            $hidden[] = ['name' => $name, 'value' => $value];
        }
        foreach (array_diff_key($filters->params(), array_flip($keys)) as $name => $value) {
            $hidden[] = ['name' => $name, 'value' => $value];
        }
        $ctx['hidden'] = $hidden;
        $ctx['clearurl'] = navigation::url($page->id(), $page->state_params())->out(false);
        return $ctx;
    }

    /**
     * Render the page inside the shell.
     *
     * @param base $page
     * @param filters $filters
     * @return string
     */
    protected static function render_page(base $page, filters $filters): string {
        global $OUTPUT, $USER, $CFG, $PAGE;
        require_once($CFG->dirroot . '/local/ceduanalytics/lib.php');

        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_ceduanalytics');
        $context = \context_system::instance();
        $filterparams = $filters->params();

        $exports = [];
        if (has_capability('local/ceduanalytics:export', $context) && count($page->all_datasets()) > 1) {
            // Every list of the page in one spreadsheet (one sheet per list).
            $exports[] = [
                'label' => get_string('export_alllists', 'local_ceduanalytics'),
                'url' => (new \moodle_url('/local/ceduanalytics/export.php', ['p' => $page->id(), 'ds' => 'all',
                    'format' => 'excel'] + $filterparams + $page->state_params()))->out(false),
            ];
        }
        if (has_capability('local/ceduanalytics:export', $context)) {
            foreach ($page->all_datasets() as $id => $label) {
                foreach (['csv' => 'CSV', 'excel' => 'XLSX'] as $format => $flabel) {
                    $exports[] = [
                        'label' => $label . ' (' . $flabel . ')',
                        'url' => (new \moodle_url('/local/ceduanalytics/export.php', ['p' => $page->id(), 'ds' => $id,
                            'format' => $format] + $filterparams + $page->state_params()))->out(false),
                    ];
                }
            }
        }

        $content = $page->content() . $page->extras_html();

        // The embedded layout has no course content header, so queued notifications
        // (e.g. after a redirect) are rendered here.
        $notifications = '';
        foreach (\core\notification::fetch() as $notification) {
            $notifications .= $OUTPUT->render($notification);
        }
        $data = [
            'logourl' => local_ceduanalytics_get_logo_url()->out(false),
            'pluginname' => get_string('pluginname', 'local_ceduanalytics'),
            'homeurl' => navigation::url(navigation::DEFAULT, $filterparams)->out(false),
            'menu' => navigation::menu($page->id(), $filterparams),
            'backurl' => (new \moodle_url('/'))->out(false),
            'version' => get_string('version', 'local_ceduanalytics', $plugin->release),
            'title' => $page->title(),
            'question' => $page->question(),
            'group' => $page->group(),
            'showgroup' => $page->group() !== $page->title(),
            'filters' => self::filter_context($page, $filters),
            'showperiod' => in_array('period', $page->filter_keys()),
            'hasexports' => !empty($exports),
            'exports' => $exports,
            'content' => $content,
            'notifications' => $notifications,
            'userpicture' => $OUTPUT->user_picture($USER, ['size' => 36, 'link' => false, 'alttext' => false]),
            'userfullname' => fullname($USER),
            'profileurl' => (new \moodle_url('/user/profile.php', ['id' => $USER->id]))->out(false),
            'stateparams' => array_map(
                fn($k, $v) => ['name' => $k, 'value' => $v],
                array_keys($page->state_params()),
                $page->state_params()
            ),
            'views' => $views = savedviews::for_template($page->url()->out(false)),
            'hasviews' => !empty($views),
            'pageurl' => $page->url()->out(false),
            'sesskey' => sesskey(),
        ];

        $PAGE->requires->js_call_amd('local_ceduanalytics/shell', 'init');
        $PAGE->requires->js_call_amd('local_ceduanalytics/search', 'init');
        $output = $OUTPUT->header();
        $output .= $OUTPUT->render_from_template('local_ceduanalytics/shell', $data);
        $output .= $OUTPUT->footer();
        return $output;
    }
}
