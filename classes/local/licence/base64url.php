<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical unpadded Base64url encoding for licence material.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Strict RFC 4648 section 5 codec.
 */
final class base64url {

    /**
     * Encode bytes without padding.
     *
     * @param string $bytes Arbitrary bytes.
     * @return string
     */
    public static function encode(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Decode a canonical unpadded value.
     *
     * Re-encoding the bytes detects unused non-zero bits and therefore rejects
     * encodings that decode successfully but are not canonical.
     *
     * @param string $value Base64url without padding.
     * @param int|null $expectedlength Required decoded byte length.
     * @return string
     * @throws licence_exception
     */
    public static function decode(string $value, ?int $expectedlength = null): string {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new licence_exception('invalid_base64url');
        }

        $remainder = strlen($value) % 4;
        if ($remainder === 1) {
            throw new licence_exception('invalid_base64url');
        }

        $padding = $remainder === 0 ? '' : str_repeat('=', 4 - $remainder);
        $decoded = base64_decode(strtr($value, '-_', '+/') . $padding, true);
        if (!is_string($decoded) || !hash_equals($value, self::encode($decoded))) {
            throw new licence_exception('invalid_base64url');
        }
        if ($expectedlength !== null && strlen($decoded) !== $expectedlength) {
            throw new licence_exception('invalid_base64url');
        }

        return $decoded;
    }
}
