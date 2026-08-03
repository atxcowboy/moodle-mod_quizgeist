<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the curriculum-aware generation prompt.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use quizgeistaddon_ai\local\ai\curriculum_source;
use quizgeistaddon_ai\local\ai\prompt_catalogue;

defined('MOODLE_INTERNAL') || die();

/**
 * F10 adds citations only when a context block is present.
 *
 * @covers \quizgeistaddon_ai\local\ai\prompt_catalogue::system_prompt
 * @covers \quizgeistaddon_ai\local\ai\prompt_catalogue::citation_contract
 */
final class prompt_citation_test extends \advanced_testcase {

    public function test_context_prompt_contains_citation_rule_and_additional_contract(): void {
        $this->resetAfterTest(true);

        $lookup = curriculum_source::lookup('Deutsch', 11, '', 'Lyrik');
        $context = (string)($lookup['block'] ?? '');
        if ($context === '') {
            // local_planung is optional and is not available on every Moodle
            // test site. This doubles the lookup() result at the seam consumed
            // by prompt_catalogue, preserving the production block shape while
            // keeping the positive prompt contract test runnable here.
            $context = curriculum_source::CONTEXT_HEADING . ":\n"
                . "Deutsch — Jgst. 11 — Lernbereich Lesen\n"
                . "Quelle: https://www.lehrplanplus.bayern.de/anker\n\n"
                . curriculum_source::CITATION_RULE;
        } else {
            $this->assertSame('ok', $lookup['reason']);
        }
        $prompt = prompt_catalogue::system_prompt(
            prompt_catalogue::TASK_CURRICULUM,
            prompt_catalogue::FORMAT_QUIZ,
            $context
        );

        $this->assertStringContainsString(curriculum_source::CITATION_RULE, $prompt);
        $this->assertStringContainsString(prompt_catalogue::citation_contract(), $prompt);
        $this->assertStringContainsString('"competency"', $prompt);
        $this->assertStringContainsString('"citation"', $prompt);
    }

    public function test_without_context_prompt_matches_reconstructed_legacy_prompt(): void {
        $this->resetAfterTest(true);

        $reflection = new \ReflectionClass(prompt_catalogue::class);
        $constants = $reflection->getConstants();
        $base_prompt = $reflection->getMethod('base_prompt');
        $base_prompt->setAccessible(true);
        $draft_contract = $reflection->getMethod('draft_contract');
        $draft_contract->setAccessible(true);

        // Reconstruct the pre-F10 prompt from the private production building
        // blocks. This is the actual legacy baseline, not a second call to the
        // F10-aware public API with an empty optional argument.
        $legacy = $base_prompt->invoke(null)
            . "\n\nAuftrag:\n"
            . $constants['TASK_INSTRUCTIONS'][prompt_catalogue::TASK_CURRICULUM]
            . "\n\nFormat:\n"
            . $constants['FORMAT_INSTRUCTIONS'][prompt_catalogue::FORMAT_QUIZ]
            . "\n\n"
            . $draft_contract->invoke(null);
        $current = prompt_catalogue::system_prompt(
            prompt_catalogue::TASK_CURRICULUM,
            prompt_catalogue::FORMAT_QUIZ
        );

        $this->assertSame($legacy, $current);
        $this->assertStringNotContainsString(prompt_catalogue::citation_contract(), $current);
    }
}
