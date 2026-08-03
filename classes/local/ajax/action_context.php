<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX action context.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\ajax;

defined('MOODLE_INTERNAL') || die();

/**
 * Immutable request data passed to an AJAX action handler.
 */
final class action_context {

    /**
     * Constructor.
     *
     * @param string $action Validated action name.
     * @param \stdClass $coursemodule Course-module record.
     * @param \stdClass $instance Quizgeist instance record.
     * @param \context_module $modulecontext Module context.
     * @param \stdClass $user Authenticated user record.
     * @param array $payload Decoded request payload.
     */
    public function __construct(
        private readonly string $action,
        private readonly \stdClass $coursemodule,
        private readonly \stdClass $instance,
        private readonly \context_module $modulecontext,
        private readonly \stdClass $user,
        private readonly array $payload,
    ) {
    }

    /**
     * Get the validated action name.
     *
     * @return string
     */
    public function get_action(): string {
        return $this->action;
    }

    /**
     * Get the course-module record.
     *
     * @return \stdClass
     */
    public function get_course_module(): \stdClass {
        return $this->coursemodule;
    }

    /**
     * Get the Quizgeist instance record.
     *
     * @return \stdClass
     */
    public function get_instance(): \stdClass {
        return $this->instance;
    }

    /**
     * Get the module context.
     *
     * @return \context_module
     */
    public function get_module_context(): \context_module {
        return $this->modulecontext;
    }

    /**
     * Get the authenticated user record.
     *
     * @return \stdClass
     */
    public function get_user(): \stdClass {
        return $this->user;
    }

    /**
     * Get the decoded payload.
     *
     * Handlers must still validate every action-specific field before use.
     *
     * @return array
     */
    public function get_payload(): array {
        return $this->payload;
    }
}
