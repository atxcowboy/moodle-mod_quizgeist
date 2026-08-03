<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Restore task for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @category   backup
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quizgeist/backup/moodle2/restore_quizgeist_stepslib.php');

/**
 * Activity restore task.
 */
class restore_quizgeist_activity_task extends restore_activity_task {

    /**
     * There are no activity-specific restore settings.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Add the activity structure step.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_quizgeist_activity_structure_step(
            'quizgeist_structure',
            'quizgeist.xml'
        ));
    }

    /**
     * Text fields that need content-link decoding.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('quizgeist', ['intro'], 'quizgeist'),
            new restore_decode_content(
                'quizgeist_questions',
                ['questiontext', 'explanation', 'optionsjson'],
                'quizgeist_question'
            ),
        ];
    }

    /**
     * Portable activity-link decode rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule(
                'QUIZGEISTVIEWBYID',
                '/mod/quizgeist/view.php?id=$1',
                'course_module'
            ),
            new restore_decode_rule(
                'QUIZGEISTINDEX',
                '/mod/quizgeist/index.php?id=$1',
                'course'
            ),
        ];
    }

    /**
     * Legacy activity log restore rules.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('quizgeist', 'add', 'view.php?id={course_module}', '{quizgeist}'),
            new restore_log_rule('quizgeist', 'update', 'view.php?id={course_module}', '{quizgeist}'),
            new restore_log_rule('quizgeist', 'view', 'view.php?id={course_module}', '{quizgeist}'),
        ];
    }

    /**
     * Legacy course log restore rules.
     *
     * @return array
     */
    public static function define_restore_log_rules_for_course() {
        return [
            new restore_log_rule('quizgeist', 'view all', 'index.php?id={course}', null),
        ];
    }
}
