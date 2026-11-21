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
 * Security: failed logins (from the aggregated table), privileged accounts and security checks.
 *
 * Checks read the effective configuration ($CFG and get_config), so a value forced in config.php
 * is reported as forced. Nothing is changed: every check links to the Moodle setting.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security {
    /** @var int[] Failed login reasons (AUTH_LOGIN_*): no user, suspended, wrong password, locked out, unauthorised. */
    const REASONS = [1, 2, 3, 4, 5];

    /** @var string[] Severity, most serious first. */
    const SEVERITIES = ['critical', 'warning', 'info', 'ok'];

    /**
     * Failed login rows in a range.
     *
     * @param int $from
     * @param int $to
     * @return \stdClass[] with day, ip, username, reason, attempts, lastts
     */
    public static function failures(int $from, int $to): array {
        global $DB;
        return array_values($DB->get_records_select(
            'local_ceduanalytics_security',
            'day >= :from AND day < :to',
            ['from' => $from, 'to' => $to],
            'day',
            'id, day, ip, username, reason, attempts, lastts'
        ));
    }

    /**
     * Privileged accounts: site administrators and holders of system-level roles.
     *
     * @return array userid => ['user' => \stdClass, 'roles' => string[], 'admin' => bool, 'mfa' => string[]]
     */
    public static function privileged(): array {
        global $DB, $CFG;
        $out = [];
        $add = function (int $uid, string $role) use (&$out) {
            if (!isset($out[$uid])) {
                $u = people::get($uid);
                if (!$u || $u->deleted) {
                    return;
                }
                $out[$uid] = ['user' => $u, 'roles' => [], 'admin' => false, 'mfa' => []];
            }
            if ($role === 'siteadmin') {
                $out[$uid]['admin'] = true;
            } else {
                $out[$uid]['roles'][$role] = $role;
            }
        };
        $assignments = $DB->get_records_sql("
            SELECT ra.id, ra.userid, r.shortname
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.contextid = :ctx", ['ctx' => \context_system::instance()->id]);
        $admins = array_filter(array_map('intval', explode(',', (string)$CFG->siteadmins)));
        people::preload(array_merge($admins, array_column($assignments, 'userid')));
        foreach ($admins as $uid) {
            $add($uid, 'siteadmin');
        }
        foreach ($assignments as $r) {
            $add((int)$r->userid, $r->shortname);
        }
        if ($out && $DB->get_manager()->table_exists('tool_mfa')) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($out));
            // Only factors the user sets up (not the automatic ones such as grace, role or iprange).
            foreach (
                $DB->get_records_select('tool_mfa', "userid $insql AND revoked = 0 AND factor IN ('totp', 'webauthn', 'sms',
                    'email', 'token')", $params, '', 'id, userid, factor') as $f
            ) {
                $out[$f->userid]['mfa'][$f->factor] = $f->factor;
            }
        }
        return $out;
    }

    /**
     * Whether a core or plugin setting is forced in config.php.
     *
     * @param string $name
     * @param string|null $plugin
     * @return bool
     */
    public static function forced(string $name, ?string $plugin = null): bool {
        global $CFG;
        if ($plugin) {
            return isset($CFG->forced_plugin_settings[$plugin][$name]);
        }
        return array_key_exists($name, $CFG->config_php_settings ?? []);
    }

    /**
     * Security checks with severity, evidence and where to fix.
     *
     * @param array $failures From failures() for the period
     * @param array $privileged From privileged()
     * @return array[] list of ['id', 'severity', 'title', 'text', 'url', 'forced']
     */
    public static function checks(array $failures, array $privileged): array {
        global $CFG, $DB;
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $checks = [];
        $add = function (string $id, string $severity, $a = null, string $url = '', bool $forced = false) use (&$checks, $s) {
            $checks[] = ['id' => $id, 'severity' => $severity, 'title' => $s('sec_check_' . $id),
                'text' => $s('sec_check_' . $id . '_' . $severity, $a), 'url' => $url, 'forced' => $forced];
        };
        $attempts = array_sum(array_map(fn($r) => (int)$r->attempts, $failures));

        // Account lockout.
        $threshold = (int)($CFG->lockoutthreshold ?? 0);
        $add(
            'lockout',
            $threshold ? 'ok' : ($attempts ? 'critical' : 'warning'),
            ['n' => fmt::int($attempts), 'threshold' => $threshold],
            (new \moodle_url('/admin/settings.php', ['section' => 'sitepolicies']))->out(false),
            self::forced('lockoutthreshold')
        );

        // Privileged accounts targeted by password guessing.
        $byname = [];
        foreach ($failures as $r) {
            if ((int)$r->reason === 3) {
                $byname[$r->username]['n'] = ($byname[$r->username]['n'] ?? 0) + (int)$r->attempts;
                $byname[$r->username]['ips'][$r->ip] = true;
            }
        }
        $targeted = [];
        foreach ($privileged as $p) {
            if (isset($byname[$p['user']->username])) {
                $targeted[] = $s('sec_targeted_item', ['username' => $p['user']->username,
                    'n' => fmt::int($byname[$p['user']->username]['n']),
                        'ips' => fmt::int(count($byname[$p['user']->username]['ips']))]);
            }
        }
        $add(
            'privtargeted',
            $targeted ? 'critical' : 'ok',
            implode('; ', $targeted),
            (new \moodle_url('/admin/roles/admins.php'))->out(false)
        );

        // MFA.
        $mfaenabled = (bool)get_config('tool_mfa', 'enabled');
        $forcedmfa = self::forced('enabled', 'tool_mfa');
        $nomfa = [];
        foreach ($privileged as $p) {
            if ($p['admin'] && !$p['mfa'] && !$p['user']->suspended) {
                $nomfa[] = $p['user']->username;
            }
        }
        if (!$mfaenabled) {
            $add('mfa', $forcedmfa ? 'info' : 'critical', null, (new \moodle_url(
                '/admin/settings.php',
                ['section' => 'managemfa']
            ))->out(false), $forcedmfa);
        } else {
            $add('mfa', $nomfa ? 'warning' : 'ok', implode(', ', $nomfa), (new \moodle_url(
                '/admin/settings.php',
                ['section' => 'managemfa']
            ))->out(false), $forcedmfa);
        }

        // Number of administrators and inactive ones.
        $admins = array_filter($privileged, fn($p) => $p['admin']);
        $stale = array_filter($admins, fn($p) => (int)$p['user']->lastaccess < time() - 90 * DAYSECS);
        $add(
            'admins',
            count($admins) > 3 || $stale ? 'warning' : 'ok',
            ['n' => count($admins),
            'stale' => implode(', ', array_map(fn($p) => $p['user']->username, $stale)) ?: '—'],
            (new \moodle_url('/admin/roles/admins.php'))->out(false)
        );

        // Cookies.
        $add(
            'cookies',
            !empty($CFG->cookiehttponly) && !empty($CFG->cookiesecure) ? 'ok' : 'warning',
            ['httponly' => empty($CFG->cookiehttponly) ? $s('no_') : $s('yes_'),
            'secure' => empty($CFG->cookiesecure) ? $s('no_') : $s('yes_')],
            (new \moodle_url('/admin/settings.php', ['section' => 'httpsecurity']))->out(false),
            self::forced('cookiehttponly') || self::forced('cookiesecure')
        );

        // Debug messages shown to users.
        $add(
            'debug',
            !empty($CFG->debugdisplay) ? 'critical' : 'ok',
            null,
            (new \moodle_url('/admin/settings.php', ['section' => 'debugging']))->out(false),
            self::forced('debugdisplay')
        );

        // Password reuse.
        $add(
            'passwordreuse',
            (int)($CFG->passwordreuselimit ?? 0) > 0 ? 'ok' : 'info',
            null,
            (new \moodle_url('/admin/settings.php', ['section' => 'sitepolicies']))->out(false)
        );

        // Cron and failing tasks.
        $lastcron = (int)$DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        $add(
            'cron',
            $lastcron > time() - HOURSECS ? 'ok' : ($lastcron > time() - DAYSECS ? 'warning' : 'critical'),
            $lastcron ? fmt::datetime($lastcron) : $s('never'),
            (new \moodle_url('/admin/tool/task/scheduledtasks.php'))->out(false)
        );
        $failing = $DB->get_fieldset_select('task_scheduled', 'classname', 'faildelay > 0');
        $add(
            'tasks',
            $failing ? 'warning' : 'ok',
            implode(', ', array_map(fn($c) => ltrim($c, '\\'), $failing)),
            (new \moodle_url('/admin/tool/task/scheduledtasks.php'))->out(false)
        );

        // Log retention.
        $lifetime = (int)get_config('logstore_standard', 'loglifetime');
        $add(
            'loglifetime',
            $lifetime ? 'ok' : 'info',
            null,
            (new \moodle_url('/admin/settings.php', ['section' => 'logsettingstandard']))->out(false)
        );

        // Self registration.
        $add(
            'registration',
            empty($CFG->registerauth) ? 'ok' : 'info',
            (string)$CFG->registerauth,
            (new \moodle_url('/admin/settings.php', ['section' => 'manageauths']))->out(false)
        );

        // Updates: new releases of Moodle and of the plugins bring security fixes.
        $checker = \core\update\checker::instance();
        $fetched = (int)$checker->get_last_timefetched();
        $core = $checker->get_update_info('core') ?: [];
        $branch = substr((string)$CFG->release, 0, 3);
        // Updates of the same branch (e.g. 4.5.x) are the safe path; a new major version is a project of its own.
        $samebranch = array_values(array_filter($core, fn($u) => strpos((string)$u->release, $branch) === 0));
        $major = implode(', ', array_map(fn($u) => preg_replace('/ \(.*$/', '', $u->release), array_filter(
            $core,
            fn($u) => (int)$u->maturity === MATURITY_STABLE && strpos((string)$u->release, $branch) !== 0
        ))) ?: '—';
        $a = ['current' => $CFG->release, 'major' => $major, 'fetched' => $fetched ? fmt::date($fetched) : '—'];
        $url = (new \moodle_url('/admin/index.php'))->out(false);
        if ($samebranch) {
            $add('coreupdate', 'warning', $a + ['latest' => $samebranch[0]->release], $url);
        } else {
            $add('coreupdate', $fetched ? 'ok' : 'info', $a, $url);
        }
        $outdated = array_filter(plugins::rows(), fn($r) => $r['updatebad']);
        $add(
            'pluginupdates',
            $outdated ? 'warning' : ($fetched ? 'ok' : 'info'),
            ['n' => count($outdated), 'list' => implode(', ', array_map(
                fn($r) => $r['plugin'] . ' (' . $r['update'] . ')',
                $outdated
            )), 'fetched' => $a['fetched']],
            (new \moodle_url('/admin/plugins.php', ['contribonly' => 1, 'updatesonly' => 1]))->out(false)
        );

        $order = array_flip(self::SEVERITIES);
        usort($checks, fn($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);
        return $checks;
    }
}
