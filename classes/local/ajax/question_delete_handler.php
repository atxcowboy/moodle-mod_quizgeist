<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Deletes or history-archives one question.
 */
final class question_delete_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        return editor_service::delete_question(
            $context->get_instance(),
            $context->get_module_context(),
            editor_service::positive_id($payload['questionid'] ?? null, 'questionid')
        );
    }
}
