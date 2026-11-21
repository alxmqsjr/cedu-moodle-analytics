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
 * Global filters, read from and written to the URL.
 *
 * Period presets: 12m (default, last 12 months), year (current year), prevyear, all, custom (from/to).
 * The comparison period has the same length and ends where the current one starts.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filters {
    /** @var string[] Period presets. */
    const PRESETS = ['12m', 'year', 'prevyear', 'all', 'custom'];

    /** @var string Default preset. */
    const DEFAULTPRESET = '12m';

    /** @var string */
    public $period = self::DEFAULTPRESET;
    /** @var string Y-m-d */
    public $from = '';
    /** @var string Y-m-d */
    public $to = '';
    /** @var int */
    public $category = 0;
    /** @var int */
    public $line = 0;
    /** @var int */
    public $course = 0;
    /** @var string */
    public $uf = '';
    /** @var string */
    public $segment = '';

    /**
     * Read the filters from the request.
     *
     * @return self
     */
    public static function from_request(): self {
        $f = new self();
        $period = optional_param('period', self::DEFAULTPRESET, PARAM_ALPHANUM);
        $f->period = in_array($period, self::PRESETS) ? $period : self::DEFAULTPRESET;
        $f->from = self::clean_date(optional_param('from', '', PARAM_RAW_TRIMMED));
        $f->to = self::clean_date(optional_param('to', '', PARAM_RAW_TRIMMED));
        if ($f->period === 'custom' && ($f->from === '' || $f->to === '')) {
            $f->period = self::DEFAULTPRESET;
        }
        $f->category = optional_param('category', 0, PARAM_INT);
        $f->line = optional_param('line', 0, PARAM_INT);
        $f->course = optional_param('course', 0, PARAM_INT);
        $uf = strtoupper(optional_param('uf', '', PARAM_ALPHA));
        $f->uf = ($uf === 'NONE' || isset(br::STATES[$uf])) ? $uf : '';
        $segment = optional_param('segment', '', PARAM_ALPHA);
        $f->segment = in_array($segment, attributes::SEGMENTS) ? $segment : '';
        return $f;
    }

    /**
     * Validate a Y-m-d date.
     *
     * @param string $value
     * @return string
     */
    protected static function clean_date(string $value): string {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : '';
    }

    /**
     * URL parameters of the non-default filters.
     *
     * @return array
     */
    public function params(): array {
        $p = [];
        if ($this->period !== self::DEFAULTPRESET) {
            $p['period'] = $this->period;
        }
        if ($this->period === 'custom') {
            $p['from'] = $this->from;
            $p['to'] = $this->to;
        }
        foreach (['category', 'line', 'course'] as $k) {
            if ($this->$k) {
                $p[$k] = $this->$k;
            }
        }
        foreach (['uf', 'segment'] as $k) {
            if ($this->$k !== '') {
                $p[$k] = $this->$k;
            }
        }
        return $p;
    }

    /**
     * Current period as [from, to) timestamps; from = 0 means "since the beginning".
     *
     * @param int|null $now
     * @return int[]
     */
    public function range(?int $now = null): array {
        $now = $now ?? time();
        $tz = \core_date::get_server_timezone_object();
        $today = new \DateTime('@' . $now);
        $today->setTimezone($tz);
        $today->setTime(0, 0);
        $tomorrow = (clone $today)->modify('+1 day')->getTimestamp();
        switch ($this->period) {
            case 'year':
                return [(clone $today)->setDate((int)$today->format('Y'), 1, 1)->getTimestamp(), $tomorrow];
            case 'prevyear':
                $y = (int)$today->format('Y') - 1;
                return [(clone $today)->setDate($y, 1, 1)->getTimestamp(), (clone $today)->setDate($y + 1, 1, 1)->getTimestamp()];
            case 'all':
                return [0, $tomorrow];
            case 'custom':
                $from = new \DateTime($this->from, $tz);
                $to = (new \DateTime($this->to, $tz))->modify('+1 day');
                return [$from->getTimestamp(), $to->getTimestamp()];
            default:
                return [(clone $today)->modify('-12 months')->modify('+1 day')->getTimestamp(), $tomorrow];
        }
    }

    /**
     * Comparison period (same length, immediately before). Null for "all".
     *
     * @param int|null $now
     * @return int[]|null
     */
    public function previous_range(?int $now = null): ?array {
        [$from, $to] = $this->range($now);
        if ($from === 0) {
            return null;
        }
        if ($this->period === 'year' || $this->period === 'prevyear') {
            $tz = \core_date::get_server_timezone_object();
            $start = (new \DateTime('@' . $from))->setTimezone($tz)->modify('-1 year')->getTimestamp();
            $end = (new \DateTime('@' . $to))->setTimezone($tz)->modify('-1 year')->getTimestamp();
            return [$start, $end];
        }
        return [$from - ($to - $from), $from];
    }

    /**
     * Human label of the current period.
     *
     * @return string
     */
    public function period_label(): string {
        [$from, $to] = $this->range();
        $fmt = get_string('strftimedatefullshort', 'core_langconfig');
        if ($from === 0) {
            return get_string('period_all', 'local_ceduanalytics');
        }
        return get_string(
            'period_range',
            'local_ceduanalytics',
            ['from' => userdate($from, $fmt), 'to' => userdate($to - 1, $fmt)]
        );
    }

    /**
     * Template context for the filter bar.
     *
     * @param string[] $enabled Filter keys the page uses
     * @param string $pageid
     * @return array
     */
    public function export_for_template(array $enabled, string $pageid): array {
        global $DB;
        if (!$enabled) {
            return ['hasfilters' => false];
        }
        $ctx = ['hasfilters' => true, 'pageid' => $pageid,
            'action' => (new \moodle_url('/local/ceduanalytics/index.php'))->out(false),
            'periodlabel' => $this->period_label(), 'active' => [], 'fields' => []];
        $opt = fn($value, $label, $selected) => ['value' => $value, 'label' => $label,
            'selected' => (string)$value === (string)$selected];

        if (in_array('period', $enabled)) {
            $options = [];
            foreach (self::PRESETS as $p) {
                $options[] = $opt($p, get_string('period_' . $p, 'local_ceduanalytics'), $this->period);
            }
            $ctx['fields'][] = ['name' => 'period', 'label' => get_string('filter_period', 'local_ceduanalytics'),
                'options' => $options, 'isperiod' => true, 'from' => $this->from, 'to' => $this->to,
                'custom' => $this->period === 'custom'];
        }
        if (in_array('category', $enabled)) {
            $options = [$opt(0, get_string('all_female', 'local_ceduanalytics'), $this->category)];
            foreach (\core_course_category::make_categories_list() as $id => $name) {
                $options[] = $opt($id, $name, $this->category);
            }
            $ctx['fields'][] = ['name' => 'category', 'label' => get_string('filter_category', 'local_ceduanalytics'),
                'options' => $options];
            if ($this->category) {
                $ctx['active'][] = ['label' => get_string('filter_category', 'local_ceduanalytics'),
                    'value' => self::chipname(
                        $DB->get_field('course_categories', 'name', ['id' => $this->category]),
                        $this->category
                    )];
            }
        }
        if (in_array('line', $enabled)) {
            $options = [$opt(0, get_string('all_female', 'local_ceduanalytics'), $this->line)];
            foreach (courses::get_lines() as $l) {
                $options[] = $opt($l->id, fmt::text($l->name), $this->line);
            }
            $ctx['fields'][] = ['name' => 'line', 'label' => get_string('filter_line', 'local_ceduanalytics'),
                'options' => $options];
            if ($this->line) {
                $ctx['active'][] = ['label' => get_string('filter_line', 'local_ceduanalytics'),
                    'value' => self::chipname(
                        $DB->get_field('local_ceduanalytics_courseline', 'name', ['id' => $this->line]),
                        $this->line
                    )];
            }
        }
        if (in_array('course', $enabled)) {
            $options = [$opt(0, get_string('all_male', 'local_ceduanalytics'), $this->course)];
            foreach (courses::get_all() as $c) {
                $label = fmt::text($c->fullname) . ' — ' . get_string('coursetype_' . $c->type, 'local_ceduanalytics');
                $options[] = $opt($c->id, $label, $this->course);
            }
            $ctx['fields'][] = ['name' => 'course', 'label' => get_string('filter_course', 'local_ceduanalytics'),
                'options' => $options, 'wide' => true];
            if ($this->course) {
                $ctx['active'][] = ['label' => get_string('filter_course', 'local_ceduanalytics'),
                    'value' => self::chipname($DB->get_field('course', 'fullname', ['id' => $this->course]), $this->course)];
            }
        }
        if (in_array('uf', $enabled)) {
            $options = [$opt('', get_string('all_female', 'local_ceduanalytics'), $this->uf),
                $opt('NONE', get_string('uf_none', 'local_ceduanalytics'), $this->uf)];
            foreach (br::STATES as $uf => [$code, $name]) {
                $options[] = $opt($uf, "$uf — $name", $this->uf);
            }
            $ctx['fields'][] = ['name' => 'uf', 'label' => get_string('filter_uf', 'local_ceduanalytics'), 'options' => $options];
            if ($this->uf !== '') {
                $ctx['active'][] = ['label' => get_string('filter_uf', 'local_ceduanalytics'),
                    'value' => $this->uf === 'NONE' ? get_string('uf_none', 'local_ceduanalytics') : $this->uf];
            }
        }
        if (in_array('segment', $enabled)) {
            $options = [$opt('', get_string('all_male', 'local_ceduanalytics'), $this->segment)];
            foreach (attributes::SEGMENTS as $s) {
                $options[] = $opt($s, get_string('segment_' . $s, 'local_ceduanalytics'), $this->segment);
            }
            $ctx['fields'][] = ['name' => 'segment', 'label' => get_string('filter_segment', 'local_ceduanalytics'),
                'options' => $options];
            if ($this->segment !== '') {
                $ctx['active'][] = ['label' => get_string('filter_segment', 'local_ceduanalytics'),
                    'value' => get_string('segment_' . $this->segment, 'local_ceduanalytics')];
            }
        }
        $ctx['hasactive'] = !empty($ctx['active']);
        $ctx['activecount'] = count($ctx['active']);
        $ctx['clearurl'] = navigation::url($pageid)->out(false);
        return $ctx;
    }

    /**
     * Name shown in the active filter: the item name, or a notice when it no longer exists.
     *
     * @param string|false $name
     * @param int $id
     * @return string
     */
    protected static function chipname($name, int $id): string {
        return $name === false ? get_string('filter_missing', 'local_ceduanalytics', $id) : fmt::text((string)$name);
    }
}
