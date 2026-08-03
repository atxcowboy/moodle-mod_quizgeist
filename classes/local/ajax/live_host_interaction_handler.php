<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies one optimistic policy-owned staged host interaction.
 */
final class live_host_interaction_handler implements action_handler {

    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $data = $payload['data'] ?? [];
        if (!is_array($data)) {
            throw new \invalid_parameter_exception('data must be an object.');
        }
        $submissionkey = array_key_exists('submissionKey', $payload)
            ? request_validator::string(
                $payload['submissionKey'],
                'submissionKey',
                64
            )
            : null;
        $interactionkind = array_key_exists('interactionKind', $payload)
            ? request_validator::string(
                $payload['interactionKind'],
                'interactionKind',
                32
            )
            : null;
        return session_service::host_interaction(
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
            request_validator::string($payload['operation'] ?? null, 'operation', 16),
            request_validator::positive_id(
                $payload['expectedStateVersion'] ?? null,
                'expectedStateVersion'
            ),
            $data,
            $submissionkey,
            $interactionkind
        );
    }
}
