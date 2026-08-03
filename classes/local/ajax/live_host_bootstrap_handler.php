<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Loads host setup or one explicitly requested reconnect session.
 */
final class live_host_bootstrap_handler implements action_handler {
    public function execute(action_context $context): array {
        return session_service::host_bootstrap(
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user(),
            request_validator::optional_id($context->get_payload(), 'sessionId')
        );
    }
}
