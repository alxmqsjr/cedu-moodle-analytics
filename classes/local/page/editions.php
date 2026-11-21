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
use local_ceduanalytics\local\courses as catalogue;
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * Editions: how an edition compares with the other editions of the same course line,
 * and how the groups (T1, T2…) move along the track.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editions extends base {
    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        $out = ['lines' => get_string('ed_lines', 'local_ceduanalytics'), 'tracks' => get_string(
            'ed_tracks',
            'local_ceduanalytics'
        )];
        if ($this->filters->line) {
            $out = ['editions' => get_string('ed_editions', 'local_ceduanalytics')] + $out;
        }
        return $out;
    }

    /**
     * Hours per course of the students in scope (whole history).
     *
     * @param scope $scope
     * @return array courseid => seconds
     */
    protected function hours(scope $scope): array {
        $all = new scope($this->filters, [0, time() + DAYSECS]);
        $out = [];
        foreach ($all->daily('d.courseid, SUM(d.activesecs) AS secs', 'd.courseid') as $r) {
            $out[(int)$r->courseid] = (int)$r->secs;
        }
        return $out;
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $context = ['lines' => $this->get_dataset('lines')->export_for_template(),
            'tracks' => $this->get_dataset('tracks')->export_for_template(), 'hasline' => false];
        $cards = config::line_cards((int)$this->filters->line);
        $context['linecards'] = ['lines' => $cards, 'haslines' => !empty($cards)];
        if ($this->filters->line) {
            $lines = catalogue::get_lines();
            $line = $lines[$this->filters->line] ?? null;
            $ds = $this->get_dataset('editions');
            $context['hasline'] = true;
            $context['linename'] = $line ? fmt::text($line->name) : '';
            $context['editions'] = $ds->export_for_template();
            $labels = [];
            $acc = [];
            $cert = [];
            foreach ($ds->rows as $row) {
                $labels[] = $row['edition'];
                $acc[] = $row['_accpct'];
                $cert[] = $row['_certpct'];
            }
            $context['chart'] = count($labels) >= 2 ? charts::bar($labels, [
                $str('series_accessedpct') => $acc,
                $str('series_certpct') => $cert,
            ], false, false, [charts::BLUE, charts::GREEN]) : '';
            $context['haschart'] = $context['chart'] !== '';
            $context['clearurl'] = $this->url(['line' => 0])->out(false);
        } else {
            $context['chooseline'] = $this->empty_state('fa-layer-group', $str('ed_choose_title'), $str('ed_choose_text'));
        }
        return $this->render('editions', $context);
    }

    /**
     * Datasets.
     *
     * @param string $id
     * @return dataset|null
     */
    public function get_dataset(string $id): ?dataset {
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $all = catalogue::get_all();
        $nolinefilters = clone $this->filters;
        $nolinefilters->line = 0;
        $stats = coursestats::by_course(new scope($nolinefilters));

        if ($id === 'editions') {
            $ds = new dataset('editions', $str('ed_editions'), [
                'edition' => $str('col_edition'), 'course' => $str('col_course'), 'start' => $str('col_start'),
                'status' => $str('col_status'), 'students' => $str('col_students'), 'accessed' => $str('col_accessed'),
                'progress' => $str('col_avgprogress'), 'certified' => $str('col_certified'), 'grade' => $str('col_gradeavg'),
                'certdays' => $str('col_certdays'), 'hours' => $str('col_hoursper'),
            ], ['students', 'accessed', 'progress', 'certified', 'grade', 'certdays', 'hours']);
            $hours = $this->hours(new scope($nolinefilters));
            $courses = array_filter($all, fn($c) => (int)$c->lineid === (int)$this->filters->line);
            uasort($courses, fn($a, $b) => strnatcmp((string)$a->edition, (string)$b->edition) ?: $a->startdate <=> $b->startdate);
            foreach ($courses as $c) {
                $s = $stats[$c->id] ?? coursestats::empty_stats();
                $n = $s['enrolled'];
                $median = coursestats::median($s['certdays']);
                $name = fmt::text($c->fullname);
                $ds->add([
                    'edition' => $c->edition ?: '—', 'course' => $name, 'start' => fmt::date((int)$c->startdate),
                    'status' => $str('cstatus_' . coursestats::status($c, $s)), 'students' => $n,
                    'accessed' => fmt::pct($s['accessed'], $n), 'progress' => $n ? fmt::percent($s['progresssum'] / $n) : '—',
                    'certified' => $s['certified'] . ' (' . fmt::pct($s['certified'], $n) . ')',
                    'grade' => $s['gradecount'] ? fmt::percent($s['gradesum'] / $s['gradecount']) : '—',
                    'certdays' => $median === null ? '—' : fmt::dec($median),
                    'hours' => $n ? fmt::hours((int)(($hours[$c->id] ?? 0) / $n)) : '—',
                    '_accpct' => $n ? round($s['accessed'] / $n * 100, 1) : 0,
                    '_certpct' => $n ? round($s['certified'] / $n * 100, 1) : 0,
                    '_html' => [
                        'course' => coursestats::link($c->id, $name, $this->filters->params()),
                        'status' => coursestats::status_badge(coursestats::status($c, $s)),
                    ],
                ]);
            }
            $ds->emptytext = $str('ed_noeditions');
            return $ds;
        }

        if ($id === 'lines') {
            $ds = new dataset('lines', $str('ed_lines'), [
                'line' => $str('col_line'), 'kind' => $str('cfg_line_kind'), 'editions' => $str('col_editions'),
                'students' => $str('col_students'), 'accessed' => $str('col_accessed'), 'certified' => $str('col_certified'),
            ], ['editions', 'students', 'accessed', 'certified']);
            foreach (catalogue::get_lines() as $l) {
                $sum = coursestats::empty_stats();
                $count = 0;
                foreach ($all as $c) {
                    if ((int)$c->lineid !== (int)$l->id) {
                        continue;
                    }
                    $count++;
                    foreach (['enrolled', 'accessed', 'certified'] as $k) {
                        $sum[$k] += ($stats[$c->id][$k] ?? 0);
                    }
                }
                $name = fmt::text($l->name);
                $ds->add([
                    'line' => $name, 'kind' => $str('linekind_' . $l->kind), 'editions' => $count, 'students' => $sum['enrolled'],
                    'accessed' => fmt::pct($sum['accessed'], $sum['enrolled']),
                    'certified' => $sum['certified'] . ' (' . fmt::pct($sum['certified'], $sum['enrolled']) . ')',
                    '_html' => ['line' => \html_writer::link($this->url(['line' => $l->id]), s($name)),
                        'kind' => \html_writer::span($str('linekind_' . $l->kind), 'cea-badge cea-kind-' . $l->kind)],
                    '_class' => (int)$l->id === (int)$this->filters->line ? 'cea-row-current' : '',
                ]);
            }
            $ds->emptytext = $str('cfg_lines_empty');
            return $ds;
        }

        if ($id === 'tracks') {
            // Groups that share an edition label (T1, T2…) across course lines with editions.
            $ds = new dataset('tracks', $str('ed_tracks'), [
                'label' => $str('col_group'), 'courses' => $str('col_courses'), 'people' => $str('col_people'),
                'one' => $str('col_in1'), 'two' => $str('col_in2'), 'more' => $str('col_in3'), 'allcert' => $str('col_allcert'),
            ], ['courses', 'people', 'one', 'two', 'more', 'allcert']);
            $groups = [];
            foreach ($all as $c) {
                if ($c->type === 'track' && $c->edition !== null && preg_match('/^T\d+$/', (string)$c->edition)) {
                    $groups[$c->edition][] = (int)$c->id;
                }
            }
            uksort($groups, 'strnatcmp');
            $enrols = (new scope($nolinefilters))->enrolments('e.id, e.userid, e.courseid, e.timecertified', '', [], '', false);
            foreach ($groups as $label => $courseids) {
                if (count($courseids) < 2) {
                    continue;
                }
                $people = [];
                foreach ($enrols as $r) {
                    if (in_array((int)$r->courseid, $courseids)) {
                        $people[$r->userid]['n'] = ($people[$r->userid]['n'] ?? 0) + 1;
                        $people[$r->userid]['cert'] = ($people[$r->userid]['cert'] ?? 0) + ($r->timecertified ? 1 : 0);
                    }
                }
                $one = $two = $more = $allcert = 0;
                foreach ($people as $p) {
                    $p['n'] === 1 ? $one++ : ($p['n'] === 2 ? $two++ : $more++);
                    if ($p['cert'] >= count($courseids)) {
                        $allcert++;
                    }
                }
                $total = count($people);
                $names = array_map(fn($cid) => fmt::text($all[$cid]->fullname), $courseids);
                $ds->add([
                    'label' => $label, 'courses' => count($courseids), 'people' => $total,
                    'one' => $one . ' (' . fmt::pct($one, $total) . ')', 'two' => $two . ' (' . fmt::pct($two, $total) . ')',
                    'more' => $more . ' (' . fmt::pct($more, $total) . ')',
                    'allcert' => $allcert . ' (' . fmt::pct($allcert, $total) . ')',
                    '_html' => ['courses' => \html_writer::span((string)count($courseids), '', ['title' => implode("\n", $names)])],
                ]);
            }
            $ds->emptytext = $str('ed_notracks');
            return $ds;
        }
        return null;
    }
}
