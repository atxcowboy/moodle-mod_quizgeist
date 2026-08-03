<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Pure SM-2 repetition arithmetic.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\schedule;

defined('MOODLE_INTERNAL') || die();

/**
 * Computes the next repetition state without touching database or clock.
 *
 * Every input arrives as a parameter, including the current time. The class
 * therefore has no hidden state, no randomness and no ambient dependency:
 * the same arguments always produce the same array. This is what makes the
 * promise "live and self-study produce the same state for the same answer"
 * provable rather than hopeful.
 *
 * The ease factor is carried as an integer hundredth (E-factor times 100) so
 * that no float rounding can drift between two call sites.
 */
final class sm2 {

    /** Works default ease factor: SM-2 starts at 2.5. */
    public const DEFAULT_EASINESS = 250;

    /** Contractual lower bound of the ease factor (1.3). */
    public const MIN_EASINESS = 130;

    /** Highest quality an answer can reach. */
    public const MAX_QUALITY = 5;

    /** Below this quality SM-2 restarts the repetition chain. */
    public const PASS_QUALITY = 3;

    /** Interval after the first successful repetition, in days. */
    public const FIRST_INTERVAL_DAYS = 1;

    /** Interval after the second successful repetition, in days. */
    public const SECOND_INTERVAL_DAYS = 6;

    /**
     * Upper bound of a single interval.
     *
     * Ten years is far beyond any school career and keeps `duetime` inside a
     * 32-bit range even for a learner who answers perfectly for years.
     */
    public const MAX_INTERVAL_DAYS = 3650;

    /**
     * Ease-factor change per quality, already multiplied by one hundred.
     *
     * SM-2 defines EF' = EF + (0.1 - (5-q) * (0.08 + (5-q) * 0.02)). All six
     * results are exact hundredths, so the table is the formula rather than an
     * approximation of it — and it can be read and checked by a teacher.
     */
    public const EASINESS_DELTA = [
        0 => -80,
        1 => -54,
        2 => -32,
        3 => -14,
        4 => 0,
        5 => 10,
    ];

    /**
     * Compute the repetition state that follows one observed answer.
     *
     * @param int $easiness Current ease factor times one hundred.
     * @param int $intervaldays Current interval in days.
     * @param int $repetitions Current number of consecutive successes.
     * @param int $quality Mapped answer quality from zero to five.
     * @param int $now Unix timestamp of the observation.
     * @return array{
     *     easiness:int,
     *     intervaldays:int,
     *     repetitions:int,
     *     lapseincrement:int,
     *     duetime:int,
     *     lastreviewed:int,
     *     lastquality:int
     * } `lapseincrement` is a delta, never an absolute count: the caller owns
     *     the persisted `lapses` column and simply adds it.
     */
    public static function next(
        int $easiness,
        int $intervaldays,
        int $repetitions,
        int $quality,
        int $now
    ): array {
        $quality = max(0, min(self::MAX_QUALITY, $quality));
        $easiness = max(self::MIN_EASINESS, $easiness);
        $intervaldays = max(0, $intervaldays);
        $repetitions = max(0, $repetitions);
        $now = max(0, $now);

        // The ease factor always reacts, including on a failure: a repeatedly
        // missed question must become permanently easier to meet again.
        $easiness = max(
            self::MIN_EASINESS,
            $easiness + self::EASINESS_DELTA[$quality]
        );

        if ($quality < self::PASS_QUALITY) {
            // A lapse restarts the chain. The question returns on the next day
            // rather than immediately, so one bad answer cannot trap a learner
            // in the same question for the rest of a session.
            return self::state(
                $easiness,
                self::FIRST_INTERVAL_DAYS,
                0,
                1,
                $quality,
                $now
            );
        }

        $repetitions++;
        if ($repetitions === 1) {
            $intervaldays = self::FIRST_INTERVAL_DAYS;
        } else if ($repetitions === 2) {
            $intervaldays = self::SECOND_INTERVAL_DAYS;
        } else {
            // From the third success on, the interval grows by the ease factor.
            // max(previous + 1, …) guarantees strict growth even for a learner
            // sitting at the 1.3 floor, where rounding could otherwise stall.
            $intervaldays = max(
                $intervaldays + 1,
                (int)round($intervaldays * $easiness / 100)
            );
        }
        $intervaldays = min(self::MAX_INTERVAL_DAYS, max(1, $intervaldays));

        return self::state($easiness, $intervaldays, $repetitions, 0, $quality, $now);
    }

    /**
     * Assemble one immutable result array.
     *
     * @param int $easiness Ease factor times one hundred.
     * @param int $intervaldays Interval in days.
     * @param int $repetitions Consecutive successes.
     * @param int $lapseincrement Zero or one.
     * @param int $quality Observed quality.
     * @param int $now Observation time.
     * @return array
     */
    private static function state(
        int $easiness,
        int $intervaldays,
        int $repetitions,
        int $lapseincrement,
        int $quality,
        int $now
    ): array {
        return [
            'easiness' => $easiness,
            'intervaldays' => $intervaldays,
            'repetitions' => $repetitions,
            'lapseincrement' => $lapseincrement,
            'duetime' => $now + ($intervaldays * DAYSECS),
            'lastreviewed' => $now,
            'lastquality' => $quality,
        ];
    }

    /**
     * Build the state of a root that has never been answered.
     *
     * A learner who has never seen a question is due for it immediately; that
     * is expressed as `duetime = 0` rather than as a missing row, so both the
     * selector and the digest can treat "new" and "overdue" alike.
     *
     * @return array{easiness:int,intervaldays:int,repetitions:int,lapses:int,duetime:int}
     */
    public static function fresh(): array {
        return [
            'easiness' => self::DEFAULT_EASINESS,
            'intervaldays' => 0,
            'repetitions' => 0,
            'lapses' => 0,
            'duetime' => 0,
        ];
    }
}
