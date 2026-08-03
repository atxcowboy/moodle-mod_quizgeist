<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\tagging\tag_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates or updates one tag; identical identities are merged, not duplicated.
 */
final class tag_save_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        if (!isset($payload['tag']) || !is_array($payload['tag'])) {
            throw new \invalid_parameter_exception('tag is required.');
        }
        return tag_service::save_tag(
            $context->get_instance(),
            $payload['tag']
        );
    }
}
