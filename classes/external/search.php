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

namespace local_ceduanalytics\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Global search (Ctrl+K): students, courses and teachers.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Text to search'),
        ]);
    }

    /**
     * Search.
     *
     * @param string $query
     * @return array
     */
    public static function execute(string $query): array {
        global $DB;
        ['query' => $query] = self::validate_parameters(self::execute_parameters(), ['query' => $query]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/ceduanalytics:view', $context);

        $query = trim($query);
        if (\core_text::strlen($query) < 2) {
            return ['results' => []];
        }
        $terms = array_slice(preg_split('/\s+/', $query), 0, 4);
        $results = [];

        // People: every term must match a name, the e-mail or the username.
        $where = ['u.deleted = 0', 'u.id > 1'];
        $params = [];
        foreach ($terms as $i => $term) {
            $or = [];
            foreach (['firstname', 'lastname', 'email', 'username'] as $f) {
                $or[] = $DB->sql_like('u.' . $f, ":t{$i}{$f}", false, false);
                $params["t{$i}{$f}"] = '%' . $DB->sql_like_escape($term) . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $namefields = implode(', ', array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields()));
        $users = $DB->get_records_sql("SELECT u.id, u.email, $namefields,
                       (SELECT COUNT(1) FROM {local_ceduanalytics_enrolstate} e
                         WHERE e.userid = u.id AND e.isstudent = 1) AS studentin,
                       (SELECT COUNT(1) FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                         WHERE ra.userid = u.id AND r.shortname IN ('editingteacher', 'teacher')) AS teaches
                  FROM {user} u
                 WHERE " . implode(' AND ', $where) . '
              ORDER BY u.lastaccess DESC', $params, 0, 12);
        foreach ($users as $u) {
            if ($u->teaches) {
                $results[] = [
                    'type' => 'teacher',
                    'typelabel' => get_string('search_teacher', 'local_ceduanalytics'),
                    'title' => fullname($u),
                    'subtitle' => $u->email . ' · ' . get_string('search_teaches', 'local_ceduanalytics', (int)$u->teaches) .
                        ($u->studentin ? ' · ' . get_string('dual_teacher', 'local_ceduanalytics') : ''),
                    'url' => \local_ceduanalytics\local\navigation::url('teacher', ['id' => $u->id])->out(false),
                ];
            }
            if ($u->studentin || !$u->teaches) {
                $results[] = [
                    'type' => 'student',
                    'typelabel' => get_string('search_student', 'local_ceduanalytics'),
                    'title' => fullname($u),
                    'subtitle' => $u->email . ' · ' . get_string('search_enrolled', 'local_ceduanalytics', (int)$u->studentin) .
                        ($u->teaches && $u->studentin ? ' · ' . get_string('dual_student', 'local_ceduanalytics') : ''),
                    'url' => \local_ceduanalytics\local\navigation::url('student', ['id' => $u->id])->out(false),
                ];
            }
        }

        // Courses.
        $where = ['c.id <> :site'];
        $params = ['site' => SITEID];
        foreach ($terms as $i => $term) {
            $where[] = '(' . $DB->sql_like('c.fullname', ":c{$i}a", false, false) . ' OR ' .
                $DB->sql_like('c.shortname', ":c{$i}b", false, false) . ')';
            $params["c{$i}a"] = '%' . $DB->sql_like_escape($term) . '%';
            $params["c{$i}b"] = '%' . $DB->sql_like_escape($term) . '%';
        }
        $courses = $DB->get_records_sql('SELECT c.id, c.fullname, c.shortname, c.visible FROM {course} c WHERE ' .
            implode(' AND ', $where) . ' ORDER BY c.startdate DESC', $params, 0, 8);
        foreach ($courses as $c) {
            $results[] = [
                'type' => 'course',
                'typelabel' => get_string('search_course', 'local_ceduanalytics'),
                'title' => \local_ceduanalytics\local\fmt::text($c->fullname),
                'subtitle' => \local_ceduanalytics\local\fmt::text($c->shortname) .
                    ($c->visible ? '' : ' · ' . get_string('visible_no', 'local_ceduanalytics')),
                'url' => \local_ceduanalytics\local\navigation::url('course', ['id' => $c->id])->out(false),
            ];
        }
        return ['results' => array_slice($results, 0, 20)];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHA, 'student, teacher or course'),
                'typelabel' => new external_value(PARAM_TEXT, 'Type label'),
                'title' => new external_value(PARAM_TEXT, 'Title'),
                'subtitle' => new external_value(PARAM_TEXT, 'Subtitle'),
                'url' => new external_value(PARAM_URL, 'Destination'),
            ])),
        ]);
    }
}
