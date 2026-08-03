<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the misconception hinge traffic-light boundaries.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\report\misconception_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * The configured threshold is inclusive, while the sample floor wins first.
 *
 * @covers \mod_quizgeist\local\report\misconception_repository::hinge_status
 */
final class hinge_threshold_test extends \advanced_testcase {

    public function test_threshold_boundaries_and_insufficient_samples(): void {
        $this->resetAfterTest(true);
        set_config('report_difficult_min_sample', 5, 'mod_quizgeist');

        $expected = [
            0 => misconception_repository::STATUS_RETEACH,
            1 => misconception_repository::STATUS_RETEACH,
            69 => misconception_repository::STATUS_RETEACH,
            70 => misconception_repository::STATUS_MOVE_ON,
            71 => misconception_repository::STATUS_MOVE_ON,
            100 => misconception_repository::STATUS_MOVE_ON,
        ];
        foreach ($expected as $percent => $status) {
            $this->assertSame(
                $status,
                misconception_repository::hinge_status($percent, 100, 70),
                "Unexpected hinge status at {$percent} percent."
            );
        }

        // No amount of correctness is enough before the configured evidence
        // floor has been reached.
        for ($sample = 0; $sample < 5; $sample++) {
            $this->assertSame(
                misconception_repository::STATUS_INSUFFICIENT,
                misconception_repository::hinge_status($sample, $sample, 0)
            );
            $this->assertSame(
                misconception_repository::STATUS_INSUFFICIENT,
                misconception_repository::hinge_status($sample, $sample, 100)
            );
        }
    }
}
