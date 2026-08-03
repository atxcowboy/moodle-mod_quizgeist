<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Live answer validation and scoring.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\editor\question_schema;
use mod_quizgeist\local\live\qtype\registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Evaluates P3 question types against the exact played content version.
 */
final class answer_evaluator {

    /**
     * Validate and score one submitted answer.
     *
     * Time points are linear from 1000 at opening to 0 at the deadline.
     * `double` doubles that time component. A correct response additionally
     * receives 50 points per prior streak link, capped at 500. Polls are
     * ungraded and leave the streak unchanged.
     *
     * @param \stdClass $record Exact played question row.
     * @param array $rawanswer Submitted type-specific payload.
     * @param scoring_context $scoring Server-owned scoring context.
     * @param submission_context|null $submission Interaction context.
     * @return array Canonical evaluated response.
     */
    public static function evaluate(
        \stdClass $record,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $submission = null
    ): array {
        $question = self::canonical_question($record);
        $strategy = registry::get((string)$question['qtype']);
        $definition = null;
        if ($submission !== null) {
            $definition = $strategy->policy($question)->submission(
                $submission->stage,
                $submission->kind,
                $submission->role
            );
        }
        // F2 Denk-Moment. A reason is a think moment, not an answer. It is
        // canonicalised here once and never reaches a type strategy: no
        // strategy has to learn about reasons, and none can accidentally make
        // one score. points = 0 and iscorrect = null are structural, not a
        // rule some caller could forget.
        if (($definition['answerType'] ?? null) === 'reason') {
            return [
                'answer' => \mod_quizgeist\local\live\qtype\strategy_support
                    ::reason_answer($rawanswer),
                'answerType' => 'reason',
                'quality' => 0.0,
                'iscorrect' => null,
                'points' => 0,
                'streak' => $scoring->priorstreak,
                'responseTimeMs' => $scoring->responsetimems,
            ];
        }
        // F13 Buehnen-Check. Dieselbe Bauweise und derselbe Grund: eine
        // Praesentation ist keine Antwort. Kein Fragetyp erfaehrt von ihr,
        // keiner kann sie versehentlich bepunkten. Die Kennzahlen sind hier
        // gar nicht im Spiel — die Zeile haelt nur den Verweis auf den bereits
        // geprueften Bericht.
        if (($definition['answerType'] ?? null) === 'stage') {
            return [
                'answer' => \mod_quizgeist\local\live\qtype\strategy_support
                    ::stage_answer($rawanswer),
                'answerType' => 'stage',
                'quality' => 0.0,
                'iscorrect' => null,
                'points' => 0,
                'streak' => $scoring->priorstreak,
                'responseTimeMs' => $scoring->responsetimems,
            ];
        }
        $evaluation = $strategy->evaluate(
            $question,
            $rawanswer,
            $scoring,
            $submission
        );
        if ($definition !== null) {
            $evaluation['answerType'] = $definition['answerType'];
        }
        return $evaluation;
    }

    /**
     * Canonicalise one exact played row for policy and strategy use.
     *
     * @param \stdClass $record Question row.
     * @return array
     */
    public static function canonical_question(\stdClass $record): array {
        $record = clone $record;
        $record->timelimit = question_timing::seconds($record);
        $normalised = question_schema::normalise([
            'qtype' => (string)$record->qtype,
            'questiontext' => (string)$record->questiontext,
            'options' => question_schema::decode_options($record->optionsjson ?? null),
            'timelimit' => (int)$record->timelimit,
            'pointmode' => (string)$record->pointmode,
            'explanation' => (string)$record->explanation,
        ]);
        return $normalised['question'];
    }
}
