<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Live strategy for poll questions.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live\qtype;

use mod_quizgeist\local\live\aggregation_context;
use mod_quizgeist\local\live\interaction_policy;
use mod_quizgeist\local\live\projection_context;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\submission_context;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles ungraded single- and multiple-choice polls.
 */
final class poll implements live_question_type {

    /** Domain separator for visit-bound poll option handles. */
    private const HANDLE_NAMESPACE = 'poll:choices';

    public function type(): string {
        return 'poll';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'choices',
            false,
            false,
            true,
            false,
            strategy_support::reason_stage($question)
        );
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        $choiceids = choice_support::canonical_ids($rawanswer);
        if (empty($question['options']['multiple']) && count($choiceids) !== 1) {
            throw new \invalid_parameter_exception('Exactly one poll choice is required.');
        }
        $available = array_values(array_column(
            $question['options']['answers'],
            'id'
        ));
        $choiceids = $context !== null && $context->visit !== ''
            ? strategy_support::resolve_opaque_ids(
                $choiceids,
                $available,
                $context->visit,
                self::HANDLE_NAMESPACE
            )
            : $choiceids;
        choice_support::assert_available(
            $choiceids,
            $available
        );
        return $choiceids;
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        $choiceids = $this->validate_answer($question, $rawanswer, $context);
        return strategy_support::evaluated(
            $question,
            ['choiceIds' => $choiceids],
            null,
            $scoring
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $metadata = array_map(
            static fn(array $answer): array => [
                'id' => (string)$answer['id'],
                'correct' => null,
            ],
            $question['options']['answers']
        );
        $aggregate = choice_support::aggregate(
            $metadata,
            choice_support::answer_lists($answers),
            false
        );
        if ($context->visit !== '') {
            $available = array_values(array_column(
                $question['options']['answers'],
                'id'
            ));
            $map = strategy_support::opaque_id_map(
                $available,
                $context->visit,
                self::HANDLE_NAMESPACE
            );
            foreach ($aggregate as &$entry) {
                $entry['choiceId'] = $map[(string)$entry['choiceId']];
            }
            unset($entry);
        }
        return $aggregate;
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $available = array_values(array_column(
            $question['options']['answers'],
            'id'
        ));
        $map = strategy_support::opaque_id_map(
            $available,
            $context->visit,
            self::HANDLE_NAMESPACE
        );
        $choices = [];
        foreach ($question['options']['answers'] as $answer) {
            $choices[] = choice_support::project_choice(
                $answer,
                $mediafiles,
                $map[(string)$answer['id']],
                $context->visit,
                'answer'
            );
        }
        if ($context->visit !== '') {
            $choices = strategy_support::shuffled(
                $choices,
                $context->visit,
                self::HANDLE_NAMESPACE
            );
        }
        return [
            'multiple' => !empty($question['options']['multiple']),
            'choices' => $choices,
            'responseType' => 'choices',
            'typeData' => [],
        ];
    }
}
