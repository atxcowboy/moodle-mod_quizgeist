<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared question-strategy submission primitives.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\live\qtype\registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps qtype policy resolution, evaluation and answer-ledger encoding common.
 *
 * Transaction boundaries, lock order and idempotency deliberately remain in
 * the live and self-study orchestrators because their ownership models differ.
 */
final class submission_pipeline {

    /**
     * Resolve one canonical interaction through the shared qtype registry.
     *
     * @param \stdClass $questionrecord Exact persisted question version.
     * @param string|null $stage Persisted stage, or null for the initial stage.
     * @param string $role Host or player.
     * @return array{
     *     question:array,
     *     strategy:\mod_quizgeist\local\live\qtype\live_question_type,
     *     policy:interaction_policy,
     *     stage:string,
     *     kind:string,
     *     definition:array
     * }
     */
    public static function interaction(
        \stdClass $questionrecord,
        ?string $stage,
        string $role
    ): array {
        $question = answer_evaluator::canonical_question($questionrecord);
        $strategy = registry::get((string)$question['qtype']);
        $policy = $strategy->policy($question);
        $resolvedstage = $stage ?? $policy->initial_stage();
        if (!$policy->supports_stage($resolvedstage)) {
            throw new \coding_exception('Persisted interaction stage is invalid.');
        }
        $kind = $policy->default_kind($resolvedstage, $role);
        return [
            'question' => $question,
            'strategy' => $strategy,
            'policy' => $policy,
            'stage' => $resolvedstage,
            'kind' => $kind,
            'definition' => $policy->submission(
                $resolvedstage,
                $kind,
                $role
            ),
        ];
    }

    /**
     * Validate and score through the one shared evaluator.
     *
     * @param \stdClass $questionrecord Exact persisted question version.
     * @param array $rawanswer Public or trusted canonical answer.
     * @param scoring_context $scoring Server-owned scoring inputs.
     * @param submission_context $submission Server-owned interaction inputs.
     * @return array
     */
    public static function evaluate(
        \stdClass $questionrecord,
        array $rawanswer,
        scoring_context $scoring,
        submission_context $submission
    ): array {
        return answer_evaluator::evaluate(
            $questionrecord,
            $rawanswer,
            $scoring,
            $submission
        );
    }

    /**
     * Build the canonical append-only answer row for either runtime.
     *
     * @param array $fields Ownership, answer and scoring fields.
     * @return \stdClass
     */
    public static function ledger_record(array $fields): \stdClass {
        foreach ([
            'questionid',
            'userid',
            'answertype',
            'answer',
            'points',
            'maxpoints',
            'responsetime',
            'timecreated',
        ] as $required) {
            if (!array_key_exists($required, $fields)) {
                throw new \coding_exception(
                    'A shared submission ledger field is missing: ' . $required
                );
            }
        }
        if (!is_array($fields['answer'])) {
            throw new \coding_exception('A shared submission answer must be an array.');
        }
        $iscorrect = $fields['iscorrect'] ?? null;
        if ($iscorrect !== null && !is_bool($iscorrect)) {
            throw new \coding_exception('Shared submission correctness is invalid.');
        }
        return (object)[
            'sessionid' => isset($fields['sessionid'])
                ? (int)$fields['sessionid']
                : null,
            'playerid' => isset($fields['playerid'])
                ? (int)$fields['playerid']
                : null,
            'attemptid' => isset($fields['attemptid'])
                ? (int)$fields['attemptid']
                : null,
            'questionid' => (int)$fields['questionid'],
            'userid' => (int)$fields['userid'],
            'answertype' => (string)$fields['answertype'],
            'visit' => isset($fields['visit'])
                ? (string)$fields['visit']
                : null,
            'submissionkey' => isset($fields['submissionkey'])
                ? (string)$fields['submissionkey']
                : null,
            'answerjson' => json_encode(
                $fields['answer'],
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ),
            'iscorrect' => $iscorrect === null ? null : ($iscorrect ? 1 : 0),
            'points' => max(0, (int)$fields['points']),
            'maxpoints' => max(0, (int)$fields['maxpoints']),
            'responsetime' => max(0, (int)$fields['responsetime']),
            'timecreated' => max(0, (int)$fields['timecreated']),
        ];
    }
}
