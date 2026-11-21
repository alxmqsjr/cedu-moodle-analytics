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
use local_ceduanalytics\local\scope;
use local_ceduanalytics\local\security as sec;

/**
 * Security: recommendations as alerts, failed logins, targeted accounts, IPs and privileged accounts.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security extends base {
    /** @var array|null */
    protected $failures = null;
    /** @var array|null */
    protected $privileged = null;
    /** @var string security|performance */
    protected $tab;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $this->tab = optional_param('tab', 'security', PARAM_ALPHA) === 'performance' ? 'performance' : 'security';
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        return $this->tab === 'performance' ? ['tab' => 'performance'] : [];
    }

    /**
     * Additional blocks only in the Security tab.
     *
     * @return string[]
     */
    public function extra_blocks(): array {
        return $this->tab === 'performance' ? [] : parent::extra_blocks();
    }

    /**
     * Tabs of the page.
     *
     * @return array
     */
    protected function tabs(): array {
        $tabs = [];
        foreach (['security', 'performance'] as $t) {
            $tabs[] = ['label' => get_string('sec_tab_' . $t, 'local_ceduanalytics'), 'active' => $t === $this->tab,
                'url' => $this->url(['tab' => $t === 'security' ? '' : $t])->out(false)];
        }
        return $tabs;
    }

    /**
     * Performance tab.
     *
     * @return string
     */
    protected function performance_content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $checks = \local_ceduanalytics\local\performance::checks();
        $counts = array_count_values(array_column($checks, 'severity'));
        $groups = [];
        foreach (['updates', 'cache', 'server', 'cron', 'database', 'storage'] as $g) {
            $items = array_values(array_filter($checks, fn($c) => $c['group'] === $g));
            foreach ($items as &$c) {
                $c['icon'] = ['critical' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation',
                    'info' => 'fa-circle-info', 'ok' => 'fa-circle-check'][$c['severity']];
                $c['severitylabel'] = $s('severity_' . $c['severity']);
                $c['hasplugins'] = !empty($c['plugins']);
            }
            unset($c);
            $groups[] = ['name' => $s('perf_group_' . $g), 'help' => $s('perf_group_' . $g . '_help'), 'items' => $items];
        }
        $kpis = [];
        foreach (['critical', 'warning', 'info', 'ok'] as $sev) {
            $kpis[] = $this->kpi('perf_' . $sev, fmt::int((int)($counts[$sev] ?? 0)));
        }
        return $this->render('performance', [
            'tabs' => $this->tabs(),
            'kpis' => $kpis,
            'groups' => $groups,
            'tables' => $this->get_dataset('dbtables')->export_for_template(),
            'purgeurl' => (new \moodle_url('/admin/purgecaches.php'))->out(false),
        ]);
    }


    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'checks' => get_string('sec_checks', 'local_ceduanalytics'),
            'usernames' => get_string('sec_usernames', 'local_ceduanalytics'),
            'ips' => get_string('sec_ips', 'local_ceduanalytics'),
            'privileged' => get_string('sec_privileged', 'local_ceduanalytics'),
            'signups' => get_string('sec_signups', 'local_ceduanalytics'),
            'perfchecks' => get_string('perf_checks', 'local_ceduanalytics'),
            'plugins' => get_string('perf_check_plugins', 'local_ceduanalytics'),
            'dbtables' => get_string('perf_tables', 'local_ceduanalytics'),
        ];
    }

    /**
     * Failed logins in the period.
     *
     * @return \stdClass[]
     */
    protected function failures(): array {
        if ($this->failures === null) {
            $scope = new scope($this->filters);
            $this->failures = sec::failures($scope->from, $scope->to);
        }
        return $this->failures;
    }

    /**
     * Privileged accounts.
     *
     * @return array
     */
    protected function privileged(): array {
        if ($this->privileged === null) {
            $this->privileged = sec::privileged();
        }
        return $this->privileged;
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        if ($this->tab === 'performance') {
            return $this->performance_content();
        }
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $rows = $this->failures();
        $scope = new scope($this->filters);
        $attempts = 0;
        $ips = [];
        $names = [];
        $byreason = array_fill_keys(sec::REASONS, 0);
        $days = [];
        foreach ($rows as $r) {
            $attempts += (int)$r->attempts;
            $ips[$r->ip] = true;
            $names[$r->username] = true;
            $byreason[(int)$r->reason] = ($byreason[(int)$r->reason] ?? 0) + (int)$r->attempts;
            $days[(int)$r->day] = ($days[(int)$r->day] ?? 0) + (int)$r->attempts;
        }
        $attackdays = count(array_filter($days, fn($n) => $n >= 100));
        $existing = $this->existing_targets();

        $kpis = [
            $this->kpi(
                'sec_failed',
                fmt::int($attempts),
                null,
                null,
                '',
                false,
                $s('sec_failed_note', fmt::pct($byreason[1], $attempts))
            ),
            $this->kpi('sec_ips', fmt::int(count($ips))),
            $this->kpi(
                'sec_usernames',
                fmt::int(count($names)),
                null,
                null,
                '',
                false,
                $s('sec_existing_note', fmt::int(count($existing)))
            ),
            $this->kpi('sec_attackdays', fmt::int($attackdays)),
        ];

        // Failed logins over time, by reason.
        $from = $scope->from ?: ($days ? min(array_keys($days)) : time());
        $unit = charts::unit_for($from, $scope->to);
        $buckets = charts::buckets($from, $scope->to, $unit);
        $starts = array_keys($buckets);
        $series = [];
        foreach ([1, 3, 2] as $reason) {
            $series[$reason] = array_fill_keys($starts, 0);
        }
        foreach ($rows as $r) {
            $b = charts::bucket_of((int)$r->day, $starts);
            $reason = in_array((int)$r->reason, [1, 3]) ? (int)$r->reason : 2;
            if ($b !== null) {
                $series[$reason][$b] += (int)$r->attempts;
            }
        }
        $chart = $attempts ? charts::bar(array_values($buckets), [
            $s('sec_reason_1') => array_values($series[1]),
            $s('sec_reason_3') => array_values($series[3]),
            $s('sec_reason_other') => array_values($series[2]),
        ], true, false, [charts::ORANGE, charts::RED, charts::NAVY]) : '';

        $checks = sec::checks($rows, $this->privileged());
        $counts = array_count_values(array_column($checks, 'severity'));
        foreach ($checks as &$c) {
            $c['icon'] = ['critical' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation',
                'info' => 'fa-circle-info', 'ok' => 'fa-circle-check'][$c['severity']];
            $c['severitylabel'] = $s('severity_' . $c['severity']);
        }
        unset($c);

        return $this->render('security', [
            'tabs' => $this->tabs(),
            'kpis' => $kpis,
            'checks' => $checks,
            'summary' => $s('sec_checks_summary', ['critical' => (int)($counts['critical'] ?? 0),
                'warning' => (int)($counts['warning'] ?? 0), 'ok' => (int)($counts['ok'] ?? 0)]),
            'chart' => $chart,
            'haschart' => $chart !== '',
            'chartempty' => $this->empty_state('fa-shield', $s('sec_nofailures'), $s('sec_nofailures_text')),
            'unitlabel' => $s('unit_' . $unit),
            'usernames' => $this->get_dataset('usernames')->export_for_template(),
            'ips' => $this->get_dataset('ips')->export_for_template(),
            'privileged' => $this->get_dataset('privileged')->export_for_template(),
            'signups' => $this->get_dataset('signups')->export_for_template(),
            'release' => $GLOBALS['CFG']->release,
        ]);
    }

    /**
     * Targeted usernames that belong to existing accounts.
     *
     * @return array username => userid
     */
    protected function existing_targets(): array {
        global $DB;
        $names = array_unique(array_map(fn($r) => $r->username, $this->failures()));
        $out = [];
        foreach (array_chunk($names, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk);
            $out += $DB->get_records_select_menu('user', "deleted = 0 AND username $insql", $params, '', 'username, id');
        }
        return $out;
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
        if ($id === 'perfchecks') {
            $ds = new dataset('perfchecks', $s('perf_checks'), ['group' => $s('col_category'),
                'severity' => $s('col_severity'), 'check' => $s('col_check'), 'text' => $s('col_detail'),
                'url' => $s('col_where')]);
            foreach (\local_ceduanalytics\local\performance::checks() as $c) {
                $ds->add(['group' => $s('perf_group_' . $c['group']), 'severity' => $s('severity_' . $c['severity']),
                    'check' => $c['title'], 'text' => $c['text'], 'url' => $c['url']]);
            }
            return $ds;
        }
        if ($id === 'plugins') {
            $ds = new dataset('plugins', $s('perf_check_plugins'), ['plugin' => $s('col_plugin'),
                'component' => $s('col_technicalname'), 'release' => $s('col_version'), 'description' => $s('col_description'),
                'usage' => $s('col_usage'), 'update' => $s('col_update')]);
            // Same rows as the "Moodle plugins" alert (cached with the performance checks).
            $check = array_filter(\local_ceduanalytics\local\performance::checks(), fn($c) => $c['id'] === 'plugins');
            foreach (reset($check)['plugins'] ?? [] as $r) {
                $ds->add($r + ['_class' => $r['usagebad'] || $r['updatebad'] ? 'cea-row-bad' : '']);
            }
            return $ds;
        }
        if ($id === 'dbtables') {
            $ds = new dataset('dbtables', $s('perf_tables'), ['table' => $s('col_table'), 'rows' => $s('col_records'),
                'size' => $s('col_size'), 'clean' => $s('col_howtoclean')], ['rows', 'size']);
            foreach (\local_ceduanalytics\local\performance::tables() as $t) {
                $ds->add(['table' => $t['table'], 'rows' => $t['rows'], 'size' => fmt::size($t['bytes']),
                    'clean' => $t['clean'],
                    '_html' => ['table' => \html_writer::tag('code', s($t['table'])), 'rows' => fmt::int($t['rows']),
                        'clean' => $t['url'] ? \html_writer::link($t['url'], s($t['clean'])) : s($t['clean'])],
                    '_class' => $t['clean'] ? '' : 'cea-row-muted']);
            }
            $ds->emptytext = $s('perf_tables_none');
            return $ds;
        }
        if ($id === 'checks') {
            $ds = new dataset('checks', $s('sec_checks'), ['severity' => $s('col_severity'), 'check' => $s('col_check'),
                'text' => $s('col_detail'), 'url' => $s('col_where')]);
            foreach (sec::checks($this->failures(), $this->privileged()) as $c) {
                $ds->add(['severity' => $s('severity_' . $c['severity']), 'check' => $c['title'], 'text' => $c['text'],
                    'url' => $c['url']]);
            }
            return $ds;
        }
        if ($id === 'usernames') {
            $existing = $this->existing_targets();
            $priv = [];
            foreach ($this->privileged() as $p) {
                $priv[$p['user']->username] = true;
            }
            $agg = [];
            foreach ($this->failures() as $r) {
                $a = &$agg[$r->username];
                $a = $a ?? ['n' => 0, 'ips' => [], 'last' => 0, 'pw' => 0];
                $a['n'] += (int)$r->attempts;
                $a['ips'][$r->ip] = true;
                $a['last'] = max($a['last'], (int)$r->lastts);
                $a['pw'] += (int)$r->reason === 3 ? (int)$r->attempts : 0;
                unset($a);
            }
            uasort($agg, fn($a, $b) => $b['n'] <=> $a['n']);
            $ds = new dataset('usernames', $s('sec_usernames'), [
                'username' => $s('col_username'), 'account' => $s('col_account'), 'attempts' => $s('col_attempts'),
                'wrongpw' => $s('col_wrongpassword'), 'ips' => $s('col_ips'), 'last' => $s('col_lastattempt'),
            ], ['attempts', 'wrongpw', 'ips']);
            foreach (array_slice($agg, 0, 200, true) as $name => $a) {
                $name = (string)$name;
                $shown = $name === '' ? $s('sec_emptyusername') : $name;
                $account = isset($priv[$name]) ? $s('sec_account_privileged') : (isset($existing[$name])
                    ? $s('sec_account_exists') : $s('sec_account_none'));
                $ds->add([
                    'username' => $shown, 'account' => $account, 'attempts' => $a['n'], 'wrongpw' => $a['pw'],
                    'ips' => count($a['ips']), 'last' => fmt::datetime($a['last']),
                    '_html' => ['account' => \html_writer::span(s($account), 'cea-badge ' . (isset($priv[$name])
                        ? 'cea-level-high' : (isset($existing[$name]) ? 'cea-level-medium' : 'cea-badge-soft')))],
                    '_class' => isset($priv[$name]) ? 'cea-row-high' : '',
                ]);
            }
            $ds->emptytext = $s('sec_nofailures');
            return $ds;
        }
        if ($id === 'ips') {
            $agg = [];
            foreach ($this->failures() as $r) {
                $a = &$agg[$r->ip];
                $a = $a ?? ['n' => 0, 'names' => [], 'days' => [], 'last' => 0];
                $a['n'] += (int)$r->attempts;
                $a['names'][$r->username] = true;
                $a['days'][$r->day] = true;
                $a['last'] = max($a['last'], (int)$r->lastts);
                unset($a);
            }
            uasort($agg, fn($a, $b) => $b['n'] <=> $a['n']);
            $ds = new dataset('ips', $s('sec_ips'), [
                'ip' => $s('col_ip'), 'attempts' => $s('col_attempts'), 'usernames' => $s('col_usernamestried'),
                'days' => $s('col_days'), 'last' => $s('col_lastattempt'),
            ], ['attempts', 'usernames', 'days']);
            foreach (array_slice($agg, 0, 200, true) as $ip => $a) {
                $ds->add(['ip' => (string)$ip, 'attempts' => $a['n'], 'usernames' => count($a['names']),
                    'days' => count($a['days']), 'last' => fmt::datetime($a['last'])]);
            }
            $ds->emptytext = $s('sec_nofailures');
            return $ds;
        }
        if ($id === 'privileged') {
            $ds = new dataset('privileged', $s('sec_privileged'), [
                'name' => $s('col_name'), 'username' => $s('col_username'), 'email' => $s('col_email'),
                'roles' => $s('col_roles'), 'auth' => $s('col_auth'), 'mfa' => $s('col_mfa'), 'lastaccess' => $s('col_lastaccess'),
                'status' => $s('col_status'),
            ]);
            $mfaon = (bool)get_config('tool_mfa', 'enabled');
            foreach ($this->privileged() as $p) {
                $u = $p['user'];
                $roles = $p['roles'];
                if ($p['admin']) {
                    array_unshift($roles, $s('sec_siteadmin'));
                }
                $mfa = $p['mfa'] ? implode(', ', $p['mfa']) : $s('sec_nomfa');
                $stale = (int)$u->lastaccess < time() - 90 * DAYSECS;
                $ds->add([
                    'name' => fullname($u), 'username' => $u->username, 'email' => $u->email, 'roles' => implode(', ', $roles),
                    'auth' => $u->auth, 'mfa' => $mfa,
                        'lastaccess' => $u->lastaccess ? fmt::date((int)$u->lastaccess) : $s('never'),
                    'status' => $u->suspended ? $s('sec_suspended') : ($stale ? $s('sec_stale') : $s('sec_active')),
                    '_html' => [
                        'name' => \html_writer::link(new \moodle_url('/user/profile.php', ['id' => $u->id]), s(fullname($u))) .
                            \local_ceduanalytics\local\people::dual_mark((int)$u->id, 'teacher'),
                        'mfa' => $p['mfa'] ? s($mfa) : \html_writer::span(s($mfa), 'cea-badge ' .
                            ($mfaon && $p['admin'] ? 'cea-level-high' : 'cea-badge-soft')),
                        'lastaccess' => \html_writer::span(
                            $u->lastaccess ? fmt::date((int)$u->lastaccess) : $s('never'),
                            '',
                            ['title' => $u->lastaccess ? fmt::ago((int)$u->lastaccess) : '']
                        ),
                    ],
                    '_class' => $stale ? 'cea-row-muted' : '',
                ]);
            }
            return $ds;
        }
        if ($id === 'signups') {
            $scope = new scope($this->filters);
            $ds = new dataset('signups', $s('sec_signups'), [
                'day' => $s('col_date'), 'accounts' => $s('col_accounts'), 'auth' => $s('col_auth'),
                'neveraccessed' => $s('col_neverloggedin'),
            ], ['accounts', 'neveraccessed']);
            $rs = $DB->get_recordset_select(
                'user',
                'deleted = 0 AND timecreated >= :from AND timecreated < :to',
                ['from' => $scope->from, 'to' => $scope->to],
                '',
                'id, timecreated, auth, lastaccess'
            );
            $agg = [];
            foreach ($rs as $u) {
                $day = usergetmidnight((int)$u->timecreated);
                $agg[$day] = $agg[$day] ?? ['n' => 0, 'auth' => [], 'never' => 0];
                $agg[$day]['n']++;
                $agg[$day]['auth'][$u->auth] = true;
                $agg[$day]['never'] += $u->lastaccess ? 0 : 1;
            }
            $rs->close();
            uasort($agg, fn($a, $b) => $b['n'] <=> $a['n']);
            foreach (array_slice($agg, 0, 15, true) as $day => $a) {
                $ds->add(['day' => fmt::date($day), 'accounts' => $a['n'], 'auth' => implode(', ', array_keys($a['auth'])),
                    'neveraccessed' => $a['never']]);
            }
            $ds->emptytext = $s('sec_nosignups');
            return $ds;
        }
        return null;
    }
}
