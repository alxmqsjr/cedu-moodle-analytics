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
use local_ceduanalytics\local\scope;

/**
 * Tests of the course filters (category, line, course).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\scope
 */
final class scope_test extends \advanced_testcase {
    /**
     * Courses selected by the given request parameters.
     *
     * @param array $get
     * @return int[]|null
     */
    protected function courses(array $get): ?array {
        $_GET = $get;
        $ids = (new scope(filters::from_request()))->course_ids();
        $_GET = [];
        if ($ids !== null) {
            sort($ids);
        }
        return $ids;
    }

    /**
     * A category includes its subcategories; items that do not exist select nothing (never every course).
     */
    public function test_course_filters(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $parent = $gen->create_category();
        $child = $gen->create_category(['parent' => $parent->id]);
        $other = $gen->create_category();
        $c1 = $gen->create_course(['category' => $parent->id]);
        $c2 = $gen->create_course(['category' => $child->id]);
        $c3 = $gen->create_course(['category' => $other->id]);

        $this->assertNull($this->courses([]));
        $this->assertSame([(int)$c1->id, (int)$c2->id], $this->courses(['category' => $parent->id]));
        $this->assertSame([(int)$c3->id], $this->courses(['category' => $other->id]));
        $this->assertSame([(int)$c2->id], $this->courses(['course' => $c2->id]));
        $this->assertSame([], $this->courses(['category' => 999999]));
        $this->assertSame([], $this->courses(['course' => 999999]));
        $this->assertSame([], $this->courses(['line' => 999999]));
        $this->assertSame([], $this->courses(['category' => $other->id, 'course' => $c1->id]));
    }
}
