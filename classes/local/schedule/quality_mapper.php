<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The single answer-to-SM-2-quality mapping.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\schedule;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps one evaluated Quizgeist response onto an SM-2 quality of 0 to 5.
 *
 * There is exactly one public mapping method on purpose. Live play and
 * self-study call it with the same arguments, so the same answer can never
 * produce two different learning states — that is a test, not an intention
 * (`tests/schedule_observe_test.php`).
 *
 * `null` means "this response carries no repetition signal at all". A viewed
 * content slide, a written think-moment and an unanswered position are events
 * about the lesson, not about recall; feeding them into SM-2 would poison the
 * learning state with organisational noise.
 */
final class quality_mapper {

    /**
     * Answer types that never carry a recall signal.
     *
     * F13 added `stage`: a stage check measures body language, not recall.
     * Feeding it into SM-2 would tell the scheduler that a learner who
     * presented confidently has understood the content — which is exactly the
     * confusion the stage check must not create.
     */
    public const SILENT_ANSWER_TYPES = ['view', 'reason', 'scorevoid', 'stage'];

    /** Response-time share up to which a correct answer stays perfect. */
    private const FAST_RATIO = 0.5;

    /** Response-time share up to which a correct answer stays confident. */
    private const HESITANT_RATIO = 0.85;

    /** Lowest quality a correct answer can be reduced to by slowness. */
    private const SLOW_CORRECT_QUALITY = 3;

    /** Floor for a correct answer that was accompanied by a written reason. */
    private const REASONED_CORRECT_QUALITY = 4;

    /** Highest quality reachable by a correct answer. */
    private const MAX_CORRECT_QUALITY = 5;

    /**
     * Map one evaluated response onto an SM-2 quality.
     *
     * @param string $answertype Ledger answer type (`answer`, `flashcard`, …).
     * @param bool|null $iscorrect Correctness, or null when ungraded.
     * @param float $accuracy Type-specific proximity from 0.0 to 1.0.
     * @param int $responsetimems Measured response time in milliseconds.
     * @param int $timelimitseconds Question time limit; zero means untimed.
     * @param bool $hasreason Whether the learner also wrote a think-moment.
     * @param bool|null $flashcardknown Self-assessment of a flashcard.
     * @param int $flashcardrounds Round in which the flashcard was placed.
     * @return int|null Quality from 0 to 5, or null when there is no signal.
     */
    public static function from_response(
        string $answertype,
        ?bool $iscorrect,
        float $accuracy,
        int $responsetimems,
        int $timelimitseconds,
        bool $hasreason = false,
        ?bool $flashcardknown = null,
        int $flashcardrounds = 1
    ): ?int {
        if (in_array($answertype, self::SILENT_ANSWER_TYPES, true)) {
            return null;
        }
        if ($answertype === 'flashcard') {
            if ($flashcardknown === null) {
                return null;
            }
            if (!$flashcardknown) {
                // Recognised only after turning the card over.
                return 1;
            }
            // Known in the first round is a clean recall; known only in the
            // repeat round is a pass with effort.
            return $flashcardrounds <= 1 ? 5 : self::SLOW_CORRECT_QUALITY;
        }
        if ($iscorrect === null) {
            // Polls, word clouds and brainstorming have no right answer. They
            // are worth playing and worthless for spaced repetition.
            return null;
        }
        $accuracy = max(0.0, min(1.0, $accuracy));
        if (!$iscorrect) {
            // 0 blackout, 1 wrong but familiar, 2 wrong yet close.
            if ($accuracy >= 0.5) {
                return 2;
            }
            return $accuracy >= 0.25 ? 1 : 0;
        }

        $quality = self::MAX_CORRECT_QUALITY;
        $limitms = max(0, $timelimitseconds) * 1000;
        if ($limitms > 0) {
            $ratio = max(0.0, min(1.0, $responsetimems / $limitms));
            if ($ratio > self::HESITANT_RATIO) {
                $quality = self::SLOW_CORRECT_QUALITY;
            } else if ($ratio > self::FAST_RATIO) {
                $quality = self::REASONED_CORRECT_QUALITY;
            }
        }
        if ($accuracy < 1.0) {
            // Correct but not exact — a slider inside tolerance, a partially
            // ordered puzzle. Recall happened, mastery did not.
            $quality = min($quality, self::REASONED_CORRECT_QUALITY);
        }
        if ($hasreason) {
            // Whoever writes a reason deliberately spends time. The clock must
            // not punish the very behaviour the think-moment asks for.
            $quality = max($quality, self::REASONED_CORRECT_QUALITY);
        }
        return $quality;
    }
}
