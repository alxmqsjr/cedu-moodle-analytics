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
 * Course catalogue: every course with its type, course line and edition.
 *
 * Course types:
 *  - track: edition of a course line that has two or more editions;
 *  - standalone: course with students that is the only edition of its line;
 *  - model: course in a model category (or in a line marked as model);
 *  - empty: course without students.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses {
    /** @var string[] Line kinds. */
    const KINDS = ['track', 'standalone', 'model'];

    /**
     * Ids of the categories configured as model categories.
     *
     * @return int[]
     */
    public static function model_categories(): array {
        $value = (string)get_config('local_ceduanalytics', 'modelcategories');
        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }

    /**
     * Detect model categories by name (used once, at install time).
     *
     * @return int[]
     */
    public static function detect_model_categories(): array {
        global $DB;
        $ids = [];
        foreach ($DB->get_records('course_categories', null, '', 'id, name') as $cat) {
            if (strpos(br::normalise($cat->name), 'modelo') !== false || strpos(br::normalise($cat->name), 'template') !== false) {
                $ids[] = (int)$cat->id;
            }
        }
        return $ids;
    }

    /**
     * All courses (except the front page) with catalogue information.
     *
     * @return \stdClass[] keyed by course id
     */
    public static function get_all(): array {
        global $DB;
        $sql = "SELECT c.id, c.fullname, c.shortname, c.category, c.visible, c.startdate, c.enddate, c.enablecompletion,
                       cc.name AS categoryname, lc.lineid, lc.edition, lc.ismanual, l.name AS linename, l.kind AS linekind
                  FROM {course} c
             LEFT JOIN {course_categories} cc ON cc.id = c.category
             LEFT JOIN {local_ceduanalytics_linecourse} lc ON lc.courseid = c.id
             LEFT JOIN {local_ceduanalytics_courseline} l ON l.id = lc.lineid
                 WHERE c.id <> :siteid
              ORDER BY c.startdate DESC, c.id DESC";
        $courses = $DB->get_records_sql($sql, ['siteid' => SITEID]);

        // Snapshot counts per course.
        $counts = $DB->get_records_sql("
            SELECT courseid,
                   SUM(isstudent) AS students,
                   SUM(CASE WHEN isstudent = 1 AND situation = 'notstarted' THEN 1 ELSE 0 END) AS notstarted,
                   SUM(CASE WHEN isstudent = 1 AND timecertified > 0 THEN 1 ELSE 0 END) AS certified,
                   SUM(CASE WHEN isstudent = 1 AND situation = 'completed' THEN 1 ELSE 0 END) AS completednocert,
                   SUM(CASE WHEN isstudent = 1 AND timecompleted > 0 THEN 1 ELSE 0 END) AS moodlecompleted,
                   COUNT(1) AS enrolled
              FROM {local_ceduanalytics_enrolstate}
          GROUP BY courseid");
        $criteria = $DB->get_records_sql_menu('SELECT course, COUNT(1) FROM {course_completion_criteria} GROUP BY course');
        $linesizes = $DB->get_records_sql_menu('SELECT lineid, COUNT(1) FROM {local_ceduanalytics_linecourse} GROUP BY lineid');
        $modelcats = self::model_categories();

        foreach ($courses as $c) {
            $n = $counts[$c->id] ?? null;
            $c->students = (int)($n->students ?? 0);
            $c->enrolled = (int)($n->enrolled ?? 0);
            $c->notstarted = (int)($n->notstarted ?? 0);
            $c->certified = (int)($n->certified ?? 0);
            $c->completednocert = (int)($n->completednocert ?? 0);
            $c->moodlecompleted = (int)($n->moodlecompleted ?? 0);
            $c->hascriteria = !empty($criteria[$c->id]);
            $c->linesize = $c->lineid ? (int)($linesizes[$c->lineid] ?? 0) : 0;
            $c->type = self::type_of($c, $modelcats);
        }
        return $courses;
    }

    /**
     * Type of a course.
     *
     * @param \stdClass $c Course with linekind, linesize, students, category
     * @param int[] $modelcats
     * @return string track|standalone|model|empty
     */
    public static function type_of(\stdClass $c, array $modelcats): string {
        if (in_array((int)$c->category, $modelcats) || $c->linekind === 'model') {
            return 'model';
        }
        if (empty($c->students)) {
            return 'empty';
        }
        if ($c->linekind === 'track' || $c->linesize >= 2) {
            return 'track';
        }
        return 'standalone';
    }

    /**
     * Course lines.
     *
     * @return \stdClass[]
     */
    public static function get_lines(): array {
        global $DB;
        return $DB->get_records_sql("
            SELECT l.*, (SELECT COUNT(1) FROM {local_ceduanalytics_linecourse} lc WHERE lc.lineid = l.id) AS courses
              FROM {local_ceduanalytics_courseline} l
          ORDER BY l.sortorder, l.name");
    }

    /**
     * Key that identifies editions of the same course from the full name.
     *
     * "T2C1: Educação Ambiental..." and "T3C1: Educação Ambiental..." give the same key.
     *
     * @param string $fullname
     * @return string[] [key, clean display name, edition label or '']
     */
    public static function parse_name(string $fullname): array {
        $name = trim(strip_tags($fullname));
        $edition = '';
        if (preg_match('/^\s*(T\d+)\s*(C\d+)?\s*[:\-–]\s*/iu', $name, $m)) {
            $edition = strtoupper($m[1]);
            $name = trim(\core_text::substr($name, \core_text::strlen($m[0])));
        }
        $clean = trim(preg_replace('/\s*[\[(][^\])]*[\])]\s*/u', ' ', $name));
        $key = br::normalise($clean);
        return [$key, $clean, $edition];
    }

    /**
     * Suggested grouping of courses into lines (pure function of the course list).
     *
     * @param \stdClass[] $courses With id, fullname, category, startdate
     * @return array key => ['name' => string, 'kind' => string, 'courses' => [courseid => edition]]
     */
    public static function suggest(array $courses): array {
        $modelcats = self::model_categories();
        $groups = [];
        foreach ($courses as $c) {
            [$key, $clean, $edition] = self::parse_name($c->fullname);
            if ($key === '') {
                $key = 'course-' . $c->id;
            }
            if ($edition === '' && !empty($c->startdate)) {
                $edition = get_string('editionyear', 'local_ceduanalytics', userdate($c->startdate, '%Y'));
            }
            if (!isset($groups[$key])) {
                $groups[$key] = ['name' => $clean, 'kind' => '', 'courses' => [], 'models' => 0];
            }
            $groups[$key]['courses'][$c->id] = $edition;
            if (in_array((int)$c->category, $modelcats)) {
                $groups[$key]['models']++;
            }
        }
        foreach ($groups as &$g) {
            if ($g['models'] === count($g['courses'])) {
                $g['kind'] = 'model';
            } else if (count($g['courses']) >= 2) {
                $g['kind'] = 'track';
            } else {
                $g['kind'] = 'standalone';
            }
            unset($g['models']);
        }
        return $groups;
    }

    /**
     * Apply the automatic suggestion to courses not edited manually.
     *
     * Courses already assigned by a manager (manual = 1) are never moved.
     *
     * @return int Number of courses assigned or reassigned
     */
    public static function apply_suggestions(): int {
        global $DB;
        $now = time();
        $courses = $DB->get_records_select('course', 'id <> ?', [SITEID], '', 'id, fullname, category, startdate');
        $assigned = $DB->get_records('local_ceduanalytics_linecourse', null, '', 'courseid, id, lineid, edition, ismanual');
        $lines = $DB->get_records('local_ceduanalytics_courseline');
        $linebykey = [];
        foreach ($lines as $l) {
            $linebykey[br::normalise($l->name)] = $l;
        }
        $changed = 0;
        foreach (self::suggest($courses) as $key => $group) {
            $free = array_filter(array_keys($group['courses']), fn($cid) => empty($assigned[$cid]->ismanual));
            if (!$free) {
                continue;
            }
            // Reuse a line that already holds one of these courses, or one with the same name.
            $line = null;
            foreach (array_keys($group['courses']) as $cid) {
                if (!empty($assigned[$cid]) && isset($lines[$assigned[$cid]->lineid])) {
                    $line = $lines[$assigned[$cid]->lineid];
                    break;
                }
            }
            $line = $line ?? ($linebykey[br::normalise($group['name'])] ?? null);
            if (!$line) {
                $line = (object)['name' => $group['name'], 'kind' => $group['kind'], 'sortorder' => count($lines) + 1,
                    'timecreated' => $now, 'timemodified' => $now];
                $line->id = $DB->insert_record('local_ceduanalytics_courseline', $line);
                $lines[$line->id] = $line;
                $linebykey[br::normalise($line->name)] = $line;
            }
            foreach ($free as $cid) {
                $edition = $group['courses'][$cid];
                if (!empty($assigned[$cid])) {
                    $rec = $assigned[$cid];
                    if ((int)$rec->lineid !== (int)$line->id || $rec->edition !== $edition) {
                        $DB->update_record('local_ceduanalytics_linecourse', (object)['id' => $rec->id,
                            'lineid' => $line->id, 'edition' => $edition, 'timemodified' => $now]);
                        $changed++;
                    }
                } else {
                    $DB->insert_record('local_ceduanalytics_linecourse', (object)['lineid' => $line->id,
                        'courseid' => $cid, 'edition' => $edition, 'ismanual' => 0, 'timemodified' => $now]);
                    $changed++;
                }
            }
        }
        self::delete_empty_lines();
        return $changed;
    }

    /**
     * Remove lines left without courses, and memberships of deleted courses.
     */
    public static function delete_empty_lines(): void {
        global $DB;
        $DB->delete_records_select(
            'local_ceduanalytics_linecourse',
            'courseid NOT IN (SELECT id FROM {course})'
        );
        $DB->delete_records_select(
            'local_ceduanalytics_courseline',
            'id NOT IN (SELECT lineid FROM {local_ceduanalytics_linecourse})'
        );
    }
}
