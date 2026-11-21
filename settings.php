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
 * Admin settings and navigation entry for Cedu Moodle Analytics.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Link to the dashboard under Site administration > Reports.
$ADMIN->add('reports', new admin_externalpage(
    'local_ceduanalytics_dashboard',
    get_string('pluginname', 'local_ceduanalytics'),
    new moodle_url('/local/ceduanalytics/index.php'),
    'local/ceduanalytics:view'
));

// And in the General tab of Site administration, in a category of its own.
$ADMIN->add('root', new admin_category('local_ceduanalytics_general', get_string('admincategory', 'local_ceduanalytics')));
$ADMIN->add('local_ceduanalytics_general', new admin_externalpage(
    'local_ceduanalytics_general_dashboard',
    get_string('pluginname', 'local_ceduanalytics'),
    new moodle_url('/local/ceduanalytics/index.php'),
    'local/ceduanalytics:view'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ceduanalytics', get_string('settings', 'local_ceduanalytics'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // Identity.
        $settings->add(new admin_setting_heading(
            'local_ceduanalytics/identityheading',
            get_string('settings_identity', 'local_ceduanalytics'),
            ''
        ));
        $settings->add(new admin_setting_configstoredfile(
            'local_ceduanalytics/logo',
            get_string('settings_logo', 'local_ceduanalytics'),
            get_string('settings_logo_desc', 'local_ceduanalytics'),
            'logo',
            0,
            ['maxfiles' => 1, 'accepted_types' => ['.png', '.svg', '.jpg', '.jpeg', '.webp']]
        ));

        // Indicators.
        $settings->add(new admin_setting_heading(
            'local_ceduanalytics/indicatorsheading',
            get_string('settings_indicators', 'local_ceduanalytics'),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            'local_ceduanalytics/mincount',
            get_string('settings_mincount', 'local_ceduanalytics'),
            get_string('settings_mincount_desc', 'local_ceduanalytics'),
            5,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_ceduanalytics/inactivedays',
            get_string('settings_inactivedays', 'local_ceduanalytics'),
            get_string('settings_inactivedays_desc', 'local_ceduanalytics'),
            14,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_ceduanalytics/pacemargin',
            get_string('settings_pacemargin', 'local_ceduanalytics'),
            get_string('settings_pacemargin_desc', 'local_ceduanalytics'),
            25,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_ceduanalytics/passthreshold',
            get_string('settings_passthreshold', 'local_ceduanalytics'),
            get_string('settings_passthreshold_desc', 'local_ceduanalytics'),
            70,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configtext(
            'local_ceduanalytics/effectivenessdays',
            get_string('settings_effectivenessdays', 'local_ceduanalytics'),
            get_string('settings_effectivenessdays_desc', 'local_ceduanalytics'),
            14,
            PARAM_INT
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_ceduanalytics/videotracking',
            get_string('settings_videotracking', 'local_ceduanalytics'),
            get_string('settings_videotracking_desc', 'local_ceduanalytics'),
            1
        ));

        // Courses.
        $settings->add(new admin_setting_heading(
            'local_ceduanalytics/coursesheading',
            get_string('settings_courses', 'local_ceduanalytics'),
            ''
        ));
        $settings->add(new admin_setting_configmultiselect(
            'local_ceduanalytics/modelcategories',
            get_string('settings_modelcategories', 'local_ceduanalytics'),
            get_string('settings_modelcategories_desc', 'local_ceduanalytics'),
            [],
            core_course_category::make_categories_list()
        ));

        // Profile field mapping.
        $settings->add(new admin_setting_heading(
            'local_ceduanalytics/fieldsheading',
            get_string('settings_fields', 'local_ceduanalytics'),
            get_string('settings_fields_desc', 'local_ceduanalytics')
        ));
        $fieldchoices = ['' => get_string('settings_field_none', 'local_ceduanalytics')];
        foreach ($DB->get_records('user_info_field', null, 'sortorder', 'id, shortname, name') as $field) {
            $fieldchoices[$field->shortname] = \local_ceduanalytics\local\fmt::text($field->name) . ' (' . $field->shortname . ')';
        }
        foreach (\local_ceduanalytics\local\attributes::FIELDS as $fieldsetting => $defaultfield) {
            $settings->add(new admin_setting_configselect(
                'local_ceduanalytics/' . $fieldsetting,
                get_string('settings_' . $fieldsetting, 'local_ceduanalytics'),
                '',
                isset($fieldchoices[$defaultfield]) ? $defaultfield : '',
                $fieldchoices
            ));
        }
    }
}
