<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the single response-to-quality mapping.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\schedule\quality_mapper;

defined('MOODLE_INTERNAL') || die();

/**
 * Boundary cases for every answer family understood by quality_mapper.
 */
final class quality_mapper_test extends \advanced_testcase {

    public function test_silent_answer_types_have_no_quality(): void {
        $this->resetAfterTest(true);

        foreach (['view', 'reason', 'scorevoid'] as $answertype) {
            $this->assertNull(
                quality_mapper::from_response(
                    $answertype,
                    true,
                    1.0,
                    100,
                    100,
                    true
                )
            );
        }
    }

    public function test_ungraded_answers_have_no_quality(): void {
        $this->resetAfterTest(true);

        $this->assertNull(
            quality_mapper::from_response('answer', null, 1.0, 100, 100)
        );
    }

    public function test_wrong_accuracy_thresholds_map_to_zero_one_two(): void {
        $this->resetAfterTest(true);

        $cases = [
            [0.0, 0],
            [0.25, 1],
            [0.3, 1],
            [0.5, 2],
            [0.6, 2],
        ];
        foreach ($cases as [$accuracy, $expected]) {
            $this->assertSame(
                $expected,
                quality_mapper::from_response(
                    'answer',
                    false,
                    $accuracy,
                    0,
                    0
                )
            );
        }
    }

    public function test_correct_timing_boundaries_and_untimed_quality(): void {
        $this->resetAfterTest(true);

        $cases = [
            [5000, 10, 5],
            [5001, 10, 4],
            [8500, 10, 4],
            [8501, 10, 3],
            [999999, 0, 5],
        ];
        foreach ($cases as [$responsetimems, $timelimitseconds, $expected]) {
            $this->assertSame(
                $expected,
                quality_mapper::from_response(
                    'answer',
                    true,
                    1.0,
                    $responsetimems,
                    $timelimitseconds
                )
            );
        }
    }

    public function test_a_reason_raises_a_slow_correct_answer_to_four(): void {
        $this->resetAfterTest(true);

        $quality = quality_mapper::from_response(
            'answer',
            true,
            1.0,
            9001,
            10,
            true
        );

        $this->assertGreaterThanOrEqual(4, $quality);
        $this->assertSame(4, $quality);
    }

    public function test_inexact_correct_answers_are_capped_at_four(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            4,
            quality_mapper::from_response('answer', true, 0.99, 100, 10)
        );
    }

    public function test_flashcard_quality_uses_knowledge_and_round(): void {
        $this->resetAfterTest(true);

        $cases = [
            [true, 1, 5],
            [true, 2, 3],
            [false, 1, 1],
            [null, 1, null],
        ];
        foreach ($cases as [$known, $round, $expected]) {
            $this->assertSame(
                $expected,
                quality_mapper::from_response(
                    'flashcard',
                    null,
                    0.0,
                    0,
                    0,
                    false,
                    $known,
                    $round
                )
            );
        }
    }

    public function test_all_qualities_stay_between_zero_and_five(): void {
        $this->resetAfterTest(true);

        $inputs = [
            ['view', null, 0.0, 0, 0, false, null, 1],
            ['answer', false, 0.0, 0, 0, false, null, 1],
            ['answer', false, 0.3, 0, 0, false, null, 1],
            ['answer', false, 0.6, 0, 0, false, null, 1],
            ['answer', true, 1.0, 9001, 10, false, null, 1],
            ['answer', true, 1.0, 100, 10, false, null, 1],
            ['answer', true, 1.0, 100, 0, false, null, 1],
            ['flashcard', null, 0.0, 0, 0, false, true, 1],
            ['flashcard', null, 0.0, 0, 0, false, true, 2],
            ['flashcard', null, 0.0, 0, 0, false, false, 1],
            ['flashcard', null, 0.0, 0, 0, false, null, 1],
        ];
        foreach ($inputs as $input) {
            $quality = quality_mapper::from_response(...$input);
            if ($quality === null) {
                continue;
            }
            $this->assertIsInt($quality);
            $this->assertGreaterThanOrEqual(0, $quality);
            $this->assertLessThanOrEqual(5, $quality);
        }
    }
}
