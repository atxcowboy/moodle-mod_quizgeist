<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Submits one idempotent answer against the displayed concrete version.
 */
final class live_answer_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        if (isset($payload['answer']) && is_array($payload['answer'])) {
            $answer = $payload['answer'];
        } else if (isset($payload['choiceIds']) && is_array($payload['choiceIds'])) {
            // REVIEWFIX3 clients remain valid during the additive P4 rollout.
            $answer = ['choiceIds' => $payload['choiceIds']];
        } else {
            throw new \invalid_parameter_exception('answer is required.');
        }
        $submissionkey = array_key_exists('submissionKey', $payload)
            ? request_validator::string(
                $payload['submissionKey'],
                'submissionKey',
                64
            )
            : null;
        return session_service::submit_answer(
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user(),
            request_validator::positive_id($payload['sessionId'] ?? null, 'sessionId'),
            request_validator::positive_id($payload['questionId'] ?? null, 'questionId'),
            request_validator::string(
                $payload['questionToken'] ?? null,
                'questionToken',
                32
            ),
            $answer,
            $submissionkey
        );
    }
}
