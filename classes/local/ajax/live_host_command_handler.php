<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies one optimistic state-machine command.
 */
final class live_host_command_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        return session_service::host_command(
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user(),
            request_validator::positive_id($payload['sessionId'] ?? null, 'sessionId'),
            request_validator::string($payload['command'] ?? null, 'command', 24),
            request_validator::positive_id(
                $payload['expectedStateVersion'] ?? null,
                'expectedStateVersion'
            )
        );
    }
}
