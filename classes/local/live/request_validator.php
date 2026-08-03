<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Small strict validators shared by live AJAX handlers.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates scalar live API fields without PHP's permissive numeric coercion.
 */
final class request_validator {

    /**
     * Require a positive integer.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @return int
     */
    public static function positive_id($raw, string $field): int {
        $value = self::integer($raw, $field);
        if ($value <= 0) {
            throw new \invalid_parameter_exception("{$field} must be positive.");
        }
        return $value;
    }

    /**
     * Parse an optional positive ID.
     *
     * @param array $payload Request payload.
     * @param string $field Field name.
     * @return int|null
     */
    public static function optional_id(array $payload, string $field): ?int {
        if (!array_key_exists($field, $payload)
                || $payload[$field] === null
                || $payload[$field] === '') {
            return null;
        }
        return self::positive_id($payload[$field], $field);
    }

    /**
     * Require a non-negative integer.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @return int
     */
    public static function non_negative_integer($raw, string $field): int {
        $value = self::integer($raw, $field);
        if ($value < 0) {
            throw new \invalid_parameter_exception("{$field} must not be negative.");
        }
        return $value;
    }

    /**
     * Require an integer inside an inclusive range.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @param int $minimum Lowest accepted value.
     * @param int $maximum Highest accepted value.
     * @return int
     */
    public static function bounded_int(
        $raw,
        string $field,
        int $minimum,
        int $maximum
    ): int {
        $value = self::integer($raw, $field);
        if ($value < $minimum || $value > $maximum) {
            throw new \invalid_parameter_exception(
                "{$field} is outside its accepted range."
            );
        }
        return $value;
    }

    /**
     * Require a bounded scalar string.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @param int $maxlength Maximum characters.
     * @return string
     */
    public static function string($raw, string $field, int $maxlength = 255): string {
        if (!is_string($raw) || \core_text::strlen($raw) > $maxlength) {
            throw new \invalid_parameter_exception("{$field} must be a string.");
        }
        return $raw;
    }

    /**
     * Parse a strict integer.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @return int
     */
    private static function integer($raw, string $field): int {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $raw)) {
            return (int)$raw;
        }
        throw new \invalid_parameter_exception("{$field} must be an integer.");
    }
}
