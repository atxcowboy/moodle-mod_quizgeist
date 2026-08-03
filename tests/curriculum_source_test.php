<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for optional LehrplanPLUS grounding.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use quizgeistaddon_ai\local\ai\curriculum_source;

defined('MOODLE_INTERNAL') || die();

/**
 * Curriculum lookup degrades to an explainable empty form.
 *
 * @covers \quizgeistaddon_ai\local\ai\curriculum_source::lookup
 */
final class curriculum_source_test extends \advanced_testcase {

    public function test_missing_local_planung_returns_empty_form_without_exception(): void {
        $this->resetAfterTest(true);

        if (curriculum_source::available()) {
            $this->markTestSkipped('local_planung is installed in this test environment.');
        }

        $result = curriculum_source::lookup('Deutsch', 11, '', 'Erzählperspektive');

        $this->assertFalse($result['available']);
        $this->assertSame('plugin_missing', $result['reason']);
        $this->assertSame('', $result['block']);
        $this->assertSame([], $result['chunks']);
    }

    public function test_unsupported_grade_returns_empty_form_instead_of_exception(): void {
        $this->resetAfterTest(true);

        // lookup() validates the requested year group before consulting the
        // optional repository, so this branch is isolated from installation
        // state and needs no process-global class stub.
        $result = curriculum_source::lookup('Deutsch', 9, '', 'Erzählperspektive');

        $this->assertFalse($result['available']);
        $this->assertSame('grade_unsupported', $result['reason']);
        $this->assertSame('', $result['block']);
        $this->assertSame([], $result['chunks']);
    }
}
