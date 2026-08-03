<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates one canonical draft question.
 */
final class question_create_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        if (!isset($payload['qtype']) || !is_string($payload['qtype'])) {
            throw new \invalid_parameter_exception('qtype is required.');
        }
        return ['question' => editor_service::create_question(
            $context->get_instance(),
            $context->get_module_context(),
            (int)$context->get_user()->id,
            $payload['qtype']
        )];
    }
}
