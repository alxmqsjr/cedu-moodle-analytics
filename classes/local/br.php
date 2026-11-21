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
 * Brazilian reference data: states (UF), regions, IBGE codes and telephone area codes (DDD).
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques <https://github.com/alxmqsjr>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class br {
    /** @var array UF => [IBGE code, name, region] */
    const STATES = [
        'AC' => [12, 'Acre', 'N'],
        'AL' => [27, 'Alagoas', 'NE'],
        'AP' => [16, 'Amapá', 'N'],
        'AM' => [13, 'Amazonas', 'N'],
        'BA' => [29, 'Bahia', 'NE'],
        'CE' => [23, 'Ceará', 'NE'],
        'DF' => [53, 'Distrito Federal', 'CO'],
        'ES' => [32, 'Espírito Santo', 'SE'],
        'GO' => [52, 'Goiás', 'CO'],
        'MA' => [21, 'Maranhão', 'NE'],
        'MT' => [51, 'Mato Grosso', 'CO'],
        'MS' => [50, 'Mato Grosso do Sul', 'CO'],
        'MG' => [31, 'Minas Gerais', 'SE'],
        'PA' => [15, 'Pará', 'N'],
        'PB' => [25, 'Paraíba', 'NE'],
        'PR' => [41, 'Paraná', 'S'],
        'PE' => [26, 'Pernambuco', 'NE'],
        'PI' => [22, 'Piauí', 'NE'],
        'RJ' => [33, 'Rio de Janeiro', 'SE'],
        'RN' => [24, 'Rio Grande do Norte', 'NE'],
        'RS' => [43, 'Rio Grande do Sul', 'S'],
        'RO' => [11, 'Rondônia', 'N'],
        'RR' => [14, 'Roraima', 'N'],
        'SC' => [42, 'Santa Catarina', 'S'],
        'SP' => [35, 'São Paulo', 'SE'],
        'SE' => [28, 'Sergipe', 'NE'],
        'TO' => [17, 'Tocantins', 'N'],
    ];

    /** @var array DDD => UF */
    const DDD = [
        11 => 'SP', 12 => 'SP', 13 => 'SP', 14 => 'SP', 15 => 'SP', 16 => 'SP', 17 => 'SP', 18 => 'SP', 19 => 'SP',
        21 => 'RJ', 22 => 'RJ', 24 => 'RJ', 27 => 'ES', 28 => 'ES',
        31 => 'MG', 32 => 'MG', 33 => 'MG', 34 => 'MG', 35 => 'MG', 37 => 'MG', 38 => 'MG',
        41 => 'PR', 42 => 'PR', 43 => 'PR', 44 => 'PR', 45 => 'PR', 46 => 'PR',
        47 => 'SC', 48 => 'SC', 49 => 'SC', 51 => 'RS', 53 => 'RS', 54 => 'RS', 55 => 'RS',
        61 => 'DF', 62 => 'GO', 64 => 'GO', 63 => 'TO', 65 => 'MT', 66 => 'MT', 67 => 'MS', 68 => 'AC', 69 => 'RO',
        71 => 'BA', 73 => 'BA', 74 => 'BA', 75 => 'BA', 77 => 'BA', 79 => 'SE',
        81 => 'PE', 87 => 'PE', 82 => 'AL', 83 => 'PB', 84 => 'RN', 85 => 'CE', 88 => 'CE', 86 => 'PI', 89 => 'PI',
        91 => 'PA', 93 => 'PA', 94 => 'PA', 92 => 'AM', 97 => 'AM', 95 => 'RR', 96 => 'AP', 98 => 'MA', 99 => 'MA',
    ];

    /**
     * Lower-case, accent-free, single-spaced version of a text, for matching.
     *
     * @param string|null $text
     * @return string
     */
    public static function normalise(?string $text): string {
        $text = trim(strip_tags((string)$text));
        if ($text === '') {
            return '';
        }
        $text = \core_text::strtolower($text);
        $text = \core_text::specialtoascii($text);
        $text = preg_replace('/[^a-z0-9\/\-]+/', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Interpret a free-text state: "SP", "sp", "São Paulo", "Sao Paulo - SP".
     *
     * @param string|null $text
     * @return string UF or empty string
     */
    public static function uf_from_text(?string $text): string {
        $norm = self::normalise($text);
        if ($norm === '') {
            return '';
        }
        $upper = strtoupper($norm);
        if (strlen($upper) === 2 && isset(self::STATES[$upper])) {
            return $upper;
        }
        foreach (self::STATES as $uf => [$code, $name, $region]) {
            if ($norm === self::normalise($name)) {
                return $uf;
            }
        }
        // Trailing "/UF" or "- UF".
        if (preg_match('/(?:^|[\s\/\-])([a-z]{2})$/', $norm, $m) && isset(self::STATES[strtoupper($m[1])])) {
            return strtoupper($m[1]);
        }
        return '';
    }

    /**
     * State from a municipality name (IBGE list), optionally written as "City/UF" or "City - UF".
     *
     * A name that exists in more than one state is resolved by the hint (e.g. the state of the phone area code)
     * when the hint is one of them; otherwise it gives no state.
     *
     * @param string|null $text
     * @param string $hint UF used to choose among homonyms
     * @return string UF or empty string
     */
    public static function uf_from_city(?string $text, string $hint = ''): string {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^(.*?)\s*[\/\-,(]\s*([A-Za-z]{2})\)?\s*$/', $text, $m) && isset(self::STATES[strtoupper($m[2])])) {
            return strtoupper($m[2]);
        }
        $ufs = brcities::CITIES[self::normalise($text)] ?? '';
        if ($ufs === '') {
            return '';
        }
        $list = explode(',', $ufs);
        if (count($list) === 1) {
            return $list[0];
        }
        return in_array($hint, $list) ? $hint : '';
    }

    /**
     * State from an INEP school code (8 digits, the first two are the IBGE state code).
     *
     * @param string|null $code
     * @return string UF or empty string
     */
    public static function uf_from_inep(?string $code): string {
        $digits = preg_replace('/\D/', '', (string)$code);
        if (strlen($digits) !== 8) {
            return '';
        }
        $ibge = (int)substr($digits, 0, 2);
        foreach (self::STATES as $uf => [$c]) {
            if ($c === $ibge) {
                return $uf;
            }
        }
        return '';
    }

    /**
     * State from a telephone number area code (DDD).
     *
     * @param string|null $phone
     * @return string UF or empty string
     */
    public static function uf_from_phone(?string $phone): string {
        $digits = preg_replace('/\D/', '', (string)$phone);
        if (strlen($digits) >= 12 && strpos($digits, '55') === 0) {
            $digits = substr($digits, 2);
        }
        $digits = ltrim($digits, '0');
        if (strlen($digits) < 10) {
            return '';
        }
        return self::DDD[(int)substr($digits, 0, 2)] ?? '';
    }

    /**
     * State name.
     *
     * @param string $uf
     * @return string
     */
    public static function state_name(string $uf): string {
        return self::STATES[$uf][1] ?? $uf;
    }

    /**
     * Region code (N, NE, CO, SE, S).
     *
     * @param string $uf
     * @return string
     */
    public static function region(string $uf): string {
        return self::STATES[$uf][2] ?? '';
    }
}
