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

use local_ceduanalytics\local\people;

/**
 * Tests of the people helper: who is both student and teacher.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\people
 */
final class people_test extends \advanced_testcase {
    /**
     * Snapshot row.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $roles
     */
    protected function snapshot_row(int $userid, int $courseid, string $roles): void {
        global $DB;
        $DB->insert_record('local_ceduanalytics_enrolstate', (object)[
            'userid' => $userid, 'courseid' => $courseid, 'roles' => $roles,
            'isstudent' => in_array('student', explode(',', $roles)) ? 1 : 0,
            'enrolmethods' => 'manual', 'situation' => 'ontrack', 'reasons' => '',
        ]);
    }

    /**
     * Student in one course and teacher in another (or in the same) is dual; the others are not.
     */
    public function test_dual(): void {
        $this->resetAfterTest();
        people::reset();
        $this->snapshot_row(10, 1, 'student');
        $this->snapshot_row(10, 2, 'editingteacher');
        $this->snapshot_row(11, 1, 'student');
        $this->snapshot_row(12, 2, 'teacher');
        $this->snapshot_row(13, 3, 'teacher,student');
        $this->snapshot_row(14, 3, 'manager,student');

        $this->assertTrue(people::is_dual(10));
        $this->assertFalse(people::is_dual(11));
        $this->assertFalse(people::is_dual(12));
        $this->assertTrue(people::is_dual(13));
        $this->assertFalse(people::is_dual(14));

        $this->assertSame('', people::dual_mark(11));
        $mark = people::dual_mark(10);
        $this->assertStringContainsString('cea-dual', $mark);
        $this->assertStringContainsString(get_string('dual_student', 'local_ceduanalytics'), $mark);
        $this->assertStringContainsString(get_string('dual_teacher', 'local_ceduanalytics'), people::dual_mark(10, 'teacher'));
        people::reset();
    }
}
