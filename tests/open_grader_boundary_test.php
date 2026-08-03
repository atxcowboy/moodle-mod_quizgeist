<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the open-answer grading boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use quizgeistaddon_ai\local\ai\open_response_grader;

defined('MOODLE_INTERNAL') || die();

/**
 * Model objections remain field-addressable, while the rule path is hard valid.
 *
 * @covers \quizgeistaddon_ai\local\ai\open_response_grader
 */
final class open_grader_boundary_test extends \advanced_testcase {

    public function test_boundary_honest_is_per_element_and_objections_are_addressable(): void {
        $this->resetAfterTest(true);

        $result = open_response_grader::assess(
            'Eine Photosynthese braucht Licht und Wasser.',
            [11 => 'Licht und Wasser werden benötigt.', 12 => 'Nur Wärme.'],
            static function (string $prompt): array {
                return [
                    'assessments' => [
                        [
                            'answerId' => 11,
                            'score' => 80,
                            'feedback' => 'Licht und Wasser sind enthalten.',
                        ],
                        [
                            'answerId' => 999,
                            'score' => 101,
                            'feedback' => '',
                        ],
                    ],
                ];
            }
        );

        $this->assertSame('gateway', $result['origin']);
        $this->assertTrue($result['boundaryHonest']);
        $this->assertCount(2, $result['assessments']);
        foreach ($result['assessments'] as $assessment) {
            $this->assertSame(
                $assessment['validationErrors'] === [],
                $assessment['valid']
            );
            foreach ($assessment['validationErrors'] as $error) {
                $this->assertIsString($error['field'] ?? null);
                $this->assertNotSame('', $error['field'] ?? '');
                $this->assertIsString($error['code'] ?? null);
                $this->assertNotSame('', $error['code'] ?? '');
            }
        }
        $this->assertFalse($result['assessments'][1]['valid']);
        $this->assertContains(
            ['field' => 'assessments.1.answerId', 'code' => 'unknown'],
            $result['assessments'][1]['validationErrors']
        );
    }

    public function test_rule_path_is_hard_valid(): void {
        $this->resetAfterTest(true);

        $result = open_response_grader::assess(
            'Eine Photosynthese braucht Licht und Wasser.',
            [11 => 'Licht und Wasser werden benötigt.']
        );

        $this->assertSame('fallback', $result['origin']);
        $this->assertTrue($result['boundaryHonest']);
        $this->assertNotEmpty($result['assessments']);
        foreach ($result['assessments'] as $assessment) {
            $this->assertTrue($assessment['valid']);
            $this->assertSame([], $assessment['validationErrors']);
        }
    }

    public function test_boundary_honest_rejects_both_false_equivalence_directions(): void {
        $this->resetAfterTest(true);

        $envelope = new \ReflectionMethod(open_response_grader::class, 'envelope');
        $envelope->setAccessible(true);

        $valid_with_errors = $envelope->invoke(
            null,
            'gateway',
            [[
                'valid' => true,
                'validationErrors' => [['field' => 'score', 'code' => 'invalid']],
            ]],
            [11 => 'Antwort'],
            'Musterlösung',
            []
        );
        $this->assertFalse($valid_with_errors['boundaryHonest']);

        $invalid_without_errors = $envelope->invoke(
            null,
            'gateway',
            [[
                'valid' => false,
                'validationErrors' => [],
            ]],
            [11 => 'Antwort'],
            'Musterlösung',
            []
        );
        $this->assertFalse($invalid_without_errors['boundaryHonest']);

        $honest = $envelope->invoke(
            null,
            'fallback',
            [[
                'valid' => true,
                'validationErrors' => [],
            ]],
            [11 => 'Antwort'],
            'Musterlösung',
            []
        );
        $this->assertTrue($honest['boundaryHonest']);
    }
}
