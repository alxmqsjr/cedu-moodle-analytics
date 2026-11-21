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

use local_ceduanalytics\local\courses as catalogue;
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * All courses: how each course is doing, with its health and the reasons.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses extends base {
    /** @var string[] Health order for sorting. */
    const HEALTHORDER = ['critical' => 0, 'attention' => 1, 'good' => 2, 'na' => 3];

    /** @var string[] Status order for sorting. */
    const STATUSORDER = ['running' => 0, 'noend' => 1, 'future' => 2, 'ended' => 3];

    /** @var array|null */
    protected $rows = null;

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return ['courses' => get_string('page_courses', 'local_ceduanalytics')];
    }

    /**
     * Every course with statistics, status and health, in display order.
     *
     * @return array
     */
    protected function rows(): array {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $scope = new scope($this->filters);
        $ids = $scope->course_ids();
        $all = catalogue::get_all();
        if ($ids !== null) {
            $all = array_intersect_key($all, array_flip($ids));
        }
        $stats = coursestats::by_course($scope);
        $teachers = coursestats::teachers(array_keys($all));
        $rows = [];
        foreach ($all as $c) {
            $s = $stats[$c->id] ?? null;
            $status = coursestats::status($c, $s);
            $t = $teachers[$c->id] ?? [];
            $rows[] = ['c' => $c, 's' => $s, 'status' => $status, 'teachers' => $t,
                'health' => coursestats::health($c->type, $status, $s, $t)];
        }
        usort($rows, function ($a, $b) {
            return [self::STATUSORDER[$a['status']], self::HEALTHORDER[$a['health']['level']], -(int)$a['c']->startdate]
                <=> [self::STATUSORDER[$b['status']], self::HEALTHORDER[$b['health']['level']], -(int)$b['c']->startdate];
        });
        return $this->rows = $rows;
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $rows = $this->rows();
        $status = array_fill_keys(array_keys(self::STATUSORDER), 0);
        $health = array_fill_keys(array_keys(self::HEALTHORDER), 0);
        foreach ($rows as $r) {
            $status[$r['status']]++;
            $health[$r['health']['level']]++;
        }
        $stats = [['value' => fmt::int(count($rows)), 'label' => get_string('cfg_courses_total', 'local_ceduanalytics')]];
        foreach ($status as $k => $n) {
            $stats[] = ['value' => fmt::int($n), 'label' => get_string('cstatus_' . $k . '_plural', 'local_ceduanalytics')];
        }
        $healthstats = [];
        foreach ($health as $k => $n) {
            $healthstats[] = ['value' => fmt::int($n), 'label' => get_string('health_' . $k . '_plural', 'local_ceduanalytics'),
                'type' => 'health-' . $k];
        }
        return $this->render('courses', [
            'stats' => $stats,
            'healthstats' => $healthstats,
            'table' => $this->get_dataset('courses')->export_for_template(),
        ]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        if ($id !== 'courses') {
            return null;
        }
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $ds = new dataset('courses', $s('page_courses'), [
            'course' => $s('col_course'), 'type' => $s('col_type'), 'line' => $s('col_line'), 'status' => $s('col_status'),
            'health' => $s('col_health'), 'reasons' => $s('col_reasons'), 'students' => $s('col_students'),
            'active' => $s('col_activeperiod'), 'never' => $s('col_notstarted'), 'progress' => $s('col_avgprogress'),
            'certified' => $s('col_certified'), 'completed' => $s('col_completednocert'), 'grade' => $s('col_gradeavg'),
            'teachers' => $s('col_teachers'), 'start' => $s('col_start'), 'end' => $s('col_end'),
        ], ['students', 'active', 'never', 'progress', 'certified', 'completed', 'grade']);
        foreach ($this->rows() as $r) {
            $c = $r['c'];
            $st = $r['s'] ?? coursestats::empty_stats();
            $n = $st['enrolled'];
            $name = fmt::text($c->fullname);
            $line = $c->linename !== null ? fmt::text($c->linename) . ($c->edition ? ' · ' . $c->edition : '') : '';
            $teachertext = [];
            $teacherhtml = [];
            foreach ($r['teachers'] as $t) {
                $teachertext[] = $t['name'] . ' (' . fmt::ago($t['lastaccess']) . ')';
                $teacherhtml[] = s(end($teachertext)) . \local_ceduanalytics\local\people::dual_mark($t['userid'], 'teacher');
            }
            $ds->add([
                'course' => $name, 'type' => $s('coursetype_' . $c->type), 'line' => $line,
                'status' => $s('cstatus_' . $r['status']), 'health' => $s('health_' . $r['health']['level'] . '_label'),
                'reasons' => implode('; ', $r['health']['reasons']),
                'students' => $n, 'active' => $st['activeperiod'],
                'never' => $n ? $st['notstarted'] . ' (' . fmt::pct($st['notstarted'], $n) . ')' : '0',
                'progress' => $n ? fmt::percent($st['progresssum'] / $n) : '—',
                'certified' => $n ? $st['certified'] . ' (' . fmt::pct($st['certified'], $n) . ')' : '0',
                'completed' => $st['completed'],
                'grade' => $st['gradecount'] ? fmt::percent($st['gradesum'] / $st['gradecount']) : '—',
                'teachers' => $teachertext ? implode('; ', $teachertext) : $s('noteachers'),
                'start' => fmt::date((int)$c->startdate), 'end' => $c->enddate ? fmt::date((int)$c->enddate) : $s('noenddate'),
                '_html' => [
                    'course' => coursestats::link($c->id, $name, $this->filters->params()) .
                        ($c->visible ? '' : ' ' . \html_writer::span($s('visible_no'), 'cea-badge cea-badge-soft')),
                    'type' => \html_writer::span($s('coursetype_' . $c->type), 'cea-badge cea-type-' . $c->type),
                    'status' => coursestats::status_badge($r['status']),
                    'health' => coursestats::health_badge($r['health']['level']),
                    'reasons' => \html_writer::alist(array_map('s', $r['health']['reasons']), ['class' => 'cea-reasons']),
                    'teachers' => $teachertext ? \html_writer::alist($teacherhtml, ['class' => 'cea-reasons'])
                        : \html_writer::span($s('noteachers'), 'cea-warn-text'),
                ],
            ]);
        }
        $ds->emptytext = $s('empty_courses_scope');
        return $ds;
    }
}
