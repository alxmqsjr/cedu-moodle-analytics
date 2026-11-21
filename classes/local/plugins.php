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
 * Additional (non-standard) Moodle plugins: what they do, whether they are used and whether they are up to date.
 *
 * Sub-plugins of an additional plugin (e.g. the elements of the custom certificate) are counted in the row of their
 * parent. Plugins whose code was removed from the server but are still registered in the database are reported as such.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plugins {
    /** @var string[] Plugins with a description written for the dashboard (strings plg_<component>). */
    const DESCRIBED = ['mod_certificate', 'mod_customcert', 'mod_simplecertificate', 'mod_attendance', 'mod_checklist',
        'mod_hvp', 'mod_offlinequiz', 'mod_publication', 'mod_questionnaire', 'mod_reengagement', 'mod_scheduler',
        'mod_subcourse', 'mod_zoom', 'booktool_wordimport', 'block_accessibility', 'block_advnotifications', 'block_grade_me',
        'block_progress', 'qbank_importasversion', 'editor_ousupsub', 'atto_c4l', 'enrol_apply', 'auth_userkey',
        'auth_cemaden_autoregistro', 'calendartype_ummulqora', 'customfield_file', 'message_telegram', 'media_realplayer',
        'format_grid', 'dataformat_xml', 'report_lpmonitoring', 'gradeexport_apogee', 'gradereport_rubrics',
        'gradingform_checklist', 'webservice_restful', 'portfolio_evernote', 'search_elastic', 'plagiarism_originality',
        'cachelock_rediscluster', 'fileconverter_onlyoffice', 'theme_stream', 'theme_boosted', 'theme_enlightlite',
        'theme_learnr', 'local_ceduanalytics', 'local_aws'];

    /**
     * Rows of the table.
     *
     * @return array list of ['plugin', 'component', 'description', 'usage', 'usagebad', 'state' (missing, disabled, never,
     *     used, enabled), 'update', 'updatebad', 'ismissing']
     */
    public static function rows(): array {
        global $DB, $CFG;
        $pm = \core_plugin_manager::instance();
        $sm = get_string_manager();
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $fetched = (int)\core\update\checker::instance()->get_last_timefetched();

        // Additional plugins and their sub-plugins.
        $all = [];
        $children = [];
        foreach ($pm->get_plugins() as $type => $list) {
            foreach ($list as $plugin) {
                if ($plugin->is_standard() || (string)$plugin->component === '') {
                    continue;
                }
                $parent = \core_component::get_subtype_parent($type);
                $parentinfo = $parent ? $pm->get_plugin_info($parent) : null;
                if ($parentinfo && !$parentinfo->is_standard()) {
                    $children[$parent][] = $plugin;
                    continue;
                }
                $all[$plugin->component] = $plugin;
            }
        }

        $modules = $DB->get_records_menu('modules', null, '', 'name, id');
        $rows = [];
        foreach ($all as $component => $plugin) {
            [$type, $name] = \core_component::normalize_component($component);
            $missing = $plugin->get_status() === \core_plugin_manager::PLUGIN_STATUS_MISSING;
            $typename = $sm->string_exists('type_' . $type, 'core_plugin') ? get_string('type_' . $type, 'core_plugin') : $type;

            // Description.
            if (in_array($component, self::DESCRIBED)) {
                $description = $s('plg_' . $component);
            } else if (!$missing && $sm->string_exists('pluginname_help', $component)) {
                $description = trim(strip_tags(get_string('pluginname_help', $component)));
            } else if (!$missing && $type === 'mod' && $sm->string_exists('modulename_help', $component)) {
                $description = trim(strip_tags(get_string('modulename_help', $component)));
            } else {
                $description = $s('plg_type', $typename);
            }
            if (!empty($children[$component])) {
                $description .= ' ' . $s('plg_children', count($children[$component]));
            }

            // Usage.
            $usagebad = true;
            $state = 'missing';
            if ($missing) {
                $usage = $s('plg_use_missing');
                $usagebad = false;
            } else {
                $count = self::count_use($type, $name, $modules);
                $enabled = $plugin->is_enabled();
                if ($type === 'theme' && $name === ($CFG->theme ?? '')) {
                    $usage = $s('plg_use_theme_site');
                    $usagebad = false;
                    $state = 'used';
                } else if ($enabled === false) {
                    $usage = $count ? $s('plg_use_disabledused', fmt::int($count)) : $s('plg_use_disabled');
                    $state = 'disabled';
                } else if ($count === 0) {
                    $usage = $s('plg_use_never');
                    $state = 'never';
                } else if ($count === null) {
                    $usage = $s('plg_use_enabled');
                    $usagebad = false;
                    $state = 'enabled';
                } else {
                    $usage = $s('plg_use_' . $type . '_count', fmt::int($count));
                    $usagebad = false;
                    $state = 'used';
                }
            }

            // Updates.
            $updatebad = false;
            if ($missing) {
                $update = '—';
            } else if ($updates = $plugin->available_updates()) {
                $latest = reset($updates);
                $update = $s('plg_upd_outdated', $latest->release ?? $latest->version);
                $updatebad = true;
            } else if ($fetched) {
                $update = $s('plg_upd_ok', fmt::date($fetched));
            } else {
                $update = $s('plg_upd_unknown');
            }

            $displayname = $missing ? $name : (string)$plugin->displayname;
            $rows[] = ['plugin' => $displayname, 'component' => $component, 'type' => $typename,
                'release' => $missing ? '' : (string)$plugin->release, 'description' => $description,
                'usage' => $usage, 'usagebad' => $usagebad, 'state' => $state, 'update' => $update, 'updatebad' => $updatebad];
        }
        // Problems first, then the rest by name; plugins whose code was removed from the server come last.
        foreach ($rows as &$row) {
            $row['ismissing'] = $row['state'] === 'missing';
        }
        unset($row);
        usort($rows, fn($a, $b) => [$a['ismissing'], !($a['usagebad'] || $a['updatebad']), \core_text::strtolower($a['plugin'])]
            <=> [$b['ismissing'], !($b['usagebad'] || $b['updatebad']), \core_text::strtolower($b['plugin'])]);
        return $rows;
    }

    /**
     * How many times a plugin is used, for the types that can be counted (null = cannot be counted).
     *
     * @param string $type
     * @param string $name
     * @param array $modules name => id
     * @return int|null
     */
    protected static function count_use(string $type, string $name, array $modules): ?int {
        global $DB;
        switch ($type) {
            case 'mod':
                return isset($modules[$name]) ? $DB->count_records('course_modules', ['module' => $modules[$name]]) : 0;
            case 'block':
                return $DB->count_records('block_instances', ['blockname' => $name]);
            case 'auth':
                return $DB->count_records('user', ['auth' => $name, 'deleted' => 0]);
            case 'enrol':
                return $DB->count_records('enrol', ['enrol' => $name]);
            case 'format':
                return $DB->count_records('course', ['format' => $name]);
            case 'qtype':
                return $DB->count_records('question', ['qtype' => $name]);
            case 'theme':
                // Courses and categories that force this theme (the site theme is reported apart).
                return $DB->count_records('course', ['theme' => $name]) + $DB->count_records(
                    'course_categories',
                    ['theme' => $name]
                );
            default:
                return null;
        }
    }
}
