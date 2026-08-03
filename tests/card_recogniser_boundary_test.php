<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the strict model-response and card-meaning boundaries.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\cards\card_code;
use mod_quizgeist\local\cards\card_scan_service;
use quizgeistaddon_ai\local\ai\card_recogniser;
use quizgeistaddon_ai\local\ai\gateway_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Only a strict document and an honest set/question boundary may pass.
 *
 * @covers \quizgeistaddon_ai\local\ai\card_recogniser::decode
 * @covers \mod_quizgeist\local\cards\card_scan_service::apply_boundary
 */
final class card_recogniser_boundary_test extends \advanced_testcase {

    public function test_unknown_duplicate_and_invalid_answer_are_rejected_with_partial_result_visible(): void {
        $this->resetAfterTest(true);

        $seed = '1234567890abcdef1234567890abcdef';
        $known = card_code::code(51, 0, $seed);
        $second = card_code::code(51, 1, $seed);
        $unknown = card_code::code(51, 2, $seed);
        $codemap = [
            $known => (object)['id' => 1, 'userid' => 11, 'cardcode' => $known],
            $second => (object)['id' => 2, 'userid' => 12, 'cardcode' => $second],
        ];
        $players = [
            11 => (object)['id' => 1, 'userid' => 11],
            12 => (object)['id' => 2, 'userid' => 12],
        ];

        $result = card_scan_service::apply_boundary(
            [
                ['cardcode' => $known, 'answerKey' => 'A', 'confidence' => 0.9],
                ['cardcode' => $unknown, 'answerKey' => 'A', 'confidence' => 0.9],
                ['cardcode' => $known, 'answerKey' => 'A', 'confidence' => 0.9],
                ['cardcode' => $second, 'answerKey' => 'Z', 'confidence' => 0.9],
            ],
            $codemap,
            ['A' => 'true', 'B' => 'false'],
            $players
        );

        $this->assertCount(1, $result['accepted']);
        $this->assertSame(1, $result['recognised']);
        $this->assertCount(3, $result['rejected']);
        $this->assertSame(
            ['code_unknown', 'code_duplicate', 'answer_unknown'],
            array_column($result['rejected'], 'code')
        );
        // The accepted line and the rejected lines are both present: a partial
        // model reading cannot silently disappear from the confirmation view.
        $this->assertNotEmpty($result['accepted']);
        $this->assertNotEmpty($result['rejected']);
    }

    public function test_empty_document_is_valid_but_remains_visibly_empty(): void {
        $this->resetAfterTest(true);

        $entries = card_recogniser::decode('{"schemaVersion":1,"cards":[]}');
        $this->assertSame([], $entries);

        $result = card_scan_service::apply_boundary(
            $entries,
            [],
            ['A' => 'true'],
            []
        );
        $this->assertSame([], $result['accepted']);
        $this->assertSame([], $result['rejected']);
        $this->assertSame(0, $result['recognised']);
    }

    public function test_markdown_fence_and_extra_fields_are_rejected(): void {
        $this->resetAfterTest(true);

        $this->assertDecodeRejected(
            "```json\n{\"schemaVersion\":1,\"cards\":[]}\n```",
            'card_response_not_json'
        );
        $this->assertDecodeRejected(
            '{"schemaVersion":1,"cards":[],"modelNote":"guess"}',
            'card_response_extra_field'
        );
    }

    /**
     * @param string $content Synthetic model answer.
     * @param string $reason Expected parser diagnosis.
     * @return void
     */
    private function assertDecodeRejected(string $content, string $reason): void {
        try {
            card_recogniser::decode($content);
            $this->fail('The synthetic model answer was accepted unexpectedly.');
        } catch (gateway_exception $exception) {
            $this->assertSame($reason, $exception->get_reason());
        }
    }
}
