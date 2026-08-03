<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ping AJAX action.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\ajax;

defined('MOODLE_INTERNAL') || die();

/**
 * Returns a minimal authenticated activity health response.
 */
final class ping_handler implements action_handler {

    /**
     * Execute the action.
     *
     * @param action_context $context Validated and authorised request context.
     * @return array
     */
    public function execute(action_context $context): array {
        $cm = $context->get_course_module();
        $quizgeist = $context->get_instance();

        return [
            'message' => 'pong',
            'cmid' => (int)$cm->id,
            'instanceid' => (int)$quizgeist->id,
            'version' => 1,
        ];
    }
}
