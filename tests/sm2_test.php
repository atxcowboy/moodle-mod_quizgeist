<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for pure SM-2 repetition arithmetic.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\schedule\sm2;

defined('MOODLE_INTERNAL') || die();

/**
 * Known states and invariants of the deterministic SM-2 calculator.
 */
final class sm2_test extends \advanced_testcase {

    public function test_known_inputs_have_exact_states(): void {
        $this->resetAfterTest(true);

        $cases = [
            [
                'input' => [250, 0, 0, 5, 1000],
                'expected' => [
                    'easiness' => 260,
                    'intervaldays' => 1,
                    'repetitions' => 1,
                    'lapseincrement' => 0,
                    'duetime' => 1000 + DAYSECS,
                    'lastreviewed' => 1000,
                    'lastquality' => 5,
                ],
            ],
            [
                'input' => [260, 1, 1, 4, 2000],
                'expected' => [
                    'easiness' => 260,
                    'intervaldays' => 6,
                    'repetitions' => 2,
                    'lapseincrement' => 0,
                    'duetime' => 2000 + (6 * DAYSECS),
                    'lastreviewed' => 2000,
                    'lastquality' => 4,
                ],
            ],
            [
                'input' => [270, 6, 2, 5, 3000],
                'expected' => [
                    'easiness' => 280,
                    'intervaldays' => 17,
                    'repetitions' => 3,
                    'lapseincrement' => 0,
                    'duetime' => 3000 + (17 * DAYSECS),
                    'lastreviewed' => 3000,
                    'lastquality' => 5,
                ],
            ],
            [
                'input' => [250, 20, 4, 2, 4000],
                'expected' => [
                    'easiness' => 218,
                    'intervaldays' => 1,
                    'repetitions' => 0,
                    'lapseincrement' => 1,
                    'duetime' => 4000 + DAYSECS,
                    'lastreviewed' => 4000,
                    'lastquality' => 2,
                ],
            ],
            [
                'input' => [130, 1, 0, 0, 5000],
                'expected' => [
                    'easiness' => 130,
                    'intervaldays' => 1,
                    'repetitions' => 0,
                    'lapseincrement' => 1,
                    'duetime' => 5000 + DAYSECS,
                    'lastreviewed' => 5000,
                    'lastquality' => 0,
                ],
            ],
            [
                'input' => [180, 4, 3, 3, 6000],
                'expected' => [
                    'easiness' => 166,
                    'intervaldays' => 7,
                    'repetitions' => 4,
                    'lapseincrement' => 0,
                    'duetime' => 6000 + (7 * DAYSECS),
                    'lastreviewed' => 6000,
                    'lastquality' => 3,
                ],
            ],
            [
                'input' => [250, 0, 0, 3, 7000],
                'expected' => [
                    'easiness' => 236,
                    'intervaldays' => 1,
                    'repetitions' => 1,
                    'lapseincrement' => 0,
                    'duetime' => 7000 + DAYSECS,
                    'lastreviewed' => 7000,
                    'lastquality' => 3,
                ],
            ],
            [
                'input' => [300, 49, 4, 5, 8000],
                'expected' => [
                    'easiness' => 310,
                    'intervaldays' => 152,
                    'repetitions' => 5,
                    'lapseincrement' => 0,
                    'duetime' => 8000 + (152 * DAYSECS),
                    'lastreviewed' => 8000,
                    'lastquality' => 5,
                ],
            ],
        ];

        foreach ($cases as $case) {
            $this->assertSame(
                $case['expected'],
                sm2::next(...$case['input'])
            );
        }
    }

    public function test_constants_and_rounding_match_the_contract(): void {
        $this->resetAfterTest(true);

        $this->assertSame(250, sm2::DEFAULT_EASINESS);
        $this->assertSame(130, sm2::MIN_EASINESS);
        $this->assertSame(5, sm2::MAX_QUALITY);
        $this->assertSame(3, sm2::PASS_QUALITY);
        $this->assertSame(1, sm2::FIRST_INTERVAL_DAYS);
        $this->assertSame(6, sm2::SECOND_INTERVAL_DAYS);
        $this->assertSame(3650, sm2::MAX_INTERVAL_DAYS);
        $this->assertSame(
            [0 => -80, 1 => -54, 2 => -32, 3 => -14, 4 => 0, 5 => 10],
            sm2::EASINESS_DELTA
        );

        // 6 * 280 / 100 is 16.8 and must round to exactly 17 days.
        $state = sm2::next(270, 6, 2, 5, 9000);
        $this->assertSame(17, $state['intervaldays']);
    }

    public function test_fresh_state_has_the_documented_defaults(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            [
                'easiness' => sm2::DEFAULT_EASINESS,
                'intervaldays' => 0,
                'repetitions' => 0,
                'lapses' => 0,
                'duetime' => 0,
            ],
            sm2::fresh()
        );
    }

    public function test_quality_below_three_restarts_the_chain(): void {
        $this->resetAfterTest(true);

        foreach ([0, 1, 2] as $quality) {
            $state = sm2::next(250, 42, 7, $quality, 10000);
            $this->assertSame(0, $state['repetitions']);
            $this->assertSame(1, $state['intervaldays']);
            $this->assertSame(1, $state['lapseincrement']);
        }
    }

    public function test_easiness_never_falls_below_the_floor(): void {
        $this->resetAfterTest(true);

        $easiness = sm2::DEFAULT_EASINESS;
        $intervaldays = 0;
        $repetitions = 0;
        for ($answer = 0; $answer < 20; $answer++) {
            $state = sm2::next(
                $easiness,
                $intervaldays,
                $repetitions,
                0,
                11000 + $answer
            );
            $this->assertGreaterThanOrEqual(
                sm2::MIN_EASINESS,
                $state['easiness']
            );
            $easiness = $state['easiness'];
            $intervaldays = $state['intervaldays'];
            $repetitions = $state['repetitions'];
        }

        $this->assertSame(sm2::MIN_EASINESS, $easiness);
    }

    public function test_quality_five_intervals_grow_strictly(): void {
        $this->resetAfterTest(true);

        $easiness = sm2::DEFAULT_EASINESS;
        $intervaldays = 0;
        $repetitions = 0;
        $intervals = [];
        for ($step = 0; $step < 7; $step++) {
            $state = sm2::next(
                $easiness,
                $intervaldays,
                $repetitions,
                5,
                12000 + $step
            );
            $intervals[] = $state['intervaldays'];
            if ($step > 0) {
                $this->assertGreaterThan(
                    $intervals[$step - 1],
                    $intervals[$step]
                );
            }
            $easiness = $state['easiness'];
            $intervaldays = $state['intervaldays'];
            $repetitions = $state['repetitions'];
        }

        $this->assertCount(7, $intervals);
    }

    public function test_duetime_is_now_plus_the_interval_in_days(): void {
        $this->resetAfterTest(true);

        $now = 1700000000;
        $state = sm2::next(250, 6, 2, 5, $now);

        $this->assertSame(
            $now + ($state['intervaldays'] * DAYSECS),
            $state['duetime']
        );
    }

    public function test_identical_arguments_produce_identical_arrays(): void {
        $this->resetAfterTest(true);

        // The supplied timestamp is the only clock input; no time() is used.
        $arguments = [230, 12, 4, 4, 1700000100];
        $first = sm2::next(...$arguments);
        $second = sm2::next(...$arguments);

        $this->assertSame($first, $second);
    }
}
