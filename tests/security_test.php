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

use local_ceduanalytics\local\filters;
use local_ceduanalytics\local\navigation;
use local_ceduanalytics\local\page\base;

/**
 * Security tests: every page with hostile names (no HTML injection) and access control.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\page\base
 * @covers     \local_ceduanalytics\external\search
 * @covers     \local_ceduanalytics\external\video_progress
 */
final class security_test extends \advanced_testcase {
    /** @var string Text that would run code if printed without escaping (in content and in attributes). */
    const PAYLOAD = '<script>xss()</script>"\'><img src=x onerror=xss()> & Co';

    /**
     * Every page and every export list with hostile names: nothing comes out as HTML.
     */
    public function test_pages_escape_hostile_names(): void {
        global $DB, $PAGE, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        $gen = $this->getDataGenerator();
        $now = time();

        $category = $gen->create_category(['name' => self::PAYLOAD]);
        $course = $gen->create_course(['fullname' => self::PAYLOAD, 'shortname' => 'X' . self::PAYLOAD,
            'category' => $category->id, 'enablecompletion' => 1, 'startdate' => $now - 30 * DAYSECS,
            'enddate' => $now + 30 * DAYSECS]);
        $gen->create_module('page', ['course' => $course->id, 'name' => self::PAYLOAD,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $gen->create_module('assign', ['course' => $course->id, 'name' => self::PAYLOAD]);
        // Moodle cleans names typed in forms; data imported or older may still hold anything, so it is stored directly.
        $student = $gen->create_user();
        $teacher = $gen->create_user();
        foreach (['firstname', 'lastname', 'city', 'institution', 'department'] as $field) {
            $DB->set_field('user', $field, self::PAYLOAD, ['id' => $student->id]);
        }
        $DB->set_field('user', 'firstname', self::PAYLOAD, ['id' => $teacher->id]);
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $group = $gen->create_group(['courseid' => $course->id, 'name' => self::PAYLOAD]);
        $gen->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $cohort = $gen->create_cohort(['name' => self::PAYLOAD]);
        cohort_add_member($cohort->id, $student->id);
        $gen->create_event(['name' => self::PAYLOAD, 'courseid' => $course->id, 'eventtype' => 'course',
            'timestart' => $now + DAYSECS, 'location' => self::PAYLOAD]);
        $DB->insert_record('user_lastaccess', (object)['userid' => $student->id, 'courseid' => $course->id,
            'timeaccess' => $now - 20 * DAYSECS]);
        $DB->insert_record('local_ceduanalytics_courseline', (object)['name' => self::PAYLOAD, 'kind' => 'track',
            'timecreated' => $now, 'timemodified' => $now]);
        \local_ceduanalytics\local\attributes::derive();
        \local_ceduanalytics\local\snapshot::build();

        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url('/local/ceduanalytics/index.php');
        $ids = ['student' => $student->id, 'teacher' => $teacher->id, 'course' => $course->id];
        $states = ['agenda' => [[], ['when' => 'all']], 'students' => [[], ['view' => 'enrolments']],
            'security' => [[], ['tab' => 'performance']], 'reports' => [[], ['view' => 'bulletin']],
            'config' => [['tab' => 'courses'], ['tab' => 'data'], ['tab' => 'segments']]];
        foreach (array_keys(navigation::pages()) as $pageid) {
            foreach ($states[$pageid] ?? [[]] as $state) {
                $_GET = ['p' => $pageid, 'period' => 'all'] + $state + (isset($ids[$pageid]) ? ['id' => $ids[$pageid]] : []);
                $page = base::create(navigation::page($pageid), filters::from_request());
                $html = $page->content() . $page->extras_html();
                $where = $pageid . ' ' . json_encode($state);
                $this->assertStringNotContainsString('<script>xss', $html, $where);
                $this->assertStringNotContainsString('<img src=x', $html, $where);
                $this->assertDoesNotMatchRegularExpression('/"\'?><img/', $html, $where);
                if ($pageid === 'student' || $pageid === 'students') {
                    // Proof that the hostile name reaches the page, escaped.
                    $this->assertStringContainsString('&lt;script&gt;xss()&lt;/script&gt;', $html, $where);
                }
                foreach (array_keys($page->all_datasets()) as $dsid) {
                    $ds = $page->find_dataset((string)$dsid);
                    $this->assertNotNull($ds, "$where / $dsid");
                    $table = json_encode($ds->export_for_template());
                    $this->assertStringNotContainsString('<script>xss', $table, "$where / $dsid");
                    $this->assertStringNotContainsString('<img src=x', $table, "$where / $dsid");
                    // Exported files keep the text as typed (no entities).
                    foreach ($ds->export_rows() as $row) {
                        foreach ($row as $cell) {
                            $this->assertStringNotContainsString('&amp;', (string)$cell, "$where / $dsid");
                        }
                    }
                }
            }
        }
        $_GET = [];
    }

    /**
     * The capabilities are given to nobody by default (only site administrators have them).
     */
    public function test_capabilities_have_no_archetypes(): void {
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/local/ceduanalytics/db/access.php');
        $this->assertNotEmpty($capabilities);
        foreach ($capabilities as $name => $def) {
            $this->assertEmpty($def['archetypes'] ?? [], $name);
            $this->assertSame(CONTEXT_SYSTEM, $def['contextlevel'], $name);
        }
    }

    /**
     * Teachers, managers of a course and students cannot open the dashboard nor use its search.
     */
    public function test_access_denied_to_other_users(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $manager = $gen->create_user();
        $gen->role_assign('manager', $manager->id, \context_course::instance($course->id)->id);
        $system = \context_system::instance();
        foreach ([$teacher, $manager] as $user) {
            $this->setUser($user);
            foreach (['view', 'export', 'contactstudents', 'configure'] as $cap) {
                $this->assertFalse(has_capability('local/ceduanalytics:' . $cap, $system), $cap);
            }
            try {
                \local_ceduanalytics\external\search::execute('ab');
                $this->fail('search should be denied');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
        $this->setAdminUser();
        $this->assertTrue(has_capability('local/ceduanalytics:view', $system));
    }

    /**
     * Video progress is only recorded for activities the person can see.
     */
    public function test_video_progress_requires_access(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id]);
        $outsider = $gen->create_user();
        $this->setUser($outsider);
        try {
            \local_ceduanalytics\external\video_progress::execute($page->cmid, 'abcdefghijk', 100, 'ff', 1, 0);
            $this->fail('an outsider should not record progress');
        } catch (\require_login_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(0, $DB->count_records('local_ceduanalytics_video'));

        // A student of the course records it; oversized data is refused.
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $ok = \local_ceduanalytics\external\video_progress::execute($page->cmid, 'abcdefghijk', 100, 'ff', 1, 0);
        $this->assertTrue($ok['recorded']);
        $big = \local_ceduanalytics\external\video_progress::execute($page->cmid, 'abcdefghijk', 100, str_repeat('f', 1001), 1, 0);
        $this->assertFalse($big['recorded']);
    }
}
