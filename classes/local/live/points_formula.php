<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared live points formula.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies server-timed points and streak rules independently of question type.
 */
final class points_formula {

    /** Maximum bonus added for an existing correct-answer streak. */
    private const MAX_STREAK_BONUS = 500;

    /** Bonus points per already completed link in a streak. */
    private const STREAK_BONUS_STEP = 50;

    /**
     * Return the maximum attainable points for one graded response.
     *
     * Callers decide whether a strategy is graded from its interaction policy;
     * this method owns the shared point-mode, mode and streak arithmetic.
     *
     * @param array $question Canonical question.
     * @param string $mode Scoring mode.
     * @param int $priorstreak Prior correct-answer streak.
     * @return int
     */
    public static function maximum(
        array $question,
        string $mode,
        int $priorstreak = 0,
        string $pace = scoring_context::PACE_TIMED
    ): int {
        return self::calculate(
            $question,
            true,
            new scoring_context($mode, 0, max(0, $priorstreak), $pace)
        )['points'];
    }

    /**
     * Score one validated answer.
     *
     * @param array $question Canonical question.
     * @param bool|null $iscorrect Correctness, or null for an ungraded answer.
     * @param scoring_context $scoring Server-owned scoring context.
     * @param float $quality Type-specific accuracy factor from 0 to 1.
     * @return array{iscorrect:?bool,points:int,streak:int,responseTimeMs:int}
     */
    public static function calculate(
        array $question,
        ?bool $iscorrect,
        scoring_context $scoring,
        float $quality = 1.0
    ): array {
        $responsetimems = $scoring->responsetimems;
        $priorstreak = $scoring->priorstreak;
        $quality = max(0.0, min(1.0, $quality));
        $limitms = max(0, (int)$question['timelimit'] * 1000);
        $responsetimems = $limitms > 0
            ? max(0, min($limitms, $responsetimems))
            : max(0, $responsetimems);
        $points = 0;
        $streak = $priorstreak;
        if ($iscorrect !== null) {
            if ($iscorrect) {
                // Two independent reasons switch the clock off, and they must
                // stay distinguishable. `accuracy` is the paid premium mode:
                // it drops the clock AND the streak AND the cosmetic unlocks.
                // `pace = even` is the free stress-free standard of the base
                // package: it drops only the clock, while the streak bonus,
                // the streak display and the reward logic remain intact.
                $ignoresclock = $scoring->mode === 'accuracy'
                    || $scoring->pace === scoring_context::PACE_EVEN;
                if ($ignoresclock) {
                    // Type-specific proximity still applies in both cases.
                    $timepoints = (int)round(1000 * $quality);
                } else {
                    $remainingratio = $limitms > 0
                        ? max(0.0, 1.0 - ($responsetimems / $limitms))
                        : 1.0;
                    if ($scoring->mode === 'selfstudy') {
                        // In asynchronous solo work the clock starts when the
                        // position is first opened and may include a reload or
                        // an interruption. Correct work therefore retains at
                        // least half of its quality-weighted base points.
                        $remainingratio = max(0.5, $remainingratio);
                    }
                    $timepoints = (int)round(
                        1000 * $remainingratio * $quality
                    );
                }
                if ($question['pointmode'] === 'double') {
                    $timepoints *= 2;
                } else if ($question['pointmode'] === 'none') {
                    $timepoints = 0;
                }
                $bonus = $question['pointmode'] === 'none'
                        || $scoring->mode === 'accuracy'
                    ? 0
                    : min(
                        self::MAX_STREAK_BONUS,
                        max(0, $priorstreak) * self::STREAK_BONUS_STEP
                    );
                $points = $timepoints + $bonus;
                // Accuracy mode represents only correctness/proximity. It
                // neither awards nor exposes streak progress or streak-based
                // cosmetic unlocks.
                $streak = $scoring->mode === 'accuracy'
                    ? 0
                    : $priorstreak + 1;
            } else {
                $streak = 0;
            }
        }
        return [
            'iscorrect' => $iscorrect,
            'points' => $points,
            'streak' => $streak,
            'responseTimeMs' => $responsetimems,
        ];
    }
}
