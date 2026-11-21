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

use local_ceduanalytics\local\snapshot;

/**
 * Tests of the enrolment snapshot: situations and the rebuild from Moodle data.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\snapshot
 */
final class snapshot_test extends \advanced_testcase {
    /** @var int Fixed "now" for the cases. */
    const NOW = 1790000000;

    /**
     * Situation of each case (course from 60 days ago to 40 days ahead, 14 days of inactivity, 25 p.p. margin).
     *
     * @dataProvider situation_provider
     * @param array $fields
     * @param array $expected
     */
    public function test_situation(array $fields, array $expected): void {
        $row = (object)($fields + ['timecertified' => 0, 'cmtotal' => 10, 'cmdone' => 6, 'progress' => 60,
            'lastaccess' => self::NOW - DAYSECS, 'timestart' => 0, 'timeend' => 0, 'timeenrolled' => self::NOW - 60 * DAYSECS]);
        $course = (object)['startdate' => self::NOW - 60 * DAYSECS, 'enddate' => self::NOW + 40 * DAYSECS];
        $this->assertSame($expected, snapshot::situation($row, $course, self::NOW, 14, 25.0));
    }

    /**
     * Cases, in the order the rules are checked.
     *
     * @return array
     */
    public static function situation_provider(): array {
        $now = self::NOW;
        return [
            'em dia' => [[], ['ontrack', '']],
            'certificado vence tudo' => [['timecertified' => $now, 'lastaccess' => 0], ['certified', '']],
            'concluiu sem certificado' => [['cmdone' => 10, 'progress' => 100], ['completed', 'nocertificate']],
            'nunca acessou' => [['lastaccess' => 0, 'cmdone' => 0, 'progress' => 0], ['notstarted', 'neveraccessed']],
            'matrícula encerrada' => [['timeend' => $now - DAYSECS], ['ended', 'endedincomplete']],
            'inativo' => [['lastaccess' => $now - 15 * DAYSECS], ['inactive', 'noaccess']],
            // 60% of the time has passed: 60 - 25 = 35% is the limit.
            'abaixo do ritmo' => [['cmdone' => 3, 'progress' => 30], ['attention', 'belowpace']],
            'no limite do ritmo' => [['cmdone' => 4, 'progress' => 40], ['ontrack', '']],
            'curso sem atividades rastreadas' => [['cmtotal' => 0, 'cmdone' => 0, 'progress' => 0], ['ontrack', '']],
        ];
    }

    /**
     * Rebuild from real Moodle records: roles, access, progress, certificate and the log fallback.
     */
    public function test_build(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;
        $gen = $this->getDataGenerator();
        $now = time();
        $course = $gen->create_course(['enablecompletion' => 1, 'startdate' => $now - 30 * DAYSECS,
            'enddate' => $now + 30 * DAYSECS]);
        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);

        $never = $gen->create_user();
        $active = $gen->create_user();
        $relogged = $gen->create_user();
        $certified = $gen->create_user();
        $teacher = $gen->create_user();
        foreach ([$never, $active, $relogged, $certified] as $u) {
            $gen->enrol_user($u->id, $course->id, 'student');
        }
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Active student: recent access and one of the two activities done.
        $DB->insert_record('user_lastaccess', (object)['userid' => $active->id, 'courseid' => $course->id,
            'timeaccess' => $now - DAYSECS]);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $DB->insert_record('course_modules_completion', (object)['coursemoduleid' => $cm->id, 'userid' => $active->id,
            'completionstate' => COMPLETION_COMPLETE, 'timemodified' => $now - DAYSECS]);

        // Re-enrolled student: no user_lastaccess row, but the aggregated log has the visits.
        $DB->insert_record('local_ceduanalytics_daily', (object)['userid' => $relogged->id, 'courseid' => $course->id,
            'day' => usergetmidnight($now - 2 * DAYSECS), 'events' => 5, 'views' => 5, 'writes' => 0, 'wsevents' => 0,
            'logins' => 0, 'activesecs' => 600, 'firstts' => $now - 2 * DAYSECS, 'lastts' => $now - 2 * DAYSECS + 600]);

        // Certified student.
        $hascert = $DB->get_manager()->table_exists('customcert_issues');
        if ($hascert) {
            $cert = $gen->create_module('customcert', ['course' => $course->id]);
            $DB->insert_record('customcert_issues', (object)['userid' => $certified->id, 'customcertid' => $cert->id,
                'code' => 'TESTCODE01', 'emailed' => 0, 'timecreated' => $now - DAYSECS]);
        }

        $this->assertSame(5, snapshot::build());
        $rows = [];
        foreach ($DB->get_records('local_ceduanalytics_enrolstate', ['courseid' => $course->id]) as $r) {
            $rows[$r->userid] = $r;
        }
        $this->assertCount(5, $rows);
        $this->assertEquals(0, $rows[$teacher->id]->isstudent);
        $this->assertSame('notstarted', $rows[$never->id]->situation);

        $this->assertSame('ontrack', $rows[$active->id]->situation);
        $this->assertEquals(2, $rows[$active->id]->cmtotal);
        $this->assertEquals(1, $rows[$active->id]->cmdone);
        $this->assertEquals(50, $rows[$active->id]->progress);

        $this->assertNotSame('notstarted', $rows[$relogged->id]->situation);
        $this->assertEquals($now - 2 * DAYSECS, $rows[$relogged->id]->firstaccess);
        $this->assertEquals($now - 2 * DAYSECS + 600, $rows[$relogged->id]->lastaccess);

        if ($hascert) {
            $this->assertSame('certified', $rows[$certified->id]->situation);
        }

        // Rebuilding keeps one row per enrolment and drops the ones that no longer exist.
        $DB->delete_records('user_enrolments', ['userid' => $never->id]);
        $this->assertSame(4, snapshot::build());
        $this->assertFalse($DB->record_exists('local_ceduanalytics_enrolstate', ['userid' => $never->id]));
    }
}
