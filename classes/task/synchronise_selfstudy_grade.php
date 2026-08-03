<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Durable grade/completion reconciliation after self-study terminalisation.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\task;

use mod_quizgeist\local\selfstudy\attempt_submission_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Retries aggregate grade and completion side effects independently.
 */
final class synchronise_selfstudy_grade extends \core\task\adhoc_task {

    /**
     * Localised task name.
     */
    public function get_name(): string {
        return get_string('task:synchroniseselfstudygrade', 'mod_quizgeist');
    }

    /**
     * Keep retrying transient gradebook/completion failures.
     */
    public function retry_until_success(): bool {
        return true;
    }

    /**
     * Reconcile from durable attempts; deleted activities/users are terminal.
     */
    public function execute(): void {
        global $DB;

        if (!\mod_quizgeist\local\addon\registry::is_installed(
            'quizgeistaddon_selfstudy'
        )) {
            return;
        }
        $data = $this->get_custom_data();
        $quizgeistid = (int)($data->quizgeistid ?? 0);
        $userid = (int)($data->userid ?? 0);
        if ($quizgeistid <= 0 || $userid <= 0) {
            throw new \coding_exception(
                'A self-study grade sync task has invalid custom data.'
            );
        }
        $quizgeist = $DB->get_record(
            'quizgeist',
            ['id' => $quizgeistid],
            '*',
            IGNORE_MISSING
        );
        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id',
            IGNORE_MISSING
        );
        if (!$quizgeist || !$user) {
            return;
        }
        $cm = get_coursemodule_from_instance(
            'quizgeist',
            $quizgeistid,
            (int)$quizgeist->course,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return;
        }
        attempt_submission_service::synchronise_grade_and_completion(
            $cm,
            $quizgeist,
            $userid
        );
    }
}
