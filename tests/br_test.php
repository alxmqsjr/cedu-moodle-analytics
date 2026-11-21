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

namespace local_ceduanalytics;

use local_ceduanalytics\local\attributes;
use local_ceduanalytics\local\br;

/**
 * Tests of the state (UF) inference and of the audience segments.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\br
 * @covers     \local_ceduanalytics\local\attributes
 */
final class br_test extends \advanced_testcase {
    /**
     * Normalised text for matching.
     */
    public function test_normalise(): void {
        $this->assertSame('sao paulo', br::normalise('  São   Paulo '));
        $this->assertSame('', br::normalise(null));
        $this->assertSame('professora educacao basica', br::normalise('Professora (Educação Básica)'));
    }

    /**
     * State written by the person.
     *
     * @dataProvider text_provider
     * @param string|null $text
     * @param string $uf
     */
    public function test_uf_from_text(?string $text, string $uf): void {
        $this->assertSame($uf, br::uf_from_text($text));
    }

    /**
     * Cases of the declared state.
     *
     * @return array
     */
    public static function text_provider(): array {
        return [
            'sigla minúscula' => ['sp', 'SP'],
            'nome com acento' => ['São Paulo', 'SP'],
            'nome sem acento' => ['Distrito federal', 'DF'],
            'sigla no fim' => ['Rio de Janeiro - RJ', 'RJ'],
            'barra' => ['Niterói/RJ', 'RJ'],
            'inválido' => ['XX', ''],
            'vazio' => ['', ''],
            'nulo' => [null, ''],
        ];
    }

    /**
     * Municipality: unique names, "City/UF" and homonyms resolved by the hint.
     */
    public function test_uf_from_city(): void {
        $this->assertSame('SP', br::uf_from_city('São José dos Campos'));
        $this->assertSame('SP', br::uf_from_city('cachoeira paulista'));
        $this->assertSame('RJ', br::uf_from_city('Qualquer/RJ'));
        $this->assertSame('MG', br::uf_from_city('Belo Horizonte (MG)'));
        $this->assertSame('', br::uf_from_city('Bom Jesus'));
        $this->assertSame('RS', br::uf_from_city('Bom Jesus', 'RS'));
        $this->assertSame('', br::uf_from_city('Bom Jesus', 'SP'));
        $this->assertSame('', br::uf_from_city('Cidade que não existe'));
        $this->assertSame('', br::uf_from_city(''));
    }

    /**
     * INEP school code: the first two digits are the IBGE state code.
     */
    public function test_uf_from_inep(): void {
        $this->assertSame('SP', br::uf_from_inep('35012345'));
        $this->assertSame('DF', br::uf_from_inep('53-000.001'));
        $this->assertSame('', br::uf_from_inep('3501234'));
        $this->assertSame('', br::uf_from_inep('99012345'));
        $this->assertSame('', br::uf_from_inep(null));
    }

    /**
     * Telephone area code (DDD), with or without country code.
     */
    public function test_uf_from_phone(): void {
        $this->assertSame('SP', br::uf_from_phone('(11) 98765-4321'));
        $this->assertSame('DF', br::uf_from_phone('+55 61 3333-4444'));
        $this->assertSame('RJ', br::uf_from_phone('021 2222-3333'));
        $this->assertSame('', br::uf_from_phone('3333-4444'));
        $this->assertSame('', br::uf_from_phone(null));
    }

    /**
     * State names and regions.
     */
    public function test_names(): void {
        $this->assertSame('Distrito Federal', br::state_name('DF'));
        $this->assertSame('ZZ', br::state_name('ZZ'));
        $this->assertSame('SE', br::region('SP'));
        $this->assertSame('CO', br::region('DF'));
        $this->assertSame('', br::region('ZZ'));
    }

    /**
     * Job title to audience segment: first rule that matches, in order.
     */
    public function test_segment_of(): void {
        $rules = [
            (object)['id' => 1, 'pattern' => 'professor', 'segment' => 'teacher'],
            (object)['id' => 2, 'pattern' => 'Defesa Civil', 'segment' => 'civildefence'],
        ];
        $this->assertSame(['teacher', 1], attributes::segment_of('Professora de Geografia', $rules));
        $this->assertSame(['civildefence', 2], attributes::segment_of('Agente da defesa civil', $rules));
        $this->assertSame(['other', 0], attributes::segment_of('Engenheiro', $rules));
        $this->assertSame(['notinformed', 0], attributes::segment_of('  ', $rules));
    }
}
