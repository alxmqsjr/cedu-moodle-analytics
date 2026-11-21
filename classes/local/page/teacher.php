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

use local_ceduanalytics\local\charts;
use local_ceduanalytics\local\contacts;
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;

/**
 * Teacher profile: courses, contacts with students, replies and pending grading of one team member.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher extends team {
    /** @var int */
    protected $userid;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $this->userid = optional_param('id', 0, PARAM_INT);
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        return ['id' => $this->userid];
    }

    /**
     * Title: the teacher name.
     *
     * @return string
     */
    public function title(): string {
        $t = contacts::team()[$this->userid] ?? null;
        return $t ? $t['name'] : get_string('page_teacher', 'local_ceduanalytics');
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        if (!$this->member()) {
            return [];
        }
        return [
            'courses' => get_string('teacher_courses', 'local_ceduanalytics'),
            'contacts' => get_string('teacher_contacts', 'local_ceduanalytics'),
            'unanswered' => get_string('team_unanswered', 'local_ceduanalytics'),
            'grading' => get_string('team_grading', 'local_ceduanalytics'),
        ];
    }

    /**
     * The team member, or null.
     *
     * @return array|null
     */
    protected function member(): ?array {
        return $this->data()['team'][$this->userid] ?? null;
    }

    /**
     * Unanswered conversations of this teacher.
     *
     * @return array[]
     */
    protected function unanswered_rows(): array {
        return array_values(array_filter(parent::unanswered_rows(), fn($u) => $u['staffid'] === $this->userid));
    }

    /**
     * Pending grading in the courses of this teacher.
     *
     * @return array[]
     */
    protected function grading_rows(): array {
        $t = $this->member();
        if (!$t) {
            return [];
        }
        return array_values(array_filter(parent::grading_rows(), fn($g) => isset($t['courses'][$g['courseid']])));
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $t = $this->member();
        if (!$t) {
            return $this->empty_state('fa-user-slash', $s('teacher_notfound'), $s('teacher_notfound_text'));
        }
        $d = $this->data();
        $st = $d['stats'][$this->userid];
        $eff = $d['eff']['bystaff'][$this->userid] ?? null;
        $kpis = [
            $this->kpi('teacher_courses', fmt::int(count($t['courses']))),
            $this->kpi('teacher_students', fmt::int($st['activestudents'])),
            $this->kpi(
                'team_messages',
                fmt::int($st['messages']),
                null,
                null,
                '',
                false,
                $s('teacher_contacted_note', fmt::int(count($st['students'])))
            ),
            $this->kpi('team_notes', fmt::int($st['notes'])),
            $this->kpi(
                'team_replytime',
                contacts::duration(contacts::median($st['replies'])),
                null,
                null,
                '',
                fmt::lowconf(count($st['replies'])),
                $s('team_replytime_note', fmt::int(count($st['replies'])))
            ),
            $this->kpi(
                'sup_effect',
                $eff && $eff['episodes'] ? fmt::pct($eff['returned'], $eff['episodes']) : '—',
                null,
                null,
                '',
                fmt::lowconf($eff['episodes'] ?? 0),
                $s('teacher_effect_note', ['n' => fmt::int($eff['episodes'] ?? 0),
                'days' => $d['eff']['days']])
            ),
        ];

        // Contacts per month in the period.
        $from = $d['from'] ?: ($st['events'] ? min(array_column($st['events'], 'time')) : time());
        $unit = charts::unit_for($from, $d['to']);
        $buckets = charts::buckets($from, $d['to'], $unit);
        $starts = array_keys($buckets);
        $series = ['message' => array_fill_keys($starts, 0), 'note' => array_fill_keys($starts, 0)];
        foreach ($st['events'] as $e) {
            $b = charts::bucket_of($e['time'], $starts);
            if ($b !== null) {
                $series[$e['kind']][$b]++;
            }
        }
        $chart = $st['events'] ? charts::bar(array_values($buckets), [
            $s('kind_message_plural') => array_values($series['message']),
            $s('kind_note_plural') => array_values($series['note']),
        ], true, false, [charts::BLUE, charts::ORANGE]) : '';

        return $this->render('teacher', [
            'kpis' => $kpis,
            'name' => $t['name'],
            'dualmark' => \local_ceduanalytics\local\people::dual_mark($this->userid, 'teacher'),
            'email' => $t['email'],
            'roles' => $this->roles($t['roles']),
            'suspended' => !empty($t['suspended']),
            'lastaccess' => $t['lastaccess'] ? fmt::datetime($t['lastaccess']) : $s('never'),
            'profileurl' => (new \moodle_url('/user/profile.php', ['id' => $this->userid]))->out(false),
            'backurl' => $this->link('team')->out(false),
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state('fa-envelope-open', $s('teacher_nocontacts'), $s('teacher_nocontacts_text')),
            'courses' => $this->get_dataset('courses')->export_for_template(),
            'contacts' => $this->get_dataset('contacts')->export_for_template(),
            'unanswered' => $this->get_dataset('unanswered')->export_for_template(),
            'grading' => $this->get_dataset('grading')->export_for_template(),
        ]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        global $DB;
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $t = $this->member();
        if (!$t) {
            return null;
        }
        if ($id === 'courses') {
            $ds = new dataset('courses', $s('teacher_courses'), [
                'course' => $s('col_course'), 'roles' => $s('col_roles'), 'students' => $s('col_activestudents'),
                'inactive' => $s('situation_inactive'), 'notstarted' => $s('situation_notstarted'),
                'certified' => $s('situation_certified'), 'lastaccess' => $s('col_teacherlastaccess'),
            ], ['students', 'inactive', 'notstarted', 'certified']);
            $courseids = array_keys($t['courses']);
            $ds->emptytext = $s('teacher_nocourses');
            if (!$courseids) {
                // Site administrators may have no course role.
                return $ds;
            }
            [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $counts = [];
            $rs = $DB->get_recordset_sql("
                SELECT courseid, situation, enrolactive, COUNT(1) AS n
                  FROM {local_ceduanalytics_enrolstate}
                 WHERE isstudent = 1 AND courseid $insql
              GROUP BY courseid, situation, enrolactive", $params);
            foreach ($rs as $r) {
                $counts[$r->courseid][$r->situation] = ($counts[$r->courseid][$r->situation] ?? 0) + $r->n;
                if ($r->enrolactive) {
                    $counts[$r->courseid]['active'] = ($counts[$r->courseid]['active'] ?? 0) + $r->n;
                }
            }
            $rs->close();
            $last = $DB->get_records_menu('user_lastaccess', ['userid' => $this->userid], '', 'courseid, timeaccess');
            $courses = $DB->get_records_list('course', 'id', $courseids, 'fullname', 'id, fullname');
            foreach ($courses as $c) {
                $name = fmt::text($c->fullname);
                $la = (int)($last[$c->id] ?? 0);
                $ds->add([
                    'course' => $name, 'roles' => $this->roles($t['courses'][$c->id]),
                    'students' => (int)($counts[$c->id]['active'] ?? 0), 'inactive' => (int)($counts[$c->id]['inactive'] ?? 0),
                    'notstarted' => (int)($counts[$c->id]['notstarted'] ?? 0),
                    'certified' => (int)($counts[$c->id]['certified'] ?? 0),
                    'lastaccess' => $la ? fmt::date($la) : $s('never'),
                    '_html' => [
                        'course' => coursestats::link((int)$c->id, $name, $this->filters->params()),
                        'lastaccess' => $la ? \html_writer::span(fmt::date($la), '', ['title' => fmt::ago($la)])
                            : \html_writer::span($s('never'), 'cea-muted'),
                    ],
                ]);
            }
            return $ds;
        }
        if ($id === 'contacts') {
            $ds = new dataset('contacts', $s('teacher_contacts'), [
                'date' => $s('col_date'), 'kind' => $s('col_kind'), 'student' => $s('col_student'),
                'course' => $s('col_course'),
            ]);
            $events = $this->data()['stats'][$this->userid]['events'];
            usort($events, fn($a, $b) => $b['time'] <=> $a['time']);
            \local_ceduanalytics\local\people::preload(array_column($events, 'studentid'));
            $coursenames = [];
            foreach ($events as $e) {
                $student = $this->name($e['studentid']);
                $course = '';
                if ($e['courseid']) {
                    if (!isset($coursenames[$e['courseid']])) {
                        $coursenames[$e['courseid']] = fmt::text((string)$DB->get_field(
                            'course',
                            'fullname',
                            ['id' => $e['courseid']]
                        ));
                    }
                    $course = $coursenames[$e['courseid']];
                }
                $ds->add([
                    'date' => fmt::datetime($e['time']), 'kind' => $s('kind_' . $e['kind']), 'student' => $student,
                    'course' => $course,
                    '_html' => ['student' => indicators::student_link($e['studentid'], $student)],
                ]);
            }
            $ds->emptytext = $s('teacher_nocontacts');
            return $ds;
        }
        return parent::get_dataset($id);
    }
}
