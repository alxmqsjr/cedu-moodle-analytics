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

use local_ceduanalytics\local\fmt;

/**
 * Tests of the number, text and variation formatting.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\fmt
 */
final class fmt_test extends \advanced_testcase {
    /**
     * Names from Moodle become plain text: no entities and no tags, so they are escaped only once on output.
     */
    public function test_text_and_plain(): void {
        $this->resetAfterTest();
        $this->assertSame('A & B "q" a < b', fmt::text('A & B "q" a < b'));
        $this->assertSame('Curso x', fmt::text('Curso <b>x</b>'));
        $this->assertSame('', fmt::text(null));
        $this->assertSame('P&D', fmt::plain(format_string('P&D')));
        $this->assertSame('A & B', fmt::plain('<span>A &amp; B</span>'));
    }

    /**
     * Integers, decimals, percentages and hours.
     */
    public function test_numbers(): void {
        $this->assertSame('1,234', fmt::int(1234));
        $this->assertSame('2.5', fmt::dec(2.5));
        // Percentages always with two decimals; 0 and 100 are whole.
        $this->assertSame('25.00%', fmt::pct(1, 4));
        $this->assertSame('33.33%', fmt::pct(1, 3));
        $this->assertSame('53.85%', fmt::pct(7, 13));
        $this->assertSame('100%', fmt::pct(4, 4));
        $this->assertSame('0%', fmt::pct(0, 4));
        $this->assertSame('99.99%', fmt::percent(99.994));
        $this->assertSame('100%', fmt::percent(99.996));
        $this->assertSame('12.50', fmt::percentnum(12.5));
        $this->assertSame('—', fmt::pct(5, 0));
        $this->assertSame('1.5 h', fmt::hours(5400));
        $this->assertSame('12 h', fmt::hours(12 * 3600));
    }

    /**
     * Small bases are flagged with the configured minimum (default 5).
     */
    public function test_lowconf(): void {
        $this->resetAfterTest();
        $this->assertTrue(fmt::lowconf(3));
        $this->assertFalse(fmt::lowconf(5));
        $this->assertFalse(fmt::lowconf(0));
        set_config('mincount', 10, 'local_ceduanalytics');
        $this->assertTrue(fmt::lowconf(9));
    }

    /**
     * Variation against the previous period.
     */
    public function test_delta(): void {
        $this->assertNull(fmt::delta(null, 10));

        $up = fmt::delta(110, 100);
        $this->assertSame('+10.00%', $up['text']);
        $this->assertSame('up', $up['direction']);
        $this->assertTrue($up['good']);

        $down = fmt::delta(90, 100);
        $this->assertSame('-10.00%', $down['text']);
        $this->assertSame('down', $down['direction']);
        $this->assertFalse($down['good']);

        // Lower is better (e.g. students who never accessed).
        $this->assertTrue(fmt::delta(90, 100, false)['good']);

        $this->assertSame('flat', fmt::delta(100, 100.2)['direction']);
        $this->assertSame('=', fmt::delta(0, 0)['text']);
        $this->assertSame('up', fmt::delta(5, 0)['direction']);

        $points = fmt::delta(55, 50, true, true);
        $this->assertSame('+5.00 p.p.', $points['text']);
        $this->assertSame('up', $points['direction']);
    }

    /**
     * Dates: zero means no date.
     */
    public function test_dates(): void {
        $this->assertSame('—', fmt::date(0));
        $this->assertSame('—', fmt::datetime(0));
        $this->assertSame(get_string('never'), fmt::ago(0));
        $this->assertSame(get_string('today_lower', 'local_ceduanalytics'), fmt::ago(time()));
    }
}
