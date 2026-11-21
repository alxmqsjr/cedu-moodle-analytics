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

use local_ceduanalytics\local\contacts;
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;
use local_ceduanalytics\local\scope;

/**
 * Teaching team: one card per teacher, pending grading and student messages without reply.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class team extends base {
    /** @var array|null */
    protected $data = null;

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'team' => get_string('team_cards', 'local_ceduanalytics'),
            'unanswered' => get_string('team_unanswered', 'local_ceduanalytics'),
            'grading' => get_string('team_grading', 'local_ceduanalytics'),
        ];
    }

    /**
     * Data shared by the team and teacher pages (period of the filters).
     *
     * @return array
     */
    protected function data(): array {
        global $DB;
        if ($this->data !== null) {
            return $this->data;
        }
        $scope = new scope($this->filters);
        $from = $scope->from;
        $to = $scope->to;
        $team = contacts::team();
        $events = contacts::events($from, $to);
        $conv = contacts::conversations();
        $grading = contacts::pending_grading();
        $eff = contacts::effectiveness($events);

        $students = $DB->get_records_sql_menu("
            SELECT courseid, COUNT(1)
              FROM {local_ceduanalytics_enrolstate}
             WHERE isstudent = 1 AND enrolactive = 1
          GROUP BY courseid");
        $courselast = [];
        if ($team) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($team));
            $lastaccess = $DB->get_records_select(
                'user_lastaccess',
                "userid $insql",
                $params,
                '',
                'id, userid, courseid, timeaccess'
            );
            foreach ($lastaccess as $r) {
                $courselast[$r->userid][$r->courseid] = (int)$r->timeaccess;
            }
        }

        $stats = [];
        foreach ($team as $uid => $t) {
            $stats[$uid] = ['messages' => 0, 'notes' => 0, 'students' => [], 'replies' => [], 'unanswered' => 0,
                'grading' => 0, 'oldestgrading' => 0, 'activestudents' => 0, 'lastcourse' => 0, 'events' => []];
            foreach (array_keys($t['courses']) as $cid) {
                $stats[$uid]['activestudents'] += (int)($students[$cid] ?? 0);
                $stats[$uid]['lastcourse'] = max($stats[$uid]['lastcourse'], (int)($courselast[$uid][$cid] ?? 0));
            }
        }
        foreach ($events as $e) {
            if (!isset($stats[$e['staffid']])) {
                continue;
            }
            $stats[$e['staffid']][$e['kind'] === 'note' ? 'notes' : 'messages']++;
            $stats[$e['staffid']]['students'][$e['studentid']] = true;
            $stats[$e['staffid']]['events'][] = $e;
        }
        $replies = [];
        foreach ($conv['replies'] as $r) {
            if ($r['time'] >= $from && $r['time'] < $to) {
                $replies[] = $r['secs'];
                if (isset($stats[$r['staffid']])) {
                    $stats[$r['staffid']]['replies'][] = $r['secs'];
                }
            }
        }
        foreach ($conv['unanswered'] as $u) {
            if (isset($stats[$u['staffid']])) {
                $stats[$u['staffid']]['unanswered']++;
            }
        }
        foreach ($grading as $g) {
            foreach ($team as $uid => $t) {
                if (isset($t['courses'][$g['courseid']])) {
                    $stats[$uid]['grading'] += $g['waiting'];
                    if (!$stats[$uid]['oldestgrading'] || $g['oldest'] < $stats[$uid]['oldestgrading']) {
                        $stats[$uid]['oldestgrading'] = $g['oldest'];
                    }
                }
            }
        }
        $this->data = ['team' => $team, 'stats' => $stats, 'events' => $events, 'conv' => $conv, 'grading' => $grading,
            'replies' => $replies, 'eff' => $eff, 'from' => $from, 'to' => $to];
        return $this->data;
    }

    /**
     * Role names of a team member.
     *
     * @param string[] $roles
     * @return string
     */
    protected function roles(array $roles): string {
        return implode(', ', array_map(fn($r) => get_string('role_' . $r, 'local_ceduanalytics'), $roles));
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $d = $this->data();
        $messages = count(array_filter($d['events'], fn($e) => $e['kind'] === 'message'));
        $notes = count($d['events']) - $messages;
        $waiting = array_sum(array_column($d['grading'], 'waiting'));
        $recent = count(array_filter($d['conv']['unanswered'], fn($u) => $u['last'] >= time() - 30 * DAYSECS));

        $kpis = [
            $this->kpi('team_members', fmt::int(count($d['team']))),
            $this->kpi('team_messages', fmt::int($messages)),
            $this->kpi('team_notes', fmt::int($notes)),
            $this->kpi(
                'team_replytime',
                contacts::duration(contacts::median($d['replies'])),
                null,
                null,
                '',
                fmt::lowconf(count($d['replies'])),
                $s('team_replytime_note', fmt::int(count($d['replies'])))
            ),
            $this->kpi(
                'team_unanswered',
                fmt::int(count($d['conv']['unanswered'])),
                null,
                null,
                '',
                false,
                $s('team_unanswered_note', fmt::int($recent))
            ),
            $this->kpi('team_grading', fmt::int($waiting)),
        ];

        $cards = [];
        foreach ($d['team'] as $uid => $t) {
            $st = $d['stats'][$uid];
            $eff = $d['eff']['bystaff'][$uid] ?? null;
            $cards[] = [
                'name' => $t['name'],
                'dualmark' => \local_ceduanalytics\local\people::dual_mark($uid, 'teacher'),
                'url' => $this->link('teacher', ['id' => $uid])->out(false),
                'roles' => $this->roles($t['roles']),
                'suspended' => !empty($t['suspended']),
                'courses' => fmt::int(count($t['courses'])),
                'students' => fmt::int($st['activestudents']),
                'messages' => fmt::int($st['messages']),
                'notes' => fmt::int($st['notes']),
                'contacted' => fmt::int(count($st['students'])),
                'replytime' => contacts::duration(contacts::median($st['replies'])),
                'unanswered' => fmt::int($st['unanswered']),
                'hasunanswered' => $st['unanswered'] > 0,
                'grading' => fmt::int($st['grading']),
                'hasgrading' => $st['grading'] > 0,
                'lastaccess' => $t['lastaccess'] ? fmt::ago($t['lastaccess']) : $s('never'),
                'lastcourse' => !$t['courses'] ? $s('team_nocourses') : ($st['lastcourse'] ? fmt::ago($st['lastcourse'])
                    : $s('never')),
                'stale' => $t['courses'] && (!$st['lastcourse'] || $st['lastcourse'] < time() - 14 * DAYSECS),
                'effect' => $eff && $eff['episodes'] ? fmt::pct($eff['returned'], $eff['episodes']) : '—',
            ];
        }
        return $this->render('team', [
            'kpis' => $kpis,
            'cards' => $cards,
            'hascards' => !empty($cards),
            'nocards' => $this->empty_state('fa-chalkboard-user', $s('team_none'), $s('team_none_text')),
            'unanswered' => $this->get_dataset('unanswered')->export_for_template(),
            'grading' => $this->get_dataset('grading')->export_for_template(),
            'effectdays' => $d['eff']['days'],
        ]);
    }

    /**
     * Full name of a user id (cached).
     *
     * @param int $uid
     * @return string
     */
    protected function name(int $uid): string {
        static $cache = [];
        if (!isset($cache[$uid])) {
            $u = \local_ceduanalytics\local\people::get($uid);
            $cache[$uid] = $u ? fullname($u) : '#' . $uid;
        }
        return $cache[$uid];
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
        $d = $this->data();
        if ($id === 'team') {
            $ds = new dataset('team', $s('team_cards'), [
                'teacher' => $s('col_teacher'), 'email' => $s('col_email'), 'roles' => $s('col_roles'),
                'courses' => $s('col_courses'), 'students' => $s('col_activestudents'), 'messages' => $s('col_messages'),
                'notes' => $s('col_notes'), 'contacted' => $s('col_contacted'), 'replytime' => $s('col_replytime'),
                'unanswered' => $s('col_unanswered'), 'grading' => $s('col_grading'), 'lastaccess' => $s('col_lastaccess'),
            ], ['courses', 'students', 'messages', 'notes', 'contacted', 'unanswered', 'grading']);
            foreach ($d['team'] as $uid => $t) {
                $st = $d['stats'][$uid];
                $ds->add([
                    'teacher' => $t['name'], 'email' => $t['email'], 'roles' => $this->roles($t['roles']),
                    'courses' => count($t['courses']), 'students' => $st['activestudents'], 'messages' => $st['messages'],
                    'notes' => $st['notes'], 'contacted' => count($st['students']),
                    'replytime' => contacts::duration(contacts::median($st['replies'])), 'unanswered' => $st['unanswered'],
                    'grading' => $st['grading'], 'lastaccess' => fmt::date($t['lastaccess']),
                ]);
            }
            $ds->emptytext = $s('team_none');
            return $ds;
        }
        if ($id === 'unanswered') {
            $ds = new dataset('unanswered', $s('team_unanswered'), [
                'student' => $s('col_student'), 'staff' => $s('col_recipient'), 'count' => $s('col_waitingmsgs'),
                'since' => $s('col_waitingsince'), 'last' => $s('col_lastmessage'),
            ], ['count']);
            $rows = $this->unanswered_rows();
            \local_ceduanalytics\local\people::preload(array_merge(
                array_column($rows, 'studentid'),
                array_column($rows, 'staffid')
            ));
            foreach ($rows as $u) {
                $student = $this->name($u['studentid']);
                $staff = $this->name($u['staffid']);
                $ds->add([
                    'student' => $student, 'staff' => $staff, 'count' => $u['count'],
                    'since' => fmt::date($u['since']), 'last' => fmt::datetime($u['last']),
                    '_html' => [
                        'student' => indicators::student_link($u['studentid'], $student),
                        'staff' => isset($d['team'][$u['staffid']]) ? contacts::teacher_link(
                            $u['staffid'],
                            $staff,
                            $this->filters->params()
                        ) : s($staff) . \local_ceduanalytics\local\people::dual_mark($u['staffid'], 'teacher') . ' ' .
                            \html_writer::span($s('siteadmin_short'), 'cea-badge'),
                        'since' => \html_writer::span(fmt::date($u['since']), '', ['title' => fmt::ago($u['since'])]),
                    ],
                    '_class' => $u['last'] >= time() - 30 * DAYSECS ? 'cea-row-high' : '',
                ]);
            }
            $ds->emptytext = $s('team_unanswered_empty');
            return $ds;
        }
        if ($id === 'grading') {
            $ds = new dataset('grading', $s('team_grading'), [
                'course' => $s('col_course'), 'activity' => $s('col_activity'), 'waiting' => $s('col_waiting'),
                'oldest' => $s('col_oldestsubmission'), 'teachers' => $s('col_teachers'),
            ], ['waiting']);
            $rows = $this->grading_rows();
            $teachers = coursestats::teachers(array_values(array_unique(array_column($rows, 'courseid'))));
            foreach ($rows as $g) {
                $course = fmt::text((string)$DB->get_field('course', 'fullname', ['id' => $g['courseid']]));
                $names = array_map(fn($t) => $t['name'], $teachers[$g['courseid']] ?? []);
                $ds->add([
                    'course' => $course, 'activity' => fmt::text($g['name']), 'waiting' => $g['waiting'],
                    'oldest' => fmt::date($g['oldest']), 'teachers' => implode(', ', $names) ?: $s('noteachers'),
                    '_html' => [
                        'course' => coursestats::link($g['courseid'], $course, $this->filters->params()),
                        'activity' => \html_writer::link(new \moodle_url(
                            '/mod/assign/view.php',
                            ['id' => $g['cmid'], 'action' => 'grading']
                        ), s(fmt::text($g['name']))),
                        'oldest' => \html_writer::span(fmt::date($g['oldest']), '', ['title' => fmt::ago($g['oldest'])]),
                    ],
                ]);
            }
            $ds->emptytext = $s('team_grading_empty');
            return $ds;
        }
        return null;
    }

    /**
     * Unanswered conversations shown on this page.
     *
     * @return array[]
     */
    protected function unanswered_rows(): array {
        return $this->data()['conv']['unanswered'];
    }

    /**
     * Pending grading shown on this page.
     *
     * @return array[]
     */
    protected function grading_rows(): array {
        return $this->data()['grading'];
    }
}
