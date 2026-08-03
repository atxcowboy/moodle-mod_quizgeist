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
 * Ungraded Likert-scale response.
 */
final class scale implements live_question_type {

    public function type(): string {
        return 'scale';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'scale',
            true,
            true,
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
        $value = $rawanswer['value'] ?? null;
        if (!is_int($value)
                || $value < 1
                || $value > (int)$question['options']['steps']) {
            throw new \invalid_parameter_exception('Scale value is invalid.');
        }
        return ['value' => $value];
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        return strategy_support::evaluated(
            $question,
            $this->validate_answer($question, $rawanswer, $context),
            null,
            $scoring
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $steps = (int)$question['options']['steps'];
        $counts = array_fill(1, $steps, 0);
        $values = [];
        foreach (strategy_support::rows($answers) as $row) {
            $value = $row['payload']['value'] ?? null;
            if (is_int($value) && isset($counts[$value])) {
                $counts[$value]++;
                $values[] = $value;
            }
        }
        $total = count($values);
        $histogram = [];
        foreach ($counts as $value => $count) {
            $histogram[] = [
                'value' => $value,
                'count' => $count,
                'percent' => $total > 0 ? round($count * 100 / $total, 1) : 0,
            ];
        }
        return [
            'kind' => 'scale',
            'total' => $total,
            'mean' => $total > 0 ? round(array_sum($values) / $total, 2) : null,
            'median' => strategy_support::median($values),
            'histogram' => $histogram,
        ];
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'scale',
            'typeData' => [
                'steps' => (int)$question['options']['steps'],
                'minLabel' => (string)$question['options']['minLabel'],
                'maxLabel' => (string)$question['options']['maxLabel'],
            ],
        ];
    }
}
