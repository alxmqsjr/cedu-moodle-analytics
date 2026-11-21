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
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * Audience profile: segments (from job titles), institutions, results by segment and segment × state.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience extends base {
    /** @var string[] Segments in display order. */
    const SEGMENTS = ['schoolmanagement', 'teacher', 'student', 'mobiliser', 'civildefence', 'socialassistance', 'research',
        'other', 'notinformed'];

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'segments' => get_string('aud_segments', 'local_ceduanalytics'),
            'institutions' => get_string('aud_institutions', 'local_ceduanalytics'),
            'matrix' => get_string('aud_matrix', 'local_ceduanalytics'),
            'jobtitles' => get_string('aud_jobtitles', 'local_ceduanalytics'),
        ];
    }

    /**
     * Students in scope, one row per user.
     *
     * @return array userid => [segment, uf, institution, jobtitle, enrolments, certified, notstarted, activeany]
     */
    protected function users(): array {
        $scope = new scope($this->filters);
        return $scope->cached('audusers', function () use ($scope) {
            $users = [];
            foreach (
                $scope->enrolments('e.id, e.userid, e.timecertified, e.situation, e.lastaccess, ua.segment, ua.uf,
                    ua.institution, ua.jobtitle') as $r
            ) {
                $uid = (int)$r->userid;
                if (!isset($users[$uid])) {
                    $users[$uid] = ['segment' => $r->segment ?: 'notinformed', 'uf' => (string)$r->uf,
                        'institution' => trim((string)$r->institution), 'jobtitle' => trim((string)$r->jobtitle),
                        'enrolments' => 0, 'certified' => 0, 'notstarted' => 0];
                }
                $users[$uid]['enrolments']++;
                $users[$uid]['certified'] += $r->timecertified ? 1 : 0;
                $users[$uid]['notstarted'] += $r->situation === 'notstarted' ? 1 : 0;
            }
            return $users;
        });
    }

    /**
     * Totals per segment.
     *
     * @return array segment => [students, enrolments, certified, certstudents, notstarted]
     */
    protected function by_segment(): array {
        $out = array_fill_keys(self::SEGMENTS, ['students' => 0, 'enrolments' => 0, 'certified' => 0, 'certstudents' => 0,
            'notstarted' => 0]);
        foreach ($this->users() as $u) {
            $seg = isset($out[$u['segment']]) ? $u['segment'] : 'other';
            $out[$seg]['students']++;
            $out[$seg]['enrolments'] += $u['enrolments'];
            $out[$seg]['certified'] += $u['certified'];
            $out[$seg]['certstudents'] += $u['certified'] ? 1 : 0;
            $out[$seg]['notstarted'] += $u['notstarted'];
        }
        return $out;
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $users = $this->users();
        $total = count($users);
        $segs = $this->by_segment();
        $classified = $total - $segs['other']['students'] - $segs['notinformed']['students'];
        $institutions = [];
        $schools = 0;
        foreach ($users as $u) {
            if ($u['institution'] !== '') {
                $key = \local_ceduanalytics\local\br::normalise($u['institution']);
                if (!isset($institutions[$key])) {
                    $institutions[$key] = true;
                    $schools += preg_match(
                        '/\b(escola|emef|emeief|emei|umef|umefti|colegio|e\.?e\.?|creche|cei)\b/i',
                        $key
                    ) ? 1 : 0;
                }
            }
        }
        $withinst = count(array_filter($users, fn($u) => $u['institution'] !== ''));
        $top = '';
        foreach ($segs as $seg => $st) {
            if (
                !in_array($seg, ['other', 'notinformed']) && $st['students'] > 0
                    && ($top === '' || $st['students'] > $segs[$top]['students'])
            ) {
                $top = $seg;
            }
        }
        $kpis = [
            $this->kpi('aud_students', fmt::int($total)),
            $this->kpi(
                'aud_classified',
                $total ? fmt::pct($classified, $total) : '—',
                null,
                $this->link('config', ['tab' => 'segments']),
                '',
                false,
                $s('aud_classified_note', fmt::int($classified))
            ),
            $this->kpi(
                'aud_institutions',
                fmt::int(count($institutions)),
                null,
                null,
                '',
                false,
                $s('aud_institutions_note', ['schools' => fmt::int($schools), 'pct' => $total ? fmt::pct($withinst, $total) : '—'])
            ),
            $this->kpi(
                'aud_topsegment',
                $top ? $s('segment_' . $top) : '—',
                null,
                null,
                '',
                false,
                $top ? $s('aud_topsegment_note', ['n' => fmt::int($segs[$top]['students']),
                'pct' => fmt::pct(
                    $segs[$top]['students'],
                    $total
                )]) : ''
            ),
        ];
        $items = [];
        foreach ($segs as $seg => $st) {
            $items[] = ['label' => $s('segment_' . $seg), 'value' => $st['students'],
                'muted' => in_array($seg, ['other', 'notinformed']),
                'url' => $this->link('students', ['segment' => $seg])->out(false)];
        }
        usort($items, fn($a, $b) => $b['value'] <=> $a['value']);
        return $this->render('audience', [
            'kpis' => $kpis,
            'bars' => charts::bars($items, $total, 'blue'),
            'segments' => $this->get_dataset('segments')->export_for_template(),
            'institutions' => $this->get_dataset('institutions')->export_for_template(),
            'matrix' => $this->get_dataset('matrix')->export_for_template(),
            'jobtitles' => $this->get_dataset('jobtitles')->export_for_template(),
            'configurl' => $this->link('config', ['tab' => 'segments'])->out(false),
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
        $lowmark = fn(int $n) => fmt::lowconf($n) ? ' ' . \html_writer::span(
            '*',
            'cea-inferred',
            ['title' => $s('lowconf_help')]
        ) : '';
        if ($id === 'segments') {
            $ds = new dataset('segments', $s('aud_segments'), [
                'segment' => $s('col_segment'), 'students' => $s('col_students'), 'enrolments' => $s('col_enrolments'),
                'certified' => $s('col_certified'), 'rate' => $s('col_certrate'), 'certstudents' => $s('col_certstudents'),
                'notstarted' => $s('col_notstarted_pct'),
            ], ['students', 'enrolments', 'certified', 'rate', 'certstudents', 'notstarted']);
            foreach ($this->by_segment() as $seg => $st) {
                $rate = $st['enrolments'] ? fmt::pct($st['certified'], $st['enrolments']) : '—';
                $ds->add([
                    'segment' => $s('segment_' . $seg), 'students' => $st['students'], 'enrolments' => $st['enrolments'],
                    'certified' => $st['certified'], 'rate' => $rate, 'certstudents' => $st['certstudents'],
                    'notstarted' => $st['enrolments'] ? fmt::pct($st['notstarted'], $st['enrolments']) : '—',
                    '_html' => [
                        'segment' => \html_writer::link($this->link('students', ['segment' => $seg]), $s('segment_' . $seg)),
                        'rate' => s($rate) . $lowmark($st['enrolments']),
                    ],
                    '_class' => in_array($seg, ['other', 'notinformed']) ? 'cea-row-muted' : '',
                ]);
            }
            return $ds;
        }
        if ($id === 'institutions') {
            $ds = new dataset('institutions', $s('aud_institutions'), [
                'institution' => $s('col_institution'), 'uf' => $s('col_uf'), 'students' => $s('col_students'),
                'segments' => $s('col_segments'), 'enrolments' => $s('col_enrolments'), 'certified' => $s('col_certified'),
                'rate' => $s('col_certrate'),
            ], ['students', 'enrolments', 'certified', 'rate']);
            $agg = [];
            foreach ($this->users() as $u) {
                if ($u['institution'] === '') {
                    continue;
                }
                $k = \local_ceduanalytics\local\br::normalise($u['institution']);
                $agg[$k] = $agg[$k] ?? ['name' => $u['institution'], 'ufs' => [], 'students' => 0, 'segs' => [],
                    'enrolments' => 0, 'certified' => 0];
                $a = &$agg[$k];
                $a['students']++;
                if ($u['uf'] !== '') {
                    $a['ufs'][$u['uf']] = true;
                }
                $a['segs'][$u['segment']] = ($a['segs'][$u['segment']] ?? 0) + 1;
                $a['enrolments'] += $u['enrolments'];
                $a['certified'] += $u['certified'];
                unset($a);
            }
            uasort($agg, fn($a, $b) => $b['students'] <=> $a['students']);
            foreach ($agg as $a) {
                arsort($a['segs']);
                $segs = [];
                foreach ($a['segs'] as $seg => $n) {
                    $segs[] = $s('segment_' . $seg) . ' (' . $n . ')';
                }
                $ds->add([
                    'institution' => $a['name'], 'uf' => implode(', ', array_keys($a['ufs'])), 'students' => $a['students'],
                    'segments' => implode(', ', $segs), 'enrolments' => $a['enrolments'], 'certified' => $a['certified'],
                    'rate' => $a['enrolments'] ? fmt::pct($a['certified'], $a['enrolments']) : '—',
                ]);
            }
            $ds->emptytext = $s('aud_noinstitutions');
            return $ds;
        }
        if ($id === 'matrix') {
            $ufs = [];
            $cells = [];
            foreach ($this->users() as $u) {
                $uf = $u['uf'] === '' ? '—' : $u['uf'];
                $ufs[$uf] = ($ufs[$uf] ?? 0) + 1;
                $cells[$uf][$u['segment']] = ($cells[$uf][$u['segment']] ?? 0) + 1;
            }
            arsort($ufs);
            $cols = ['uf' => $s('col_uf')];
            foreach (self::SEGMENTS as $seg) {
                $cols[$seg] = $s('segment_' . $seg);
            }
            $cols['total'] = $s('col_total');
            $ds = new dataset('matrix', $s('aud_matrix'), $cols, array_merge(self::SEGMENTS, ['total']));
            foreach ($ufs as $uf => $n) {
                $row = ['uf' => $uf, 'total' => $n, '_html' => []];
                foreach (self::SEGMENTS as $seg) {
                    $v = (int)($cells[$uf][$seg] ?? 0);
                    $row[$seg] = $v;
                    // Orange from light (#fff4e0) to dark (#b35f00) by the share of the UF in the segment.
                    $ratio = $v / max(1, $n);
                    $mix = fn(int $a, int $b) => (int)round($a + ($b - $a) * $ratio);
                    $row['_html'][$seg] = $v ? \html_writer::span(
                        fmt::int($v),
                        'cea-heatcell',
                        ['style' => 'background: rgb(' . $mix(255, 179) . ', ' . $mix(244, 95) . ', ' . $mix(224, 0) . '); ' .
                            'color: ' .
                            ($ratio > 0.5 ? '#fff' : '#6b3d00')]
                    ) : \html_writer::span('·', 'cea-muted');
                }
                $ds->add($row);
            }
            return $ds;
        }
        if ($id === 'jobtitles') {
            $ds = new dataset('jobtitles', $s('aud_jobtitles'), [
                'jobtitle' => $s('col_jobtitle_raw'), 'segment' => $s('col_segment'), 'students' => $s('col_students'),
            ], ['students']);
            $agg = [];
            foreach ($this->users() as $u) {
                if (!in_array($u['segment'], ['other', 'notinformed'])) {
                    continue;
                }
                $label = $u['jobtitle'] === '' ? $s('aud_nojobtitle') : $u['jobtitle'];
                $k = \local_ceduanalytics\local\br::normalise($label);
                $agg[$k] = $agg[$k] ?? ['label' => $label, 'segment' => $u['segment'], 'n' => 0];
                $agg[$k]['n']++;
            }
            uasort($agg, fn($a, $b) => $b['n'] <=> $a['n']);
            foreach ($agg as $a) {
                $ds->add(['jobtitle' => $a['label'], 'segment' => $s('segment_' . $a['segment']), 'students' => $a['n']]);
            }
            $ds->emptytext = $s('aud_alljobsclassified');
            return $ds;
        }
        return null;
    }
}
