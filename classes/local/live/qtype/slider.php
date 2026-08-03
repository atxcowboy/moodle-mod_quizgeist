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
 * Numeric estimate scored by tolerance and proximity.
 */
final class slider implements live_question_type {

    public function type(): string {
        return 'slider';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'slider',
            reasonstage: strategy_support::reason_stage($question)
        );
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        $raw = $rawanswer['value'] ?? null;
        if ((!is_int($raw) && !is_float($raw)) || !is_finite((float)$raw)) {
            throw new \invalid_parameter_exception('Slider value is invalid.');
        }
        $value = (float)$raw;
        $min = (float)$question['options']['min'];
        $max = (float)$question['options']['max'];
        $step = (float)$question['options']['step'];
        $epsilon = max(1.0e-9, abs($step) * 1.0e-6);
        $nearest = round(($value - $min) / $step);
        if ($value < $min - $epsilon
                || $value > $max + $epsilon
                || abs(($min + $nearest * $step) - $value) > $epsilon) {
            throw new \invalid_parameter_exception('Slider value is out of range.');
        }
        return ['value' => round($min + $nearest * $step, 6)];
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        $answer = $this->validate_answer($question, $rawanswer, $context);
        $distance = abs($answer['value'] - (float)$question['options']['target']);
        $tolerance = (float)$question['options']['tolerance'];
        $epsilon = max(1.0e-9, (float)$question['options']['step'] * 1.0e-6);
        $correct = $distance <= $tolerance + $epsilon;
        $quality = !$correct
            ? 0.0
            : ($tolerance <= $epsilon
                ? 1.0
                : 0.5 + 0.5 * max(0.0, 1.0 - $distance / $tolerance));
        $answer['distance'] = round($distance, 6);
        return strategy_support::evaluated(
            $question,
            $answer,
            $correct,
            $scoring,
            $quality
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $values = [];
        $correct = 0;
        foreach (strategy_support::rows($answers) as $row) {
            $value = $row['payload']['value'] ?? null;
            if (is_int($value) || is_float($value)) {
                $values[] = (float)$value;
                $correct += $row['isCorrect'] === true ? 1 : 0;
            }
        }
        $aggregate = [
            'kind' => 'slider',
            'total' => count($values),
            'correctCount' => $correct,
            'mean' => $values
                ? round(array_sum($values) / count($values), 3)
                : null,
            'median' => strategy_support::median($values),
            'values' => $values,
        ];
        if ($context->includecorrect) {
            $aggregate['target'] = (float)$question['options']['target'];
            $aggregate['tolerance'] = (float)$question['options']['tolerance'];
        }
        return $aggregate;
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $typedata = [
            'min' => (float)$question['options']['min'],
            'max' => (float)$question['options']['max'],
            'step' => (float)$question['options']['step'],
        ];
        if ($context->includecorrect) {
            $typedata['target'] = (float)$question['options']['target'];
            $typedata['tolerance'] = (float)$question['options']['tolerance'];
        }
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'slider',
            'typeData' => $typedata,
        ];
    }
}
