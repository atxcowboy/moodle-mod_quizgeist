<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Finalise due, closed and abandoned self-study attempts.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\task;

use mod_quizgeist\local\selfstudy\attempt_submission_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies the same locked terminalisation path used by an explicit finish.
 */
final class finalise_stale_attempts extends \core\task\scheduled_task {

    /**
     * Localised task name.
     */
    public function get_name(): string {
        return get_string('task:finalisestaleattempts', 'mod_quizgeist');
    }

    /**
     * Complete keyset-paginated candidates while preserving every answer.
     */
    public function execute(): void {
        if (!\mod_quizgeist\local\addon\registry::is_installed(
            'quizgeistaddon_selfstudy'
        )) {
            return;
        }
        $completed = attempt_submission_service::finalise_scheduled();
        mtrace(
            'mod_quizgeist: finalised '
            . $completed
            . ' due, closed or stale self-study attempt(s).'
        );
    }
}
