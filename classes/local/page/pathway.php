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

use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * Pathway and dropout: where do students stop?
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pathway extends base {
    /** @var array|null enrolments in scope grouped by course */
    protected $bycourse = null;

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'courses' => get_string('path_bycourse', 'local_ceduanalytics'),
            'stops' => get_string('path_stops', 'local_ceduanalytics'),
        ];
    }

    /**
     * Enrolments in scope grouped by course.
     *
     * @return array courseid => ['name' => string, 'rows' => userid => row]
     */
    protected function bycourse(): array {
        global $DB;
        if ($this->bycourse !== null) {
            return $this->bycourse;
        }
        $scope = new scope($this->filters);
        $out = [];
        foreach (
            $scope->enrolments('e.id, e.userid, e.courseid, e.situation, e.progress, e.timecertified, e.gradepct,
                e.lastaccess, e.timeenrolled, e.firstaccess, e.cmtotal, c.fullname') as $r
        ) {
            $out[$r->courseid]['name'] = fmt::text($r->fullname);
            $out[$r->courseid]['rows'][(int)$r->userid] = $r;
            $out[$r->courseid]['tracked'] = max($out[$r->courseid]['tracked'] ?? 0, (int)$r->cmtotal);
        }
        // Courses with a certificate activity (mod_customcert), so "0%" is told apart from "no certificate in the course".
        $withcert = [];
        if ($out && $DB->get_manager()->table_exists('customcert')) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($out));
            $withcert = array_flip($DB->get_fieldset_select('customcert', 'DISTINCT course', "course $insql", $params));
        }
        foreach ($out as $cid => $c) {
            $out[$cid]['hascert'] = isset($withcert[$cid]);
        }
        return $this->bycourse = $out;
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $total = coursestats::empty_stats();
        foreach ($this->bycourse() as $c) {
            foreach ($c['rows'] as $r) {
                coursestats::add($total, $r);
            }
        }
        $n = $total['enrolled'];
        $started = $total['accessed'];
        $finishedamongstarted = $total['p100'];
        return $this->render('pathway', [
            'funnel' => coursestats::funnel($total, fn($stage) => in_array($stage, ['certified']) ?
                $this->link('students', ['situation' => 'certified', 'view' => 'enrolments'])->out(false) : ''),
            'insight' => $n ? get_string('path_insight', 'local_ceduanalytics', [
                'never' => fmt::pct($total['notstarted'], $n),
                'finish' => fmt::pct($finishedamongstarted, $started),
            ]) : '',
            'courses' => $this->get_dataset('courses')->export_for_template(),
            'stops' => $this->get_dataset('stops')->export_for_template(),
        ]);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        if ($id === 'courses') {
            $cols = ['course' => $str('col_course')];
            foreach (coursestats::STAGES as $stage) {
                $cols[$stage] = $str('funnel_' . $stage);
            }
            $ds = new dataset('courses', $str('path_bycourse'), $cols, coursestats::STAGES);
            foreach ($this->bycourse() as $cid => $c) {
                $s = coursestats::empty_stats();
                foreach ($c['rows'] as $r) {
                    coursestats::add($s, $r);
                }
                $row = ['course' => $c['name'], '_html' => ['course' => coursestats::link(
                    $cid,
                    $c['name'],
                    $this->filters->params()
                )]];
                foreach (coursestats::STAGES as $stage) {
                    $row[$stage] = $stage === 'enrolled' ? (string)$s[$stage] :
                        fmt::pct($s[$stage], $s['enrolled']) . ' (' . $s[$stage] . ')';
                    // Without tracked activities (or without a certificate activity) the stage cannot be measured.
                    $nottracked = in_array($stage, ['p25', 'p50', 'p75', 'p100']) && !$c['tracked'];
                    $nocert = $stage === 'certified' && !$c['hascert'] && !$s['certified'];
                    if ($nottracked || $nocert) {
                        $text = $str($nottracked ? 'path_notracked' : 'path_nocertactivity');
                        $row[$stage] = $text;
                        $row['_html'][$stage] = \html_writer::span(s($text), 'cea-muted', ['title' => $str(
                            $nottracked ? 'path_notracked_help' : 'path_nocertactivity_help'
                        )]);
                    }
                }
                $ds->add($row);
            }
            usort($ds->rows, fn($a, $b) => (int)$b['enrolled'] <=> (int)$a['enrolled']);
            $ds->emptytext = $str('empty_students');
            return $ds;
        }
        if ($id === 'stops') {
            $ds = new dataset('stops', $str('path_stops'), [
                'course' => $str('col_course'), 'section' => $str('col_section'), 'activity' => $str('col_activity'),
                'type' => $str('col_modtype'), 'stopped' => $str('col_stoppedhere'), 'pct' => $str('col_stoppedpct'),
            ], ['stopped', 'pct']);
            $rows = [];
            foreach ($this->bycourse() as $cid => $c) {
                $a = coursestats::activity_stats($cid, $c['rows']);
                $unfinished = count(array_filter($c['rows'], fn($r) => !$r->timecertified && (float)$r->progress < 100));
                if ($a['beforefirst']) {
                    $rows[] = ['course' => $c['name'], 'section' => '—', 'activity' => $str('stopped_beforefirst'), 'type' => '',
                        'stopped' => $a['beforefirst'], 'pct' => fmt::pct($a['beforefirst'], $unfinished),
                        '_html' => ['course' => coursestats::link($cid, $c['name'], $this->filters->params())]];
                }
                foreach ($a['rows'] as $row) {
                    if (!$row['stopped']) {
                        continue;
                    }
                    $cm = $row['cm'];
                    $rows[] = ['course' => $c['name'], 'section' => $row['section'],
                        'activity' => fmt::plain($cm->get_formatted_name()),
                        'type' => (string)$cm->get_module_type_name(), 'stopped' => $row['stopped'],
                        'pct' => fmt::pct($row['stopped'], $unfinished),
                        '_html' => ['course' => coursestats::link($cid, $c['name'], $this->filters->params()),
                            'activity' => \html_writer::link(
                                $cm->url ?? new \moodle_url('/course/view.php', ['id' => $cid]),
                                s(fmt::plain($cm->get_formatted_name()))
                            )]];
                }
            }
            usort($rows, fn($a, $b) => $b['stopped'] <=> $a['stopped']);
            foreach (array_slice($rows, 0, 50) as $r) {
                $ds->add($r);
            }
            $ds->emptytext = $str('path_nostops');
            return $ds;
        }
        return null;
    }
}
