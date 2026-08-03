<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\report\report_service;
use mod_quizgeist\local\report\source_selection;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds one selected historical report.
 */
final class report_data_handler implements action_handler {
    public function execute(action_context $context): array {
        return report_service::build(
            $context->get_course_module(),
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user(),
            source_selection::from_payload($context->get_payload())
        );
    }
}
