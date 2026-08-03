<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Polls host state with the stateVersion shortcut.
 */
final class live_host_poll_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        return session_service::host_poll(
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user(),
            request_validator::positive_id($payload['sessionId'] ?? null, 'sessionId'),
            request_validator::non_negative_integer(
                $payload['knownStateVersion'] ?? 0,
                'knownStateVersion'
            ),
            request_validator::non_negative_integer(
                $payload['knownAggregateRevision'] ?? 0,
                'knownAggregateRevision'
            )
        );
    }
}
