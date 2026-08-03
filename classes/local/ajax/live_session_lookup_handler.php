<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves a six-digit join code without exposing participant identities.
 */
final class live_session_lookup_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        return session_service::lookup_session(
            $context->get_instance(),
            request_validator::string($payload['joinCode'] ?? null, 'joinCode', 6),
            $context->get_module_context(),
            $context->get_user()
        );
    }
}
