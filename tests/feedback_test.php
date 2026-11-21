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

use local_ceduanalytics\local\page\feedback;

/**
 * Tests of the reading of mod_feedback multiple-choice options.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\page\feedback
 */
final class feedback_test extends \advanced_testcase {
    /**
     * Mode (r = one answer, c = several, d = dropdown), options numbered from 1, values and settings removed.
     */
    public function test_options(): void {
        $this->assertSame(['r', [1 => 'Sim', 2 => 'Não']], feedback::options('r>>>>>Sim|Não'));
        $this->assertSame(['c', [1 => 'A', 2 => 'B', 3 => 'C']], feedback::options("c>>>>>A\n|B|C<<<<<1"));
        $this->assertSame(['', [1 => 'Ruim', 2 => 'Bom']], feedback::options('1####Ruim|2####Bom'));
        $this->assertSame(['d', [1 => 'Fraco', 2 => 'Ótimo']], feedback::options('d>>>>>-1####Fraco|2.5####Ótimo'));
    }
}
