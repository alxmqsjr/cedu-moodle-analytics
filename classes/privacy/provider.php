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

namespace local_ceduanalytics\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for local_ceduanalytics.
 *
 * All personal data is derived from Moodle data (log, enrolments, completion, profile) and is
 * stored against the system context.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /** @var string[] Tables with a userid column => fields exported. */
    const USERTABLES = [
        'local_ceduanalytics_daily' => ['courseid', 'day', 'events', 'views', 'writes', 'wsevents', 'logins', 'activesecs',
            'firstts', 'lastts'],
        'local_ceduanalytics_cmview' => ['cmid', 'courseid', 'views', 'firstview', 'lastview'],
        'local_ceduanalytics_enrolstate' => ['courseid', 'roles', 'enrolmethods', 'timeenrolled', 'firstaccess', 'lastaccess',
            'cmtotal', 'cmdone', 'progress', 'timecertified', 'timecompleted', 'gradepct', 'situation', 'reasons'],
        'local_ceduanalytics_userattr' => ['uf', 'ufsource', 'city', 'institution', 'jobtitle', 'segment'],
        'local_ceduanalytics_savedview' => ['name', 'pageid', 'params', 'timecreated'],
        'local_ceduanalytics_video' => ['courseid', 'cmid', 'videoid', 'duration', 'watchedsecs', 'plays', 'linkclicks',
            'timefirst', 'timelast'],
        'local_ceduanalytics_followup' => ['courseid', 'reason', 'status', 'assignedto', 'contacts', 'lastcontact',
            'lastkind', 'usermodified', 'timemodified'],
    ];

    /** @var string[] Staff columns of the follow-up table (responsible and last editor). */
    const STAFFFIELDS = ['assignedto', 'usermodified'];

    /**
     * Describe the stored data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        foreach (self::USERTABLES as $table => $fields) {
            $desc = ['userid' => 'privacy:metadata:userid'];
            foreach ($fields as $f) {
                $desc[$f] = 'privacy:metadata:' . $table . ':' . $f;
            }
            // Use a compact description: one string per table and a generic one per field group.
            $collection->add_database_table(
                $table,
                array_fill_keys(array_keys($desc), 'privacy:metadata:field'),
                'privacy:metadata:' . $table
            );
        }
        $collection->add_database_table('local_ceduanalytics_security', [
            'ip' => 'privacy:metadata:local_ceduanalytics_security:ip',
            'username' => 'privacy:metadata:local_ceduanalytics_security:username',
            'attempts' => 'privacy:metadata:field',
        ], 'privacy:metadata:local_ceduanalytics_security');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        $collection->add_subsystem_link('core_notes', [], 'privacy:metadata:core_notes');
        $collection->add_user_preference('local_ceduanalytics_menu', 'privacy:metadata:preference:menu');
        return $collection;
    }

    /**
     * Contexts with data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::user_has_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Whether a user has rows in any table.
     *
     * @param int $userid
     * @return bool
     */
    protected static function user_has_data(int $userid): bool {
        global $DB;
        foreach (array_keys(self::USERTABLES) as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                return true;
            }
        }
        foreach (self::STAFFFIELDS as $field) {
            if ($DB->record_exists('local_ceduanalytics_followup', [$field => $userid])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        if (!$userlist->get_context() instanceof \context_system) {
            return;
        }
        foreach (array_keys(self::USERTABLES) as $table) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}}", []);
        }
        foreach (self::STAFFFIELDS as $field) {
            $userlist->add_from_sql($field, "SELECT $field FROM {local_ceduanalytics_followup} WHERE $field > 0", []);
        }
    }

    /**
     * Export the data of a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_system) {
                continue;
            }
            foreach (self::USERTABLES as $table => $fields) {
                $rows = $DB->get_records($table, ['userid' => $userid], 'id', implode(',', array_merge(['id'], $fields)));
                if ($rows) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_ceduanalytics'), $table],
                        (object)['rows' => array_values($rows)]
                    );
                }
            }
            $staffrows = $DB->get_records_select(
                'local_ceduanalytics_followup',
                'assignedto = ? OR usermodified = ?',
                [$userid, $userid],
                'id',
                'id, courseid, reason, status, assignedto, usermodified, timemodified'
            );
            if ($staffrows) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_ceduanalytics'), 'local_ceduanalytics_followup_staff'],
                    (object)['rows' => array_values($staffrows)]
                );
            }
        }
    }

    /**
     * Export user preferences.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid) {
        $value = get_user_preferences('local_ceduanalytics_menu', null, $userid);
        if ($value !== null) {
            writer::export_user_preference(
                'local_ceduanalytics',
                'local_ceduanalytics_menu',
                $value,
                get_string('privacy:metadata:preference:menu', 'local_ceduanalytics')
            );
        }
    }

    /**
     * Delete all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;
        if (!$context instanceof \context_system) {
            return;
        }
        foreach (array_keys(self::USERTABLES) as $table) {
            $DB->delete_records($table);
        }
        $DB->delete_records('local_ceduanalytics_security');
    }

    /**
     * Delete the data of a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_system) {
                self::delete_users([(int)$contextlist->get_user()->id]);
            }
        }
    }

    /**
     * Delete the data of several users.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context() instanceof \context_system) {
            self::delete_users($userlist->get_userids());
        }
    }

    /**
     * Delete rows of the given users (and failed logins using their usernames).
     *
     * @param int[] $userids
     */
    protected static function delete_users(array $userids): void {
        global $DB;
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids);
        foreach (array_keys(self::USERTABLES) as $table) {
            $DB->delete_records_select($table, "userid $insql", $params);
        }
        foreach (self::STAFFFIELDS as $field) {
            $DB->set_field_select('local_ceduanalytics_followup', $field, 0, "$field $insql", $params);
        }
        $usernames = $DB->get_fieldset_select('user', 'username', "id $insql", $params);
        if ($usernames) {
            $DB->delete_records_list('local_ceduanalytics_security', 'username', $usernames);
        }
    }
}
