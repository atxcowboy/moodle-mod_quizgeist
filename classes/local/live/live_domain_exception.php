<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Stable live-domain error exposed by the JSON dispatcher.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries a client-stable error code independently from its translated text.
 */
final class live_domain_exception extends \moodle_exception {

    /**
     * @param string $domaincode Stable snake-case API error code.
     * @param string $stringkey mod_quizgeist language-string key.
     * @param int $httpstatus HTTP client-error status.
     */
    public function __construct(
        private readonly string $domaincode,
        private readonly string $stringkey,
        private readonly int $httpstatus = 400
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $domaincode)) {
            throw new \coding_exception('A live-domain error code is invalid.');
        }
        if (!preg_match('/^[a-z][a-z0-9:_-]{0,127}$/D', $stringkey)) {
            throw new \coding_exception('A live-domain language key is invalid.');
        }
        if ($httpstatus < 400 || $httpstatus > 499) {
            throw new \coding_exception('A live-domain HTTP status must be a 4xx status.');
        }
        parent::__construct($stringkey, 'mod_quizgeist');
    }

    /**
     * Return the stable API error code.
     *
     * @return string
     */
    public function get_error_code(): string {
        return $this->domaincode;
    }

    /**
     * Return the mod_quizgeist language-string key.
     *
     * @return string
     */
    public function get_string_key(): string {
        return $this->stringkey;
    }

    /**
     * Return the HTTP client-error status.
     *
     * @return int
     */
    public function get_http_status(): int {
        return $this->httpstatus;
    }
}
