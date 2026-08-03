<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical Moodle wwwroot binding for offline licences.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Implements version 1 of the shared wwwroot canonicalisation contract.
 */
final class wwwroot {

    /**
     * Canonicalise one absolute HTTP(S) Moodle root URL.
     *
     * The remaining path is deliberately not decoded or re-encoded: path case
     * and percent-encoding are binding material in contract version 1.
     *
     * @param string $value Configured wwwroot.
     * @return string
     * @throws licence_exception
     */
    public static function canonicalise(string $value): string {
        $value = trim($value, " \t\n\r\f\v");
        if ($value === ''
                || preg_match('//u', $value) !== 1
                || preg_match('/[\x00-\x20\x7F]/', $value) === 1
                || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//D', $value) !== 1) {
            throw new licence_exception('invalid_wwwroot');
        }

        $parts = parse_url($value);
        if (!is_array($parts)
                || !isset($parts['scheme'], $parts['host'])
                || array_key_exists('user', $parts)
                || array_key_exists('pass', $parts)
                || array_key_exists('query', $parts)
                || array_key_exists('fragment', $parts)) {
            throw new licence_exception('invalid_wwwroot');
        }

        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new licence_exception('invalid_wwwroot');
        }

        $host = self::canonical_host((string)$parts['host']);
        $port = '';
        if (array_key_exists('port', $parts)) {
            $portnumber = $parts['port'];
            if (!is_int($portnumber) || $portnumber < 1 || $portnumber > 65535) {
                throw new licence_exception('invalid_wwwroot');
            }
            if (!(($scheme === 'http' && $portnumber === 80)
                    || ($scheme === 'https' && $portnumber === 443))) {
                $port = ':' . $portnumber;
            }
        }

        $path = array_key_exists('path', $parts) ? (string)$parts['path'] : '';
        if (($path !== '' && !str_starts_with($path, '/'))
                || preg_match(
                    '#%(?![0-9A-Fa-f]{2})|[^\x80-\xFFA-Za-z0-9\-._~!$&\'()*+,;=:@/%]#',
                    $path
                ) === 1) {
            throw new licence_exception('invalid_wwwroot');
        }
        if ($path === '' || $path === '/') {
            $path = '';
        } else {
            $path = rtrim($path, '/');
        }

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Calculate the contract hash over the canonical UTF-8 URL.
     *
     * @param string $value Configured wwwroot.
     * @return string
     */
    public static function hash(string $value): string {
        return 'sha256:' . hash('sha256', self::canonicalise($value));
    }

    /**
     * Canonicalise a DNS name or IP literal.
     *
     * @param string $host Host as returned by parse_url().
     * @return string
     */
    private static function canonical_host(string $host): string {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '') {
            throw new licence_exception('invalid_wwwroot');
        }

        $packed = @inet_pton($host);
        if (is_string($packed)) {
            $normalised = inet_ntop($packed);
            if (!is_string($normalised)) {
                throw new licence_exception('invalid_wwwroot');
            }
            return strlen($packed) === 16 ? '[' . strtolower($normalised) . ']' : $normalised;
        }

        if (preg_match('/^[\x00-\x7F]+$/D', $host) !== 1 || str_contains($host, ':')) {
            throw new licence_exception('invalid_wwwroot');
        }
        $host = strtolower($host);
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if ($host === ''
                || strlen($host) > 253
                || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1
                || preg_match('/^[0-9.]+$/D', $host) === 1) {
            throw new licence_exception('invalid_wwwroot');
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if ($label === ''
                    || strlen($label) > 63
                    || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label) !== 1) {
                throw new licence_exception('invalid_wwwroot');
            }
        }
        return $host;
    }
}
