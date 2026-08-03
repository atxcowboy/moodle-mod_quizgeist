<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\live\qtype;

use mod_quizgeist\local\live\aggregation_context;
use mod_quizgeist\local\live\interaction_policy;
use mod_quizgeist\local\live\projection_context;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\submission_context;

defined('MOODLE_INTERNAL') || die();

/**
 * Timed tile reveal with a text answer.
 */
final class reveal implements live_question_type {

    public function type(): string {
        return 'reveal';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'reveal',
            reasonstage: strategy_support::reason_stage($question)
        );
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        return text_answer_support::payload($rawanswer);
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        $answer = $this->validate_answer($question, $rawanswer, $context);
        return strategy_support::evaluated(
            $question,
            $answer,
            text_answer_support::matches(
                $answer['text'],
                $question['options']['acceptedAnswers'],
                true
            ),
            $scoring
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $total = 0;
        $correct = 0;
        foreach (strategy_support::rows($answers) as $row) {
            if (is_string($row['payload']['text'] ?? null)) {
                $total++;
                $correct += $row['isCorrect'] === true ? 1 : 0;
            }
        }
        return [
            'kind' => 'reveal',
            'total' => $total,
            'correctCount' => $correct,
        ];
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $grid = (int)$question['options']['grid'];
        $tiles = array_map(
            static fn(int $id): array => ['id' => (string)$id],
            range(0, $grid * $grid - 1)
        );
        $tiles = strategy_support::shuffled($tiles, $context->visit, 'reveal');
        $typedata = [
            'grid' => $grid,
            'revealSeconds' => (int)$question['options']['revealSeconds'],
            'tileOrder' => array_map(
                static fn(array $tile): int => (int)$tile['id'],
                $tiles
            ),
            'maxChars' => 255,
        ];
        if ($context->includecorrect) {
            $typedata['acceptedAnswers'] = array_values(
                $question['options']['acceptedAnswers']
            );
        }
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'reveal',
            'typeData' => $typedata,
        ];
    }
}
