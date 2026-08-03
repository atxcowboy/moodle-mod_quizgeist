<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\tagging\tag_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Replaces the complete tag assignment list of one question root.
 *
 * The handler resolves the root itself; a caller can only ever name an exact
 * question version. Assignments outlive every content version because they
 * hang on quizgeist_questions.rootid.
 */
final class question_tags_save_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $questionid = (int)($payload['questionId'] ?? 0);
        if ($questionid <= 0) {
            throw new \invalid_parameter_exception('questionId is required.');
        }
        if (!array_key_exists('tags', $payload)) {
            throw new \invalid_parameter_exception('tags is required.');
        }
        // A learner suggestion is never written by this teacher action; the
        // question workshop owns that path and its own capability.
        return tag_service::save_question_tags(
            $context->get_instance(),
            $questionid,
            $payload['tags'],
            (int)$context->get_user()->id,
            'approved'
        );
    }
}
