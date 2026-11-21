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
 * Support queue: who needs support now, why and how urgently; follow-up actions.
 *
 * Only active student enrolments in running courses enter the queue:
 *  - inactive: no course access for N days (high up to 2×N days away, medium up to 90, low after);
 *  - neveraccessed: never accessed the course after it started (low in the first 7 days, high up to 30,
 *    medium up to 90, low after);
 *  - belowpace: progress below the expected pace (high when the enrolment ends in 14 days);
 *  - endingsoon: enrolment or course ends in 30 days without completion (high in 7 days);
 *  - nocertificate: 100% of the activities without certificate (medium).
 *
 * Priority favours recent cases: contact works best right after the student starts to drift away.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class support {
    /** @var string[] Reasons, in display order. */
    const REASONS = ['inactive', 'neveraccessed', 'belowpace', 'endingsoon', 'nocertificate'];

    /** @var string[] Levels, most urgent first. */
    const LEVELS = ['high', 'medium', 'low'];

    /** @var string[] Follow-up statuses ('open' = no follow-up record or a new reason). */
    const STATUSES = ['open', 'contacted', 'resolved', 'dismissed'];

    /** @var string[] Message templates (strings tpl_<id> and tpl_<id>_text). */
    const TEMPLATES = ['inactive', 'neveraccessed', 'belowpace', 'endingsoon', 'nocertificate', 'free'];

    /**
     * Reason and level of an enrolment, or null when it does not need support.
     *
     * @param \stdClass $e enrolstate row with coursestart and courseend
     * @param int $now
     * @param int $inactivedays
     * @return array|null [reason, level]
     */
    public static function classify(\stdClass $e, int $now, int $inactivedays): ?array {
        if (empty($e->isstudent) || empty($e->enrolactive) || $e->situation === 'certified') {
            return null;
        }
        $end = (int)$e->timeend ?: (int)$e->courseend;
        $start = (int)$e->timestart ?: ((int)$e->coursestart ?: (int)$e->timeenrolled);
        if (($end && $end < $now) || ($start && $start > $now)) {
            return null;
        }
        $daysleft = $end ? ($end - $now) / DAYSECS : null;
        switch ($e->situation) {
            case 'completed':
                return ['nocertificate', 'medium'];
            case 'inactive':
                // Recent dropouts first: the earlier the contact, the better the chance of return.
                $away = ($now - (int)$e->lastaccess) / DAYSECS;
                return ['inactive', $away < 2 * $inactivedays ? 'high' : ($away < 90 ? 'medium' : 'low')];
            case 'notstarted':
                $since = ($now - max($start, (int)$e->timeenrolled)) / DAYSECS;
                return ['neveraccessed', $since < 7 ? 'low' : ($since < 30 ? 'high' : ($since < 90 ? 'medium' : 'low'))];
            case 'attention':
                return ['belowpace', ($daysleft !== null && $daysleft <= 14) ? 'high' : 'medium'];
        }
        if ($daysleft !== null && $daysleft <= 30 && (float)$e->progress < 100) {
            return ['endingsoon', $daysleft <= 7 ? 'high' : 'medium'];
        }
        return null;
    }

    /**
     * Fields needed by classify() (aliases e and c).
     *
     * @return string
     */
    public static function fields(): string {
        return 'e.id, e.userid, e.courseid, e.isstudent, e.enrolactive, e.situation, e.timestart, e.timeend, e.timeenrolled,
                e.lastaccess, e.firstaccess, e.progress, e.cmdone, e.cmtotal, c.startdate AS coursestart, c.enddate AS courseend';
    }

    /**
     * Follow-up records.
     *
     * @return array "userid|courseid" => record
     */
    public static function followups(): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_ceduanalytics_followup') as $f) {
            $out[$f->userid . '|' . $f->courseid] = $f;
        }
        return $out;
    }

    /**
     * Effective follow-up status: a record only applies while the reason is the same.
     *
     * @param \stdClass|null $f
     * @param string $reason
     * @return string
     */
    public static function status(?\stdClass $f, string $reason): string {
        if (!$f || $f->reason !== $reason) {
            return 'open';
        }
        return $f->status;
    }

    /**
     * Number of open or contacted cases in the whole platform (menu counter).
     *
     * @return int
     */
    public static function count_open(): int {
        global $DB;
        $now = time();
        $inactivedays = max(1, (int)get_config('local_ceduanalytics', 'inactivedays') ?: 14);
        $followups = self::followups();
        $n = 0;
        $rs = $DB->get_recordset_sql('SELECT ' . self::fields() . '
              FROM {local_ceduanalytics_enrolstate} e
              JOIN {course} c ON c.id = e.courseid
             WHERE e.isstudent = 1 AND e.enrolactive = 1');
        foreach ($rs as $e) {
            $c = self::classify($e, $now, $inactivedays);
            if ($c && in_array(self::status($followups[$e->userid . '|' . $e->courseid] ?? null, $c[0]), ['open', 'contacted'])) {
                $n++;
            }
        }
        $rs->close();
        return $n;
    }

    /**
     * Replace the placeholders of a message template.
     *
     * @param string $text
     * @param \stdClass $user
     * @param \stdClass[] $courses
     * @param int $end Enrolment or course end
     * @return string
     */
    public static function fill(string $text, \stdClass $user, array $courses, int $end): string {
        $first = reset($courses);
        $names = array_map(
            fn($c) => trim(fmt::text($c->fullname, ['context' => \context_course::instance($c->id)])),
            $courses
        );
        return strtr($text, [
            '{firstname}' => $user->firstname,
            '{fullname}' => fullname($user),
            '{course}' => implode(', ', $names),
            '{courseurl}' => $first ? (new \moodle_url('/course/view.php', ['id' => $first->id]))->out(false) : '',
            '{enddate}' => $end ? userdate($end, get_string('strftimedate', 'langconfig')) : '',
            '{sender}' => fullname(people::get($GLOBALS['USER']->id)),
        ]);
    }

    /**
     * Parse the selected "userid_courseid" keys into enrolments that exist in the snapshot.
     *
     * @param string[] $keys
     * @return array[] list of ['userid', 'courseid', 'reason']
     */
    public static function parse_selection(array $keys): array {
        global $DB;
        $out = [];
        $now = time();
        $inactivedays = max(1, (int)get_config('local_ceduanalytics', 'inactivedays') ?: 14);
        foreach (array_unique($keys) as $key) {
            if (!preg_match('/^(\d+)_(\d+)$/', $key, $m)) {
                continue;
            }
            $e = $DB->get_record_sql('SELECT ' . self::fields() . '
                  FROM {local_ceduanalytics_enrolstate} e
                  JOIN {course} c ON c.id = e.courseid
                 WHERE e.userid = :uid AND e.courseid = :cid AND e.isstudent = 1', ['uid' => $m[1], 'cid' => $m[2]]);
            if (!$e) {
                continue;
            }
            $c = self::classify($e, $now, $inactivedays);
            $out[] = ['userid' => (int)$e->userid, 'courseid' => (int)$e->courseid, 'reason' => $c ? $c[0] : 'other',
                'end' => (int)$e->timeend ?: (int)$e->courseend];
        }
        return $out;
    }

    /**
     * Group a selection by student (one message per student).
     *
     * @param array[] $selection
     * @return array userid => ['user' => \stdClass, 'courses' => \stdClass[], 'items' => array[], 'end' => int]
     */
    public static function by_student(array $selection): array {
        global $DB;
        $out = [];
        foreach ($selection as $s) {
            if (!isset($out[$s['userid']])) {
                $user = \core_user::get_user($s['userid']);
                if (!$user || $user->deleted) {
                    continue;
                }
                $out[$s['userid']] = ['user' => $user, 'courses' => [], 'items' => [], 'end' => 0];
            }
            $out[$s['userid']]['courses'][$s['courseid']] = $DB->get_record(
                'course',
                ['id' => $s['courseid']],
                'id, fullname, shortname'
            );
            $out[$s['userid']]['items'][] = $s;
            if ($s['end'] && (!$out[$s['userid']]['end'] || $s['end'] < $out[$s['userid']]['end'])) {
                $out[$s['userid']]['end'] = $s['end'];
            }
        }
        return $out;
    }

    /**
     * Send a private message (Message API) from the current user to each selected student.
     *
     * @param array[] $selection
     * @param string $text Template with placeholders
     * @return array ['sent' => int, 'failed' => string[] names]
     */
    public static function send_messages(array $selection, string $text): array {
        global $USER;
        $result = ['sent' => 0, 'failed' => []];
        foreach (self::by_student($selection) as $uid => $g) {
            $body = self::fill($text, $g['user'], $g['courses'], $g['end']);
            try {
                if ((int)$uid === (int)$USER->id) {
                    throw new \moodle_exception('cannotmessageself', 'local_ceduanalytics');
                }
                $convid = \core_message\api::get_conversation_between_users([$USER->id, $uid]);
                if (!$convid) {
                    $convid = \core_message\api::create_conversation(
                        \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                        [$USER->id, $uid]
                    )->id;
                }
                \core_message\api::send_message_to_conversation($USER->id, $convid, $body, FORMAT_MOODLE);
                $result['sent']++;
                foreach ($g['items'] as $item) {
                    self::record_contact($item, 'message');
                }
            } catch (\Throwable $ex) {
                $result['failed'][] = fullname($g['user']);
            }
        }
        return $result;
    }

    /**
     * Record a note (core_notes) about each selected enrolment, in its course.
     *
     * @param array[] $selection
     * @param string $text Template with placeholders
     * @param string $state NOTES_STATE_PUBLIC (course) or NOTES_STATE_DRAFT (personal)
     * @return array ['sent' => int, 'failed' => string[]]
     */
    public static function add_notes(array $selection, string $text, string $state): array {
        global $CFG;
        require_once($CFG->dirroot . '/notes/lib.php');
        $result = ['sent' => 0, 'failed' => []];
        foreach (self::by_student($selection) as $g) {
            foreach ($g['items'] as $item) {
                $note = new \stdClass();
                $note->courseid = $item['courseid'];
                $note->userid = $item['userid'];
                $note->content = self::fill($text, $g['user'], [$g['courses'][$item['courseid']]], $item['end']);
                $note->format = FORMAT_PLAIN;
                $note->publishstate = $state;
                if (note_save($note)) {
                    $result['sent']++;
                    self::record_contact($item, 'note');
                } else {
                    $result['failed'][] = fullname($g['user']);
                }
            }
        }
        return $result;
    }

    /**
     * Update the follow-up after a contact.
     *
     * @param array $item ['userid', 'courseid', 'reason']
     * @param string $kind message|note
     */
    protected static function record_contact(array $item, string $kind): void {
        global $DB, $USER;
        $now = time();
        $f = $DB->get_record('local_ceduanalytics_followup', ['userid' => $item['userid'], 'courseid' => $item['courseid']]);
        if (!$f) {
            $f = (object)['userid' => $item['userid'], 'courseid' => $item['courseid'], 'assignedto' => $USER->id,
                'contacts' => 0, 'timecreated' => $now];
        }
        $changed = !isset($f->reason) || $f->reason !== $item['reason'];
        $f->reason = $item['reason'];
        if ($changed || !isset($f->status) || in_array($f->status, ['open', 'contacted', ''])) {
            $f->status = 'contacted';
        }
        if (empty($f->assignedto)) {
            $f->assignedto = $USER->id;
        }
        $f->contacts++;
        $f->lastcontact = $now;
        $f->lastkind = $kind;
        $f->usermodified = $USER->id;
        $f->timemodified = $now;
        if (empty($f->id)) {
            $DB->insert_record('local_ceduanalytics_followup', $f);
        } else {
            $DB->update_record('local_ceduanalytics_followup', $f);
        }
    }

    /**
     * Set the status and/or the responsible of the selected cases.
     *
     * @param array[] $selection
     * @param string $status '' to keep, 'open' to reopen
     * @param int $assignedto -1 to keep, 0 to clear
     * @return int Cases updated
     */
    public static function set_status(array $selection, string $status, int $assignedto): int {
        global $DB, $USER;
        $now = time();
        $n = 0;
        foreach ($selection as $item) {
            $f = $DB->get_record('local_ceduanalytics_followup', ['userid' => $item['userid'], 'courseid' => $item['courseid']]);
            if (!$f) {
                $f = (object)['userid' => $item['userid'], 'courseid' => $item['courseid'], 'assignedto' => 0, 'contacts' => 0,
                    'lastcontact' => 0, 'lastkind' => '', 'status' => 'open', 'timecreated' => $now];
            }
            $f->reason = $item['reason'];
            if ($status !== '') {
                $f->status = $status;
            }
            if ($assignedto >= 0) {
                $f->assignedto = $assignedto;
            }
            $f->usermodified = $USER->id;
            $f->timemodified = $now;
            if (empty($f->id)) {
                $DB->insert_record('local_ceduanalytics_followup', $f);
            } else {
                $DB->update_record('local_ceduanalytics_followup', $f);
            }
            $n++;
        }
        return $n;
    }

    /**
     * Staff who can be responsible for a case: site administrators and holders of contactstudents.
     *
     * @return array userid => name
     */
    public static function responsibles(): array {
        global $CFG;
        $out = [];
        $ids = array_filter(array_map('intval', explode(',', (string)$CFG->siteadmins)));
        $users = get_users_by_capability(\context_system::instance(), 'local/ceduanalytics:contactstudents', 'u.id');
        $ids = array_unique(array_merge($ids, array_map('intval', array_keys($users))));
        foreach ($ids as $id) {
            $u = people::get($id);
            if ($u && !$u->deleted && !$u->suspended) {
                $out[$id] = fullname($u);
            }
        }
        \core_collator::asort($out);
        return $out;
    }
}
