<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;
use mod_quizgeist\local\editor\template_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Imports a school template into the current activity.
 */
final class template_import_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $mode = $payload['mode'] ?? 'append';
        if (!is_string($mode)) {
            throw new \invalid_parameter_exception('mode must be a string.');
        }
        return template_service::import(
            $context->get_instance(),
            $context->get_module_context(),
            (int)$context->get_user()->id,
            editor_service::positive_id($payload['templateid'] ?? null, 'templateid'),
            $mode,
            !array_key_exists('includeappearance', $payload) || !empty($payload['includeappearance'])
        );
    }
}
