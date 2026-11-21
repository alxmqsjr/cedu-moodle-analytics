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
 * People (user records) loaded in batches and kept for the request, instead of one query per person.
 *
 * Only the fields the dashboard shows are loaded (names, e-mail, picture, access and status); never the password hash.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class people {
    /** @var array userid => record|null (null = does not exist) */
    protected static $cache = [];

    /**
     * Fields loaded.
     *
     * @return string
     */
    protected static function fields(): string {
        $fields = \core_user\fields::for_userpic()->including(
            'username',
            'email',
            'lastaccess',
            'timecreated',
            'deleted',
            'suspended',
            'auth',
            'city'
        );
        return $fields->get_sql('', false, '', '', false)->selects;
    }

    /**
     * Load the given people in one query (those already loaded are skipped).
     *
     * @param int[] $ids
     */
    public static function preload(array $ids): void {
        global $DB;
        $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $ids))), array_keys(self::$cache)));
        foreach (array_chunk($ids, 1000) as $chunk) {
            $records = $DB->get_records_list('user', 'id', $chunk, '', self::fields());
            foreach ($chunk as $id) {
                self::$cache[$id] = $records[$id] ?? null;
            }
        }
    }

    /**
     * One person (loaded on demand when not preloaded).
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, self::$cache)) {
            self::preload([$id]);
        }
        return self::$cache[$id];
    }

    /**
     * Full name of a person, or "#id" when the record no longer exists.
     *
     * @param int $id
     * @return string
     */
    public static function name(int $id): string {
        $u = self::get($id);
        return $u ? fullname($u) : '#' . $id;
    }

    /** @var array<int, bool>|null People who are students in a course and teachers in a course (the same or another). */
    protected static $dual = null;

    /**
     * Whether the person is a student and also a teacher (editing or non-editing) in some course.
     *
     * Comes from the enrolment snapshot, which keeps the role short names of every enrolment.
     *
     * @param int $id
     * @return bool
     */
    public static function is_dual(int $id): bool {
        global $DB;
        if (self::$dual === null) {
            self::$dual = [];
            $like = $DB->sql_like('roles', ':teacher');
            $ids = $DB->get_fieldset_sql("
                SELECT userid
                  FROM {local_ceduanalytics_enrolstate}
              GROUP BY userid
                HAVING MAX(isstudent) = 1 AND SUM(CASE WHEN $like THEN 1 ELSE 0 END) > 0", ['teacher' => '%teacher%']);
            foreach ($ids as $uid) {
                self::$dual[(int)$uid] = true;
            }
        }
        return isset(self::$dual[$id]);
    }

    /**
     * Red mark shown after the name of a person who is both student and teacher.
     *
     * @param int $id
     * @param string $as 'student' when the name is shown as a student, 'teacher' when shown as a member of the team
     * @return string HTML ('' for everybody else)
     */
    public static function dual_mark(int $id, string $as = 'student'): string {
        if (!self::is_dual($id)) {
            return '';
        }
        $text = get_string($as === 'teacher' ? 'dual_teacher' : 'dual_student', 'local_ceduanalytics');
        return ' ' . \html_writer::span(
            \html_writer::tag('i', '', ['class' => 'fa-solid fa-circle-exclamation', 'aria-hidden' => 'true']) .
                \html_writer::span(s($text), 'sr-only'),
            'cea-dual',
            ['title' => $text, 'data-toggle' => 'tooltip']
        );
    }

    /**
     * Forget the loaded records (tests).
     */
    public static function reset(): void {
        self::$cache = [];
        self::$dual = null;
    }
}
