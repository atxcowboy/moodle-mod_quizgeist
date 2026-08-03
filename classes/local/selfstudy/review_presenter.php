<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Disclosure-safe question projection for self-study attempts.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

use mod_quizgeist\local\live\answer_evaluator;
use mod_quizgeist\local\live\explanation_policy;
use mod_quizgeist\local\live\qtype\registry as question_type_registry;
use mod_quizgeist\local\live\qtype\strategy_support;
use mod_quizgeist\local\live\question_presenter;

defined('MOODLE_INTERNAL') || die();

/**
 * Reuses live type strategies and adds only the self-study disclosure gate.
 */
final class review_presenter {

    /**
     * Project one exact attempted version.
     *
     * @param \stdClass $question Exact question record.
     * @param \stdClass $attemptquestion Attempt-question row.
     * @param \context_module $context Module context.
     * @param int[] $questionids Frozen attempt order.
     * @param bool $disclose Whether solution information is unlocked.
     * @param array|null $answer Persisted canonical answer or safe test draft.
     * @param bool $answeriscanonical Whether opaque IDs need public projection.
     * @param bool|null $iscorrect Stored correctness.
     * @param int|null $points Stored points.
     * @param int|null $maxpoints Stored maximum.
     * @param string $explanationpolicy Worked-solution disclosure policy.
     * @return array
     */
    public static function present(
        \stdClass $question,
        \stdClass $attemptquestion,
        \context_module $context,
        array $questionids,
        bool $disclose,
        ?array $answer = null,
        bool $answeriscanonical = true,
        ?bool $iscorrect = null,
        ?int $points = null,
        ?int $maxpoints = null,
        string $explanationpolicy = explanation_policy::IMMEDIATE
    ): array {
        $canonical = answer_evaluator::canonical_question($question);
        $policy = question_type_registry::get(
            (string)$canonical['qtype']
        )->policy($canonical);
        $stage = $policy->initial_stage();

        // Correct solutions are type-owned projections. Self-study remains a
        // player projection both before and after its explicit reveal gate.
        $presentationquestion = clone $question;
        if (isset($question->questionstatus)) {
            $presentationquestion->status = $question->questionstatus;
        }
        if (isset($question->questiontimemodified)) {
            $presentationquestion->timemodified =
                $question->questiontimemodified;
        }
        $dto = question_presenter::present(
            $presentationquestion,
            $context,
            [
                'questionToken' => (string)$attemptquestion->visit,
                'currentIndex' => (int)$attemptquestion->sortindex,
                'questionIds' => $questionids,
            ],
            $disclose,
            'player',
            $disclose ? 'reveal' : 'question',
            $stage,
            $explanationpolicy
        );
        $dto['attemptQuestionStatus'] = (string)$attemptquestion->status;
        $dto['round'] = (int)($attemptquestion->round ?? 1);

        if ($answer !== null) {
            if ($answeriscanonical
                    && array_is_list($answer)
                    && $policy->response_type() === 'choices') {
                $answer = ['choiceIds' => $answer];
            }
            $publicanswer = $answeriscanonical
                ? strategy_support::public_answer(
                    $canonical,
                    $answer,
                    (string)$attemptquestion->visit
                )
                : $answer;
            $publicanswer = self::live_answer(
                $publicanswer,
                $policy->response_type()
            );
            $dto['submission'] = [
                'submitted' => in_array(
                    (string)$attemptquestion->status,
                    ['draft', 'submitted'],
                    true
                ),
                'answer' => $publicanswer,
            ];
            if ($disclose && $iscorrect !== null) {
                $dto['submission']['isCorrect'] = $iscorrect;
            }
            if ($disclose && $points !== null && $maxpoints !== null) {
                $dto['submission']['points'] = $points;
                $dto['submission']['maxPoints'] = $maxpoints;
            }
        } else {
            $dto['submission'] = [
                'submitted' => false,
                'answer' => null,
            ];
        }

        // The worked solution is deliberately NOT added here any more. Exactly
        // one place decides whether it may enter a question DTO at all:
        // question_presenter::present(). With `atend` or `never` the reveal
        // payload therefore contains no explanation text (F8, proof in the
        // payload).
        return $dto;
    }

    /**
     * Whether the type's own policy declares a right/wrong result.
     *
     * @param \stdClass $question Exact question.
     * @return bool
     */
    public static function shows_correctness(\stdClass $question): bool {
        $canonical = answer_evaluator::canonical_question($question);
        $policy = question_type_registry::get(
            (string)$canonical['qtype']
        )->policy($canonical);
        $descriptor = $policy->descriptor(
            $policy->initial_stage(),
            'player'
        );
        return !empty($descriptor['showsCorrectness']);
    }

    /**
     * Add the shared frontend answer discriminator after strategy projection.
     *
     * This is presentation metadata only. Validation and canonicalisation stay
     * entirely inside the qtype strategy.
     *
     * @param array $answer Public strategy payload.
     * @param string $responsetype Strategy-owned public response type.
     * @return array
     */
    private static function live_answer(
        array $answer,
        string $responsetype
    ): array {
        if (is_array($answer['choiceIds'] ?? null)) {
            return ['kind' => 'choices'] + $answer;
        }
        if (is_array($answer['orderIds'] ?? null)) {
            return ['kind' => 'order'] + $answer;
        }
        if (is_string($answer['text'] ?? null)) {
            return [
                'kind' => $responsetype === 'brainstorm'
                    ? 'brainstormIdea'
                    : 'text',
            ] + $answer;
        }
        if (isset($answer['x'], $answer['y'])) {
            return ['kind' => 'pin'] + $answer;
        }
        if (isset($answer['value'])) {
            return ['kind' => 'number'] + $answer;
        }
        if (is_string($answer['reaction'] ?? null)) {
            return ['kind' => 'reaction'] + $answer;
        }
        if (is_string($answer['groupKey'] ?? null)) {
            return ['kind' => 'brainstormVote'] + $answer;
        }
        return $answer;
    }
}
