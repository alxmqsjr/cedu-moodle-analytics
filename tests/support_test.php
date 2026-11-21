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

use local_ceduanalytics\local\support;

/**
 * Tests of the inactivity queue: reasons, levels, follow-up status and message placeholders.
 *
 * @package    local_ceduanalytics
 * @copyright  2026 Alexandre Marques Almeida Junior
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ceduanalytics\local\support
 */
final class support_test extends \advanced_testcase {
    /** @var int Fixed "now" for the cases. */
    const NOW = 1790000000;

    /**
     * Enrolment of an active student in a course running now (overridable).
     *
     * @param array $fields
     * @return \stdClass
     */
    protected static function enrolment(array $fields): \stdClass {
        return (object)($fields + [
            'isstudent' => 1, 'enrolactive' => 1, 'situation' => 'ontrack', 'timestart' => 0, 'timeend' => 0,
            'timeenrolled' => self::NOW - 60 * DAYSECS, 'lastaccess' => self::NOW - DAYSECS, 'progress' => 50,
            'coursestart' => self::NOW - 60 * DAYSECS, 'courseend' => self::NOW + 60 * DAYSECS,
        ]);
    }

    /**
     * Reason and level of each case.
     *
     * @dataProvider classify_provider
     * @param array $fields
     * @param array|null $expected
     */
    public function test_classify(array $fields, ?array $expected): void {
        $this->assertSame($expected, support::classify(self::enrolment($fields), self::NOW, 14));
    }

    /**
     * Cases of the queue.
     *
     * @return array
     */
    public static function classify_provider(): array {
        $now = self::NOW;
        return [
            'em dia' => [[], null],
            'não é estudante' => [['isstudent' => 0, 'situation' => 'inactive'], null],
            'matrícula suspensa' => [['enrolactive' => 0, 'situation' => 'inactive'], null],
            'certificado' => [['situation' => 'certified'], null],
            'curso encerrado' => [['situation' => 'inactive', 'courseend' => $now - DAYSECS], null],
            'curso não começou' => [['situation' => 'notstarted', 'coursestart' => $now + DAYSECS], null],
            'concluiu sem certificado' => [['situation' => 'completed'], ['nocertificate', 'medium']],
            'inativo recente' => [['situation' => 'inactive', 'lastaccess' => $now - 20 * DAYSECS], ['inactive', 'high']],
            'inativo há 60 dias' => [['situation' => 'inactive', 'lastaccess' => $now - 60 * DAYSECS], ['inactive', 'medium']],
            'inativo há 120 dias' => [['situation' => 'inactive', 'lastaccess' => $now - 120 * DAYSECS], ['inactive', 'low']],
            'nunca acessou, 3 dias' => [['situation' => 'notstarted', 'timeenrolled' => $now - 3 * DAYSECS,
                'coursestart' => $now - 3 * DAYSECS], ['neveraccessed', 'low']],
            'nunca acessou, 10 dias' => [['situation' => 'notstarted', 'timeenrolled' => $now - 10 * DAYSECS,
                'coursestart' => $now - 10 * DAYSECS], ['neveraccessed', 'high']],
            'nunca acessou, 60 dias' => [['situation' => 'notstarted'], ['neveraccessed', 'medium']],
            'abaixo do ritmo' => [['situation' => 'attention'], ['belowpace', 'medium']],
            'abaixo do ritmo, fim perto' => [['situation' => 'attention', 'courseend' => $now + 10 * DAYSECS],
                ['belowpace', 'high']],
            'fim em 20 dias' => [['courseend' => $now + 20 * DAYSECS], ['endingsoon', 'medium']],
            'fim em 5 dias' => [['courseend' => $now + 5 * DAYSECS], ['endingsoon', 'high']],
            'fim em 5 dias com 100%' => [['courseend' => $now + 5 * DAYSECS, 'progress' => 100], null],
            'fim da matrícula vale' => [['timeend' => $now + 5 * DAYSECS], ['endingsoon', 'high']],
        ];
    }

    /**
     * A follow-up only applies while the reason is the same.
     */
    public function test_status(): void {
        $f = (object)['reason' => 'inactive', 'status' => 'contacted'];
        $this->assertSame('contacted', support::status($f, 'inactive'));
        $this->assertSame('open', support::status($f, 'belowpace'));
        $this->assertSame('open', support::status(null, 'inactive'));
    }

    /**
     * Message placeholders; course names are plain text.
     */
    public function test_fill(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ana', 'lastname' => 'Souza']);
        $c1 = $this->getDataGenerator()->create_course(['fullname' => 'Riscos & Desastres']);
        $c2 = $this->getDataGenerator()->create_course(['fullname' => 'Clima']);
        $text = support::fill('{firstname}|{fullname}|{course}|{courseurl}|{sender}', $user, [$c1, $c2], 0);
        $parts = explode('|', $text);
        $this->assertSame('Ana', $parts[0]);
        $this->assertSame('Ana Souza', $parts[1]);
        $this->assertSame('Riscos & Desastres, Clima', $parts[2]);
        $this->assertStringContainsString('/course/view.php?id=' . $c1->id, $parts[3]);
        $this->assertSame(fullname(get_admin()), $parts[4]);
        $this->assertSame('', support::fill('{enddate}', $user, [$c1], 0));
    }
}
