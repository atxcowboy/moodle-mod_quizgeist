<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Live strategy for true/false questions.
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
 * Handles the canonical true and false choices.
 */
final class truefalse implements live_question_type {

    public function type(): string {
        return 'truefalse';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'choices',
            reasonstage: strategy_support::reason_stage($question)
        );
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        $choiceids = choice_support::canonical_ids($rawanswer);
        if (count($choiceids) !== 1
                || !in_array($choiceids[0], ['true', 'false'], true)) {
            throw new \invalid_parameter_exception('A true/false choice is required.');
        }
        return $choiceids;
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        $choiceids = $this->validate_answer($question, $rawanswer, $context);
        $iscorrect = ($choiceids[0] === 'true')
            === !empty($question['options']['correct']);
        return strategy_support::evaluated(
            $question,
            ['choiceIds' => $choiceids],
            $iscorrect,
            $scoring
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $correctistrue = !empty($question['options']['correct']);
        return choice_support::aggregate([
            ['id' => 'true', 'correct' => $correctistrue],
            ['id' => 'false', 'correct' => !$correctistrue],
        ], choice_support::answer_lists($answers), $context->includecorrect);
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $correctistrue = !empty($question['options']['correct']);
        $choices = [
            ['id' => 'true', 'text' => get_string('live:true', 'mod_quizgeist')],
            ['id' => 'false', 'text' => get_string('live:false', 'mod_quizgeist')],
        ];
        if ($context->includecorrect) {
            $choices[0]['correct'] = $correctistrue;
            $choices[1]['correct'] = !$correctistrue;
        }
        return [
            'multiple' => false,
            'choices' => $choices,
            'responseType' => 'choices',
            'typeData' => [],
        ];
    }
}
