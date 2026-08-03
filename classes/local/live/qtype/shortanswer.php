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
 * Short free-text answer with bounded typo tolerance.
 */
final class shortanswer implements live_question_type {

    public function type(): string {
        return 'shortanswer';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'text',
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
        $correct = text_answer_support::matches(
            $answer['text'],
            $question['options']['acceptedAnswers'],
            !empty($question['options']['typoTolerance'])
        );
        return strategy_support::evaluated(
            $question,
            $answer,
            $correct,
            $scoring
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $counts = [];
        $labels = [];
        $correct = 0;
        foreach (strategy_support::rows($answers) as $row) {
            $text = $row['payload']['text'] ?? null;
            if (!is_string($text)) {
                continue;
            }
            $key = strategy_support::text_key($text);
            $labels[$key] ??= $text;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $correct += $row['isCorrect'] === true ? 1 : 0;
        }
        arsort($counts, SORT_NUMERIC);
        $responses = [];
        foreach ($counts as $key => $count) {
            $responses[] = ['text' => $labels[$key], 'count' => $count];
        }
        return [
            'kind' => 'shortanswer',
            'total' => array_sum($counts),
            'correctCount' => $correct,
            'responses' => $responses,
        ];
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $typedata = [
            'maxChars' => 255,
            'typoTolerance' => !empty($question['options']['typoTolerance']),
        ];
        if ($context->includecorrect) {
            $typedata['acceptedAnswers'] = array_values(
                $question['options']['acceptedAnswers']
            );
        }
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'text',
            'typeData' => $typedata,
        ];
    }
}
