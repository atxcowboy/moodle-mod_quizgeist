<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the Bühnen-Check combined with the question-type addons.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\qtype\registry as question_type_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * The stage sub-mode belongs to base slide questions as well as premium open.
 */
final class stage_qtypes_combination_test extends \advanced_testcase {

    public function test_without_qtypes_open_is_not_creatable_but_slide_is(): void {
        $this->resetAfterTest(true);
        set_config('version', 2026073000, 'quizgeistaddon_buehne');
        unset_config('version', 'quizgeistaddon_qtypes');

        try {
            question_type_registry::assert_creatable('open');
            $this->fail('The premium open question type was creatable without qtypes.');
        } catch (\invalid_parameter_exception $exception) {
            $this->addToAssertionCount(1);
        }

        question_type_registry::assert_creatable('slide');
        $this->assertTrue(question_type_registry::is_creatable('slide'));
    }

    public function test_slide_stagecheck_policy_contains_stage_stage(): void {
        $this->resetAfterTest(true);
        set_config('version', 2026073000, 'quizgeistaddon_buehne');
        unset_config('version', 'quizgeistaddon_qtypes');

        $policy = question_type_registry::get('slide')->policy([
            'options' => ['stageCheck' => true],
        ]);

        $this->assertTrue($policy->supports_stage('stage'));
    }
}
