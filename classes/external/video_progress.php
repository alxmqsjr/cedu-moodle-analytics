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
use core_external\external_single_structure;
use core_external\external_value;
use local_ceduanalytics\local\videos;

/**
 * Records the playback of an embedded YouTube video by the current user (called by amd/videotrack).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class video_progress extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module holding the video'),
            'videoid' => new external_value(PARAM_ALPHANUMEXT, 'YouTube video id'),
            'duration' => new external_value(PARAM_INT, 'Duration in seconds, 0 if unknown', VALUE_DEFAULT, 0),
            'segments' => new external_value(PARAM_ALPHANUM, 'Hex bitmap of the 5-second parts played', VALUE_DEFAULT, ''),
            'plays' => new external_value(PARAM_INT, 'New play starts', VALUE_DEFAULT, 0),
            'linkclicks' => new external_value(PARAM_INT, 'New clicks on links to the video', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Record.
     *
     * @param int $cmid
     * @param string $videoid
     * @param int $duration
     * @param string $segments
     * @param int $plays
     * @param int $linkclicks
     * @return array
     */
    public static function execute(
        int $cmid,
        string $videoid,
        int $duration = 0,
        string $segments = '',
        int $plays = 0,
        int $linkclicks = 0
    ): array {
        global $USER;
        $p = self::validate_parameters(self::execute_parameters(), compact(
            'cmid',
            'videoid',
            'duration',
            'segments',
            'plays',
            'linkclicks'
        ));
        [$course, $cm] = get_course_and_cm_from_cmid($p['cmid']);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        if (
            !videos::enabled() || isguestuser() || !$cm->uservisible || strlen($p['videoid']) > 20
                || strlen($p['segments']) > 1000
        ) {
            return ['recorded' => false, 'watched' => 0];
        }
        $row = videos::record(
            (int)$USER->id,
            $cm,
            $p['videoid'],
            $p['duration'],
            $p['segments'],
            $p['plays'],
            $p['linkclicks']
        );
        return ['recorded' => true, 'watched' => (int)$row->watchedsecs];
    }

    /**
     * Result.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'recorded' => new external_value(PARAM_BOOL, 'Whether the progress was recorded'),
            'watched' => new external_value(PARAM_INT, 'Distinct seconds watched so far'),
        ]);
    }
}
