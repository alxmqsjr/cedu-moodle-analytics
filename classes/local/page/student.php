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
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;

/**
 * Student profile (detail page): who the student is, every enrolment and the activity over time.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student extends base {
    /** @var \stdClass */
    protected $user;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        global $DB;
        parent::__construct($def, $filters);
        $this->user = $DB->get_record('user', ['id' => required_param('id', PARAM_INT), 'deleted' => 0], '*', MUST_EXIST);
    }

    /**
     * State.
     *
     * @return array
     */
    public function state_params(): array {
        return ['id' => $this->user->id];
    }

    /**
     * Title: the student name.
     *
     * @return string
     */
    public function title(): string {
        return fullname($this->user);
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return ['enrolments' => get_string('student_enrolments', 'local_ceduanalytics')];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        global $DB, $OUTPUT, $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $u = $this->user;
        $attr = $DB->get_record('local_ceduanalytics_userattr', ['userid' => $u->id]);
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);

        // All custom profile fields (admin-only page: show everything the profile has).
        $profilefields = [];
        foreach (profile_get_user_fields_with_data($u->id) as $field) {
            $value = $field->display_data();
            $profilefields[] = ['label' => fmt::text($field->field->name),
                'value' => trim(strip_tags((string)$value)) !== '' ? $value : '—'];
        }

        $enrolments = $DB->get_records_sql("SELECT e.*, c.fullname, l.name AS linename, lc.edition
                FROM {local_ceduanalytics_enrolstate} e
                JOIN {course} c ON c.id = e.courseid
           LEFT JOIN {local_ceduanalytics_linecourse} lc ON lc.courseid = c.id
           LEFT JOIN {local_ceduanalytics_courseline} l ON l.id = lc.lineid
               WHERE e.userid = ? ORDER BY e.timeenrolled DESC", [$u->id]);
        $totals = $DB->get_record_sql('SELECT SUM(activesecs) AS secs, COUNT(DISTINCT day) AS days, SUM(events) AS events,
                SUM(wsevents) AS ws, SUM(logins) AS logins
                FROM {local_ceduanalytics_daily} WHERE userid = ?', [$u->id]);
        $certs = count(array_filter($enrolments, fn($e) => $e->timecertified > 0));
        $studentenrol = count(array_filter($enrolments, fn($e) => $e->isstudent));

        // Monthly activity (whole history).
        $first = (int)$DB->get_field_sql('SELECT MIN(day) FROM {local_ceduanalytics_daily} WHERE userid = ?', [$u->id]);
        $chart = '';
        if ($first) {
            $buckets = charts::buckets($first, time(), 'month');
            $starts = array_keys($buckets);
            $hours = array_fill_keys($starts, 0);
            $days = array_fill_keys($starts, 0);
            foreach (
                $DB->get_records_sql('SELECT day, SUM(activesecs) AS secs FROM {local_ceduanalytics_daily}
                    WHERE userid = ? GROUP BY day', [$u->id]) as $r
            ) {
                $b = charts::bucket_of((int)$r->day, $starts);
                if ($b !== null) {
                    $hours[$b] += round($r->secs / 3600, 2);
                    $days[$b]++;
                }
            }
            $chart = charts::bar(array_values($buckets), [
                $s('series_activedays') => array_values($days),
                $s('series_hours') => array_values(array_map(fn($h) => round($h, 1), $hours)),
            ], false, false, [charts::GREEN, charts::ORANGE]);
        }

        // Contacts from the team (metadata only: counts and dates, never the content).
        $notes = $DB->get_record_sql("SELECT COUNT(1) AS n, MAX(created) AS last FROM {post}
                WHERE module = 'notes' AND userid = ?", [$u->id]);
        $messages = $DB->get_record_sql("SELECT COUNT(1) AS n, MAX(m.timecreated) AS last
                FROM {messages} m
                JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid AND mcm.userid = :uid
               WHERE m.useridfrom <> :uid2", ['uid' => $u->id, 'uid2' => $u->id]);
        $sent = $DB->get_record_sql(
            'SELECT COUNT(1) AS n, MAX(timecreated) AS last FROM {messages} WHERE useridfrom = ?',
            [$u->id]
        );

        $ufsource = $attr && $attr->ufsource ? $s('ufsource_' . $attr->ufsource) : $s('ufsource_none');
        return $this->render('student', [
            'picture' => $OUTPUT->user_picture($u, ['size' => 96, 'link' => false]),
            'fullname' => fullname($u),
            'dualmark' => \local_ceduanalytics\local\people::dual_mark((int)$u->id),
            'email' => $u->email,
            'username' => $u->username,
            'auth' => $u->auth,
            'suspended' => (bool)$u->suspended,
            'uf' => $attr && $attr->uf ? $attr->uf . ' — ' . \local_ceduanalytics\local\br::state_name($attr->uf) : '—',
            'ufsource' => $ufsource,
            'city' => $attr->city ?? '',
            'institution' => $attr->institution ?? '',
            'jobtitle' => $attr->jobtitle ?? '',
            'segment' => $attr && $attr->segment ? $s('segment_' . $attr->segment) : '—',
            'created' => fmt::date((int)$u->timecreated),
            'firstaccess' => fmt::date((int)$u->firstaccess),
            'lastaccess' => fmt::datetime((int)$u->lastaccess) . ' (' . fmt::ago((int)$u->lastaccess) . ')',
            'profilefields' => $profilefields,
            'kpis' => [
                $this->kpi('st_courses', fmt::int($studentenrol)),
                $this->kpi('st_certs', fmt::int($certs)),
                $this->kpi('st_hours', fmt::hours((int)($totals->secs ?? 0))),
                $this->kpi('st_days', fmt::int((int)($totals->days ?? 0))),
                $this->kpi('st_app', fmt::pct((int)($totals->ws ?? 0), (int)($totals->events ?? 0))),
            ],
            'contacts' => [
                ['label' => $s('st_contacts_notes'), 'value' => fmt::int((int)$notes->n), 'last' => fmt::date((int)$notes->last)],
                ['label' => $s('st_contacts_received'), 'value' => fmt::int((int)$messages->n),
                    'last' => fmt::date((int)$messages->last)],
                ['label' => $s('st_contacts_sent'), 'value' => fmt::int((int)$sent->n), 'last' => fmt::date((int)$sent->last)],
            ],
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state('fa-chart-column', $s('empty_activity_title'), $s('st_noactivity')),
            'table' => $this->get_dataset('enrolments')->export_for_template(),
            'links' => [
                ['label' => $s('st_link_profile'), 'icon' => 'fa-user',
                    'url' => (new \moodle_url('/user/profile.php', ['id' => $u->id]))->out(false)],
                ['label' => $s('st_link_message'), 'icon' => 'fa-comment',
                    'url' => (new \moodle_url('/message/index.php', ['id' => $u->id]))->out(false)],
                ['label' => $s('st_link_notes'), 'icon' => 'fa-note-sticky',
                    'url' => (new \moodle_url('/notes/index.php', ['userid' => $u->id]))->out(false)],
                ['label' => $s('st_link_log'), 'icon' => 'fa-clock-rotate-left',
                    'url' => (new \moodle_url('/report/log/user.php', ['id' => $u->id, 'course' => SITEID,
                        'mode' => 'all']))->out(false)],
                ['label' => $s('st_link_edit'), 'icon' => 'fa-pen',
                    'url' => (new \moodle_url('/user/editadvanced.php', ['id' => $u->id]))->out(false)],
            ],
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
        if ($id !== 'enrolments') {
            return null;
        }
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $ds = new dataset('enrolments', $s('student_enrolments'), [
            'course' => $s('col_course'), 'line' => $s('col_line'), 'roles' => $s('col_roles'), 'situation' => $s('col_situation'),
            'progress' => $s('col_progress'), 'activities' => $s('col_activities'), 'grade' => $s('col_grade'),
            'enrolled' => $s('col_enrolled_on'), 'firstaccess' => $s('col_firstaccess'), 'lastaccess' => $s('col_lastaccess'),
            'certified' => $s('col_certified_on'), 'methods' => $s('col_methods'),
        ], ['progress', 'activities', 'grade']);
        $rows = $DB->get_records_sql("SELECT e.*, c.fullname, l.name AS linename, lc.edition
                FROM {local_ceduanalytics_enrolstate} e
                JOIN {course} c ON c.id = e.courseid
           LEFT JOIN {local_ceduanalytics_linecourse} lc ON lc.courseid = c.id
           LEFT JOIN {local_ceduanalytics_courseline} l ON l.id = lc.lineid
               WHERE e.userid = ? ORDER BY e.timeenrolled DESC", [$this->user->id]);
        foreach ($rows as $r) {
            $course = fmt::text($r->fullname);
            $line = $r->linename ? fmt::text($r->linename) . ($r->edition ? ' · ' . $r->edition : '') : '';
            $ds->add([
                'course' => $course, 'line' => $line, 'roles' => $r->roles,
                'situation' => $r->isstudent ? $s('situation_' . $r->situation) : '',
                'progress' => fmt::percent($r->progress), 'activities' => $r->cmdone . '/' . $r->cmtotal,
                'grade' => $r->gradepct === null ? '—' : fmt::percent($r->gradepct),
                'enrolled' => fmt::date((int)$r->timeenrolled), 'firstaccess' => fmt::date((int)$r->firstaccess),
                'lastaccess' => fmt::date((int)$r->lastaccess), 'certified' => fmt::date((int)$r->timecertified),
                'methods' => $r->enrolmethods,
                '_html' => [
                    'course' => \local_ceduanalytics\local\coursestats::link((int)$r->courseid, $course),
                    'situation' => $r->isstudent ? indicators::situation_badge($r->situation) : '',
                ],
            ]);
        }
        $ds->emptytext = $s('st_noenrolments');
        return $ds;
    }
}
