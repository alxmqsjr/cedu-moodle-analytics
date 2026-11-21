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
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\scope;

/**
 * Materials and videos: are the contents being used?
 *
 * "Opened" means the student viewed the activity at least once (from the aggregated log). The log does
 * not record whether an embedded video was played, only that the page holding it was opened.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class materials extends base {
    /** @var array|null */
    protected $data = null;

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'items' => get_string('mat_items', 'local_ceduanalytics'),
            'videos' => get_string('mat_videos', 'local_ceduanalytics'),
            'playback' => get_string('mat_playback', 'local_ceduanalytics'),
            'types' => get_string('mat_types', 'local_ceduanalytics'),
        ];
    }

    /**
     * Every module of the courses in scope with students, opening figures and embedded videos.
     *
     * @return array ['items' => list, 'students' => courseid => n]
     */
    protected function data(): array {
        global $DB;
        if ($this->data !== null) {
            return $this->data;
        }
        $scope = new scope($this->filters);
        $students = [];
        foreach ($scope->enrolments('e.id, e.courseid, e.userid', '', [], '', false) as $r) {
            $students[(int)$r->courseid] = ($students[(int)$r->courseid] ?? 0) + 1;
        }
        $items = [];
        if (!$students) {
            return $this->data = ['items' => [], 'students' => []];
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'mc');
        [$uw, $up] = $scope->user_where();
        $views = $DB->get_records_sql("
            SELECT v.cmid, COUNT(DISTINCT v.userid) AS users, SUM(v.views) AS views, MAX(v.lastview) AS lastview
              FROM {local_ceduanalytics_cmview} v
              JOIN {local_ceduanalytics_enrolstate} e ON e.userid = v.userid AND e.courseid = v.courseid AND e.isstudent = 1
         LEFT JOIN {local_ceduanalytics_userattr} ua ON ua.userid = v.userid
             WHERE v.courseid $in" . ($uw ? " AND $uw" : '') . "
          GROUP BY v.cmid", $params + $up);

        // Embedded YouTube/Vimeo videos in pages and book chapters.
        $videos = [];
        $pattern = '~(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=)|youtu\.be/|player\.vimeo\.com/video/)([A-Za-z0-9_-]{6,})~';
        foreach (
            $DB->get_records_sql("SELECT cm.id AS cmid, p.content FROM {page} p
                JOIN {modules} m ON m.name = 'page'
                JOIN {course_modules} cm ON cm.instance = p.id AND cm.module = m.id
               WHERE p.course $in", $params) as $r
        ) {
            if (preg_match_all($pattern, (string)$r->content, $m)) {
                $videos[(int)$r->cmid] = array_values(array_unique($m[1]));
            }
        }
        if ($DB->get_manager()->table_exists('book_chapters')) {
            foreach (
                $DB->get_records_sql("SELECT bc.id, cm.id AS cmid, bc.content FROM {book_chapters} bc
                    JOIN {book} b ON b.id = bc.bookid
                    JOIN {modules} m ON m.name = 'book'
                    JOIN {course_modules} cm ON cm.instance = b.id AND cm.module = m.id
                   WHERE b.course $in", $params) as $r
            ) {
                if (preg_match_all($pattern, (string)$r->content, $m)) {
                    $videos[(int)$r->cmid] = array_values(array_unique(array_merge($videos[(int)$r->cmid] ?? [], $m[1])));
                }
            }
        }

        foreach (array_keys($students) as $courseid) {
            $modinfo = get_fast_modinfo($courseid, -1);
            $course = $modinfo->get_course();
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->deletioninprogress) {
                    continue;
                }
                $v = $views[$cm->id] ?? null;
                $items[] = [
                    'courseid' => $courseid,
                    'course' => fmt::text($course->fullname),
                    'section' => get_section_name($course, $cm->sectionnum),
                    'cmid' => $cm->id,
                    'name' => fmt::plain($cm->get_formatted_name()),
                    'modname' => $cm->modname,
                    'type' => (string)$cm->get_module_type_name(),
                    'visible' => (bool)$cm->visible,
                    'url' => $cm->url ? $cm->url->out(false) : '',
                    'users' => (int)($v->users ?? 0),
                    'views' => (int)($v->views ?? 0),
                    'lastview' => (int)($v->lastview ?? 0),
                    'students' => $students[$courseid],
                    'videos' => $videos[$cm->id] ?? [],
                ];
            }
        }
        return $this->data = ['items' => $items, 'students' => $students];
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $str = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $d = $this->data();
        $items = array_filter($d['items'], fn($i) => $i['modname'] !== 'label');
        $visible = array_filter($items, fn($i) => $i['visible']);
        $never = array_filter($visible, fn($i) => $i['users'] === 0);
        $videoitems = array_filter($items, fn($i) => $i['videos']);
        $videoids = [];
        $videoopen = 0;
        $videostudents = 0;
        foreach ($videoitems as $i) {
            foreach ($i['videos'] as $vid) {
                $videoids[$vid] = true;
            }
            $videoopen += $i['users'];
            $videostudents += $i['students'];
        }
        $openrate = 0;
        $openbase = 0;
        foreach ($visible as $i) {
            $openrate += $i['users'];
            $openbase += $i['students'];
        }

        $kpis = [
            $this->kpi(
                'mat_items',
                fmt::int(count($items)),
                null,
                null,
                '',
                false,
                $str('mat_visible', fmt::int(count($visible)))
            ),
            $this->kpi('mat_openrate', fmt::pct($openrate, $openbase), null, null, '', fmt::lowconf($openbase)),
            $this->kpi('mat_never', fmt::int(count($never)), null, null, '', false, fmt::pct(count($never), count($visible))),
            $this->kpi(
                'mat_videos',
                fmt::int(array_sum(array_map(fn($i) => count($i['videos']), $videoitems))),
                null,
                null,
                '',
                false,
                $str('mat_distinct', fmt::int(count($videoids)))
            ),
            $this->kpi(
                'mat_videoopen',
                fmt::pct($videoopen, $videostudents),
                null,
                null,
                '',
                fmt::lowconf($videostudents),
                $str('mat_videopages', fmt::int(count($videoitems)))
            ),
        ];

        $playback = $this->get_dataset('playback');
        $players = array_sum(array_map(fn($r) => (int)$r['played'], $playback->rows));
        $kpis[] = $this->kpi(
            'mat_videoplayed',
            fmt::int($players),
            null,
            null,
            '',
            false,
            $str('mat_videoplayed_note', fmt::int(array_sum(array_map(fn($r) => (int)$r['completed'], $playback->rows))))
        );

        $types = $this->get_dataset('types');
        $bars = [];
        foreach ($types->rows as $row) {
            $bars[] = ['label' => $row['type'], 'value' => (int)$row['count']];
        }
        return $this->render('materials', [
            'kpis' => $kpis,
            'typebars' => charts::bars($bars, count($items), 'orange'),
            'types' => $types->export_for_template(),
            'items' => $this->get_dataset('items')->export_for_template(),
            'videos' => $this->get_dataset('videos')->export_for_template(),
            'playback' => $playback->export_for_template(),
            'trackingon' => \local_ceduanalytics\local\videos::enabled(),
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
        $items = array_filter($this->data()['items'], fn($i) => $i['modname'] !== 'label');
        if ($id === 'types') {
            $ds = new dataset('types', $str('mat_types'), [
                'type' => $str('col_modtype'), 'count' => $str('col_count'), 'visible' => $str('col_visiblecount'),
                'never' => $str('col_neveropened'), 'openrate' => $str('col_openrate'),
            ], ['count', 'visible', 'never', 'openrate']);
            $by = [];
            foreach ($items as $i) {
                $t = &$by[$i['type']];
                $t['count'] = ($t['count'] ?? 0) + 1;
                $t['visible'] = ($t['visible'] ?? 0) + ($i['visible'] ? 1 : 0);
                $t['never'] = ($t['never'] ?? 0) + ($i['visible'] && !$i['users'] ? 1 : 0);
                $t['users'] = ($t['users'] ?? 0) + ($i['visible'] ? $i['users'] : 0);
                $t['students'] = ($t['students'] ?? 0) + ($i['visible'] ? $i['students'] : 0);
                unset($t);
            }
            uasort($by, fn($a, $b) => $b['count'] <=> $a['count']);
            foreach ($by as $type => $t) {
                $ds->add(['type' => $type, 'count' => $t['count'], 'visible' => $t['visible'], 'never' => $t['never'],
                    'openrate' => fmt::pct($t['users'], $t['students'])]);
            }
            return $ds;
        }
        if ($id === 'items') {
            $ds = new dataset('items', $str('mat_items'), [
                'course' => $str('col_course'), 'section' => $str('col_section'), 'activity' => $str('col_activity'),
                'type' => $str('col_modtype'), 'opened' => $str('col_openedby'), 'pct' => $str('col_openrate'),
                'views' => $str('col_views'), 'last' => $str('col_lastview'),
            ], ['opened', 'pct', 'views']);
            usort($items, fn($a, $b) => [$a['course'], $a['users']] <=> [$b['course'], $b['users']]);
            foreach ($items as $i) {
                $ds->add([
                    'course' => $i['course'], 'section' => $i['section'], 'activity' => $i['name'], 'type' => $i['type'],
                    'opened' => $i['users'], 'pct' => fmt::pct($i['users'], $i['students']), 'views' => $i['views'],
                    'last' => fmt::date($i['lastview']),
                    '_html' => [
                        'course' => coursestats::link($i['courseid'], $i['course'], $this->filters->params()),
                        'activity' => ($i['url'] ? \html_writer::link($i['url'], s($i['name'])) : s($i['name'])) .
                            ($i['visible'] ? '' : ' ' . \html_writer::span($str('visible_no'), 'cea-badge cea-badge-soft')) .
                            ($i['videos'] ? ' ' . \html_writer::span(
                                '▶ ' . count($i['videos']),
                                'cea-badge cea-badge-video',
                                ['title' => $str('mat_hasvideo')]
                            ) : ''),
                        'opened' => $i['visible'] && !$i['users'] ? \html_writer::span('0', 'cea-stopped') : (string)$i['users'],
                    ],
                ]);
            }
            $ds->emptytext = $str('mat_empty');
            return $ds;
        }
        if ($id === 'playback') {
            $ds = new dataset('playback', $str('mat_playback'), [
                'course' => $str('col_course'), 'activity' => $str('col_activity'), 'video' => $str('col_video'),
                'duration' => $str('col_duration'), 'opened' => $str('col_openedby'), 'played' => $str('col_played'),
                'avg' => $str('col_avgwatched'), 'completed' => $str('col_watched90'), 'plays' => $str('col_plays'),
                'clicks' => $str('col_linkclicks'),
            ], ['duration', 'opened', 'played', 'avg', 'completed', 'plays', 'clicks']);
            $stats = \local_ceduanalytics\local\videos::stats(new \local_ceduanalytics\local\scope($this->filters));
            $bycm = [];
            foreach ($items as $i) {
                $bycm[$i['cmid']] = $i;
            }
            foreach ($items as $i) {
                foreach ($i['videos'] as $vid) {
                    $stats[$i['cmid'] . '|' . $vid] = $stats[$i['cmid'] . '|' . $vid] ?? ['cmid' => $i['cmid'], 'videoid' => $vid,
                        'duration' => 0, 'players' => 0, 'plays' => 0, 'linkclicks' => 0, 'pctsum' => 0.0, 'completed' => 0,
                        'clickers' => 0];
                }
            }
            uasort($stats, fn($a, $b) => [$b['players'], $b['clickers']] <=> [$a['players'], $a['clickers']]);
            foreach ($stats as $st) {
                $i = $bycm[$st['cmid']] ?? null;
                if (!$i) {
                    continue;
                }
                $avg = $st['players'] ? $st['pctsum'] / $st['players'] * 100 : null;
                $ds->add([
                    'course' => $i['course'], 'activity' => $i['name'], 'video' => $st['videoid'],
                    'duration' => $st['duration'] ? gmdate($st['duration'] >= 3600 ? 'H:i:s' : 'i:s', $st['duration']) : '—',
                    'opened' => $i['users'], 'played' => $st['players'],
                    'avg' => $avg === null ? '—' : fmt::percent($avg), 'completed' => $st['completed'],
                    'plays' => $st['plays'], 'clicks' => $st['linkclicks'],
                    '_html' => [
                        'activity' => $i['url'] ? \html_writer::link($i['url'], s($i['name'])) : s($i['name']),
                        'video' => \html_writer::link(
                            'https://www.youtube.com/watch?v=' . $st['videoid'],
                            s($st['videoid']),
                            ['target' => '_blank', 'rel' => 'noopener']
                        ),
                    ],
                    '_class' => $st['players'] || $st['clickers'] ? '' : 'cea-row-muted',
                ]);
            }
            $ds->emptytext = $str('mat_novideos');
            return $ds;
        }
        if ($id === 'videos') {
            $ds = new dataset('videos', $str('mat_videos'), [
                'course' => $str('col_course'), 'activity' => $str('col_activity'), 'type' => $str('col_modtype'),
                'videos' => $str('col_videocount'), 'opened' => $str('col_openedby'), 'pct' => $str('col_openrate'),
                'ids' => $str('col_videoids'),
            ], ['videos', 'opened', 'pct']);
            $list = array_filter($items, fn($i) => $i['videos']);
            usort($list, fn($a, $b) => [$a['course'], $a['section']] <=> [$b['course'], $b['section']]);
            foreach ($list as $i) {
                $ds->add([
                    'course' => $i['course'], 'activity' => $i['name'], 'type' => $i['type'], 'videos' => count($i['videos']),
                    'opened' => $i['users'], 'pct' => fmt::pct($i['users'], $i['students']),
                    'ids' => implode(' ', $i['videos']),
                    '_html' => [
                        'activity' => $i['url'] ? \html_writer::link($i['url'], s($i['name'])) : s($i['name']),
                        'ids' => implode(' ', array_map(fn($v) => \html_writer::link(
                            'https://www.youtube.com/watch?v=' . $v,
                            s($v),
                            ['target' => '_blank', 'rel' => 'noopener']
                        ), $i['videos'])),
                    ],
                ]);
            }
            $ds->emptytext = $str('mat_novideos');
            return $ds;
        }
        return null;
    }
}
