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
        $quizgeist = $context->get_instance();
        // "Fehlerfreundlich bei neuen Themen" works through the reserved tag
        // "Neu". Instance creation and the upgrade create it; this covers
        // activities that arrived by restore or duplication. A race on the
        // unique index must never cost the teacher the tag list.
        if (!empty($quizgeist->friendlynew)) {
            try {
                tag_service::ensure_reserved_new_tag($quizgeist);
            } catch (\dml_exception $exception) {
                debugging('Quizgeist: reserved tag not ensured: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return tag_service::project_activity($quizgeist);
    }
}
