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

use local_ceduanalytics\local\videos;

/**
 * Tests of the video playback bitmaps (one bit per part of the video watched).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\videos
 */
final class videos_test extends \advanced_testcase {
    /**
     * Union of the parts watched in two sessions.
     */
    public function test_merge(): void {
        $this->assertSame('03', videos::merge('01', '02'));
        $this->assertSame('ff01', videos::merge('ff', '0001'));
        $this->assertSame('f0', videos::merge('f', ''));
        $this->assertSame('', videos::merge('', ''));
        $this->assertSame('0f', videos::merge('zz0f', '08'));
    }

    /**
     * Number of parts watched.
     */
    public function test_count(): void {
        $this->assertSame(0, videos::count(''));
        $this->assertSame(0, videos::count('zz'));
        $this->assertSame(1, videos::count('01'));
        $this->assertSame(8, videos::count('FF'));
        $this->assertSame(9, videos::count('ff01'));
        $this->assertSame(16, videos::count(videos::merge('ff00', '00ff')));
    }

    /**
     * Tracking is on by default and can be switched off.
     */
    public function test_enabled(): void {
        $this->resetAfterTest();
        $this->assertTrue(videos::enabled());
        set_config('videotracking', 0, 'local_ceduanalytics');
        $this->assertFalse(videos::enabled());
    }
}
