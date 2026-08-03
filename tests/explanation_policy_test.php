<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the F8 worked-solution disclosure policy.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\explanation_policy;
use mod_quizgeist\local\live\question_presenter;
use mod_quizgeist\local\selfstudy\review_summary;

defined('MOODLE_INTERNAL') || die();

/**
 * With `atend` the reveal DTO contains NO explanation text — proof in the payload.
 */
final class explanation_policy_test extends \advanced_testcase {

    /**
     * Create one activity with a question carrying a worked solution.
     *
     * @return array{context:\context_module,record:\stdClass}
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $context = \context_module::instance($module->cmid);
        $now = time();
        $questionid = $DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$module->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'truefalse',
            'questiontext' => 'Ist Wasser nass?',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode(['media' => null, 'correct' => true]),
            'timelimit' => 20,
            'pointmode' => 'standard',
            'explanation' => 'Weil Wasser benetzt.',
            'status' => 'ready',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, [
            'id' => $questionid,
        ]);
        return [
            'context' => $context,
            'record' => $DB->get_record(
                'quizgeist_questions',
                ['id' => $questionid],
                '*',
                MUST_EXIST
            ),
        ];
    }

    /**
     * Minimal live state accepted by the presenter.
     *
     * @return array
     */
    private function state(): array {
        return [
            'questionToken' => str_repeat('a', 32),
            'currentIndex' => 0,
            'questionIds' => [1],
        ];
    }

    public function test_immediate_policy_discloses_on_reveal(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();

        $dto = question_presenter::present(
            $fixture['record'],
            $fixture['context'],
            $this->state(),
            true,
            'player',
            'reveal',
            'answer',
            explanation_policy::IMMEDIATE
        );

        $this->assertSame('Weil Wasser benetzt.', $dto['explanation']);
    }

    public function test_atend_policy_leaves_no_explanation_in_the_payload(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();

        $dto = question_presenter::present(
            $fixture['record'],
            $fixture['context'],
            $this->state(),
            true,
            'player',
            'reveal',
            'answer',
            explanation_policy::ATEND
        );

        // The hard promise of F8: not hidden, absent.
        $this->assertArrayNotHasKey('explanation', $dto);
        $this->assertStringNotContainsString(
            'Weil Wasser benetzt.',
            json_encode($dto)
        );
    }

    public function test_never_policy_leaves_no_explanation_in_the_payload(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();

        $dto = question_presenter::present(
            $fixture['record'],
            $fixture['context'],
            $this->state(),
            true,
            'player',
            'reveal',
            'answer',
            explanation_policy::NEVER
        );

        $this->assertArrayNotHasKey('explanation', $dto);
    }

    public function test_an_unrevealed_question_never_carries_the_solution(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();

        $dto = question_presenter::present(
            $fixture['record'],
            $fixture['context'],
            $this->state(),
            false,
            'player',
            'question',
            'answer',
            explanation_policy::IMMEDIATE
        );

        $this->assertArrayNotHasKey('explanation', $dto);
    }

    public function test_live_play_keeps_its_previous_payload(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();

        // Live callers pass no policy at all. The default is NEVER, so the
        // live payload is bit-identical to the state before P11.
        $dto = question_presenter::present(
            $fixture['record'],
            $fixture['context'],
            $this->state(),
            true,
            'host',
            'reveal',
            'answer'
        );

        $this->assertArrayNotHasKey('explanation', $dto);
    }

    public function test_policy_normalisation_and_predicates(): void {
        $this->resetAfterTest(true);

        $this->assertSame('immediate', explanation_policy::DEFAULT_POLICY);
        $this->assertSame('immediate', explanation_policy::normalise('unfug'));
        $this->assertTrue(explanation_policy::discloses_on_reveal('immediate'));
        $this->assertFalse(explanation_policy::discloses_on_reveal('atend'));
        $this->assertFalse(explanation_policy::discloses_on_reveal('never'));
        $this->assertTrue(explanation_policy::discloses_at_end('immediate'));
        $this->assertTrue(explanation_policy::discloses_at_end('atend'));
        $this->assertFalse(explanation_policy::discloses_at_end('never'));
    }

    public function test_the_closing_screen_collects_what_was_withheld(): void {
        $this->resetAfterTest(true);

        $attempt = (object)[
            'status' => 'completed',
            'activityexplanationpolicy' => explanation_policy::ATEND,
        ];
        $rows = [
            (object)['sortindex' => 0, 'questiontext' => 'Erste Frage',
                'explanation' => 'Erster Lösungsweg'],
            (object)['sortindex' => 1, 'questiontext' => 'Zweite Frage',
                'explanation' => '   '],
        ];
        $summary = review_summary::for_attempt($attempt, $rows);

        $this->assertTrue($summary['available']);
        $this->assertTrue($summary['deferred']);
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['withExplanation']);
        $this->assertSame('Erster Lösungsweg', $summary['entries'][0]['explanation']);
    }

    public function test_the_closing_screen_stays_shut_for_never(): void {
        $this->resetAfterTest(true);

        $summary = review_summary::for_attempt(
            (object)[
                'status' => 'completed',
                'activityexplanationpolicy' => explanation_policy::NEVER,
            ],
            [(object)['sortindex' => 0, 'questiontext' => 'F',
                'explanation' => 'Geheim']]
        );

        $this->assertFalse($summary['available']);
        $this->assertSame([], $summary['entries']);
        $this->assertStringNotContainsString('Geheim', json_encode($summary));
    }

    public function test_a_running_attempt_has_no_closing_screen(): void {
        $this->resetAfterTest(true);

        $summary = review_summary::for_attempt(
            (object)[
                'status' => 'inprogress',
                'activityexplanationpolicy' => explanation_policy::ATEND,
            ],
            [(object)['sortindex' => 0, 'questiontext' => 'F',
                'explanation' => 'Noch nicht']]
        );

        $this->assertFalse($summary['available']);
        $this->assertStringNotContainsString('Noch nicht', json_encode($summary));
    }
}
