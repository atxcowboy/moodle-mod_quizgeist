<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the question-workshop licence-operation declarations.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use quizgeistaddon_selfstudy\local\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * New workshop submissions are gated; existing work remains readable.
 *
 * @covers \quizgeistaddon_selfstudy\local\provider::ajax_actions
 */
final class workshop_gate_test extends \advanced_testcase {

    public function test_workshop_actions_keep_the_create_and_existing_split(): void {
        $this->resetAfterTest(true);
        $actions = provider::ajax_actions();

        $this->assertSame('selfstudy', $actions['workshop_submit']['feature']);
        $this->assertSame('create_new', $actions['workshop_submit']['operation']);
        foreach (['workshop_rate', 'workshop_list', 'workshop_curate'] as $action) {
            $this->assertSame('selfstudy', $actions[$action]['feature']);
            $this->assertSame('view_existing', $actions[$action]['operation']);
        }
    }
}
