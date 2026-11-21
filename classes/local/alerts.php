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
 * Platform alerts: every problem the dashboard can detect, with severity, evidence and where to fix it.
 *
 * Categories: security, platform (tasks, storage, privacy, plugins), teaching setup (completion, grades,
 * dates, visibility), content, data quality and follow-up. Strings: alert_<id> (title) and
 * alert_<id>_text (explanation with evidence). Only reads; nothing is changed.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class alerts {
    /** @var string[] Categories in display order. */
    const CATEGORIES = ['security', 'platform', 'teaching', 'content', 'data', 'followup'];


    /**
     * All alerts (cached for an hour with the indicators cache).
     *
     * @return array[] list of ['id', 'category', 'severity', 'title', 'text', 'fixurl', 'pageurl']
     */
    public static function all(): array {
        $cache = \cache::make('local_ceduanalytics', 'indicators');
        $alerts = $cache->get('alerts');
        if ($alerts === false) {
            $alerts = self::compute();
            $cache->set('alerts', $alerts);
        }
        return $alerts;
    }

    /**
     * Compute every alert.
     *
     * @return array[]
     */
    protected static function compute(): array {
        global $DB, $CFG;
        $out = [];
        $now = time();
        $add = function (
            string $id,
            string $category,
            string $severity,
            $a = null,
            ?\moodle_url $fix = null,
            ?\moodle_url $page = null
        ) use (&$out) {
            $out[] = ['id' => $id, 'category' => $category, 'severity' => $severity,
                'title' => get_string('alert_' . $id, 'local_ceduanalytics'),
                'text' => get_string('alert_' . $id . '_text', 'local_ceduanalytics', $a),
                'fixurl' => $fix ? $fix->out(false) : '', 'pageurl' => $page ? $page->out(false) : ''];
        };

        // Security: the checks of the Security page that are not ok (last 12 months).
        $failures = security::failures($now - 365 * DAYSECS, $now + DAYSECS);
        foreach (security::checks($failures, security::privileged()) as $c) {
            if ($c['severity'] !== 'ok') {
                $out[] = ['id' => 'sec_' . $c['id'], 'category' => 'security', 'severity' => $c['severity'],
                    'title' => $c['title'], 'text' => $c['text'] . ($c['forced'] ? ' (' .
                    get_string('sec_forced', 'local_ceduanalytics') . ')' : ''), 'fixurl' => $c['url'],
                    'pageurl' => navigation::url('security')->out(false)];
            }
        }

        // Platform: privacy requests waiting.
        if ($DB->get_manager()->table_exists('tool_dataprivacy_request')) {
            $pending = $DB->count_records_select('tool_dataprivacy_request', 'status IN (0, 1, 2, 3)');
            if ($pending) {
                $oldest = (int)$DB->get_field_sql('SELECT MIN(timecreated) FROM {tool_dataprivacy_request}
                    WHERE status IN (0, 1, 2, 3)');
                $add(
                    'privacy',
                    'platform',
                    'critical',
                    ['n' => fmt::int($pending), 'since' => fmt::date($oldest)],
                    new \moodle_url('/admin/tool/dataprivacy/datarequests.php')
                );
            }
        }
        // Sessions stored in the database, mostly anonymous.
        $sessions = $DB->count_records('sessions');
        $anon = $DB->count_records('sessions', ['userid' => 0]);
        if ($sessions > 2000 && $anon > $sessions / 2) {
            $add(
                'sessions',
                'platform',
                'warning',
                ['n' => fmt::int($sessions), 'anon' => fmt::int($anon)],
                new \moodle_url('/admin/settings.php', ['section' => 'sessionhandling'])
            );
        }
        // Language string cache, asynchronous backups, analytics.
        if (empty($CFG->langstringcache)) {
            $add('langcache', 'platform', 'warning', null, new \moodle_url('/admin/settings.php', ['section' => 'langsettings']));
        }
        if (empty($CFG->enableasyncbackup)) {
            $add('asyncbackup', 'platform', 'info', null, new \moodle_url(
                '/admin/settings.php',
                ['section' => 'asyncgeneralsettings']
            ));
        }
        if (!empty($CFG->enableanalytics)) {
            $add(
                'analytics',
                'platform',
                'info',
                fmt::int($DB->count_records('analytics_predictions')),
                new \moodle_url('/admin/settings.php', ['section' => 'optionalsubsystems'])
            );
        }
        // Additional plugins: the same table as the "Moodle plugins" alert of the Performance tab.
        $rows = plugins::rows();
        $unused = array_column(array_filter($rows, fn($r) => in_array($r['state'], ['never', 'disabled'])), 'component');
        $missing = array_column(array_filter($rows, fn($r) => $r['state'] === 'missing'), 'component');
        if ($unused) {
            $add(
                'unusedplugins',
                'platform',
                'info',
                ['n' => count($unused), 'list' => implode(', ', $unused)],
                new \moodle_url('/admin/plugins.php', ['contribonly' => 1])
            );
        }
        if ($missing) {
            $add(
                'missingplugins',
                'platform',
                'warning',
                ['n' => count($missing), 'list' => implode(', ', $missing)],
                new \moodle_url('/admin/plugins.php', ['contribonly' => 1])
            );
        }
        // Storage.
        $files = $DB->get_records_sql("SELECT component, COUNT(1) AS n, SUM(filesize) AS bytes
            FROM {files} WHERE filename <> '.' GROUP BY component");
        $total = array_sum(array_map(fn($f) => (float)$f->bytes, $files));
        $qmatch = (float)($files['qtype_match']->bytes ?? 0);
        $backup = (float)($files['backup']->bytes ?? 0);
        $add(
            'storage',
            'platform',
            $qmatch > 100 * 1048576 || $backup > 200 * 1048576 ? 'warning' : 'info',
            ['total' => fmt::size($total), 'qmatch' => fmt::size($qmatch), 'backup' => fmt::size($backup)],
            null
        );
        // The dashboard's own processing.
        $maxid = (int)$DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}');
        $mark = (int)get_config('local_ceduanalytics', 'logwatermark');
        $lastsnapshot = (int)get_config('local_ceduanalytics', 'lastsnapshot');
        if ($maxid - $mark > 5000 || $lastsnapshot < $now - 3 * HOURSECS) {
            $add(
                'processing',
                'platform',
                'warning',
                ['lag' => fmt::int(max(0, $maxid - $mark)),
                'snapshot' => $lastsnapshot ? fmt::datetime($lastsnapshot) : get_string('never', 'local_ceduanalytics')],
                null,
                navigation::url('config', ['tab' => 'data'])
            );
        }

        // Teaching setup.
        $hidden = $DB->get_records_sql("
            SELECT c.id, c.fullname, c.startdate, c.enddate, COUNT(1) AS n
              FROM {local_ceduanalytics_enrolstate} e
              JOIN {course} c ON c.id = e.courseid AND c.visible = 0
             WHERE e.isstudent = 1 AND e.enrolactive = 1
          GROUP BY c.id, c.fullname, c.startdate, c.enddate");
        // Model courses are hidden on purpose.
        $catalogue = courses::get_all();
        $hidden = array_filter($hidden, fn($c) => ($catalogue[$c->id]->type ?? '') !== 'model');
        $running = array_filter($hidden, fn($c) => $c->startdate <= $now && (!$c->enddate || $c->enddate > $now));
        if ($running) {
            $add(
                'hiddenrunning',
                'teaching',
                'critical',
                ['n' => count($running),
                'students' => fmt::int(array_sum(array_column($running, 'n'))),
                'list' => implode('; ', array_map(fn($c) => trim(fmt::text($c->fullname)), array_slice($running, 0, 8)))],
                new \moodle_url('/course/management.php'),
                navigation::url('courses')
            );
        }
        $ended = array_diff_key($hidden, $running);
        if ($ended) {
            $add(
                'hiddenended',
                'teaching',
                'info',
                ['n' => count($ended),
                'students' => fmt::int(array_sum(array_column($ended, 'n')))],
                new \moodle_url('/course/management.php'),
                navigation::url('courses')
            );
        }
        $withstudents = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {local_ceduanalytics_enrolstate} WHERE isstudent = 1');
        if ($withstudents && !$DB->record_exists('course_completion_criteria', [])) {
            $add('nocompletion', 'teaching', 'warning', fmt::int(count($withstudents)), null, navigation::url('completions'));
        }
        if (!$DB->record_exists_select('grade_items', 'gradepass > 0')) {
            $add(
                'nogradepass',
                'teaching',
                'info',
                fmt::int($DB->count_records_select('grade_items', "itemtype IN ('mod', 'course')")),
                null,
                navigation::url('assessments')
            );
        }
        $notrack = $DB->get_records_sql("
            SELECT e.courseid, c.fullname, COUNT(1) AS n
              FROM {local_ceduanalytics_enrolstate} e
              JOIN {course} c ON c.id = e.courseid
             WHERE e.isstudent = 1
          GROUP BY e.courseid, c.fullname
            HAVING MAX(e.cmtotal) = 0");
        if ($notrack) {
            $add(
                'notracking',
                'teaching',
                'warning',
                ['n' => count($notrack), 'list' => implode(
                    '; ',
                    array_map(fn($c) => fmt::text($c->fullname) . ' (' . $c->n . ')', $notrack)
                )],
                null,
                navigation::url('pathway')
            );
        }
        $noend = (int)$DB->count_records_sql("
            SELECT COUNT(1) FROM {local_ceduanalytics_enrolstate} e JOIN {course} c ON c.id = e.courseid
             WHERE e.isstudent = 1 AND e.enrolactive = 1 AND e.timeend = 0 AND c.enddate = 0");
        if ($noend) {
            $add('noenddates', 'teaching', 'info', fmt::int($noend), null, navigation::url('courses'));
        }
        $completednocert = $DB->count_records('local_ceduanalytics_enrolstate', ['situation' => 'completed', 'isstudent' => 1]);
        if ($completednocert) {
            $add(
                'completednocert',
                'teaching',
                'warning',
                fmt::int($completednocert),
                null,
                navigation::url('students', ['situation' => 'completed', 'view' => 'enrolments'])
            );
        }
        $staffstudents = (int)$DB->count_records_sql(
            "
            SELECT COUNT(1) FROM {local_ceduanalytics_enrolstate}
             WHERE isstudent = 1 AND (" . $DB->sql_like('roles', ':r1') . ' OR ' . $DB->sql_like('roles', ':r2') . ')',
            ['r1' => '%teacher%', 'r2' => '%manager%']
        );
        if ($staffstudents) {
            $add('staffstudents', 'teaching', 'info', fmt::int($staffstudents));
        }

        // Content.
        $emptyfeedback = (int)$DB->count_records_sql('SELECT COUNT(1) FROM {feedback} f
            WHERE NOT EXISTS (SELECT 1 FROM {feedback_completed} c WHERE c.feedback = f.id)');
        if ($emptyfeedback) {
            $add('emptyfeedback', 'content', 'info', fmt::int($emptyfeedback), null, navigation::url(
                'feedback',
                ['period' => 'all']
            ));
        }
        $noteacher = [];
        $courses = $DB->get_records_list('course', 'id', $withstudents ?: [0], '', 'id, fullname, visible, startdate, enddate');
        $teachers = coursestats::teachers(array_keys($courses));
        foreach ($courses as $c) {
            $isrunning = $c->startdate <= $now && (!$c->enddate || $c->enddate > $now);
            if ($isrunning && empty($teachers[$c->id])) {
                $noteacher[] = fmt::text($c->fullname);
            }
        }
        if ($noteacher) {
            $add(
                'noteacher',
                'content',
                'warning',
                ['n' => count($noteacher), 'list' => implode('; ', $noteacher)],
                null,
                navigation::url('courses')
            );
        }

        // Data quality.
        $users = (int)$DB->count_records('local_ceduanalytics_userattr');
        $nouf = (int)$DB->count_records_select('local_ceduanalytics_userattr', "uf = '' OR uf IS NULL");
        if ($users && $nouf / $users > 0.1) {
            $add(
                'nouf',
                'data',
                'info',
                ['n' => fmt::int($nouf), 'pct' => fmt::pct($nouf, $users)],
                null,
                navigation::url('map')
            );
        }
        $noseg = (int)$DB->count_records_select('local_ceduanalytics_userattr', "segment IN ('other', 'notinformed')");
        if ($users && $noseg / $users > 0.1) {
            $add(
                'nosegment',
                'data',
                'info',
                ['n' => fmt::int($noseg), 'pct' => fmt::pct($noseg, $users)],
                null,
                navigation::url('config', ['tab' => 'segments'])
            );
        }
        $never = (int)$DB->count_records_select('user', 'deleted = 0 AND suspended = 0 AND lastaccess = 0 AND id > 2');
        if ($never) {
            $add('neverloggedin', 'data', 'info', fmt::int($never), new \moodle_url('/admin/user.php'));
        }
        $suspended = (int)$DB->count_records_sql('SELECT COUNT(DISTINCT u.id) FROM {user} u
            JOIN {local_ceduanalytics_enrolstate} e ON e.userid = u.id AND e.enrolactive = 1 WHERE u.suspended = 1');
        if ($suspended) {
            $add('suspendedenrolled', 'data', 'info', fmt::int($suspended), new \moodle_url('/admin/user.php'));
        }

        // Follow-up.
        $conv = contacts::conversations();
        $recent = count(array_filter($conv['unanswered'], fn($u) => $u['last'] >= $now - 30 * DAYSECS));
        if ($conv['unanswered']) {
            $add('unanswered', 'followup', $recent ? 'warning' : 'info', ['n' => fmt::int(count($conv['unanswered'])),
                'recent' => fmt::int($recent)], null, navigation::url('team'));
        }
        $grading = contacts::pending_grading();
        $old = array_filter($grading, fn($g) => $g['oldest'] < $now - 14 * DAYSECS);
        if ($old) {
            $add('oldgrading', 'followup', 'warning', ['n' => fmt::int(array_sum(array_column($old, 'waiting'))),
                'since' => fmt::date(min(array_column($old, 'oldest')))], null, navigation::url('team'));
        }
        $notes = (int)$DB->count_records('post', ['module' => 'notes']);
        $drafts = (int)$DB->count_records('post', ['module' => 'notes', 'publishstate' => 'draft']);
        if ($notes && $drafts / $notes > 0.3) {
            $add(
                'draftnotes',
                'followup',
                'info',
                ['n' => fmt::int($drafts), 'pct' => fmt::pct($drafts, $notes)],
                null,
                navigation::url('team')
            );
        }
        $high = support::count_open();
        if ($high) {
            $add('supportqueue', 'followup', 'info', fmt::int($high), null, navigation::url('support'));
        }

        $order = array_flip(security::SEVERITIES);
        $cat = array_flip(self::CATEGORIES);
        usort($out, fn($a, $b) => [$order[$a['severity']], $cat[$a['category']]] <=> [$order[$b['severity']],
            $cat[$b['category']]]);
        return $out;
    }
}
