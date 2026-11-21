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
 * Derived user attributes: state (UF) with its source, city, institution, job title and segment.
 *
 * State sources, in order: declared state field, municipality (IBGE list), INEP school code and telephone
 * area code (DDD). The source is always stored and shown.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attributes {
    /** @var string[] Audience segments, in display order. */
    const SEGMENTS = ['schoolmanagement', 'teacher', 'student', 'mobiliser', 'civildefence', 'socialassistance',
        'research', 'other', 'notinformed'];

    /** @var string[] Profile field settings => default shortname. */
    const FIELDS = [
        'field_uf' => 'escola_estado',
        'field_city' => 'escola_cidade',
        'field_inep' => 'inep',
        'field_phone' => 'telefone',
        'field_institution' => 'organizacao',
        'field_jobtitle' => 'cargo',
    ];

    /** @var array Default segment rules: [pattern, segment]. Patterns are matched on the normalised job title. */
    const DEFAULT_RULES = [
        ['vice dire', 'schoolmanagement'], ['vice-dire', 'schoolmanagement'], ['diretor', 'schoolmanagement'],
        ['diretora', 'schoolmanagement'], ['direcao', 'schoolmanagement'], ['gestor', 'schoolmanagement'],
        ['coordenador', 'schoolmanagement'], ['coordenadora', 'schoolmanagement'], ['supervisor', 'schoolmanagement'],
        ['secretari', 'schoolmanagement'], ['agente de organizacao escolar', 'schoolmanagement'],
        ['auxiliar de secretaria', 'schoolmanagement'], ['assistente de apoio escolar', 'schoolmanagement'],
        ['professor', 'teacher'], ['professora', 'teacher'], ['docente', 'teacher'], ['pedagog', 'teacher'],
        ['educador', 'teacher'], ['monitor', 'teacher'], ['tutor', 'teacher'],
        ['estudante', 'student'], ['aluno', 'student'], ['aluna', 'student'], ['discente', 'student'],
        ['mobilizador', 'mobiliser'], ['mobilizadora', 'mobiliser'],
        ['defesa civil', 'civildefence'], ['protecao civil', 'civildefence'], ['bombeiro', 'civildefence'],
        ['assistente social', 'socialassistance'], ['assistencia social', 'socialassistance'], ['cras', 'socialassistance'],
        ['pesquisador', 'research'], ['pesquisadora', 'research'], ['bolsista', 'research'], ['cientista', 'research'],
    ];

    /**
     * Seed the default segment rules (install time).
     */
    public static function seed_rules(): void {
        global $DB;
        if ($DB->record_exists('local_ceduanalytics_segrule', [])) {
            return;
        }
        $i = 0;
        foreach (self::DEFAULT_RULES as [$pattern, $segment]) {
            $DB->insert_record('local_ceduanalytics_segrule', (object)['pattern' => $pattern, 'segment' => $segment,
                'sortorder' => ++$i * 10]);
        }
    }

    /**
     * Set the default profile field mapping, when those fields exist (install time).
     */
    public static function seed_field_mapping(): void {
        global $DB;
        foreach (self::FIELDS as $setting => $shortname) {
            if (
                get_config('local_ceduanalytics', $setting) === false &&
                    $DB->record_exists('user_info_field', ['shortname' => $shortname])
            ) {
                set_config($setting, $shortname, 'local_ceduanalytics');
            }
        }
    }

    /**
     * Segment of a job title according to the rules.
     *
     * @param string $jobtitle
     * @param \stdClass[] $rules ordered by sortorder
     * @return array [segment, rule id]
     */
    public static function segment_of(string $jobtitle, array $rules): array {
        $norm = br::normalise($jobtitle);
        if ($norm === '') {
            return ['notinformed', 0];
        }
        foreach ($rules as $rule) {
            $pattern = br::normalise($rule->pattern);
            if ($pattern !== '' && strpos($norm, $pattern) !== false) {
                return [$rule->segment, (int)$rule->id];
            }
        }
        return ['other', 0];
    }

    /**
     * Rebuild the attributes of every user.
     *
     * @param \progress_trace|null $trace
     * @return int Number of users processed
     */
    public static function derive(?\progress_trace $trace = null): int {
        global $DB;
        $now = time();
        $rules = $DB->get_records('local_ceduanalytics_segrule', null, 'sortorder, id');

        // Profile values by user for the mapped fields.
        $fieldids = [];
        foreach (array_keys(self::FIELDS) as $setting) {
            $shortname = (string)get_config('local_ceduanalytics', $setting);
            if ($shortname !== '' && ($id = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]))) {
                $fieldids[$setting] = (int)$id;
            }
        }
        $profile = [];
        if ($fieldids) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($fieldids));
            $settingbyid = array_flip($fieldids);
            $rs = $DB->get_recordset_select('user_info_data', "fieldid $insql", $params, '', 'id, userid, fieldid, data');
            foreach ($rs as $r) {
                $profile[$r->userid][$settingbyid[$r->fieldid]] = trim((string)$r->data);
            }
            $rs->close();
        }

        $existing = $DB->get_records_menu('local_ceduanalytics_userattr', null, '', 'userid, id');
        $transaction = $DB->start_delegated_transaction();
        $count = 0;
        $inserts = [];
        $rs = $DB->get_recordset_select('user', 'deleted = 0 AND id > 1', null, '', 'id, city, institution');
        foreach ($rs as $u) {
            $p = $profile[$u->id] ?? [];
            $uf = '';
            $source = '';
            // Order: declared state, municipality (IBGE list; homonyms resolved by the phone or INEP state),
            // school INEP code, phone area code (DDD). A phone may belong to another state, so it comes last.
            $phoneuf = br::uf_from_phone($p['field_phone'] ?? '');
            $inepuf = br::uf_from_inep($p['field_inep'] ?? '');
            $citytext = ($p['field_city'] ?? '') ?: (string)$u->city;
            if (($v = br::uf_from_text($p['field_uf'] ?? '')) !== '') {
                [$uf, $source] = [$v, 'declared'];
            } else if (($v = br::uf_from_city($citytext, $phoneuf ?: $inepuf)) !== '') {
                [$uf, $source] = [$v, 'city'];
            } else if ($inepuf !== '') {
                [$uf, $source] = [$inepuf, 'inep'];
            } else if ($phoneuf !== '') {
                [$uf, $source] = [$phoneuf, 'phone'];
            }
            $city = trim(($p['field_city'] ?? '') ?: (string)$u->city);
            $jobtitle = $p['field_jobtitle'] ?? '';
            [$segment, $ruleid] = self::segment_of($jobtitle, $rules);
            $row = (object)[
                'userid' => (int)$u->id,
                'uf' => $uf,
                'ufsource' => $source,
                'city' => \core_text::substr($city, 0, 255),
                'institution' => \core_text::substr(trim(($p['field_institution'] ?? '') ?: (string)$u->institution), 0, 255),
                'jobtitle' => \core_text::substr($jobtitle, 0, 255),
                'segment' => $segment,
                'segmentruleid' => $ruleid,
                'timemodified' => $now,
            ];
            if (isset($existing[$u->id])) {
                $row->id = $existing[$u->id];
                $DB->update_record('local_ceduanalytics_userattr', $row);
                unset($existing[$u->id]);
            } else {
                $inserts[] = $row;
            }
            $count++;
        }
        $rs->close();
        if ($inserts) {
            $DB->insert_records('local_ceduanalytics_userattr', $inserts);
        }
        foreach (array_chunk(array_values($existing), 500) as $chunk) {
            $DB->delete_records_list('local_ceduanalytics_userattr', 'id', $chunk);
        }
        set_config('lastattributes', $now, 'local_ceduanalytics');
        $transaction->allow_commit();
        \cache::make('local_ceduanalytics', 'indicators')->purge();
        if ($trace) {
            $trace->output("Attributes: {$count} users.");
        }
        return $count;
    }
}
