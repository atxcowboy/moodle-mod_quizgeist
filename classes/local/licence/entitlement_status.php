<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ordered licence entitlement states.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Makes the contract ordering active < grace < read_only explicit.
 */
final class entitlement_status {

    /** The entitlement can create and use premium content. */
    public const ACTIVE = 'active';

    /** The entitlement remains usable during its signed grace period. */
    public const GRACE = 'grace';

    /** Existing data remains available, but premium use cannot expand. */
    public const READ_ONLY = 'read_only';

    /** @var array<string, int> Severity ordered from least to most restrictive. */
    private const SEVERITY = [
        self::ACTIVE => 0,
        self::GRACE => 1,
        self::READ_ONLY => 2,
    ];

    /**
     * Whether a value is one of the three contract states.
     *
     * @param string $status State to inspect.
     * @return bool
     */
    public static function is_known(string $status): bool {
        return array_key_exists($status, self::SEVERITY);
    }

    /**
     * Return the stricter of two valid states.
     *
     * @param string $first First state.
     * @param string $second Second state.
     * @return string
     */
    public static function stricter(string $first, string $second): string {
        if (!self::is_known($first) || !self::is_known($second)) {
            throw new \InvalidArgumentException('Unknown entitlement status.');
        }

        return self::SEVERITY[$first] >= self::SEVERITY[$second] ? $first : $second;
    }
}
