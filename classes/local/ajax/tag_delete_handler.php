<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\tagging\tag_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Deletes one tag and every question assignment it carries.
 *
 * The answer is the complete tagging read model of this activity, the same
 * shape tag_list returns, so the editor can redraw without a second request.
 */
final class tag_delete_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $tagid = (int)($payload['tagId'] ?? 0);
        if ($tagid <= 0) {
            throw new \invalid_parameter_exception('tagId is required.');
        }
        return tag_service::delete_tag($context->get_instance(), $tagid);
    }
}
