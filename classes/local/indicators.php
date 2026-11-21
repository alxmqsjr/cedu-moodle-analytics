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
 * Indicators shared by the pages, computed from the plugin tables for a scope (filters + period).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class indicators {
    /**
     * Summary of the enrolments in scope.
     *
     * @param scope $scope
     * @return array
     */
    public static function summary(scope $scope): array {
        return $scope->cached('summary', function () use ($scope) {
            $rows = $scope->enrolments('e.id, e.userid, e.courseid, e.situation, e.timeenrolled, e.timecertified,
                e.enrolactive, e.gradepct, e.lastaccess, e.firstaccess');
            $users = [];
            $certusers = [];
            $s = ['enrolments' => count($rows), 'newenrol' => 0, 'certified' => 0, 'certifiedall' => 0, 'gradesum' => 0.0,
                'gradecount' => 0, 'support' => 0, 'situations' => array_fill_keys(snapshot::SITUATIONS, 0)];
            foreach ($rows as $r) {
                $users[$r->userid] = true;
                $s['situations'][$r->situation] = ($s['situations'][$r->situation] ?? 0) + 1;
                if ($r->timeenrolled >= $scope->from && $r->timeenrolled < $scope->to) {
                    $s['newenrol']++;
                }
                if ($r->timecertified) {
                    $s['certifiedall']++;
                    if ($r->timecertified >= $scope->from && $r->timecertified < $scope->to) {
                        $s['certified']++;
                        $certusers[$r->userid] = true;
                    }
                }
                if ($r->gradepct !== null) {
                    $s['gradesum'] += (float)$r->gradepct;
                    $s['gradecount']++;
                }
                if ($r->enrolactive && in_array($r->situation, ['attention', 'inactive'])) {
                    $s['support']++;
                }
            }
            $s['students'] = count($users);
            $s['certusers'] = count($certusers);
            $s['gradeavg'] = $s['gradecount'] ? $s['gradesum'] / $s['gradecount'] : null;
            $s['certrate'] = $s['enrolments'] ? $s['certifiedall'] / $s['enrolments'] * 100 : null;
            $s['active'] = self::active_students($scope);
            return $s;
        });
    }

    /**
     * Distinct students with activity in the courses in scope during the period.
     *
     * @param scope $scope
     * @return int
     */
    public static function active_students(scope $scope): int {
        $rows = $scope->daily('COUNT(DISTINCT d.userid) AS n');
        return (int)($rows[0]->n ?? 0);
    }

    /**
     * Active students and new enrolments per time bucket.
     *
     * @param scope $scope
     * @return array ['labels' => [], 'active' => [], 'enrolments' => [], 'hours' => [], 'unit' => string]
     */
    public static function timeline(scope $scope): array {
        return $scope->cached('timeline', function () use ($scope) {
            $from = $scope->from ?: self::first_day();
            $unit = charts::unit_for($from, $scope->to);
            $buckets = charts::buckets($from, $scope->to, $unit);
            $starts = array_keys($buckets);
            $active = array_fill_keys($starts, []);
            $hours = array_fill_keys($starts, 0);
            foreach ($scope->daily('d.userid, d.day, SUM(d.activesecs) AS secs', 'd.userid, d.day') as $r) {
                $b = charts::bucket_of((int)$r->day, $starts);
                if ($b !== null) {
                    $active[$b][$r->userid] = true;
                    $hours[$b] += (int)$r->secs;
                }
            }
            $enrol = array_fill_keys($starts, 0);
            foreach ($scope->enrolments('e.id, e.timeenrolled') as $r) {
                if ($r->timeenrolled >= $from && $r->timeenrolled < $scope->to) {
                    $b = charts::bucket_of((int)$r->timeenrolled, $starts);
                    if ($b !== null) {
                        $enrol[$b]++;
                    }
                }
            }
            return [
                'unit' => $unit,
                'labels' => array_values($buckets),
                'active' => array_values(array_map('count', $active)),
                'enrolments' => array_values($enrol),
                'hours' => array_values(array_map(fn($s) => round($s / 3600, 1), $hours)),
            ];
        });
    }

    /**
     * First aggregated day (used when the period is "all").
     *
     * @return int
     */
    public static function first_day(): int {
        global $DB;
        return (int)$DB->get_field_sql('SELECT MIN(day) FROM {local_ceduanalytics_daily} WHERE courseid <> ?', [SITEID]) ?: time();
    }

    /**
     * Last day with at least $min active students (any course).
     *
     * @param int $min
     * @return int timestamp or 0
     */
    public static function last_busy_day(int $min = 5): int {
        global $DB;
        $sql = "SELECT d.day, COUNT(DISTINCT d.userid) AS n
                  FROM {local_ceduanalytics_daily} d
                  JOIN {local_ceduanalytics_enrolstate} e ON e.userid = d.userid AND e.courseid = d.courseid AND e.isstudent = 1
              GROUP BY d.day
                HAVING COUNT(DISTINCT d.userid) >= :min
              ORDER BY d.day DESC";
        $rows = $DB->get_records_sql($sql, ['min' => $min], 0, 1);
        return $rows ? (int)reset($rows)->day : 0;
    }

    /**
     * Number of distinct students active in the last N days (all courses).
     *
     * @param int $days
     * @return int
     */
    public static function recent_active(int $days = 14): int {
        global $DB;
        return (int)$DB->count_records_sql("
            SELECT COUNT(DISTINCT d.userid)
              FROM {local_ceduanalytics_daily} d
              JOIN {local_ceduanalytics_enrolstate} e ON e.userid = d.userid AND e.courseid = d.courseid AND e.isstudent = 1
             WHERE d.day >= ?", [time() - $days * DAYSECS]);
    }

    /**
     * Situation badge HTML.
     *
     * @param string $situation
     * @return string
     */
    public static function situation_badge(string $situation): string {
        if ($situation === '') {
            return '';
        }
        return \html_writer::span(
            get_string('situation_' . $situation, 'local_ceduanalytics'),
            'cea-badge cea-sit cea-sit-' . $situation
        );
    }

    /**
     * Link to the student profile page.
     *
     * @param int $userid
     * @param string $name
     * @return string
     */
    public static function student_link(int $userid, string $name): string {
        return \html_writer::link(navigation::url('student', ['id' => $userid]), s($name)) . people::dual_mark($userid);
    }

    /**
     * UF with an "inferred" mark when it does not come from the profile.
     *
     * @param string|null $uf
     * @param string|null $source
     * @return string HTML
     */
    public static function uf_html(?string $uf, ?string $source): string {
        if (!$uf) {
            return \html_writer::span('—', 'cea-muted', ['title' => get_string('uf_none', 'local_ceduanalytics')]);
        }
        if ($source === 'declared') {
            return s($uf);
        }
        return s($uf) . ' ' . \html_writer::span(
            '*',
            'cea-inferred',
            ['title' => get_string('ufsource_' . ($source ?: 'none'), 'local_ceduanalytics')]
        );
    }
}
