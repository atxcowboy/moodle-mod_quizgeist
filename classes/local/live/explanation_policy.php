<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Disclosure policy for the worked solution (F8 Erklär-Geist).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * One place decides when a stored worked solution may leave the server.
 *
 * The field, the editor upkeep, the AI pre-fill and the step_by_step format
 * all existed before P11. What was missing is exactly this: a policy. The
 * hard promise of F8 is measured in the payload — with `atend` the reveal DTO
 * contains no explanation text at all, not a hidden one.
 */
final class explanation_policy {

    /** Practice mode: the worked solution appears with the reveal. */
    public const IMMEDIATE = 'immediate';

    /** Performance mode: the worked solution appears only after the run. */
    public const ATEND = 'atend';

    /** The worked solution never reaches a learner. */
    public const NEVER = 'never';

    /** @var string[] Stable persisted policy catalogue. */
    public const POLICIES = [self::IMMEDIATE, self::ATEND, self::NEVER];

    /** Works default; unchanged behaviour for every activity built before P11. */
    public const DEFAULT_POLICY = self::IMMEDIATE;

    /**
     * Normalise one persisted or submitted policy value.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    public static function normalise($value): string {
        return is_string($value) && in_array($value, self::POLICIES, true)
            ? $value
            : self::DEFAULT_POLICY;
    }

    /**
     * Read the policy from one activity record.
     *
     * @param \stdClass|null $quizgeist Activity record.
     * @return string
     */
    public static function of(?\stdClass $quizgeist): string {
        return self::normalise($quizgeist->explanationpolicy ?? null);
    }

    /**
     * Whether a per-question reveal may carry the worked solution.
     *
     * @param string $policy Canonical policy.
     * @return bool
     */
    public static function discloses_on_reveal(string $policy): bool {
        return self::normalise($policy) === self::IMMEDIATE;
    }

    /**
     * Whether the closing screen may collect every worked solution.
     *
     * @param string $policy Canonical policy.
     * @return bool
     */
    public static function discloses_at_end(string $policy): bool {
        return self::normalise($policy) !== self::NEVER;
    }
}
