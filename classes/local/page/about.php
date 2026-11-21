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

namespace local_ceduanalytics\local\page;

use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\navigation;
use local_ceduanalytics\local\snapshot;

/**
 * About: what the dashboard is, who made it, how to read it (common explanations), a detailed guide by menu and
 * the glossary.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class about extends base {
    /** @var string[] Glossary terms (strings about_term_<key> and about_term_<key>_def). */
    const TERMS = ['student', 'enrolment', 'period', 'comparison', 'active', 'situation', 'progress', 'pace', 'completion',
        'offseason', 'line', 'health', 'segment', 'uf', 'lowconf', 'queue', 'effectiveness', 'unanswered', 'replytime',
        'watched', 'severity'];

    /** @var string[] Common reading topics (about_read_<key> and about_read_<key>_text). */
    const READ = ['filters', 'numbers', 'tables', 'empty', 'export', 'views', 'search', 'data', 'privacy'];

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        global $CFG;
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_ceduanalytics');

        $read = [];
        foreach (self::READ as $k) {
            $read[] = ['title' => $s('about_read_' . $k), 'text' => $s('about_read_' . $k . '_text')];
        }
        $terms = [];
        foreach (self::TERMS as $k) {
            $terms[] = ['term' => $s('about_term_' . $k), 'def' => $s('about_term_' . $k . '_def')];
        }
        $situations = [];
        foreach (array_reverse(snapshot::SITUATIONS) as $sit) {
            $situations[] = ['label' => $s('situation_' . $sit), 'help' => $s('situation_' . $sit . '_help'), 'key' => $sit];
        }
        $groups = [];
        foreach (navigation::pages() as $id => $def) {
            if (!empty($def['hidden']) || $id === $this->id()) {
                continue;
            }
            $g = $def['group'];
            $groups[$g] = $groups[$g] ?? ['name' => $s('group_' . $g), 'pages' => []];
            $lines = array_map(fn($l) => ['text' => $l], array_filter(array_map('trim', explode("\n", $s('about_page_' . $id)))));
            $groups[$g]['pages'][] = ['id' => $id, 'title' => $s('page_' . $id), 'question' => $s('page_' . $id . '_q'),
                'lines' => array_values($lines), 'icon' => $def['icon'], 'url' => $this->link($id)->out(false)];
        }
        $last = (int)get_config('local_ceduanalytics', 'lastsnapshot');

        return $this->render('about', [
            'logourl' => local_ceduanalytics_get_logo_url(),
            'version' => $plugin ? $plugin->release . ' (' . $plugin->versiondisk . ')' : '',
            'moodle' => $CFG->release,
            'lastsnapshot' => $last ? fmt::datetime($last) : $s('never'),
            'read' => $read,
            'terms' => $terms,
            'situations' => $situations,
            'groups' => array_values($groups),
        ]);
    }
}
