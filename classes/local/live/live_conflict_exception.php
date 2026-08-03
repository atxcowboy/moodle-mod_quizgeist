<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Optimistic live-session conflict.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries the current canonical session DTO for an HTTP 409 response.
 */
final class live_conflict_exception extends \moodle_exception {

    /**
     * @param array $state Current canonical role-specific session state.
     */
    public function __construct(private readonly array $state) {
        parent::__construct('ajax:conflict', 'mod_quizgeist');
    }

    /**
     * Return the current canonical state.
     *
     * @return array
     */
    public function get_state(): array {
        return $this->state;
    }
}
