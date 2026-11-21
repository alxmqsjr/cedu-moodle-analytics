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

use local_ceduanalytics\local\dataset;

/**
 * Tests of the tables shown on screen and exported.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\dataset
 */
final class dataset_test extends \advanced_testcase {
    /**
     * A name with "&" is escaped once on screen and kept as typed in the export.
     */
    public function test_escaping(): void {
        $ds = new dataset('x', 'X', ['course' => 'Course', 'n' => 'N'], ['n']);
        $ds->add(['course' => format_string('P&D'), 'n' => 3]);
        $ds->add(['course' => 'Plain', 'n' => 1, '_html' => ['course' => '<a href="#">Plain</a>']]);

        $this->assertSame([['P&D', 3], ['Plain', 1]], iterator_to_array($ds->export_rows()));

        $ctx = $ds->export_for_template();
        $this->assertSame('P&amp;D', $ctx['rows'][0]['cells'][0]['html']);
        $this->assertSame('<a href="#">Plain</a>', $ctx['rows'][1]['cells'][0]['html']);
        $this->assertTrue($ctx['rows'][0]['cells'][1]['numeric']);
        $this->assertSame(2, $ctx['count']);
        $this->assertTrue($ctx['sortable']);
    }

    /**
     * On screen the e-mail goes under the person; the export keeps both columns.
     */
    public function test_email_under_person(): void {
        $ds = new dataset('x', 'X', ['student' => 'Student', 'email' => 'E-mail', 'course' => 'Course']);
        $ds->add(['student' => 'Ana', 'email' => 'ana@example.com', 'course' => 'C1']);

        $ctx = $ds->export_for_template();
        $this->assertCount(2, $ctx['headers']);
        $this->assertStringContainsString('ana@example.com', $ctx['rows'][0]['cells'][0]['html']);
        $this->assertStringContainsString('cea-email', $ctx['rows'][0]['cells'][0]['html']);
        $this->assertSame(['student', 'email', 'course'], array_keys($ds->export_columns()));
    }

    /**
     * Screen-only columns are left out of the export.
     */
    public function test_screenonly(): void {
        $ds = new dataset('x', 'X', ['select' => '', 'name' => 'Name']);
        $ds->screenonly = ['select'];
        $ds->add(['select' => '', 'name' => 'Ana']);
        $this->assertSame(['name'], array_keys($ds->export_columns()));
        $this->assertSame([['Ana']], iterator_to_array($ds->export_rows()));
    }

    /**
     * Consecutive rows of the same student share the merged cells (rowspan).
     */
    public function test_merge(): void {
        $ds = new dataset('x', 'X', ['student' => 'Student', 'course' => 'Course']);
        $ds->add(['student' => 'Ana', 'course' => 'C1', '_mergekey' => 'u1']);
        $ds->add(['student' => 'Ana', 'course' => 'C2', '_mergekey' => 'u1']);
        $ds->add(['student' => 'Bia', 'course' => 'C1', '_mergekey' => 'u2']);
        $ds->merge(['student']);

        $ctx = $ds->export_for_template();
        $this->assertFalse($ctx['sortable']);
        $this->assertSame(2, $ctx['groups']);
        $this->assertSame(2, $ctx['rows'][0]['cells'][0]['rowspan']);
        $this->assertCount(1, $ctx['rows'][1]['cells']);
        $this->assertSame('C2', $ctx['rows'][1]['cells'][0]['html']);
        $this->assertCount(2, $ctx['rows'][2]['cells']);
        // The export repeats the student on every row.
        $this->assertCount(3, iterator_to_array($ds->export_rows()));
    }

    /**
     * Empty table.
     */
    public function test_empty(): void {
        $ds = new dataset('x', 'X', ['a' => 'A']);
        $ctx = $ds->export_for_template();
        $this->assertFalse($ctx['hasrows']);
        $this->assertSame(get_string('empty_generic', 'local_ceduanalytics'), $ctx['emptytext']);
    }
}
