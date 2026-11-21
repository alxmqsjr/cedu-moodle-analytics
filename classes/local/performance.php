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
 * Performance: optimisations of the Moodle installation (caches, PHP, cron, logs, sessions, storage,
 * database, plugins and updates). Only reads; every item links to where it is changed in Moodle.
 *
 * Strings: perf_check_<id> (title) and perf_check_<id>_<severity> (explanation).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class performance {
    /** @var array Tables that can be cleaned => [setting or task that cleans them, URL] */
    const CLEANABLE = [
        'logstore_standard_log' => ['loglifetime', '/admin/settings.php?section=logsettingstandard'],
        'sessions' => ['sessions', '/admin/settings.php?section=sessionhandling'],
        'task_log' => ['tasklog', '/admin/settings.php?section=tasklogging'],
        'tiny_autosave' => ['autosave', ''],
        'analytics_predict_samples' => ['analytics', '/admin/settings.php?section=analyticssettings'],
        'analytics_indicator_calc' => ['analytics', '/admin/settings.php?section=analyticssettings'],
        'upgrade_log' => ['upgradelog', ''],
        'config_log' => ['configlog', ''],
        'backup_controllers' => ['backups', '/admin/settings.php?section=automated'],
        'question_attempt_step_data' => ['questions', ''],
        'grade_grades_history' => ['gradehistory', '/admin/settings.php?section=gradessettings'],
        'notifications' => ['notifications', '/admin/settings.php?section=messages'],
    ];

    /**
     * All performance checks (cached for an hour).
     *
     * @return array[] list of ['id', 'group', 'severity', 'title', 'text', 'url']
     */
    public static function checks(): array {
        $cache = \cache::make('local_ceduanalytics', 'indicators');
        $out = $cache->get('perfchecks');
        if ($out === false) {
            $out = self::compute();
            $cache->set('perfchecks', $out);
        }
        return $out;
    }

    /**
     * Compute the checks.
     *
     * @return array[]
     */
    protected static function compute(): array {
        global $CFG, $DB;
        $out = [];
        $add = function (string $id, string $group, string $severity, $a = null, string $url = '') use (&$out) {
            $out[] = ['id' => $id, 'group' => $group, 'severity' => $severity,
                'title' => get_string('perf_check_' . $id, 'local_ceduanalytics'),
                'text' => get_string('perf_check_' . $id . '_' . $severity, 'local_ceduanalytics', $a),
                'url' => $url ? (new \moodle_url($url))->out(false) : ''];
        };

        // Caches.
        $add('purge', 'cache', 'info', null, '/admin/purgecaches.php');
        $stores = array_unique(array_map(fn($s) => $s['plugin'], \core_cache\config::instance()->get_all_stores()));
        $add(
            'cachestores',
            'cache',
            array_diff($stores, ['file', 'session', 'static']) ? 'ok' : 'info',
            implode(', ', $stores),
            '/cache/admin.php'
        );
        $add(
            'langcache',
            'cache',
            empty($CFG->langstringcache) ? 'warning' : 'ok',
            null,
            '/admin/settings.php?section=langsettings'
        );
        $add(
            'jscache',
            'cache',
            empty($CFG->cachejs) || empty($CFG->cachetemplates) ? 'warning' : 'ok',
            null,
            '/admin/settings.php?section=ajax'
        );
        $add(
            'themedesigner',
            'cache',
            !empty($CFG->themedesignermode) ? 'critical' : 'ok',
            null,
            '/admin/settings.php?section=themesettingsadvanced'
        );
        $add(
            'debug',
            'cache',
            (int)$CFG->debug >= DEBUG_DEVELOPER ? 'warning' : 'ok',
            null,
            '/admin/settings.php?section=debugging'
        );

        // Server.
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $add('opcache', 'server', !empty($opcache['opcache_enabled']) ? 'ok' : 'critical', null, '/admin/environment.php');
        $add('php', 'server', 'info', ['php' => PHP_VERSION, 'memory' => ini_get('memory_limit'),
            'inputvars' => ini_get('max_input_vars')], '/admin/phpinfo.php');
        $add(
            'inputvars',
            'server',
            (int)ini_get('max_input_vars') >= 5000 ? 'ok' : 'warning',
            ini_get('max_input_vars'),
            '/admin/environment.php'
        );
        $server = $DB->get_server_info();
        $add(
            'database',
            'server',
            'info',
            ['family' => $DB->get_dbfamily(), 'version' => $server['version'] ?? '?'],
            '/admin/environment.php'
        );

        // Cron and tasks.
        $lastcron = (int)$DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        $add(
            'cron',
            'cron',
            $lastcron > time() - HOURSECS ? 'ok' : 'warning',
            ['last' => $lastcron ? fmt::datetime($lastcron) : get_string('never', 'local_ceduanalytics'),
            'cli' => empty($CFG->cronclionly) ? get_string('no_', 'local_ceduanalytics') : get_string(
                'yes_',
                'local_ceduanalytics'
            )],
            '/admin/tool/task/scheduledtasks.php'
        );
        $failing = $DB->get_fieldset_select('task_scheduled', 'classname', 'faildelay > 0');
        $add(
            'failingtasks',
            'cron',
            $failing ? 'warning' : 'ok',
            implode(', ', array_map(fn($c) => ltrim($c, '\\'), $failing)),
            '/admin/tool/task/scheduledtasks.php'
        );
        $adhoc = $DB->count_records('task_adhoc');
        $add('adhoc', 'cron', $adhoc > 500 ? 'warning' : 'ok', fmt::int($adhoc), '/admin/tool/task/adhoctasks.php');
        $add('stats', 'cron', !empty($CFG->enablestats) ? 'warning' : 'ok', null, '/admin/settings.php?section=stats');
        $add(
            'analytics',
            'cron',
            !empty($CFG->enableanalytics) ? 'info' : 'ok',
            null,
            '/admin/settings.php?section=optionalsubsystems'
        );

        // Logs, sessions and database.
        $logrows = self::rows('logstore_standard_log');
        $add(
            'loglifetime',
            'database',
            (int)get_config('logstore_standard', 'loglifetime') ? 'ok' : 'warning',
            fmt::int($logrows),
            '/admin/settings.php?section=logsettingstandard'
        );
        $sessions = $DB->count_records('sessions');
        $anon = $DB->count_records('sessions', ['userid' => 0]);
        $handler = $CFG->session_handler_class ?? '\core\session\database';
        $indb = strpos($handler, 'database') !== false;
        $add(
            'sessions',
            'database',
            $indb && $anon > 2000 ? 'warning' : ($indb ? 'info' : 'ok'),
            ['n' => fmt::int($sessions), 'anon' => fmt::int($anon), 'handler' => ltrim($handler, '\\')],
            '/admin/settings.php?section=sessionhandling'
        );
        $size = self::database_size();
        if ($size !== null) {
            $add('dbsize', 'database', 'info', fmt::size($size), '');
        }

        // Storage.
        $files = (float)$DB->get_field_sql("SELECT SUM(filesize) FROM {files} WHERE filename <> '.'");
        $backups = (float)$DB->get_field_sql("SELECT SUM(filesize) FROM {files} WHERE component = 'backup' AND filename <> '.'");
        $add(
            'files',
            'storage',
            $backups > 200 * 1048576 ? 'warning' : 'info',
            ['total' => fmt::size($files), 'backups' => fmt::size($backups)],
            '/admin/settings.php?section=automated'
        );
        $add(
            'autobackup',
            'storage',
            get_config('backup', 'backup_auto_active') ? 'ok' : 'warning',
            null,
            '/admin/settings.php?section=automated'
        );
        $add(
            'asyncbackup',
            'storage',
            !empty($CFG->enableasyncbackup) ? 'ok' : 'info',
            null,
            '/admin/settings.php?section=asyncgeneralsettings'
        );
        $bin = $DB->count_records('tool_recyclebin_course') + $DB->count_records('tool_recyclebin_category');
        $add('recyclebin', 'storage', 'info', ['n' => fmt::int($bin), 'days' => (int)(get_config(
            'tool_recyclebin',
            'coursebinexpiry'
        ) / DAYSECS)], '/admin/settings.php?section=tool_recyclebin');
        $dirs = self::dataroot_sizes();
        $add('dataroot', 'storage', 'info', ['cache' => fmt::size($dirs['cache'] + $dirs['localcache']),
            'temp' => fmt::size($dirs['temp']), 'trash' => fmt::size($dirs['trashdir'])], '/admin/purgecaches.php');

        // Additional plugins: one alert with the table of every plugin (use and updates). The Moodle version and the
        // plugin updates are security checks (Security tab), because updates bring security fixes.
        $fetched = (int)\core\update\checker::instance()->get_last_timefetched();
        $rows = plugins::rows();
        $bad = count(array_filter($rows, fn($r) => $r['usagebad'] || $r['updatebad']));
        $missing = count(array_filter($rows, fn($r) => $r['state'] === 'missing'));
        $add(
            'plugins',
            'updates',
            $bad || $missing ? 'warning' : ($fetched ? 'ok' : 'info'),
            ['n' => count($rows), 'bad' => $bad, 'missing' => $missing],
            '/admin/plugins.php?contribonly=1'
        );
        $out[count($out) - 1]['plugins'] = $rows;

        $order = array_flip(security::SEVERITIES);
        usort($out, fn($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);
        return $out;
    }

    /**
     * Estimated rows of a table (information_schema on MySQL/MariaDB, COUNT otherwise).
     *
     * @param string $table
     * @return int
     */
    public static function rows(string $table): int {
        global $DB, $CFG;
        if ($DB->get_dbfamily() === 'mysql') {
            return (int)$DB->get_field_sql('SELECT TABLE_ROWS FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$CFG->prefix . $table]);
        }
        return $DB->count_records($table);
    }

    /**
     * Total size of the database (MySQL/MariaDB only).
     *
     * @return float|null bytes
     */
    public static function database_size(): ?float {
        global $DB, $CFG;
        if ($DB->get_dbfamily() !== 'mysql') {
            return null;
        }
        return (float)$DB->get_field_sql('SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?', [$CFG->prefix . '%']);
    }

    /**
     * Largest tables (MySQL/MariaDB only), with what cleans them.
     *
     * @param int $limit
     * @return array[] list of ['table', 'rows', 'bytes', 'clean', 'url']
     */
    public static function tables(int $limit = 20): array {
        global $DB, $CFG;
        if ($DB->get_dbfamily() !== 'mysql') {
            return [];
        }
        $rows = $DB->get_records_sql('SELECT TABLE_NAME AS t, TABLE_ROWS AS n, DATA_LENGTH + INDEX_LENGTH AS bytes
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?
            ORDER BY DATA_LENGTH + INDEX_LENGTH DESC', [$CFG->prefix . '%'], 0, $limit);
        $out = [];
        foreach ($rows as $r) {
            $name = substr($r->t, strlen($CFG->prefix));
            $clean = self::CLEANABLE[$name] ?? null;
            $out[] = ['table' => $name, 'rows' => (int)$r->n, 'bytes' => (float)$r->bytes,
                'clean' => $clean ? get_string('perf_clean_' . $clean[0], 'local_ceduanalytics') : '',
                'url' => $clean && $clean[1] ? (new \moodle_url($clean[1]))->out(false) : ''];
        }
        return $out;
    }

    /**
     * Sizes of the dataroot folders that can be emptied (cache, local cache, temp, trash), with a time limit.
     *
     * @return array folder => bytes
     */
    public static function dataroot_sizes(): array {
        global $CFG;
        $out = [];
        $deadline = microtime(true) + 3;
        foreach (['cache', 'localcache', 'temp', 'trashdir'] as $dir) {
            $out[$dir] = 0.0;
            $path = $CFG->dataroot . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $out[$dir] += $file->getSize();
                    }
                    if (microtime(true) > $deadline) {
                        break 2;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return $out;
    }
}
