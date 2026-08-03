<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the F2 think-moment stage.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\editor\question_schema;
use mod_quizgeist\local\live\interaction_policy;
use mod_quizgeist\local\live\qtype\registry;
use mod_quizgeist\local\live\qtype\strategy_support;

defined('MOODLE_INTERNAL') || die();

/**
 * The stage appears only when switched on, and a reason never scores.
 */
final class reason_stage_test extends \advanced_testcase {

    /**
     * Canonicalise one question with the reason switch in a known state.
     *
     * @param string $qtype Question type.
     * @param bool $reasonstep Whether the think moment is on.
     * @return array
     */
    private function question(string $qtype, bool $reasonstep): array {
        $defaults = question_schema::defaults($qtype);
        $defaults['questiontext'] = 'Warum ist das so?';
        $defaults['options']['reasonStep'] = $reasonstep;
        return question_schema::normalise($defaults)['question'];
    }

    public function test_the_stage_is_absent_without_the_switch(): void {
        $this->resetAfterTest(true);

        $policy = registry::get('quiz')->policy($this->question('quiz', false));

        $this->assertSame('answer', $policy->initial_stage());
        $this->assertTrue($policy->final_stage('answer'));
        $this->assertNull($policy->next_stage('answer'));
        $this->assertNotContains('reason', $policy->answer_types());
    }

    public function test_the_stage_appears_with_the_switch(): void {
        $this->resetAfterTest(true);

        $policy = registry::get('quiz')->policy($this->question('quiz', true));

        $this->assertSame('answer', $policy->initial_stage());
        $this->assertFalse($policy->final_stage('answer'));
        $this->assertSame('reason', $policy->next_stage('answer'));
        $this->assertTrue($policy->supports_stage('reason'));
        $this->assertContains('reason', $policy->answer_types());
    }

    public function test_the_reason_submission_is_single_and_references_the_answer(): void {
        $this->resetAfterTest(true);

        $policy = interaction_policy::standard(
            'choices',
            reasonstage: true
        );
        $definition = $policy->submission('reason', 'reason', 'player');

        $this->assertSame('reason', $definition['answerType']);
        $this->assertSame('single', $definition['cardinality']);
        $this->assertSame(1, $definition['maxPerActor']);
        $this->assertSame(['answer'], $definition['referenceAnswerTypes']);
    }

    public function test_every_standard_strategy_carries_the_switch(): void {
        $this->resetAfterTest(true);

        foreach (question_schema::REASON_STAGE_TYPES as $qtype) {
            $question = $this->question($qtype, true);
            if ($qtype === 'wordcloud') {
                // Moderation owns its own multi-stage flow; the plain variant
                // is the one that carries the reason stage.
                $question['options']['moderation'] = false;
            }
            $policy = registry::get($qtype)->policy($question);
            $this->assertTrue(
                $policy->supports_stage('reason'),
                "{$qtype} reicht den Denk-Moment nicht durch"
            );
        }
    }

    public function test_a_slide_never_offers_the_stage(): void {
        $this->resetAfterTest(true);

        $this->assertNotContains('slide', question_schema::REASON_STAGE_TYPES);
        $normalised = question_schema::normalise(
            question_schema::defaults('slide')
        )['question'];
        $this->assertArrayNotHasKey('reasonStep', $normalised['options']);
    }

    public function test_the_reason_text_is_bounded_plain_text(): void {
        $this->resetAfterTest(true);

        $canonical = strategy_support::reason_answer([
            'text' => "  Weil <b>zwei</b> Drittel\r\n mehr sind.  ",
        ]);
        $this->assertStringNotContainsString('<b>', $canonical['text']);
        $this->assertSame(
            "Weil zwei Drittel\nmehr sind.",
            $canonical['text']
        );

        $this->expectException(\invalid_parameter_exception::class);
        strategy_support::reason_answer([
            'text' => str_repeat('a', strategy_support::MAX_REASON_LENGTH + 1),
        ]);
    }

    public function test_an_empty_reason_is_refused(): void {
        $this->resetAfterTest(true);

        $this->expectException(\invalid_parameter_exception::class);
        strategy_support::reason_answer(['text' => '   ']);
    }

    public function test_the_switch_survives_schema_normalisation(): void {
        $this->resetAfterTest(true);

        $on = $this->question('shortanswer', true);
        $off = $this->question('shortanswer', false);
        $this->assertTrue($on['options']['reasonStep']);
        $this->assertFalse($off['options']['reasonStep']);
        $this->assertTrue(strategy_support::reason_stage($on));
        $this->assertFalse(strategy_support::reason_stage($off));
    }
}
