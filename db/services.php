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

/**
 * External functions of Cedu Moodle Analytics.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ceduanalytics_search' => [
        'classname' => \local_ceduanalytics\external\search::class,
        'description' => 'Global search of the dashboard: students, courses and teachers.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/ceduanalytics:view',
        'loginrequired' => true,
    ],
    'local_ceduanalytics_video_progress' => [
        'classname' => \local_ceduanalytics\external\video_progress::class,
        'description' => 'Records the playback of an embedded YouTube video by the current user.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
