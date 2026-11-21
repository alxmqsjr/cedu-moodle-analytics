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
 * Library callbacks for Cedu Moodle Analytics.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve plugin files (the configurable logo).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file was not found
 */
function local_ceduanalytics_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM || $filearea !== 'logo') {
        return false;
    }
    // The logo is not sensitive and is shown on the dashboard shell only.
    require_login();

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_ceduanalytics', 'logo', $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
}

/**
 * URL of the dashboard logo: the uploaded one, or the bundled default.
 *
 * @return moodle_url
 */
function local_ceduanalytics_get_logo_url(): moodle_url {
    global $OUTPUT;
    $logo = get_config('local_ceduanalytics', 'logo');
    if (!empty($logo)) {
        return moodle_url::make_pluginfile_url(
            context_system::instance()->id,
            'local_ceduanalytics',
            'logo',
            0,
            dirname($logo) === '/' ? '/' : dirname($logo) . '/',
            basename($logo)
        );
    }
    return $OUTPUT->image_url('logo', 'local_ceduanalytics');
}

/**
 * User preferences used by the plugin (menu groups collapsed by the user).
 *
 * @return array
 */
function local_ceduanalytics_user_preferences(): array {
    return [
        'local_ceduanalytics_menu' => [
            'type' => PARAM_RAW,
            'null' => NULL_NOT_ALLOWED,
            'default' => '',
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}
