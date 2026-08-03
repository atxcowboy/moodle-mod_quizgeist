<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Optimistic editor conflict.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\editor;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries the current canonical server entity for an HTTP-409 response.
 */
final class edit_conflict_exception extends \moodle_exception {

    /**
     * @param string $entity Stable response key.
     * @param array $current Current canonical entity.
     */
    public function __construct(
        private readonly string $entity,
        private readonly array $current
    ) {
        parent::__construct('ajax:conflict', 'mod_quizgeist');
    }

    /**
     * Stable response key (`question` or `activity`).
     *
     * @return string
     */
    public function get_entity(): string {
        return $this->entity;
    }

    /**
     * Current canonical server entity.
     *
     * @return array
     */
    public function get_current(): array {
        return $this->current;
    }
}
