<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\template_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Searches the school-wide template library.
 */
final class template_search_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $query = $payload['query'] ?? '';
        if (!is_scalar($query) && $query !== null) {
            throw new \invalid_parameter_exception('query must be scalar.');
        }
        return template_service::search(
            (string)$query,
            (int)$context->get_user()->id,
            (int)$context->get_module_context()->instanceid
        );
    }
}
