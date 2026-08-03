<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\report\report_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Returns the capability-safe report source catalogue.
 */
final class report_bootstrap_handler implements action_handler {
    public function execute(action_context $context): array {
        return report_service::bootstrap(
            $context->get_course_module(),
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user()
        );
    }
}
