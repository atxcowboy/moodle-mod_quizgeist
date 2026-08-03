<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Teacher bootstrap AJAX action.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\ajax;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the initial teacher application state.
 */
final class teacher_bootstrap_handler implements action_handler {

    /**
     * Execute the action.
     *
     * @param action_context $context Validated and authorised request context.
     * @return array
     */
    public function execute(action_context $context): array {
        $cm = $context->get_course_module();
        $quizgeist = $context->get_instance();
        $modulecontext = $context->get_module_context();

        return [
            'cmid' => (int)$cm->id,
            'instanceid' => (int)$quizgeist->id,
            'name' => format_string($quizgeist->name),
            'theme' => $quizgeist->theme,
            'season' => $quizgeist->season ?? 'herbst',
            'allowbacktrack' => !empty($quizgeist->allowbacktrack),
            'defaultmode' => $quizgeist->defaultmode,
            'canhost' => has_capability('mod/quizgeist:host', $modulecontext),
            'canviewreports' => has_capability('mod/quizgeist:viewreports', $modulecontext),
        ];
    }
}
