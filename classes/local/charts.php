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
 * Chart helpers: Moodle core charts (with their accessible data table), inline SVG sparklines,
 * horizontal bar lists and the day × hour heatmap.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class charts {
    /** @var string[] Brand colours (default order of the series). */
    const COLORS = ['#062e6b', '#43b000', '#0096ff', '#fb9a00', '#7a5af8', '#c62828', '#5f6b7d'];

    /** @var string Colours of the logo palette, for charts that choose their own. */
    const NAVY = '#062e6b';
    /** @var string */
    const GREEN = '#43b000';
    /** @var string */
    const ORANGE = '#fb9a00';
    /** @var string */
    const BLUE = '#0096ff';
    /** @var string */
    const RED = '#c62828';

    /**
     * Time buckets (weeks or months) covering a range.
     *
     * @param int $from
     * @param int $to
     * @param string $unit week|month
     * @return array bucket start => label
     */
    public static function buckets(int $from, int $to, string $unit): array {
        $tz = \core_date::get_server_timezone_object();
        $start = (new \DateTime('@' . $from))->setTimezone($tz);
        $start->setTime(0, 0);
        if ($unit === 'month') {
            $start->setDate((int)$start->format('Y'), (int)$start->format('m'), 1);
        } else {
            $start->modify('monday this week');
        }
        $out = [];
        $cur = clone $start;
        $guard = 0;
        while ($cur->getTimestamp() < $to && $guard++ < 600) {
            $out[$cur->getTimestamp()] = $unit === 'month' ? userdate($cur->getTimestamp(), '%b/%y') :
                userdate($cur->getTimestamp(), '%d/%m/%y');
            $cur->modify($unit === 'month' ? '+1 month' : '+1 week');
        }
        return $out;
    }

    /**
     * Bucket of a timestamp.
     *
     * @param int $ts
     * @param int[] $starts sorted bucket starts
     * @return int|null
     */
    public static function bucket_of(int $ts, array $starts): ?int {
        $found = null;
        foreach ($starts as $s) {
            if ($s <= $ts) {
                $found = $s;
            } else {
                break;
            }
        }
        return $found;
    }

    /**
     * Choose weekly buckets for periods up to ~6 months, monthly otherwise.
     *
     * @param int $from
     * @param int $to
     * @return string
     */
    public static function unit_for(int $from, int $to): string {
        return ($to - $from) <= 190 * DAYSECS ? 'week' : 'month';
    }

    /**
     * Line chart.
     *
     * @param string[] $labels
     * @param array $series name => values
     * @param bool $smooth
     * @param string[] $colors Colour of each series, in order (default: COLORS)
     * @return string HTML
     */
    public static function line(array $labels, array $series, bool $smooth = false, array $colors = []): string {
        global $OUTPUT;
        $chart = new \core\chart_line();
        $chart->set_smooth($smooth);
        $chart->set_labels(array_values($labels));
        $i = 0;
        foreach ($series as $name => $values) {
            $s = new \core\chart_series($name, array_values($values));
            $s->set_color($colors[$i] ?? self::COLORS[$i % count(self::COLORS)]);
            $i++;
            $chart->add_series($s);
        }
        $chart->get_yaxis(0, true)->set_min(0);
        return $OUTPUT->render($chart);
    }

    /**
     * Bar chart.
     *
     * @param string[] $labels
     * @param array $series name => values
     * @param bool $stacked
     * @param bool $horizontal
     * @param string[] $colors Colour of each series, in order (default: COLORS)
     * @return string HTML
     */
    public static function bar(
        array $labels,
        array $series,
        bool $stacked = false,
        bool $horizontal = false,
        array $colors = []
    ): string {
        global $OUTPUT;
        $chart = new \core\chart_bar();
        $chart->set_stacked($stacked);
        $chart->set_horizontal($horizontal);
        $chart->set_labels(array_values($labels));
        $i = 0;
        foreach ($series as $name => $values) {
            $s = new \core\chart_series($name, array_values($values));
            $s->set_color($colors[$i] ?? self::COLORS[$i % count(self::COLORS)]);
            $i++;
            $chart->add_series($s);
        }
        return $OUTPUT->render($chart);
    }

    /**
     * Inline SVG sparkline.
     *
     * @param float[] $values
     * @param string $color
     * @return string SVG
     */
    public static function sparkline(array $values, string $color = '#062e6b'): string {
        $values = array_values($values);
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $w = 120;
        $h = 32;
        $max = max(max($values), 1);
        $points = [];
        foreach ($values as $i => $v) {
            $points[] = round($i / ($n - 1) * $w, 1) . ',' . round($h - 2 - ($v / $max) * ($h - 4), 1);
        }
        $line = implode(' ', $points);
        $area = '0,' . $h . ' ' . $line . ' ' . $w . ',' . $h;
        return '<svg class="cea-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none"'
            . ' aria-hidden="true" focusable="false">'
            . '<polygon points="' . $area . '" fill="' . $color . '" fill-opacity=".10"/>'
            . '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" '
            . 'vector-effect="non-scaling-stroke"/></svg>';
    }

    /**
     * Horizontal bar list context (templates/bars.mustache).
     *
     * @param array $items list of ['label' => string, 'value' => number, 'url' => ?string, 'note' => ?string]
     * @param int|null $total For percentages (null = no percentage)
     * @param string $tone Colour of the bars: navy (default), green, orange or blue
     * @return array
     */
    public static function bars(array $items, ?int $total = null, string $tone = ''): array {
        $max = 0;
        foreach ($items as $it) {
            $max = max($max, $it['value']);
        }
        $out = [];
        foreach ($items as $it) {
            $out[] = $it + [
                'width' => $max ? round($it['value'] / $max * 100, 1) : 0,
                'valuetext' => fmt::int($it['value']),
                'pct' => $total ? fmt::pct($it['value'], $total) : '',
                'url' => $it['url'] ?? '',
                'note' => $it['note'] ?? '',
            ];
        }
        return ['bars' => $out, 'hasbars' => !empty($out), 'tone' => $tone];
    }

    /**
     * Day × hour heatmap context (templates/heatmap.mustache).
     *
     * @param array $grid [dow 0..6][hour 0..23] => count (0 = Sunday)
     * @return array
     */
    public static function heatmap(array $grid): array {
        $max = 0;
        foreach ($grid as $hours) {
            $max = max($max, max($hours ?: [0]));
        }
        $daykeys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $rows = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
            $cells = [];
            for ($h = 0; $h < 24; $h++) {
                $v = (int)($grid[$dow][$h] ?? 0);
                $level = $max ? (int)ceil($v / $max * 5) : 0;
                $cells[] = ['value' => fmt::int($v), 'level' => $level, 'hour' => sprintf('%02d', $h)];
            }
            $rows[] = ['day' => get_string(substr($daykeys[$dow], 0, 3), 'calendar'), 'cells' => $cells];
        }
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[] = ['label' => sprintf('%02d', $h), 'show' => $h % 3 === 0];
        }
        return ['rows' => $rows, 'hours' => $hours, 'hasdata' => $max > 0];
    }
}
