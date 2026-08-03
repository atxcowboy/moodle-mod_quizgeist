<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Duplicates one question and all question media.
 */
final class question_duplicate_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $questionid = editor_service::positive_id($payload['questionid'] ?? null, 'questionid');
        return editor_service::duplicate_question(
            $context->get_instance(),
            $context->get_module_context(),
            (int)$context->get_user()->id,
            $questionid
        );
    }
}
