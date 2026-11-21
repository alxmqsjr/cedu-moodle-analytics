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
 * Number, percentage, date and variation formatting (uses the language separators).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fmt {
    /**
     * Name typed in Moodle (course, activity, category…) as plain text: format_string() applies the filters
     * (multi-language, for example) and the result is turned back into text without tags or entities.
     *
     * The plugin keeps every name as plain text and escapes it once, where it becomes HTML (s(), the {{ }} of
     * the templates or the table renderer), so "A & B" never shows up as "A &amp; B".
     *
     * @param string|null $raw
     * @param array $options format_string() options (e.g. context)
     * @return string
     */
    public static function text(?string $raw, array $options = []): string {
        return self::plain(format_string((string)$raw, true, $options));
    }

    /**
     * HTML produced by Moodle (format_string(), cm_info::get_formatted_name()) as plain text.
     *
     * @param string|null $html
     * @return string
     */
    public static function plain(?string $html): string {
        return trim(html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * File size with the decimal separator of the language: "282,5 MB".
     *
     * @param int|float $bytes
     * @return string
     */
    public static function size($bytes): string {
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return ($i === 0 ? self::int($bytes) : self::dec($bytes, 1)) . ' ' . $units[$i];
    }

    /**
     * Integer with thousands separator.
     *
     * @param int|float|null $n
     * @return string
     */
    public static function int($n): string {
        return number_format((float)$n, 0, get_string('decsep', 'langconfig'), get_string('thousandssep', 'langconfig'));
    }

    /**
     * Decimal number.
     *
     * @param float|null $n
     * @param int $decimals
     * @return string
     */
    public static function dec($n, int $decimals = 1): string {
        return number_format((float)$n, $decimals, get_string('decsep', 'langconfig'), get_string('thousandssep', 'langconfig'));
    }

    /**
     * Percentage of part over total ("—" when total is zero).
     *
     * @param int|float $part
     * @param int|float $total
     * @param int $decimals
     * @return string
     */
    public static function pct($part, $total, int $decimals = 2): string {
        if (!$total) {
            return '—';
        }
        return self::percent($part / $total * 100);
    }

    /**
     * A value that is already a percentage, always with two decimals ("53,85%"); 0 and 100 are whole ("0%", "100%").
     *
     * @param float|int|null $value
     * @return string
     */
    public static function percent($value): string {
        return self::percentnum($value) . '%';
    }

    /**
     * Number of a percentage without the sign, for strings that add "%" themselves.
     *
     * @param float|int|null $value
     * @return string
     */
    public static function percentnum($value): string {
        $value = round((float)$value, 2);
        if ($value == 0 || $value == 100) {
            return self::int($value);
        }
        return self::dec($value, 2);
    }

    /**
     * Minimum number of cases for reliable percentages and averages.
     *
     * @return int
     */
    public static function mincount(): int {
        return max(1, (int)get_config('local_ceduanalytics', 'mincount') ?: 5);
    }

    /**
     * Whether a base is too small for a reliable rate or average.
     *
     * @param int $n
     * @return bool
     */
    public static function lowconf(int $n): bool {
        return $n > 0 && $n < self::mincount();
    }

    /**
     * Short date.
     *
     * @param int $ts
     * @return string
     */
    public static function date(int $ts): string {
        return $ts ? userdate($ts, get_string('strftimedatefullshort', 'core_langconfig')) : '—';
    }

    /**
     * Date and time.
     *
     * @param int $ts
     * @return string
     */
    public static function datetime(int $ts): string {
        return $ts ? userdate($ts, get_string('strftimedatetimeshort', 'core_langconfig')) : '—';
    }

    /**
     * "x days ago" style relative time.
     *
     * @param int $ts
     * @return string
     */
    public static function ago(int $ts): string {
        if (!$ts) {
            return get_string('never');
        }
        $days = (int)floor((time() - $ts) / DAYSECS);
        if ($days <= 0) {
            return get_string('today_lower', 'local_ceduanalytics');
        }
        return get_string('daysago', 'local_ceduanalytics', self::int($days));
    }

    /**
     * Hours from seconds.
     *
     * @param int $secs
     * @return string
     */
    public static function hours(int $secs): string {
        return self::dec($secs / 3600, $secs < 36000 ? 1 : 0) . ' h';
    }

    /**
     * Variation between current and previous values, for templates.
     *
     * @param float|null $current
     * @param float|null $previous
     * @param bool $higherisbetter
     * @param bool $points Compare as percentage points (for rates) instead of relative change
     * @return array|null [text, direction (up|down|flat), good (bool), title]
     */
    public static function delta(?float $current, ?float $previous, bool $higherisbetter = true, bool $points = false): ?array {
        if ($current === null || $previous === null) {
            return null;
        }
        if ($points) {
            $diff = $current - $previous;
            $text = ($diff > 0 ? '+' : '') . self::dec($diff, 2) . ' p.p.';
        } else {
            if ($previous == 0) {
                if ($current == 0) {
                    return ['text' => '=', 'direction' => 'flat', 'good' => true, 'icon' => 'fa-arrow-right',
                        'title' => get_string('delta_same', 'local_ceduanalytics')];
                }
                return ['text' => get_string('delta_new', 'local_ceduanalytics'), 'direction' => 'up', 'good' => $higherisbetter,
                    'icon' => 'fa-arrow-trend-up',
                    'title' => get_string('delta_prev', 'local_ceduanalytics', self::int($previous))];
            }
            $diff = ($current - $previous) / $previous * 100;
            $text = ($diff > 0 ? '+' : '') . self::percent($diff);
        }
        $direction = abs($diff) < 0.5 ? 'flat' : ($diff > 0 ? 'up' : 'down');
        $good = $direction === 'flat' || (($direction === 'up') === $higherisbetter);
        $icon = ['up' => 'fa-arrow-trend-up', 'down' => 'fa-arrow-trend-down', 'flat' => 'fa-arrow-right'][$direction];
        return ['text' => $text, 'direction' => $direction, 'good' => $good, 'icon' => $icon,
            'title' => get_string('delta_prev', 'local_ceduanalytics', $points ? self::percent($previous) : self::int($previous))];
    }
}
