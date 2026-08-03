<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies an exact active-question order.
 */
final class question_reorder_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        if (!isset($payload['questionids']) || !is_array($payload['questionids'])) {
            throw new \invalid_parameter_exception('questionids is required.');
        }
        return editor_service::reorder_questions(
            $context->get_instance(),
            $context->get_module_context(),
            (int)$context->get_user()->id,
            $payload['questionids']
        );
    }
}
