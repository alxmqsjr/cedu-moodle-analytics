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
 * Readable names of the Moodle settings ("Site security settings › Account lockout threshold"), for the list of
 * configuration changes.
 *
 * The names come from the administration tree, which takes several seconds to build. The daily task builds the list
 * once per installed language and saves it in the data folder; pages only read the file. While the file does not exist,
 * the Moodle string with the same name as the setting is used, and, failing that, the technical name alone.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adminlabels {
    /** @var array|null Names of the current language, "plugin|name" => label (plugin empty for core). */
    protected static $map = null;

    /**
     * File of a language.
     *
     * @param string $lang
     * @return string
     */
    protected static function file(string $lang): string {
        global $CFG;
        return $CFG->dataroot . '/local_ceduanalytics/adminlabels_' . clean_param($lang, PARAM_SAFEDIR) . '.json';
    }

    /**
     * Build the lists of every installed language from the administration tree (several seconds per language).
     *
     * @param \progress_trace|null $trace
     * @return int Number of settings named (last language)
     */
    public static function build(?\progress_trace $trace = null): int {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        make_writable_directory($CFG->dataroot . '/local_ceduanalytics');
        $previous = current_language();
        $count = 0;
        foreach (array_keys(get_string_manager()->get_list_of_translations()) as $lang) {
            force_current_language($lang);
            $root = admin_get_root(true, true);
            $map = [];
            $walk = function ($node) use (&$walk, &$map) {
                if ($node instanceof \admin_settingpage) {
                    foreach ($node->settings as $setting) {
                        $plugin = in_array((string)$setting->plugin, ['', 'core', 'moodle']) ? '' : (string)$setting->plugin;
                        $map[$plugin . '|' . $setting->name] = trim(strip_tags((string)$node->visiblename)) . ' › '
                            . trim(strip_tags((string)$setting->visiblename));
                    }
                }
                if (!empty($node->children)) {
                    foreach ($node->children as $child) {
                        $walk($child);
                    }
                }
            };
            $walk($root);
            file_put_contents(self::file($lang), json_encode($map, JSON_UNESCAPED_UNICODE));
            $count = count($map);
            if ($trace) {
                $trace->output("Setting names ({$lang}): {$count}.");
            }
        }
        force_current_language($previous);
        admin_get_root(true, false);
        self::$map = null;
        return $count;
    }

    /**
     * Readable name of a setting followed by the technical name in parentheses.
     *
     * @param string|null $plugin
     * @param string $name
     * @return string
     */
    public static function label(?string $plugin, string $name): string {
        $plugin = in_array((string)$plugin, ['', 'core', 'moodle']) ? '' : (string)$plugin;
        $tech = ($plugin !== '' ? $plugin . ' / ' : '') . $name;
        $label = self::lookup($plugin, $name);
        if ($label === null && preg_match('/^(.+)_locked$/', $name, $m)) {
            $base = self::lookup($plugin, $m[1]);
            $label = $base !== null ? get_string('x_configlog_locked', 'local_ceduanalytics', $base) : null;
        }
        $label = $label ?? self::provider($name);
        if ($label === null && $plugin === '' && in_array($name, ['siteadmins', 'auth'])) {
            $label = get_string('x_configlog_' . $name, 'local_ceduanalytics');
        }
        if ($label === null && $plugin !== '' && get_string_manager()->string_exists('pluginname', $plugin)) {
            $label = get_string('x_configlog_plugin', 'local_ceduanalytics', get_string('pluginname', $plugin));
        } else if ($label === null && $plugin !== '' && \core_component::get_component_directory($plugin) === null) {
            $label = get_string('x_configlog_removed', 'local_ceduanalytics');
        }
        return $label !== null ? $label . ' (' . $tech . ')' : $tech;
    }

    /**
     * Notification preferences of the site ("message_provider_mod_forum_posts_enabled",
     * "email_provider_moodle_instantmessage_locked"): the notification and the delivery method.
     *
     * @param string $name
     * @return string|null
     */
    protected static function provider(string $name): ?string {
        global $DB;
        static $providers = null;
        if (!preg_match('/^([a-z0-9]+)_provider_(.+)_(enabled|locked)$/', $name, $m)) {
            return null;
        }
        if ($providers === null) {
            $providers = [];
            foreach ($DB->get_records('message_providers', null, '', 'id, component, name') as $p) {
                $providers[$p->component . '_' . $p->name] = [$p->component, $p->name];
            }
        }
        if (!isset($providers[$m[2]])) {
            return null;
        }
        [$component, $provider] = $providers[$m[2]];
        $notification = preg_replace('/ \([^()]*\)$/', '', names::notification($component, $provider));
        $sm = get_string_manager();
        $method = $m[1] === 'message' ? get_string('x_configlog_alldefault', 'local_ceduanalytics')
            : ($sm->string_exists('pluginname', 'message_' . $m[1]) ? get_string('pluginname', 'message_' . $m[1]) : $m[1]);
        return get_string(
            'x_configlog_provider' . ($m[3] === 'locked' ? 'locked' : ''),
            'local_ceduanalytics',
            (object)['notification' => $notification, 'method' => $method]
        );
    }

    /**
     * Name of a setting in the saved list or, without it, a Moodle string with the same name.
     *
     * @param string $plugin
     * @param string $name
     * @return string|null
     */
    protected static function lookup(string $plugin, string $name): ?string {
        if (self::$map === null) {
            $file = self::file(current_language());
            $data = is_readable($file) ? json_decode((string)file_get_contents($file), true) : null;
            self::$map = is_array($data) ? $data : [];
        }
        if (isset(self::$map[$plugin . '|' . $name])) {
            return self::$map[$plugin . '|' . $name];
        }
        $sm = get_string_manager();
        $component = $plugin === '' ? 'admin' : $plugin;
        foreach ([$name, 'config' . $name] as $id) {
            if ($sm->string_exists($id, $component) && !$sm->string_deprecated($id, $component)) {
                return get_string($id, $component);
            }
        }
        return null;
    }
}
