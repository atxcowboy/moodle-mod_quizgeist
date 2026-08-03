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
 * Ungraded long-form teacher-reviewed response.
 */
final class open implements live_question_type {

    public function type(): string {
        return 'open';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'text',
            false,
            false,
            false,
            false,
            strategy_support::reason_stage($question),
            // F13: `open` ist ein Premium-Fragetyp (Addon `qtypes`), der
            // Buehnen-Check ein eigenes Addon. Die beiden Tore sind
            // unabhaengig; hier entscheidet allein, ob der Untermodus fuer
            // DIESE Frage gesetzt und sein Code installiert ist.
            strategy_support::stage_check($question)
        );
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        // F9: a spoken answer is complete the moment it is spoken. Its text is
        // filled in by clip_binding as soon as the transcript exists.
        $clipid = strategy_support::spoken_clip_id($rawanswer);
        if ($clipid !== null) {
            return ['text' => '', 'clipId' => $clipid];
        }
        return [
            'text' => strategy_support::multiline_text(
                $rawanswer['text'] ?? null,
                6000,
                'text'
            ),
        ];
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
        $responses = [];
        foreach (strategy_support::rows($answers) as $row) {
            if (is_string($row['payload']['text'] ?? null)) {
                $responses[] = [
                    'id' => $row['id'],
                    'text' => $row['payload']['text'],
                ];
            }
        }
        return [
            'kind' => 'open',
            'total' => count($responses),
            'responses' => $responses,
        ];
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $typedata = ['maxChars' => 6000];
        if ($context->includecorrect
                && (string)$question['options']['sampleAnswer'] !== '') {
            $typedata['sampleAnswer'] = (string)$question['options']['sampleAnswer'];
        }
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'text',
            'typeData' => $typedata,
        ];
    }
}
