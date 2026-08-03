<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\tagging\tag_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Lists every tag and question assignment visible to this activity.
 */
final class tag_list_handler implements action_handler {
    public function execute(action_context $context): array {
        return tag_service::project_activity($context->get_instance());
    }
}
