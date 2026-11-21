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

use local_ceduanalytics\local\contacts;
use local_ceduanalytics\local\coursestats;
use local_ceduanalytics\local\dataset;
use local_ceduanalytics\local\fmt;
use local_ceduanalytics\local\indicators;
use local_ceduanalytics\local\scope;
use local_ceduanalytics\local\support as queue;

/**
 * Students who need support: queue by reason and level, contact actions, follow-up and effectiveness.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class support extends base {
    /** @var string */
    protected $reason;
    /** @var string */
    protected $level;
    /** @var string active|open|contacted|resolved|dismissed|all */
    protected $status;
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
        $reason = optional_param('reason', '', PARAM_ALPHA);
        $this->reason = in_array($reason, queue::REASONS) ? $reason : '';
        $level = optional_param('level', '', PARAM_ALPHA);
        $this->level = in_array($level, queue::LEVELS) ? $level : '';
        $status = optional_param('status', 'active', PARAM_ALPHA);
        $this->status = in_array($status, array_merge(queue::STATUSES, ['active', 'all'])) ? $status : 'active';
    }

    /**
     * Page state kept in links.
     *
     * @return array
     */
    public function state_params(): array {
        return array_filter(['reason' => $this->reason, 'level' => $this->level,
            'status' => $this->status === 'active' ? '' : $this->status]);
    }

    /**
     * Datasets.
     *
     * @return array
     */
    public function datasets(): array {
        return [
            'queue' => get_string('sup_queue', 'local_ceduanalytics'),
            'effectiveness' => get_string('sup_effect', 'local_ceduanalytics'),
        ];
    }

    /**
     * Whether the current user can act on cases.
     *
     * @return bool
     */
    protected function canact(): bool {
        return has_capability('local/ceduanalytics:contactstudents', \context_system::instance());
    }

    /**
     * Handle the actions: confirm message/note, change status or responsible.
     */
    public function process_actions(): void {
        $mode = optional_param('mode', '', PARAM_ALPHA);
        if (!in_array($mode, ['confirm', 'apply']) || !confirm_sesskey()) {
            return;
        }
        require_capability('local/ceduanalytics:contactstudents', \context_system::instance());
        $selection = queue::parse_selection(optional_param_array('sel', [], PARAM_ALPHANUMEXT));
        $back = $this->url();
        if (!$selection) {
            redirect($back, get_string('sup_noselection', 'local_ceduanalytics'), null, \core\output\notification::NOTIFY_WARNING);
        }
        if ($mode === 'apply') {
            $status = optional_param('newstatus', '', PARAM_ALPHA);
            $status = in_array($status, queue::STATUSES) ? $status : '';
            $assigned = optional_param('assignedto', -1, PARAM_INT);
            $n = queue::set_status($selection, $status, $assigned);
            \local_ceduanalytics\local\snapshot::update_counters();
            redirect(
                $back,
                get_string('sup_status_done', 'local_ceduanalytics', $n),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        $act = optional_param('act', 'message', PARAM_ALPHA);
        $text = trim(optional_param('text', '', PARAM_TEXT));
        if ($text === '') {
            redirect($back, get_string('sup_notext', 'local_ceduanalytics'), null, \core\output\notification::NOTIFY_WARNING);
        }
        if ($act === 'note') {
            $state = optional_param('notestate', 'public', PARAM_ALPHA) === 'draft' ? 'draft' : 'public';
            $r = queue::add_notes($selection, $text, $state);
            $msg = get_string('sup_notes_done', 'local_ceduanalytics', $r['sent']);
        } else {
            $r = queue::send_messages($selection, $text);
            $msg = get_string('sup_messages_done', 'local_ceduanalytics', $r['sent']);
        }
        \local_ceduanalytics\local\snapshot::update_counters();
        if ($r['failed']) {
            $msg .= ' ' . get_string('sup_failed', 'local_ceduanalytics', implode(', ', $r['failed']));
        }
        redirect($back, $msg, null, $r['failed'] ? \core\output\notification::NOTIFY_WARNING
            : \core\output\notification::NOTIFY_SUCCESS);
    }

    /**
     * All queue items in scope (current moment; the period filter does not apply to the queue).
     *
     * @return array[]
     */
    protected function items(): array {
        if ($this->items !== null) {
            return $this->items;
        }
        $now = time();
        $inactivedays = max(1, (int)get_config('local_ceduanalytics', 'inactivedays') ?: 14);
        $scope = new scope($this->filters);
        $rows = $scope->enrolments(
            queue::fields() . ', c.fullname AS coursename, u.firstname, u.lastname,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename, u.email, ua.uf, ua.ufsource',
            'e.enrolactive = 1',
            [],
            'u.firstname, u.lastname',
            false
        );
        $followups = queue::followups();
        $last = contacts::last_contacts();
        $names = [];
        $this->items = [];
        foreach ($rows as $r) {
            $c = queue::classify($r, $now, $inactivedays);
            if (!$c) {
                continue;
            }
            $f = $followups[$r->userid . '|' . $r->courseid] ?? null;
            $assigned = $f ? (int)$f->assignedto : 0;
            if ($assigned && !isset($names[$assigned])) {
                $u = \local_ceduanalytics\local\people::get($assigned);
                $names[$assigned] = $u ? fullname($u) : '';
            }
            $this->items[] = ['r' => $r, 'reason' => $c[0], 'level' => $c[1], 'status' => queue::status($f, $c[0]),
                'f' => $f, 'assigned' => $assigned ? $names[$assigned] : '', 'last' => $last[$r->userid] ?? null];
        }
        $order = array_flip(queue::LEVELS);
        usort($this->items, fn($a, $b) => [$order[$a['level']], (int)$a['r']->lastaccess] <=>
            [$order[$b['level']], (int)$b['r']->lastaccess]);
        return $this->items;
    }

    /**
     * Items after the reason, level and status chips.
     *
     * @return array[]
     */
    protected function filtered(): array {
        return array_values(array_filter($this->items(), function ($i) {
            if ($this->reason !== '' && $i['reason'] !== $this->reason) {
                return false;
            }
            if ($this->level !== '' && $i['level'] !== $this->level) {
                return false;
            }
            if ($this->status === 'active') {
                return in_array($i['status'], ['open', 'contacted']);
            }
            return $this->status === 'all' || $i['status'] === $this->status;
        }));
    }

    /**
     * Effectiveness of the staff contacts in the period, with students in scope.
     *
     * @return array
     */
    protected function effectiveness(): array {
        $scope = new scope($this->filters);
        return $scope->cached('supeffect', function () use ($scope) {
            $students = [];
            foreach ($scope->enrolments('DISTINCT e.userid') as $r) {
                $students[(int)$r->userid] = true;
            }
            return contacts::effectiveness(contacts::events($scope->from, $scope->to, $students));
        });
    }

    /**
     * Page body.
     *
     * @return string
     */
    public function content(): string {
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $all = $this->items();
        $active = array_filter($all, fn($i) => in_array($i['status'], ['open', 'contacted']));
        $high = count(array_filter($active, fn($i) => $i['level'] === 'high'));
        $nocontact = count(array_filter($active, fn($i) => !$i['last'] || $i['last']['time'] < time() - 30 * DAYSECS));
        $eff = $this->effectiveness();
        $risk = $eff['atrisk'];

        $kpis = [
            $this->kpi(
                'sup_active',
                fmt::int(count($active)),
                null,
                null,
                '',
                false,
                $s('sup_active_note', fmt::int(count($all)))
            ),
            $this->kpi('sup_high', fmt::int($high), null, $this->url(['level' => 'high'])),
            $this->kpi('sup_nocontact', fmt::int($nocontact)),
            $this->kpi(
                'sup_effect',
                $risk['episodes'] ? fmt::pct($risk['returned'], $risk['episodes']) : '—',
                null,
                null,
                '',
                fmt::lowconf($risk['episodes']),
                $s('sup_effect_note', ['n' => fmt::int($risk['episodes']), 'days' => $eff['days']])
            ),
        ];

        // Chips.
        $count = function (string $field, string $value) use ($all) {
            return count(array_filter($all, fn($i) => $i[$field] === $value && in_array($i['status'], ['open', 'contacted'])));
        };
        $reasonchips = [['label' => $s('all_male'), 'url' => $this->url(['reason' => ''])->out(false),
            'active' => $this->reason === '', 'count' => fmt::int(count($active)), 'key' => 'all']];
        foreach (queue::REASONS as $r) {
            $reasonchips[] = ['label' => $s('reason_' . $r), 'help' => $s('reason_' . $r . '_help'), 'key' => $r,
                'url' => $this->url(['reason' => $r])->out(false), 'active' => $this->reason === $r,
                'count' => fmt::int($count('reason', $r))];
        }
        $levelchips = [['label' => $s('all_male'), 'url' => $this->url(['level' => ''])->out(false),
            'active' => $this->level === '', 'key' => 'all', 'count' => fmt::int(count($active))]];
        foreach (queue::LEVELS as $l) {
            $levelchips[] = ['label' => $s('level_' . $l), 'key' => 'lvl-' . $l, 'url' => $this->url(['level' => $l])->out(false),
                'active' => $this->level === $l, 'count' => fmt::int($count('level', $l))];
        }
        $statuschips = [];
        foreach (['active', 'open', 'contacted', 'resolved', 'dismissed', 'all'] as $st) {
            $n = $st === 'all' ? count($all) : ($st === 'active' ? count($active)
                : count(array_filter($all, fn($i) => $i['status'] === $st)));
            $statuschips[] = ['label' => $s('fstatus_' . $st), 'key' => 'st-' . $st,
                'url' => $this->url(['status' => $st === 'active' ? '' : $st])->out(false),
                'active' => $this->status === $st, 'count' => fmt::int($n)];
        }

        $canact = $this->canact();
        $templates = [];
        foreach (queue::TEMPLATES as $t) {
            $templates[] = ['id' => $t, 'label' => $s('tpl_' . $t), 'text' => $s('tpl_' . $t . '_text'),
                'selected' => $t === ($this->reason ?: 'free')];
        }
        $responsibles = [];
        foreach (queue::responsibles() as $id => $name) {
            $responsibles[] = ['id' => $id, 'name' => $name];
        }
        $statusoptions = [];
        foreach (queue::STATUSES as $st) {
            $statusoptions[] = ['id' => $st, 'label' => $s('fstatus_' . $st)];
        }

        $effds = $this->get_dataset('effectiveness');
        $ds = $this->get_dataset('queue');
        return $this->render('support', [
            'kpis' => $kpis,
            'preview' => $canact ? $this->preview() : '',
            'reasonchips' => $reasonchips,
            'levelchips' => $levelchips,
            'statuschips' => $statuschips,
            'table' => $ds->export_for_template(),
            'canact' => $canact,
            'formaction' => $this->url()->out(false),
            'sesskey' => sesskey(),
            'templates' => $templates,
            'responsibles' => $responsibles,
            'statusoptions' => $statusoptions,
            'placeholders' => array_map(
                fn($p) => ['code' => '{' . $p . '}', 'text' => get_string('sup_ph_' . $p, 'local_ceduanalytics')],
                ['firstname', 'fullname', 'course', 'courseurl', 'enddate', 'sender']
            ),
            'effect' => $effds->export_for_template(),
            'effecthelp' => $s('sup_effect_help', $eff['days']),
            'currentuserid' => $GLOBALS['USER']->id,
        ]);
    }

    /**
     * Preview of a message or note before sending (POST mode=preview).
     *
     * @return string HTML ('' when not previewing)
     */
    protected function preview(): string {
        if (optional_param('mode', '', PARAM_ALPHA) !== 'preview' || !confirm_sesskey()) {
            return '';
        }
        $s = fn($k, $a = null) => get_string($k, 'local_ceduanalytics', $a);
        $keys = optional_param_array('sel', [], PARAM_ALPHANUMEXT);
        $selection = queue::parse_selection($keys);
        $text = trim(optional_param('text', '', PARAM_TEXT));
        $act = optional_param('act', 'message', PARAM_ALPHA) === 'note' ? 'note' : 'message';
        $notestate = optional_param('notestate', 'public', PARAM_ALPHA) === 'draft' ? 'draft' : 'public';
        if (!$selection || $text === '') {
            return $this->empty_state(
                'fa-circle-exclamation',
                $s('sup_preview'),
                $selection ? $s('sup_notext') : $s('sup_noselection')
            );
        }
        $groups = queue::by_student($selection);
        $samples = [];
        foreach (array_slice($groups, 0, 3, true) as $g) {
            $samples[] = ['name' => fullname($g['user']),
                'text' => queue::fill($text, $g['user'], $act === 'note' ? [reset($g['courses'])] : $g['courses'], $g['end'])];
        }
        $recipients = [];
        foreach ($groups as $g) {
            $recipients[] = fullname($g['user']) . ' (' . implode(', ', array_map(
                fn($c) => fmt::text($c->fullname),
                $g['courses']
            )) . ')';
        }
        $hidden = [];
        foreach ($keys as $k) {
            $hidden[] = ['name' => 'sel[]', 'value' => $k];
        }
        foreach (
            ['act' => $act, 'text' => $text, 'notestate' => $notestate, 'mode' => 'confirm',
            'sesskey' => sesskey()] as $n => $v
        ) {
            $hidden[] = ['name' => $n, 'value' => $v];
        }
        $count = $act === 'note' ? count($selection) : count($groups);
        return $this->render('support_preview', [
            'title' => $s(
                $act === 'note' ? 'sup_preview_note' : 'sup_preview_message',
                ['n' => fmt::int($count), 'students' => fmt::int(count($groups))]
            ),
            'isnote' => $act === 'note',
            'notestate' => $s('sup_notestate_' . $notestate),
            'samples' => $samples,
            'recipients' => implode('; ', $recipients),
            'hidden' => $hidden,
            'formaction' => $this->url()->out(false),
            'cancelurl' => $this->url()->out(false),
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
        if ($id === 'effectiveness') {
            $eff = $this->effectiveness();
            $ds = new dataset('effectiveness', $s('sup_effect'), [
                'group' => $s('col_contacts'), 'episodes' => $s('col_episodes'), 'returned' => $s('col_returned'),
                'progressed' => $s('col_progressed'),
            ], ['episodes', 'returned', 'progressed']);
            $rows = ['eff_all' => $eff['all'], 'eff_atrisk' => $eff['atrisk']];
            foreach ($eff['bykind'] as $kind => $st) {
                $rows['eff_kind_' . $kind] = $st;
            }
            foreach ($rows as $key => $st) {
                $low = fmt::lowconf($st['episodes']) ? ' ' . \html_writer::span($s('lowconf_short'), 'cea-badge cea-lowconf') : '';
                $ds->add([
                    'group' => $s($key, $eff['days']), 'episodes' => fmt::int($st['episodes']),
                    'returned' => fmt::pct($st['returned'], $st['episodes']),
                    'progressed' => fmt::pct($st['progressed'], $st['episodes']),
                    '_html' => ['group' => s($s($key, $eff['days'])) . $low],
                ]);
            }
            $ds->emptytext = $s('sup_effect_empty');
            if (!$eff['all']['episodes']) {
                $ds->rows = [];
            }
            return $ds;
        }
        if ($id !== 'queue') {
            return null;
        }
        $canact = $this->canact();
        $cols = $canact ? ['select' => $s('select')] : [];
        $cols += [
            'student' => $s('col_student'), 'email' => $s('col_email'), 'course' => $s('col_course'),
            'reason' => $s('col_reason'), 'level' => $s('col_level'), 'lastaccess' => $s('col_lastaccess'),
            'progress' => $s('col_progress'), 'lastcontact' => $s('col_lastcontact'), 'status' => $s('col_fstatus'),
            'assigned' => $s('col_responsible'),
        ];
        $ds = new dataset('queue', $s('sup_queue'), $cols, ['progress']);
        $ds->screenonly = ['select'];
        foreach ($this->filtered() as $i) {
            $r = $i['r'];
            $name = fullname($r);
            $course = fmt::text($r->coursename);
            $last = $i['last'];
            $lastcontact = $last ? fmt::date($last['time']) . ' (' . $s('kind_' . $last['kind']) . ')' : $s('sup_nevercontacted');
            $access = $r->lastaccess ? fmt::date((int)$r->lastaccess) : $s('never');
            $row = [
                'student' => $name, 'email' => $r->email, 'course' => $course,
                'reason' => $s('reason_' . $i['reason']), 'level' => $s('level_' . $i['level']),
                'lastaccess' => $access, 'progress' => fmt::percent($r->progress),
                'lastcontact' => $lastcontact, 'status' => $s('fstatus_' . $i['status']), 'assigned' => $i['assigned'],
                '_html' => [
                    'student' => indicators::student_link((int)$r->userid, $name),
                    'course' => coursestats::link((int)$r->courseid, $course, $this->filters->params()),
                    'reason' => \html_writer::span(
                        s($s('reason_' . $i['reason'])),
                        'cea-badge cea-reason',
                        ['title' => $s('reason_' . $i['reason'] . '_help')]
                    ),
                    'level' => \html_writer::span(s($s('level_' . $i['level'])), 'cea-badge cea-level-' . $i['level']),
                    'assigned' => $i['assigned'] === '' ? '' : s($i['assigned']) .
                        \local_ceduanalytics\local\people::dual_mark((int)$i['f']->assignedto, 'teacher'),
                    'lastaccess' => \html_writer::span(
                        s($access),
                        '',
                        ['title' => $r->lastaccess ? fmt::ago((int)$r->lastaccess) : '']
                    ),
                    'status' => \html_writer::span(
                        s($s('fstatus_' . $i['status'])),
                        'cea-badge cea-fstatus-' . $i['status'],
                        ['title' => $i['f'] && $i['f']->contacts ? $s('sup_contacts_count', $i['f']->contacts) : '']
                    ),
                ],
                '_class' => 'cea-row-' . $i['level'],
            ];
            if ($canact) {
                $key = $r->userid . '_' . $r->courseid;
                $row['select'] = '';
                $row['_html']['select'] = \html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'sel[]',
                    'value' => $key, 'form' => 'cea-support-form', 'class' => 'cea-sel', 'data-region' => 'cea-sel',
                    'aria-label' => $s('select_student', $name)]);
            }
            $ds->add($row);
        }
        $ds->emptytext = $s('sup_empty');
        return $ds;
    }
}
