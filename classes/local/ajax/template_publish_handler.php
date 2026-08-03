<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\template_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Publishes an independent system-context template snapshot.
 */
final class template_publish_handler implements action_handler {
    public function execute(action_context $context): array {
        return ['template' => template_service::publish(
            $context->get_instance(),
            $context->get_module_context(),
            (int)$context->get_user()->id,
            $context->get_payload()
        )];
    }
}
