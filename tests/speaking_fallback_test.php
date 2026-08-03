<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for deterministic speaking feedback without AI.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use quizgeistaddon_ai\local\ai\speaking_coach;

defined('MOODLE_INTERNAL') || die();

/**
 * The fallback is a complete round, not an empty error response.
 *
 * @covers \quizgeistaddon_ai\local\ai\speaking_coach
 */
final class speaking_fallback_test extends \advanced_testcase {

    public function test_same_input_without_ai_has_identical_feedback(): void {
        $this->resetAfterTest(true);

        $first = speaking_coach::turn(
            'Die Photosynthese braucht Licht und Wasser.',
            ['Licht Wasser Kohlenstoffdioxid'],
            'Erkläre die Photosynthese.'
        );
        $second = speaking_coach::turn(
            'Die Photosynthese braucht Licht und Wasser.',
            ['Licht Wasser Kohlenstoffdioxid'],
            'Erkläre die Photosynthese.'
        );

        $this->assertSame($first, $second);
        $this->assertSame('fallback', $first['origin']);
        $this->assertTrue($first['valid']);
        $this->assertNotSame('', $first['feedback']);
        $this->assertNotSame('', $first['nextPrompt']);
    }

    public function test_gateway_failure_uses_the_same_deterministic_round(): void {
        $this->resetAfterTest(true);

        $fallback = speaking_coach::turn('Wasser kocht bei 100 Grad.', ['100 Grad'], 'Nenne den Siedepunkt.');
        $failed = speaking_coach::turn(
            'Wasser kocht bei 100 Grad.',
            ['100 Grad'],
            'Nenne den Siedepunkt.',
            static function (): array {
                throw new \RuntimeException('gateway unavailable');
            }
        );

        $this->assertSame('fallback', $failed['origin']);
        $this->assertSame($fallback['score'], $failed['score']);
        $this->assertSame($fallback['feedback'], $failed['feedback']);
        $this->assertSame($fallback['nextPrompt'], $failed['nextPrompt']);
        $this->assertContains('gateway_unavailable', $failed['warnings']);
    }
}
