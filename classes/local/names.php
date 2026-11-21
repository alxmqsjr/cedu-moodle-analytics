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
 * Readable names for technical identifiers shown in the tables: Moodle components (where files come from),
 * notification types and mobile app platforms. The technical name stays in parentheses when it helps to find
 * the item in Moodle.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class names {
    /** @var string[] Core areas with a description of their own (strings comp_<area>). */
    const CORE = ['core', 'admin', 'backup', 'course', 'question', 'user', 'h5p', 'contentbank', 'grades', 'blog', 'badges',
        'group', 'calendar', 'block', 'competency'];

    /**
     * Readable name of a Moodle component: "Question type: Matching (qtype_match)", "Course backups (backup)".
     *
     * @param string|null $component
     * @return string
     */
    public static function component(?string $component): string {
        $component = trim((string)$component);
        if ($component === '') {
            return '—';
        }
        $sm = get_string_manager();
        [$type, $name] = \core_component::normalize_component($component);
        if ($type === 'core') {
            $area = $name ?: 'core';
            return $sm->string_exists('comp_' . $area, 'local_ceduanalytics')
                ? get_string('comp_' . $area, 'local_ceduanalytics') . ' (' . $component . ')'
                : get_string('comp_other', 'local_ceduanalytics', $component);
        }
        $full = $type . '_' . $name;
        $plugin = $sm->string_exists('pluginname', $full) ? get_string('pluginname', $full) : $name;
        $typename = '';
        if ($sm->string_exists('type_' . $type, 'core_plugin')) {
            $typename = get_string('type_' . $type, 'core_plugin');
        } else if (
            ($parent = \core_component::get_subtype_parent($type)) &&
                $sm->string_exists('subplugintype_' . $type, $parent)
        ) {
            $typename = get_string('subplugintype_' . $type, $parent);
        }
        return ($typename !== '' ? $typename . ': ' : '') . $plugin . ' (' . $component . ')';
    }

    /**
     * Readable name of a notification type: "Welcome message for new course enrolments (enrolcoursewelcomemessage)".
     *
     * @param string|null $component
     * @param string|null $eventtype
     * @return string
     */
    public static function notification(?string $component, ?string $eventtype): string {
        $component = (string)$component;
        $eventtype = (string)$eventtype;
        $sm = get_string_manager();
        $stringcomponent = in_array($component, ['', 'moodle', 'core']) ? 'moodle' : $component;
        $label = $sm->string_exists('messageprovider:' . $eventtype, $stringcomponent)
            ? get_string('messageprovider:' . $eventtype, $stringcomponent) : $eventtype;
        if ($stringcomponent !== 'moodle' && $sm->string_exists('pluginname', $component)) {
            $label = get_string('pluginname', $component) . ': ' . $label;
        }
        return $label === $eventtype ? $eventtype : $label . ' (' . $eventtype . ')';
    }

    /**
     * Name of a mobile app platform as people know it ("android-fcm" is Android).
     *
     * @param string|null $platform
     * @return string
     */
    public static function platform(?string $platform): string {
        $p = \core_text::strtolower(trim((string)$platform));
        if ($p === '') {
            return get_string('x_devices_unknown', 'local_ceduanalytics');
        }
        if (strpos($p, 'android') === 0) {
            return 'Android';
        }
        if (strpos($p, 'ios') === 0) {
            return get_string('x_devices_ios', 'local_ceduanalytics');
        }
        return \core_text::strtotitle(preg_replace('/[-_]fcm$/', '', $p));
    }
}
