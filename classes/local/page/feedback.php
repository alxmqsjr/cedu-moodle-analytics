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

use local_ceduanalytics\local\br;
use local_ceduanalytics\local\charts;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * Feedback: results of every feedback activity (mod_feedback), grouped into instruments.
 *
 * Instances with the same name (copied across editions) form one instrument, and their questions are
 * matched by text. Answers are aggregated only: responses are never linked to users, so anonymous
 * activities stay anonymous. The only breakdown is a question of the instrument itself (e.g. the
 * region the respondent declares), never the user profile.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback extends base {
    /** @var string Instrument key */
    protected $instrument;
    /** @var string Breakdown option (label of the split question) */
    protected $split;
    /** @var array|null */
    protected $data = null;

    /**
     * Constructor.
     *
     * @param array $def
     * @param \local_ceduanalytics\local\filters $filters
     */
    public function __construct(array $def, \local_ceduanalytics\local\filters $filters) {
        parent::__construct($def, $filters);
        $this->instrument = optional_param('instrument', '', PARAM_ALPHANUMEXT);
        $this->split = optional_param('split', '', PARAM_TEXT);
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        return array_filter(['instrument' => $this->instrument, 'split' => $this->split]);
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'instances' => get_string('fb_instances', 'local_ceduanalytics'),
            'answers' => get_string('fb_answers', 'local_ceduanalytics'),
            'bysplit' => get_string('fb_bysplit', 'local_ceduanalytics'),
        ];
    }

    /**
     * Options of a choice item, from its presentation.
     *
     * @param string $presentation
     * @return array [mode (r radio, c checkbox, d dropdown), options (1-based index => label)]
     */
    public static function options(string $presentation): array {
        $mode = '';
        if (preg_match('/^([rcd])>>>>>/', $presentation, $m)) {
            $mode = $m[1];
            $presentation = substr($presentation, 6);
        }
        $presentation = preg_replace('/<<<<<.*$/s', '', $presentation);
        $out = [];
        $i = 1;
        foreach (explode('|', $presentation) as $opt) {
            $opt = trim(preg_replace('/^\s*-?\d+(\.\d+)?####/', '', $opt));
            $out[$i++] = $opt;
        }
        return [$mode, $out];
    }

    /**
     * Instruments, questions and aggregated answers in scope.
     *
     * @return array
     */
    protected function data(): array {
        global $DB;
        if ($this->data !== null) {
            return $this->data;
        }
        $scope = new scope($this->filters);
        $courseids = $scope->course_ids();
        $where = 'f.course <> :site';
        $params = ['site' => SITEID];
        if ($courseids !== null) {
            if (!$courseids) {
                $courseids = [0];
            }
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $where .= " AND f.course $insql";
            $params += $inparams;
        }
        $instances = $DB->get_records_sql("
            SELECT f.id, f.course, f.name, f.anonymous, c.fullname AS coursename, cm.id AS cmid
              FROM {feedback} f
              JOIN {course} c ON c.id = f.course
              JOIN {modules} md ON md.name = 'feedback'
         LEFT JOIN {course_modules} cm ON cm.instance = f.id AND cm.module = md.id
             WHERE $where
          ORDER BY f.name, c.fullname", $params);
        $instruments = [];
        foreach ($instances as $f) {
            $key = substr(md5(br::normalise($f->name)), 0, 10);
            $instruments[$key] = $instruments[$key] ?? ['key' => $key, 'name' => fmt::text($f->name), 'instances' => [],
                'questions' => [], 'responses' => 0, 'anonymous' => true];
            $instruments[$key]['instances'][$f->id] = $f;
            $instruments[$key]['anonymous'] = $instruments[$key]['anonymous'] && (int)$f->anonymous === 1;
        }
        // Responses per instance (period).
        $resp = [];
        $total = [];
        $last = [];
        if ($instances) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($instances), SQL_PARAMS_NAMED);
            foreach (
                $DB->get_records_sql("
                    SELECT feedback, COUNT(1) AS n, MAX(timemodified) AS last,
                           SUM(CASE WHEN timemodified >= :from AND timemodified < :to THEN 1 ELSE 0 END) AS inperiod
                      FROM {feedback_completed}
                     WHERE feedback $insql
                  GROUP BY feedback", $inparams + ['from' => $scope->from, 'to' => $scope->to]) as $r
            ) {
                $resp[$r->feedback] = (int)$r->inperiod;
                $total[$r->feedback] = (int)$r->n;
                $last[$r->feedback] = (int)$r->last;
            }
        }
        foreach ($instruments as $key => &$ins) {
            foreach ($ins['instances'] as $fid => $f) {
                $ins['responses'] += $resp[$fid] ?? 0;
            }
        }
        unset($ins);
        uasort($instruments, fn($a, $b) => $b['responses'] <=> $a['responses']);
        if ($this->instrument === '' || !isset($instruments[$this->instrument])) {
            $this->instrument = (string)array_key_first($instruments);
        }
        $current = $instruments[$this->instrument] ?? null;

        // Questions and answers of the current instrument.
        $questions = [];
        $answers = [];
        $splitq = null;
        if ($current) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($current['instances']), SQL_PARAMS_NAMED);
            $items = $DB->get_records_sql("
                SELECT id, feedback, name, typ, presentation, position, hasvalue
                  FROM {feedback_item}
                 WHERE feedback $insql AND hasvalue = 1
              ORDER BY position, id", $inparams);
            $itemq = [];
            foreach ($items as $it) {
                $qkey = substr(md5(br::normalise($it->name) . '|' . $it->typ), 0, 10);
                if (!isset($questions[$qkey])) {
                    [$mode, $opts] = in_array($it->typ, ['multichoice', 'multichoicerated'])
                        ? self::options($it->presentation) : ['', []];
                    $questions[$qkey] = ['key' => $qkey, 'name' => fmt::text($it->name), 'typ' => $it->typ, 'mode' => $mode,
                        'options' => $opts, 'position' => (int)$it->position];
                }
                $itemq[$it->id] = $qkey;
            }
            // The split question: a single-choice question whose options are the Brazilian regions.
            foreach ($questions as $q) {
                $norm = array_map([br::class, 'normalise'], $q['options']);
                if ($q['mode'] === 'r' && in_array('nordeste', $norm) && in_array('sudeste', $norm)) {
                    $splitq = $q['key'];
                    break;
                }
            }
            if ($itemq) {
                [$iinsql, $iparams] = $DB->get_in_or_equal(array_keys($itemq), SQL_PARAMS_NAMED);
                $rs = $DB->get_recordset_sql(
                    "
                    SELECT v.id, v.item, v.completed, v.value
                      FROM {feedback_value} v
                      JOIN {feedback_completed} c ON c.id = v.completed
                     WHERE v.item $iinsql AND c.timemodified >= :from AND c.timemodified < :to",
                    $iparams + ['from' => $scope->from, 'to' => $scope->to]
                );
                foreach ($rs as $v) {
                    $answers[(int)$v->completed][$itemq[$v->item]] = (string)$v->value;
                }
                $rs->close();
            }
        }
        $this->data = ['instruments' => $instruments, 'current' => $current, 'questions' => $questions,
            'answers' => $answers, 'splitq' => $splitq, 'resp' => $resp, 'total' => $total, 'last' => $last];
        return $this->data;
    }

    /**
     * Selected option indexes of an answer.
     *
     * @param string $value
     * @return int[]
     */
    protected static function chosen(string $value): array {
        return array_values(array_filter(array_map('intval', explode('|', $value)), fn($i) => $i > 0));
    }

    /**
     * Answers of the current breakdown (all when no split option is selected).
     *
     * @param string|null $splitlabel
     * @return array completed id => [question key => value]
     */
    protected function answers(?string $splitlabel = null): array {
        $d = $this->data();
        $splitlabel = $splitlabel ?? $this->split;
        if ($splitlabel === '' || !$d['splitq']) {
            return $d['answers'];
        }
        $q = $d['questions'][$d['splitq']];
        $index = array_search($splitlabel, $q['options']);
        return array_filter($d['answers'], fn($a) => isset($a[$d['splitq']]) && in_array($index, self::chosen($a[$d['splitq']])));
    }

    /**
     * Option counts of a question over a set of answers.
     *
     * @param array $q
     * @param array $answers
     * @return array [answered, counts (index => n), other (non-choice answers)]
     */
    protected static function count(array $q, array $answers): array {
        $answered = 0;
        $counts = array_fill_keys(array_keys($q['options']), 0);
        $other = 0;
        foreach ($answers as $a) {
            if (!isset($a[$q['key']]) || $a[$q['key']] === '') {
                continue;
            }
            if (!$q['options']) {
                $other++;
                $answered++;
                continue;
            }
            $sel = self::chosen($a[$q['key']]);
            if (!$sel) {
                continue;
            }
            $answered++;
            foreach ($sel as $i) {
                if (isset($counts[$i])) {
                    $counts[$i]++;
                }
            }
        }
        return [$answered, $counts, $other];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $d = $this->data();
        if (!$d['instruments']) {
            return $this->empty_state('fa-comment-dots', $s('fb_none'), $s('fb_none_text'));
        }
        $current = $d['current'];
        $answers = $this->answers();
        $totalresp = array_sum(array_column($d['instruments'], 'responses'));

        $kpis = [
            $this->kpi(
                'fb_activities',
                fmt::int(array_sum(array_map(fn($i) => count($i['instances']), $d['instruments']))),
                null,
                null,
                '',
                false,
                $s('fb_instruments_note', fmt::int(count($d['instruments'])))
            ),
            $this->kpi('fb_responses', fmt::int($totalresp)),
            $this->kpi(
                'fb_current',
                fmt::int(count($answers)),
                null,
                null,
                '',
                fmt::lowconf(count($answers)),
                $current['name'] . ($this->split !== '' ? ' · ' . $this->split : '')
            ),
        ];

        $instruments = [];
        foreach ($d['instruments'] as $key => $ins) {
            $instruments[] = ['label' => $ins['name'] . ' (' . fmt::int($ins['responses']) . ')',
                'active' => $key === $this->instrument,
                'url' => $this->url(['instrument' => $key, 'split' => ''])->out(false)];
        }
        $splits = [];
        if ($d['splitq']) {
            $q = $d['questions'][$d['splitq']];
            $splits[] = ['label' => $s('all_female'), 'active' => $this->split === '', 'count' => fmt::int(count($d['answers'])),
                'url' => $this->url(['split' => ''])->out(false)];
            foreach ($q['options'] as $opt) {
                $splits[] = ['label' => $opt, 'active' => $this->split === $opt, 'count' => fmt::int(count($this->answers($opt))),
                    'url' => $this->url(['split' => $opt])->out(false)];
            }
        }

        $questions = [];
        $n = 0;
        foreach ($d['questions'] as $q) {
            $n++;
            [$answered, $counts, $other] = self::count($q, $answers);
            $items = [];
            foreach ($q['options'] as $i => $label) {
                $items[] = ['label' => $label, 'value' => $counts[$i]];
            }
            // Text answers are shown as they were written, never with the name of who answered.
            $texts = [];
            if (!$q['options']) {
                foreach ($answers as $a) {
                    $value = trim(strip_tags((string)($a[$q['key']] ?? '')));
                    if ($value !== '') {
                        $texts[] = ['text' => $value];
                    }
                }
                shuffle($texts);
            }
            $questions[] = [
                'number' => $n,
                'name' => $q['name'],
                'multiple' => $q['mode'] === 'c',
                'answered' => $s('fb_answered', fmt::int($answered)),
                'lowconf' => $answered > 0 && fmt::lowconf($answered),
                'bars' => $q['options'] ? charts::bars($items, $answered, 'orange') : null,
                'haschoices' => (bool)$q['options'],
                'texts' => $texts,
                'hastexts' => !empty($texts),
                'textnote' => $q['options'] ? '' : $s('fb_textanswers', fmt::int($other)),
                'issplit' => $q['key'] === $d['splitq'],
            ];
        }

        return $this->render('feedback', [
            'kpis' => $kpis,
            'instruments' => $instruments,
            'hasmanyinstruments' => count($instruments) > 1,
            'current' => $current['name'],
            'anonymous' => $current['anonymous'],
            'splits' => $splits,
            'hassplits' => !empty($splits),
            'questions' => $questions,
            'hasanswers' => !empty($answers),
            'noanswers' => $this->empty_state('fa-comment-slash', $s('fb_noanswers'), $s('fb_noanswers_text')),
            'bysplit' => $this->get_dataset('bysplit')->export_for_template(),
            'hasbysplit' => (bool)$d['splitq'],
            'instances' => $this->get_dataset('instances')->export_for_template(),
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
        $d = $this->data();
        if ($id === 'instances') {
            $ds = new dataset('instances', $s('fb_instances'), [
                'name' => $s('col_activity'), 'course' => $s('col_course'), 'anonymous' => $s('col_anonymous'),
                'inperiod' => $s('col_responsesperiod'), 'total' => $s('col_responsestotal'), 'last' => $s('col_lastresponse'),
            ], ['inperiod', 'total']);
            foreach ($d['instruments'] as $ins) {
                foreach ($ins['instances'] as $f) {
                    $name = fmt::text($f->name);
                    $course = fmt::text($f->coursename);
                    $ds->add([
                        'name' => $name, 'course' => $course,
                        'anonymous' => (int)$f->anonymous === 1 ? $s('yes_') : $s('no_'),
                        'inperiod' => $d['resp'][$f->id] ?? 0, 'total' => $d['total'][$f->id] ?? 0,
                        'last' => isset($d['last'][$f->id]) ? fmt::date($d['last'][$f->id]) : '—',
                        '_html' => [
                            'name' => $f->cmid ? \html_writer::link(new \moodle_url(
                                '/mod/feedback/analysis.php',
                                ['id' => $f->cmid]
                            ), s($name)) : s($name),
                            'course' => \local_ceduanalytics\local\coursestats::link(
                                (int)$f->course,
                                $course,
                                $this->filters->params()
                            ),
                        ],
                        '_class' => empty($d['total'][$f->id]) ? 'cea-row-muted' : '',
                    ]);
                }
            }
            return $ds;
        }
        if ($id === 'answers') {
            $ds = new dataset('answers', $s('fb_answers'), [
                'question' => $s('col_question'), 'option' => $s('col_option'), 'count' => $s('col_count'),
                'pct' => $s('col_pctrespondents'), 'answered' => $s('col_answered'),
            ], ['count', 'pct', 'answered']);
            $answers = $this->answers();
            foreach ($d['questions'] as $q) {
                [$answered, $counts, $other] = self::count($q, $answers);
                if (!$q['options']) {
                    // Each text answer in its own row, without the respondent.
                    foreach ($answers as $a) {
                        $value = trim(strip_tags((string)($a[$q['key']] ?? '')));
                        if ($value !== '') {
                            $ds->add(['question' => $q['name'], 'option' => $value, 'count' => 1, 'pct' => '',
                                'answered' => $answered]);
                        }
                    }
                }
                foreach ($q['options'] as $i => $label) {
                    $ds->add(['question' => $q['name'], 'option' => $label, 'count' => $counts[$i],
                        'pct' => fmt::pct($counts[$i], $answered), 'answered' => $answered]);
                }
            }
            return $ds;
        }
        if ($id === 'bysplit') {
            // One row per split option; one column per single-choice question: share of its first option
            // ("Sim" in the research activity), or the most chosen option for multiple choice.
            $cols = ['split' => $s('col_region'), 'n' => $s('col_respondents')];
            $qs = [];
            foreach ($d['questions'] as $q) {
                if ($q['key'] === $d['splitq'] || !$q['options']) {
                    continue;
                }
                $qs[] = $q;
                $cols[$q['key']] = $q['name'];
            }
            $ds = new dataset('bysplit', $s('fb_bysplit'), $cols, array_merge(['n'], array_column($qs, 'key')));
            if (!$d['splitq']) {
                return $ds;
            }
            $labels = array_merge([''], array_values($d['questions'][$d['splitq']]['options']));
            foreach ($labels as $label) {
                $answers = $this->answers($label);
                $row = ['split' => $label === '' ? $s('all_female') : $label, 'n' => count($answers), '_html' => []];
                foreach ($qs as $q) {
                    [$answered, $counts] = self::count($q, $answers);
                    if (!$answered) {
                        $row[$q['key']] = '—';
                        continue;
                    }
                    if ($q['mode'] === 'c' || br::normalise($q['options'][1] ?? '') !== 'sim') {
                        arsort($counts);
                        $top = array_key_first($counts);
                        $row[$q['key']] = $q['options'][$top] . ' (' . fmt::pct($counts[$top], $answered) . ')';
                    } else {
                        $row[$q['key']] = $q['options'][1] . ': ' . fmt::pct($counts[1], $answered);
                    }
                    if (fmt::lowconf($answered)) {
                        $row['_html'][$q['key']] = s($row[$q['key']]) . ' ' . \html_writer::span(
                            '*',
                            'cea-inferred',
                            ['title' => $s('lowconf_help')]
                        );
                    }
                }
                $row['_class'] = $label === '' ? 'cea-row-total' : '';
                $ds->add($row);
            }
            return $ds;
        }
        return null;
    }
}
