<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Autosaves one complete question.
 */
final class question_save_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        if (!isset($payload['question']) || !is_array($payload['question'])) {
            throw new \invalid_parameter_exception('question is required.');
        }
        return ['question' => editor_service::save_question(
            $context->get_instance(),
            $context->get_module_context(),
            (int)$context->get_user()->id,
            $payload['question']
        )];
    }
}
