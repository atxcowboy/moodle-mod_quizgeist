<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Saves inline activity title, appearance and navigation.
 */
final class quiz_save_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        if (!isset($payload['activity']) || !is_array($payload['activity'])) {
            throw new \invalid_parameter_exception('activity is required.');
        }
        return ['activity' => editor_service::save_activity(
            $context->get_instance(),
            $payload['activity']
        )];
    }
}
