<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;
use mod_quizgeist\local\editor\template_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Removes a template owned by the user (or by a site administrator).
 */
final class template_delete_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        return template_service::delete(
            editor_service::positive_id($payload['templateid'] ?? null, 'templateid'),
            (int)$context->get_user()->id
        );
    }
}
