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
 * Percentage-coordinate image pin with a target radius.
 */
final class pin implements live_question_type {

    public function type(): string {
        return 'pin';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'pin',
            reasonstage: strategy_support::reason_stage($question)
        );
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        $answer = [];
        foreach (['x', 'y'] as $axis) {
            $raw = $rawanswer[$axis] ?? null;
            if ((!is_int($raw) && !is_float($raw))
                    || !is_finite((float)$raw)
                    || (float)$raw < 0
                    || (float)$raw > 100) {
                throw new \invalid_parameter_exception('Pin coordinate is invalid.');
            }
            $answer[$axis] = round((float)$raw, 4);
        }
        return $answer;
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        $answer = $this->validate_answer($question, $rawanswer, $context);
        if (empty($question['options']['hasTarget'])) {
            return strategy_support::evaluated(
                $question,
                $answer,
                null,
                $scoring,
                0.0
            );
        }
        $target = $question['options']['target'];
        $distance = sqrt(
            ($answer['x'] - (float)$target['x']) ** 2
            + ($answer['y'] - (float)$target['y']) ** 2
        );
        $radius = (float)$question['options']['radius'];
        $correct = $distance <= $radius + 1.0e-9;
        $quality = $correct
            ? 0.5 + 0.5 * max(0.0, 1.0 - $distance / $radius)
            : 0.0;
        $answer['distance'] = round($distance, 4);
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
        $points = [];
        $correct = 0;
        foreach (strategy_support::rows($answers) as $row) {
            $x = $row['payload']['x'] ?? null;
            $y = $row['payload']['y'] ?? null;
            if ((!is_int($x) && !is_float($x))
                    || (!is_int($y) && !is_float($y))) {
                continue;
            }
            $points[] = ['x' => (float)$x, 'y' => (float)$y];
            $correct += $row['isCorrect'] === true ? 1 : 0;
        }
        $aggregate = [
            'kind' => 'pin',
            'total' => count($points),
            'correctCount' => $correct,
            'points' => $points,
        ];
        if ($context->includecorrect && !empty($question['options']['hasTarget'])) {
            $aggregate['target'] = [
                'x' => (float)$question['options']['target']['x'],
                'y' => (float)$question['options']['target']['y'],
            ];
            $aggregate['radius'] = (float)$question['options']['radius'];
        }
        return $aggregate;
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $typedata = [];
        if ($context->includecorrect && !empty($question['options']['hasTarget'])) {
            $typedata['target'] = [
                'x' => (float)$question['options']['target']['x'],
                'y' => (float)$question['options']['target']['y'],
            ];
            $typedata['radius'] = (float)$question['options']['radius'];
        }
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'pin',
            'typeData' => $typedata,
        ];
    }
}
