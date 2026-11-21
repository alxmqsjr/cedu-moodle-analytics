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
use local_ceduanalytics\local\scope;

/**
 * Agenda: every date of the platform in one place. Calendar events of all kinds (site, category, course,
 * group, user and activity dates such as due dates), course start and end dates, and enrolment ends.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class agenda extends base {
    /** @var string[] Views (the first one is the default). */
    const VIEWS = ['month', 'upcoming', 'past', 'all'];

    /** @var int Items shown in a day of the month grid before "+ n more". */
    const DAYITEMS = 3;

    /** @var string */
    protected $when;
    /** @var int Month of the grid (YYYYMM), 0 = current month */
    protected $month;
    /** @var array|null */
    protected $items = null;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $when = optional_param('when', self::VIEWS[0], PARAM_ALPHA);
        $this->when = in_array($when, self::VIEWS) ? $when : self::VIEWS[0];
        $month = optional_param('month', 0, PARAM_INT);
        $this->month = ($month >= 190001 && $month <= 299912 && $month % 100 >= 1 && $month % 100 <= 12) ? $month : 0;
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        if ($this->when !== 'month') {
            return ['when' => $this->when];
        }
        return $this->month ? ['month' => $this->month] : [];
    }

    /**
     * First and last second of the month shown in the grid, in the user's time zone.
     *
     * @return int[] [start, end (exclusive), year, month]
     */
    protected function month_range(): array {
        $tz = \core_date::get_user_timezone_object();
        $now = new \DateTime('now', $tz);
        $y = $this->month ? intdiv($this->month, 100) : (int)$now->format('Y');
        $m = $this->month ? $this->month % 100 : (int)$now->format('n');
        $start = new \DateTime(sprintf('%04d-%02d-01 00:00:00', $y, $m), $tz);
        $end = (clone $start)->modify('+1 month');
        return [$start->getTimestamp(), $end->getTimestamp(), $y, $m];
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return ['agenda' => get_string('page_agenda', 'local_ceduanalytics')];
    }

    /**
     * Every agenda item of the course filters, sorted by date.
     *
     * @return array[] list of ['time', 'duration', 'title', 'type', 'kind', 'courseid', 'course', 'detail']
     */
    protected function items(): array {
        global $DB;
        if ($this->items !== null) {
            return $this->items;
        }
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $scope = new scope($this->filters);
        $courseids = $scope->course_ids();
        $incourses = fn(int $cid) => $courseids === null || in_array($cid, $courseids);
        $items = [];
        $coursenames = $DB->get_records_menu('course', null, '', 'id, fullname');
        $cname = fn(int $cid) => isset($coursenames[$cid]) && $cid !== SITEID ? fmt::text($coursenames[$cid]) : '';

        // Calendar events.
        $fields = 'id, name, courseid, categoryid, groupid, userid, modulename, eventtype, timestart, timeduration, location';
        $rs = $DB->get_recordset('event', ['visible' => 1], 'timestart', $fields);
        foreach ($rs as $e) {
            $cid = (int)$e->courseid;
            if ($courseids !== null && (!$cid || !$incourses($cid))) {
                continue;
            }
            $kind = $e->modulename ? 'activity' : $e->eventtype;
            $type = $e->modulename ? get_string('modulename', $e->modulename) . ' · ' .
                (get_string_manager()->string_exists('ag_event_' . $e->eventtype, 'local_ceduanalytics')
                    ? $s('ag_event_' . $e->eventtype) : $e->eventtype)
                : (get_string_manager()->string_exists('ag_type_' . $e->eventtype, 'local_ceduanalytics')
                    ? $s('ag_type_' . $e->eventtype) : $e->eventtype);
            $detail = [];
            if ($e->eventtype === 'user' && $e->userid) {
                $u = \local_ceduanalytics\local\people::get($e->userid);
                $detail[] = $s('ag_owner', $u ? fullname($u) : '#' . $e->userid);
            }
            if ($e->groupid) {
                $detail[] = $s('ag_group', fmt::text((string)$DB->get_field('groups', 'name', ['id' => $e->groupid])));
            }
            if ($e->categoryid) {
                $catname = (string)$DB->get_field('course_categories', 'name', ['id' => $e->categoryid]);
                $detail[] = $s('ag_category', fmt::text($catname));
            }
            if (trim((string)$e->location) !== '') {
                $detail[] = $s('ag_location', fmt::text($e->location));
            }
            $items[] = ['time' => (int)$e->timestart, 'duration' => (int)$e->timeduration, 'title' => fmt::text($e->name),
                'type' => $type, 'kind' => $kind, 'courseid' => $cid, 'course' => $cname($cid),
                'detail' => implode(' · ', $detail)];
        }
        $rs->close();

        // Course start and end dates.
        $allcourses = $DB->get_records_select('course', 'id <> :site', ['site' => SITEID], '', 'id, fullname, startdate, enddate');
        foreach ($allcourses as $c) {
            if (!$incourses((int)$c->id)) {
                continue;
            }
            foreach (['startdate' => 'coursestart', 'enddate' => 'courseend'] as $field => $kind) {
                if ((int)$c->$field) {
                    $items[] = ['time' => (int)$c->$field, 'duration' => 0, 'title' => fmt::text($c->fullname),
                        'type' => $s('ag_type_' . $kind), 'kind' => $kind, 'courseid' => (int)$c->id,
                        'course' => '', 'detail' => '', 'titlecourse' => true];
                }
            }
        }

        // Enrolment ends, per course and day.
        $ends = $DB->get_recordset_sql("
            SELECT e.courseid, ue.timeend, COUNT(1) AS n
              FROM {user_enrolments} ue
              JOIN {enrol} e ON e.id = ue.enrolid
             WHERE ue.timeend > 0 AND ue.status = 0 AND e.status = 0
          GROUP BY e.courseid, ue.timeend");
        $byday = [];
        foreach ($ends as $r) {
            if (!$incourses((int)$r->courseid)) {
                continue;
            }
            $day = usergetmidnight((int)$r->timeend);
            $k = $r->courseid . '|' . $day;
            $byday[$k] = $byday[$k] ?? ['courseid' => (int)$r->courseid, 'time' => (int)$r->timeend, 'n' => 0];
            $byday[$k]['n'] += (int)$r->n;
        }
        foreach ($byday as $b) {
            $items[] = ['time' => $b['time'], 'duration' => 0, 'title' => $s('ag_enrolend', fmt::int($b['n'])),
                'type' => $s('ag_type_enrolend'), 'kind' => 'enrolend', 'courseid' => $b['courseid'],
                'course' => $cname($b['courseid']), 'detail' => ''];
        }
        $ends->close();
        usort($items, fn($a, $b) => $a['time'] <=> $b['time']);
        $this->items = $items;
        return $items;
    }

    /**
     * Items of the current view.
     *
     * @return array[]
     */
    protected function visible(): array {
        $today = usergetmidnight(time());
        $items = $this->items();
        if ($this->when === 'month') {
            [$start, $end] = $this->month_range();
            return array_values(array_filter($items, fn($i) => $i['time'] < $end && $i['time'] + $i['duration'] >= $start));
        }
        if ($this->when === 'upcoming') {
            return array_values(array_filter($items, fn($i) => $i['time'] + $i['duration'] >= $today));
        }
        if ($this->when === 'past') {
            return array_reverse(array_values(array_filter($items, fn($i) => $i['time'] + $i['duration'] < $today)));
        }
        return $items;
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $now = time();
        $today = usergetmidnight($now);
        $items = $this->items();
        $count = fn(int $from, int $to) => count(array_filter($items, fn($i) => $i['time'] >= $from && $i['time'] < $to));
        $kpis = [
            $this->kpi('ag_next7', fmt::int($count($today, $today + 7 * DAYSECS))),
            $this->kpi('ag_next30', fmt::int($count($today, $today + 30 * DAYSECS))),
            $this->kpi('ag_future', fmt::int($count($today, PHP_INT_MAX))),
            $this->kpi('ag_past', fmt::int($count($today - 365 * DAYSECS, $today))),
        ];
        $views = [];
        foreach (self::VIEWS as $v) {
            $views[] = ['label' => $s('ag_view_' . $v), 'active' => $v === $this->when,
                'url' => $this->url(['when' => $v === self::VIEWS[0] ? '' : $v, 'month' => ''])->out(false)];
        }
        if ($this->when === 'month') {
            return $this->render('agenda', [
                'kpis' => $kpis,
                'views' => $views,
                'ismonth' => true,
                'grid' => $this->month_grid(),
            ]);
        }
        $months = [];
        foreach ($this->visible() as $i) {
            $key = userdate($i['time'], '%Y%m');
            if (!isset($months[$key])) {
                $months[$key] = ['month' => \core_text::strtotitle(userdate($i['time'], '%B %Y')), 'items' => []];
            }
            $isnow = $i['time'] <= $now && $i['time'] + $i['duration'] >= $now;
            $courseurl = $i['courseid'] && $i['courseid'] !== SITEID ? $this->link('course', ['id' => $i['courseid']]) : null;
            $meta = [];
            if (!in_array($i['kind'], ['coursestart', 'courseend', 'enrolend'])) {
                $meta[] = \html_writer::tag('i', '', ['class' => 'fa-regular fa-clock', 'aria-hidden' => 'true']) . ' ' .
                    userdate($i['time'], '%H:%M') . ($i['duration'] ? ' – ' . userdate($i['time'] + $i['duration'], '%H:%M') : '');
            }
            if ($i['course'] !== '') {
                $meta[] = $courseurl ? \html_writer::link($courseurl, s($i['course'])) : s($i['course']);
            }
            if ($i['detail'] !== '') {
                $meta[] = s($i['detail']);
            }
            $title = !empty($i['titlecourse']) && $courseurl ? \html_writer::link($courseurl, s($i['title'])) : s($i['title']);
            $months[$key]['items'][] = [
                'day' => userdate($i['time'], '%d'),
                'weekday' => userdate($i['time'], '%a'),
                'title' => $title,
                'type' => $i['type'],
                'kind' => $i['kind'],
                'meta' => implode(' · ', $meta),
                'past' => $i['time'] + $i['duration'] < $today,
                'today' => usergetmidnight($i['time']) === $today || $isnow,
            ];
        }
        return $this->render('agenda', [
            'kpis' => $kpis,
            'views' => $views,
            'months' => array_values($months),
            'hasitems' => !empty($months),
            'empty' => $this->empty_state('fa-calendar-xmark', $s('ag_empty'), $s('ag_empty_' . $this->when)),
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
        if ($id !== 'agenda') {
            return null;
        }
        $ds = new dataset('agenda', $s('page_agenda'), ['date' => $s('col_date'), 'time' => $s('col_time'),
            'title' => $s('col_title'), 'type' => $s('col_type'), 'course' => $s('col_course'), 'detail' => $s('col_detail')]);
        foreach ($this->visible() as $i) {
            $ds->add(['date' => fmt::date($i['time']), 'time' => in_array($i['kind'], ['coursestart', 'courseend', 'enrolend'])
                ? '' : userdate($i['time'], '%H:%M'), 'title' => $i['title'], 'type' => $i['type'], 'course' => $i['course'],
                'detail' => $i['detail']]);
        }
        $ds->emptytext = $s('ag_empty_' . $this->when);
        return $ds;
    }

    /**
     * Month grid: weeks of 7 days (first weekday of the site calendar), with the items of each day.
     *
     * Items that last several days appear on each day. Days outside the month are shown greyed out, without items.
     *
     * @return array template context
     */
    protected function month_grid(): array {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        [$start, $end, $y, $m] = $this->month_range();
        $tz = \core_date::get_user_timezone_object();
        $today = usergetmidnight(time());

        // Items by day (Y-m-d in the user's time zone).
        $byday = [];
        foreach ($this->visible() as $i) {
            $from = max($i['time'], $start);
            $to = min($i['time'] + $i['duration'], $end - 1);
            $day = new \DateTime('@' . $from);
            $day->setTimezone($tz);
            $day->setTime(0, 0);
            $last = (new \DateTime('@' . $to))->setTimezone($tz)->format('Y-m-d');
            for ($guard = 0; $guard < 62; $guard++) {
                $byday[$day->format('Y-m-d')][] = $i;
                if ($day->format('Y-m-d') >= $last) {
                    break;
                }
                $day->modify('+1 day');
            }
        }

        // First cell: the first weekday of the calendar on or before the 1st.
        $first = (new \DateTime('@' . $start))->setTimezone($tz);
        $startwday = calendar_get_starting_weekday();
        $offset = ((int)$first->format('w') - $startwday + 7) % 7;
        $cell = (clone $first)->modify('-' . $offset . ' days');

        $weekdays = [];
        $wd = clone $cell;
        for ($d = 0; $d < 7; $d++) {
            $weekdays[] = ['short' => \core_text::strtotitle(userdate($wd->getTimestamp(), '%a')),
                'long' => \core_text::strtotitle(userdate($wd->getTimestamp(), '%A'))];
            $wd->modify('+1 day');
        }

        $weeks = [];
        while ($cell->getTimestamp() < $end) {
            $days = [];
            for ($d = 0; $d < 7; $d++) {
                $ts = $cell->getTimestamp();
                $inmonth = $ts >= $start && $ts < $end;
                $key = $cell->format('Y-m-d');
                $list = $inmonth ? ($byday[$key] ?? []) : [];
                usort($list, fn($a, $b) => $a['time'] <=> $b['time']);
                $entries = [];
                foreach ($list as $i) {
                    $timed = !in_array($i['kind'], ['coursestart', 'courseend', 'enrolend']) && usergetmidnight($i['time']) === $ts;
                    $title = ($timed ? userdate($i['time'], '%H:%M') . ' ' : '') . $i['title'];
                    $tip = implode(' · ', array_filter([$title, $i['type'], $i['course'], $i['detail']]));
                    $courseurl = $i['courseid'] && $i['courseid'] !== SITEID ? $this->link(
                        'course',
                        ['id' => $i['courseid']]
                    ) : null;
                    $entries[] = ['text' => $title, 'tip' => $tip, 'kind' => $i['kind'],
                        'url' => $courseurl ? $courseurl->out(false) : ''];
                }
                $days[] = [
                    'day' => (int)$cell->format('j'),
                    'label' => userdate($ts, get_string('strftimedaydate', 'langconfig')),
                    'inmonth' => $inmonth,
                    'today' => $ts === $today,
                    'past' => $ts < $today,
                    'hasitems' => !empty($entries),
                    'items' => array_slice($entries, 0, self::DAYITEMS),
                    'more' => array_slice($entries, self::DAYITEMS),
                    'morelabel' => count($entries) > self::DAYITEMS
                        ? $s('ag_more', count($entries) - self::DAYITEMS) : '',
                ];
                $cell->modify('+1 day');
            }
            $weeks[] = ['days' => $days];
        }
        $total = count($this->visible());

        $prev = (new \DateTime('@' . $start))->setTimezone($tz)->modify('-1 month');
        $next = (new \DateTime('@' . $start))->setTimezone($tz)->modify('+1 month');
        $kinds = [];
        foreach (['activity', 'course', 'coursestart', 'courseend', 'enrolend', 'site', 'category', 'group', 'user'] as $k) {
            $kinds[] = ['kind' => $k, 'label' => $s('ag_kind_' . $k)];
        }
        return [
            'title' => \core_text::strtotitle(userdate($start, '%B %Y')),
            'prevurl' => $this->url(['month' => (int)$prev->format('Ym')])->out(false),
            'nexturl' => $this->url(['month' => (int)$next->format('Ym')])->out(false),
            'todayurl' => $this->url(['month' => ''])->out(false),
            'iscurrent' => !$this->month || $this->month === (int)(new \DateTime('now', $tz))->format('Ym'),
            'weekdays' => $weekdays,
            'weeks' => $weeks,
            'count' => $s('ag_monthcount', fmt::int($total)),
            'kinds' => $kinds,
        ];
    }
}
