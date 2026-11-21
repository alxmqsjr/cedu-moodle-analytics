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
 * Translates the global filters into SQL over the plugin tables.
 *
 * An enrolment belongs to the period when its interval overlaps it: it starts (enrolment start or
 * creation) before the end of the period, and it ends (enrolment end, otherwise course end, otherwise
 * open) after the start of the period, or the student accessed the course during the period.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /** @var filters */
    public $filters;
    /** @var int */
    public $from;
    /** @var int */
    public $to;
    /** @var int */
    protected static $counter = 0;

    /**
     * Constructor.
     *
     * @param filters $filters
     * @param array|null $range [from, to]; defaults to the filter period
     */
    public function __construct(filters $filters, ?array $range = null) {
        $this->filters = $filters;
        [$this->from, $this->to] = $range ?? $filters->range();
    }

    /**
     * Same filters, comparison period. Null when the period is "all".
     *
     * @return scope|null
     */
    public function previous(): ?scope {
        $range = $this->filters->previous_range();
        return $range ? new scope($this->filters, $range) : null;
    }

    /**
     * Unique parameter name.
     *
     * @param string $base
     * @return string
     */
    protected static function p(string $base): string {
        return $base . '_cea' . (++self::$counter);
    }

    /**
     * Course ids selected by the course filters, or null for "all courses".
     *
     * @return int[]|null
     */
    public function course_ids(): ?array {
        global $DB;
        $f = $this->filters;
        if (!$f->category && !$f->line && !$f->course) {
            return null;
        }
        $where = ['c.id <> :siteid'];
        $params = ['siteid' => SITEID];
        if ($f->course) {
            $where[] = 'c.id = :fcourse';
            $params['fcourse'] = $f->course;
        }
        if ($f->category) {
            $cat = $DB->get_record('course_categories', ['id' => $f->category], 'id, path');
            if ($cat) {
                $where[] = '(cc.id = :fcat OR ' . $DB->sql_like('cc.path', ':fcatpath') . ')';
                $params['fcat'] = $cat->id;
                $params['fcatpath'] = $cat->path . '/%';
            } else {
                // A category that no longer exists (old link or saved view) selects no course, like a missing course or line.
                $where[] = '1 = 0';
            }
        }
        if ($f->line) {
            $where[] = 'EXISTS (SELECT 1 FROM {local_ceduanalytics_linecourse} lc WHERE lc.courseid = c.id AND lc.lineid = :fline)';
            $params['fline'] = $f->line;
        }
        return array_map('intval', $DB->get_fieldset_sql(
            'SELECT c.id FROM {course} c JOIN {course_categories} cc ON cc.id = c.category WHERE ' . implode(' AND ', $where),
            $params
        ));
    }

    /**
     * Conditions on enrolments: students, course filters, user filters and period.
     *
     * Expects the aliases e (enrolstate), c (course) and ua (userattr, LEFT JOIN).
     *
     * @param bool $withperiod
     * @return array [sql, params]
     */
    public function enrol_where(bool $withperiod = true): array {
        global $DB;
        $where = ['e.isstudent = 1'];
        $params = [];
        $courses = $this->course_ids();
        if ($courses !== null) {
            if (!$courses) {
                return ['1 = 0', []];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($courses, SQL_PARAMS_NAMED, self::p('c'));
            $where[] = "e.courseid $insql";
            $params += $inparams;
        }
        [$usql, $uparams] = $this->user_where();
        if ($usql !== '') {
            $where[] = $usql;
            $params += $uparams;
        }
        if ($withperiod && $this->from > 0) {
            $pto = self::p('to');
            $pfrom = self::p('from');
            $pfrom2 = self::p('from');
            $where[] = "(CASE WHEN e.timestart > 0 THEN e.timestart ELSE e.timeenrolled END) < :$pto";
            $where[] = "((e.timeend = 0 AND c.enddate = 0)
                         OR (CASE WHEN e.timeend > 0 THEN e.timeend ELSE c.enddate END) >= :$pfrom
                         OR e.lastaccess >= :$pfrom2)";
            $params[$pto] = $this->to;
            $params[$pfrom] = $this->from;
            $params[$pfrom2] = $this->from;
        } else if ($withperiod) {
            $pto = self::p('to');
            $where[] = "(CASE WHEN e.timestart > 0 THEN e.timestart ELSE e.timeenrolled END) < :$pto";
            $params[$pto] = $this->to;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * Conditions on the user attributes (alias ua).
     *
     * @return array [sql, params]
     */
    public function user_where(): array {
        $where = [];
        $params = [];
        if ($this->filters->uf === 'NONE') {
            $where[] = "(ua.uf IS NULL OR ua.uf = '')";
        } else if ($this->filters->uf !== '') {
            $p = self::p('uf');
            $where[] = "ua.uf = :$p";
            $params[$p] = $this->filters->uf;
        }
        if ($this->filters->segment !== '') {
            $p = self::p('seg');
            $where[] = "ua.segment = :$p";
            $params[$p] = $this->filters->segment;
        }
        return [implode(' AND ', $where), $params];
    }

    /**
     * FROM clause for enrolments with course, user and attributes.
     *
     * @return string
     */
    public static function enrol_from(): string {
        return '{local_ceduanalytics_enrolstate} e
                JOIN {course} c ON c.id = e.courseid
                JOIN {user} u ON u.id = e.userid AND u.deleted = 0
           LEFT JOIN {local_ceduanalytics_userattr} ua ON ua.userid = e.userid';
    }

    /**
     * Enrolments in scope.
     *
     * @param string $fields
     * @param string $extrawhere Additional SQL condition
     * @param array $extraparams
     * @param string $sort
     * @param bool $withperiod Restrict to enrolments overlapping the period
     * @return \stdClass[] (not keyed; uses a recordset internally)
     */
    public function enrolments(
        string $fields = 'e.*',
        string $extrawhere = '',
        array $extraparams = [],
        string $sort = '',
        bool $withperiod = true
    ): array {
        global $DB;
        [$where, $params] = $this->enrol_where($withperiod);
        if ($extrawhere !== '') {
            $where .= ' AND ' . $extrawhere;
            $params += $extraparams;
        }
        $sql = 'SELECT ' . $fields . ' FROM ' . self::enrol_from() . ' WHERE ' . $where . ($sort ? ' ORDER BY ' . $sort : '');
        $out = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $r) {
            $out[] = $r;
        }
        $rs->close();
        return $out;
    }

    /**
     * Daily activity rows of students in scope within the period (alias d).
     *
     * @param string $fields
     * @param string $groupby
     * @return \stdClass[]
     */
    public function daily(string $fields, string $groupby = ''): array {
        global $DB;
        [$where, $params] = $this->enrol_where(false);
        $where .= ' AND d.day >= :dfrom AND d.day < :dto';
        $params['dfrom'] = $this->from;
        $params['dto'] = $this->to;
        $sql = "SELECT $fields
                  FROM {local_ceduanalytics_daily} d
                  JOIN {local_ceduanalytics_enrolstate} e ON e.userid = d.userid AND e.courseid = d.courseid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {user} u ON u.id = e.userid AND u.deleted = 0
             LEFT JOIN {local_ceduanalytics_userattr} ua ON ua.userid = e.userid
                 WHERE $where" . ($groupby ? " GROUP BY $groupby" : '');
        $out = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $r) {
            $out[] = $r;
        }
        $rs->close();
        return $out;
    }

    /**
     * Cache key for this scope and a block name.
     *
     * @param string $block
     * @return string
     */
    public function cachekey(string $block): string {
        return $block . '_' . md5(json_encode([$this->filters->params(), $this->from, $this->to]));
    }

    /**
     * Compute a value with the indicators cache (purged by every task run).
     *
     * @param string $block
     * @param callable $compute
     * @return mixed
     */
    public function cached(string $block, callable $compute) {
        $cache = \cache::make('local_ceduanalytics', 'indicators');
        $key = $this->cachekey($block);
        $value = $cache->get($key);
        if ($value === false) {
            $value = $compute();
            $cache->set($key, $value);
        }
        return $value;
    }
}
