<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\addon\registry as addon_registry;
use mod_quizgeist\local\editor\editor_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the full P2 editor state.
 */
final class editor_bootstrap_handler implements action_handler {
    public function execute(action_context $context): array {
        $bootstrap = editor_service::bootstrap(
            $context->get_course_module(),
            $context->get_instance(),
            $context->get_module_context()
        );
        $bootstrap['ai'] = [
            'installed' => false,
            'available' => false,
        ];
        if (addon_registry::is_installed('quizgeistaddon_ai')) {
            $bootstrap['ai'] = \quizgeistaddon_ai\local\ai\workshop_service::configuration(
                $context->get_course_module()
            );
        }
        return $bootstrap;
    }
}
