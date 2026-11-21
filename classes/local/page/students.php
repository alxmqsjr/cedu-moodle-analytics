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

use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;
use local_ceduanalytics\local\scope;
use local_ceduanalytics\local\snapshot;

/**
 * Students overview: who they are, how many, and how they are doing.
 *
 * Two views: one row per student, or one row per enrolment. Situation chips filter the list.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class students extends base {
    /** @var string */
    protected $situation;
    /** @var string students|enrolments */
    protected $view;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $situation = optional_param('situation', '', PARAM_ALPHA);
        $this->situation = in_array($situation, snapshot::SITUATIONS) ? $situation : '';
        $this->view = optional_param('view', 'students', PARAM_ALPHA) === 'enrolments' ? 'enrolments' : 'students';
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        $p = ['view' => $this->view];
        if ($this->situation !== '') {
            $p['situation'] = $this->situation;
        }
        return $p;
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'students' => get_string('students_view_students', 'local_ceduanalytics'),
            'enrolments' => get_string('students_view_enrolments', 'local_ceduanalytics'),
        ];
    }

    /**
     * Enrolment rows in scope (all situations), with user and course data.
     *
     * @return \stdClass[]
     */
    protected function rows(): array {
        $scope = new scope($this->filters);
        return $scope->enrolments('e.*, c.fullname AS coursename, c.enddate AS courseend, u.firstname, u.lastname,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email, u.lastaccess AS sitelastaccess,
            ua.uf, ua.ufsource, ua.segment, ua.institution, ua.city', '', [], 'u.firstname, u.lastname, c.fullname');
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $rows = $this->rows();
        $counts = array_fill_keys(snapshot::SITUATIONS, 0);
        $users = [];
        foreach ($rows as $r) {
            $counts[$r->situation]++;
            $users[$r->userid] = true;
        }
        $chips = [['label' => get_string('all_female', 'local_ceduanalytics'), 'count' => fmt::int(count($rows)),
            'url' => $this->url(['situation' => ''])->out(false), 'active' => $this->situation === '', 'key' => 'all']];
        foreach (snapshot::SITUATIONS as $sit) {
            $chips[] = ['label' => get_string('situation_' . $sit, 'local_ceduanalytics'), 'count' => fmt::int($counts[$sit]),
                'url' => $this->url(['situation' => $sit])->out(false), 'active' => $this->situation === $sit, 'key' => $sit,
                'help' => get_string('situation_' . $sit . '_help', 'local_ceduanalytics')];
        }
        $ds = $this->get_dataset($this->view);
        return $this->render('students', [
            'chips' => $chips,
            'summary' => get_string('students_summary', 'local_ceduanalytics', ['students' => fmt::int(count($users)),
                'enrolments' => fmt::int(count($rows))]),
            'viewstudents' => $this->view === 'students',
            'urlstudents' => $this->url(['view' => 'students'])->out(false),
            'urlenrolments' => $this->url(['view' => 'enrolments'])->out(false),
            'table' => $ds->export_for_template(),
            'situationhelp' => $this->situation ? get_string('situation_' . $this->situation . '_help', 'local_ceduanalytics') : '',
        ]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $rows = $this->rows();
        if ($this->situation !== '') {
            if ($id === 'students') {
                $keep = [];
                foreach ($rows as $r) {
                    if ($r->situation === $this->situation) {
                        $keep[$r->userid] = true;
                    }
                }
                $rows = array_filter($rows, fn($r) => isset($keep[$r->userid]));
            } else {
                $rows = array_filter($rows, fn($r) => $r->situation === $this->situation);
            }
        }

        if ($id === 'enrolments') {
            $ds = new dataset('enrolments', $s('students_view_enrolments'), [
                'student' => $s('col_student'), 'email' => $s('col_email'), 'course' => $s('col_course'),
                'situation' => $s('col_situation'), 'progress' => $s('col_progress'), 'activities' => $s('col_activities'),
                'grade' => $s('col_grade'), 'enrolled' => $s('col_enrolled_on'), 'firstaccess' => $s('col_firstaccess'),
                'lastaccess' => $s('col_lastaccess'), 'certified' => $s('col_certified_on'), 'end' => $s('col_enrolend'),
            ], ['progress', 'activities', 'grade']);
            foreach ($rows as $r) {
                $name = fullname($r);
                $course = fmt::text($r->coursename);
                $ds->add([
                    'student' => $name, 'email' => $r->email, 'course' => $course,
                    'situation' => $s('situation_' . $r->situation),
                    'progress' => fmt::percent($r->progress),
                    'activities' => $r->cmdone . '/' . $r->cmtotal,
                    'grade' => $r->gradepct === null ? '—' : fmt::percent($r->gradepct),
                    'enrolled' => fmt::date((int)$r->timeenrolled),
                    'firstaccess' => fmt::date((int)$r->firstaccess),
                    'lastaccess' => fmt::date((int)$r->lastaccess),
                    'certified' => fmt::date((int)$r->timecertified),
                    'end' => $r->timeend ? fmt::date((int)$r->timeend) : $s('noenddate'),
                    '_html' => [
                        'student' => indicators::student_link($r->userid, $name),
                        'course' => \local_ceduanalytics\local\coursestats::link(
                            (int)$r->courseid,
                            $course,
                            $this->filters->params()
                        ),
                        'situation' => indicators::situation_badge($r->situation),
                        'progress' => $this->progress_html((float)$r->progress),
                        'lastaccess' => \html_writer::span(
                            fmt::date((int)$r->lastaccess),
                            '',
                            ['title' => fmt::ago((int)$r->lastaccess)]
                        ),
                    ],
                ]);
            }
            $ds->emptytext = $s('empty_students');
            return $ds;
        }

        if ($id === 'students') {
            $byuser = [];
            foreach ($rows as $r) {
                if (!isset($byuser[$r->userid])) {
                    $byuser[$r->userid] = ['r' => $r, 'courses' => 0, 'progress' => 0.0, 'certs' => 0, 'last' => 0,
                        'situations' => []];
                }
                $u = &$byuser[$r->userid];
                $u['courses']++;
                $u['progress'] += (float)$r->progress;
                $u['certs'] += $r->timecertified ? 1 : 0;
                $u['last'] = max($u['last'], (int)$r->lastaccess);
                $u['situations'][$r->situation] = ($u['situations'][$r->situation] ?? 0) + 1;
                unset($u);
            }
            $ds = new dataset('students', $s('students_view_students'), [
                'student' => $s('col_student'), 'email' => $s('col_email'), 'uf' => $s('col_uf'), 'city' => $s('col_city'),
                'segment' => $s('col_segment'), 'institution' => $s('col_institution'), 'courses' => $s('col_courses'),
                'situations' => $s('col_situations'), 'progress' => $s('col_avgprogress'), 'certs' => $s('col_certified'),
                'lastaccess' => $s('col_lastaccess'),
            ], ['courses', 'progress', 'certs']);
            foreach ($byuser as $uid => $u) {
                $r = $u['r'];
                $name = fullname($r);
                $sits = [];
                $sitshtml = [];
                foreach (snapshot::SITUATIONS as $sit) {
                    if (!empty($u['situations'][$sit])) {
                        $label = $s('situation_' . $sit) . ($u['situations'][$sit] > 1 ? ' ×' . $u['situations'][$sit] : '');
                        $sits[] = $label;
                        $sitshtml[] = \html_writer::span(s($label), 'cea-badge cea-sit cea-sit-' . $sit);
                    }
                }
                $avg = $u['progress'] / $u['courses'];
                $ds->add([
                    'student' => $name, 'email' => $r->email,
                    'uf' => $r->uf ? $r->uf . ($r->ufsource !== 'declared' ? ' (' . $s('ufsource_' . $r->ufsource) . ')' : '') : '',
                    'city' => (string)$r->city,
                    'segment' => $r->segment ? $s('segment_' . $r->segment) : '',
                    'institution' => (string)$r->institution, 'courses' => $u['courses'], 'situations' => implode(', ', $sits),
                    'progress' => fmt::percent($avg), 'certs' => $u['certs'], 'lastaccess' => fmt::date($u['last']),
                    '_html' => [
                        'student' => indicators::student_link($uid, $name),
                        'uf' => indicators::uf_html($r->uf, $r->ufsource),
                        'situations' => implode(' ', $sitshtml),
                        'progress' => $this->progress_html($avg),
                        'lastaccess' => \html_writer::span(fmt::date($u['last']), '', ['title' => fmt::ago($u['last'])]),
                    ],
                ]);
            }
            $ds->emptytext = $s('empty_students');
            return $ds;
        }
        return null;
    }

    /**
     * Progress bar HTML.
     *
     * @param float $pct
     * @return string
     */
    protected function progress_html(float $pct): string {
        return \html_writer::span(
            \html_writer::span('', 'cea-progress-fill', ['style' => 'width: ' . min(100, max(0, $pct)) . '%']),
            'cea-progress',
            ['aria-hidden' => 'true']
        ) . ' ' . fmt::percent($pct);
    }
}
