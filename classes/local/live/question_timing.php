<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical live question timing.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the single server-side clamp used for opening and scoring.
 */
final class question_timing {

    /** Minimum supported live limit. */
    public const MIN_SECONDS = 5;

    /** Maximum supported live limit. */
    public const MAX_SECONDS = 240;

    /**
     * Return the canonical live time limit.
     *
     * @param \stdClass $question Persisted question.
     * @return int Seconds.
     */
    public static function seconds(\stdClass $question): int {
        return self::normalise((int)$question->timelimit);
    }

    /**
     * Clamp a raw persisted value to the live timing contract.
     *
     * @param int $seconds Raw seconds.
     * @return int
     */
    public static function normalise(int $seconds): int {
        if ($seconds === 0) {
            return 0;
        }
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
    }
}
