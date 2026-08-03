<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the Bühnen-Check entitlement boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\licence\feature_gate;
use mod_quizgeist\local\live\qtype\strategy_support;

defined('MOODLE_INTERNAL') || die();

/**
 * Existing stage content remains usable while report creation is gated.
 */
final class stage_gate_test extends \advanced_testcase {

    public function test_installed_buehne_without_entitlement_is_read_only(): void {
        $this->resetAfterTest(true);
        set_config('version', 2026073000, 'quizgeistaddon_buehne');

        $this->assertTrue(feature_gate::allows(
            'buehne',
            feature_gate::VIEW_EXISTING
        ));
        $this->assertFalse(feature_gate::allows(
            'buehne',
            feature_gate::CREATE_NEW
        ));
    }

    public function test_missing_buehne_disables_stage_submode_entirely(): void {
        $this->resetAfterTest(true);
        unset_config('version', 'quizgeistaddon_buehne');

        $this->assertFalse(feature_gate::allows(
            'buehne',
            feature_gate::VIEW_EXISTING
        ));
        $this->assertFalse(strategy_support::stage_check([
            'options' => ['stageCheck' => true],
        ]));
    }
}
