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

namespace local_ceduanalytics\local;

/**
 * Menu registry: groups and pages of the dashboard.
 *
 * Each page has: group, Font Awesome icon, the phase in which it is delivered, the filters it
 * uses and, when implemented, its class. Pages without class render the "coming soon" state.
 * Strings: page_<id> (title), page_<id>_q (question), page_<id>_desc (what it will show).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class navigation {
    /** @var string[] Groups in order. */
    const GROUPS = ['start', 'students', 'courses', 'team', 'reach', 'reports', 'platform'];

    /** @var string Default page. */
    const DEFAULT = 'home';

    /** @var string[] Menu groups open until the user changes them. */
    const DEFAULT_OPEN = ['students'];

    /**
     * Page definitions.
     *
     * @return array id => definition
     */
    public static function pages(): array {
        $period = ['period'];
        $all = ['period', 'category', 'line', 'course', 'uf', 'segment'];
        return [
            'home' => ['group' => 'start', 'icon' => 'fa-house', 'phase' => 2, 'filters' => $all,
                'class' => page\home::class],
            'agenda' => ['group' => 'start', 'icon' => 'fa-calendar-days', 'phase' => 10,
                'filters' => ['category', 'line', 'course'], 'class' => page\agenda::class],
            'students' => ['group' => 'students', 'icon' => 'fa-users', 'phase' => 2, 'filters' => $all,
                'class' => page\students::class],
            'student' => ['group' => 'students', 'icon' => 'fa-user', 'phase' => 2, 'filters' => [],
                'class' => page\student::class, 'hidden' => true, 'parent' => 'students'],
            'arrivals' => ['group' => 'students', 'icon' => 'fa-door-open', 'phase' => 2, 'filters' => $all,
                'class' => page\arrivals::class],
            'participation' => ['group' => 'students', 'icon' => 'fa-chart-line', 'phase' => 2, 'filters' => $all,
                'class' => page\participation::class],
            'support' => ['group' => 'students', 'icon' => 'fa-user-clock', 'phase' => 4, 'filters' => $all,
                'counter' => 'support', 'class' => page\support::class],
            'completions' => ['group' => 'students', 'icon' => 'fa-award', 'phase' => 3, 'filters' => $all,
                'class' => page\completions::class],
            'courses' => ['group' => 'courses', 'icon' => 'fa-book-open', 'phase' => 3,
                'filters' => ['period', 'category', 'line', 'uf', 'segment'], 'class' => page\courses::class],
            'course' => ['group' => 'courses', 'icon' => 'fa-book-open', 'phase' => 3, 'filters' => ['period', 'uf', 'segment'],
                'class' => page\course::class, 'hidden' => true, 'parent' => 'courses'],
            'editions' => ['group' => 'courses', 'icon' => 'fa-layer-group', 'phase' => 3, 'filters' => ['line', 'uf', 'segment'],
                'class' => page\editions::class],
            'pathway' => ['group' => 'courses', 'icon' => 'fa-route', 'phase' => 3, 'filters' => $all,
                'class' => page\pathway::class],
            'assessments' => ['group' => 'courses', 'icon' => 'fa-list-check', 'phase' => 3, 'filters' => $all,
                'class' => page\assessments::class],
            'materials' => ['group' => 'courses', 'icon' => 'fa-photo-film', 'phase' => 3,
                'filters' => ['category', 'line', 'course', 'uf', 'segment'], 'class' => page\materials::class],
            'feedback' => ['group' => 'courses', 'icon' => 'fa-comment-dots', 'phase' => 5, 'filters' => ['period', 'line',
                'course'],
                'class' => page\feedback::class],
            'team' => ['group' => 'team', 'icon' => 'fa-chalkboard-user', 'phase' => 4, 'filters' => $period,
                'counter' => 'team', 'class' => page\team::class],
            'teacher' => ['group' => 'team', 'icon' => 'fa-user-tie', 'phase' => 4, 'filters' => $period,
                'class' => page\teacher::class, 'hidden' => true, 'parent' => 'team'],
            'map' => ['group' => 'reach', 'icon' => 'fa-map-location-dot', 'phase' => 5, 'filters' => $all,
                'class' => page\map::class],
            'audience' => ['group' => 'reach', 'icon' => 'fa-id-card', 'phase' => 5, 'filters' => $all,
                'class' => page\audience::class],
            'reports' => ['group' => 'reports', 'icon' => 'fa-file-export', 'phase' => 6, 'filters' => $all,
                'class' => page\reports::class],
            'security' => ['group' => 'platform', 'icon' => 'fa-shield-halved', 'phase' => 6, 'filters' => $period,
                'class' => page\security::class],
            'alerts' => ['group' => 'platform', 'icon' => 'fa-triangle-exclamation', 'phase' => 7, 'filters' => [],
                'class' => page\alerts::class, 'counter' => 'alerts'],
            'config' => ['group' => 'platform', 'icon' => 'fa-sliders', 'phase' => 1, 'filters' => [],
                'class' => page\config::class, 'capability' => 'local/ceduanalytics:configure'],
            'about' => ['group' => 'platform', 'icon' => 'fa-circle-info', 'phase' => 9, 'filters' => [],
                'class' => page\about::class],
        ];
    }

    /**
     * Definition of one page (falls back to the default page).
     *
     * @param string $id
     * @return array definition with 'id'
     */
    public static function page(string $id): array {
        $pages = self::pages();
        if (!isset($pages[$id])) {
            $id = self::DEFAULT;
        }
        return ['id' => $id] + $pages[$id];
    }

    /**
     * URL of a page, keeping the given filter parameters. Empty values (the default of a filter or tab) are left out.
     *
     * @param string $id
     * @param array $params
     * @return \moodle_url
     */
    public static function url(string $id, array $params = []): \moodle_url {
        $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
        return new \moodle_url('/local/ceduanalytics/index.php', ['p' => $id] + $params);
    }

    /**
     * Menu structure for the template.
     *
     * @param string $current Current page id
     * @param array $filterparams Filter parameters kept across pages
     * @return array
     */
    public static function menu(string $current, array $filterparams): array {
        $context = \context_system::instance();
        // Groups the user left open ({"open": [...]}). Until the user opens or closes a group, only Students is open, so
        // the whole menu fits on the screen; a list in the old format (closed groups) also falls back to this default.
        $saved = json_decode((string)get_user_preferences('local_ceduanalytics_menu', ''), true);
        $open = is_array($saved) && isset($saved['open']) && is_array($saved['open']) ? $saved['open'] : self::DEFAULT_OPEN;
        $counters = snapshot::counters();
        $groups = [];
        foreach (self::GROUPS as $g) {
            $groups[$g] = ['id' => $g, 'label' => get_string('group_' . $g, 'local_ceduanalytics'), 'items' => [],
                'hasactive' => false, 'single' => $g === 'start'];
        }
        $pages = self::pages();
        foreach ($pages as $id => $def) {
            if (!empty($def['hidden']) || (!empty($def['capability']) && !has_capability($def['capability'], $context))) {
                continue;
            }
            $count = isset($def['counter']) ? (int)($counters[$def['counter']] ?? 0) : 0;
            $active = $id === $current || ($def['parent'] ?? null) === null && $id === ($pages[$current]['parent'] ?? null);
            $groups[$def['group']]['items'][] = [
                'id' => $id,
                'label' => get_string('page_' . $id, 'local_ceduanalytics'),
                'icon' => $def['icon'],
                'url' => self::url($id, $filterparams)->out(false),
                'active' => $active,
                'counter' => $count,
                'hascounter' => $count > 0,
                'soon' => empty($def['class']),
                'phase' => $def['phase'],
            ];
            if ($active) {
                $groups[$def['group']]['hasactive'] = true;
            }
        }
        $out = [];
        foreach ($groups as $g) {
            if (!$g['items']) {
                continue;
            }
            $g['expanded'] = $g['single'] || $g['hasactive'] || in_array($g['id'], $open);
            $out[] = $g;
        }
        return $out;
    }
}
