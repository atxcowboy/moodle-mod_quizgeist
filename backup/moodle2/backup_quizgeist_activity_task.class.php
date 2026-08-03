<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Backup task for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @category   backup
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quizgeist/backup/moodle2/backup_quizgeist_stepslib.php');

/**
 * Activity backup task.
 */
class backup_quizgeist_activity_task extends backup_activity_task {

    /**
     * There are no activity-specific backup settings.
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
        $this->add_step(new backup_quizgeist_activity_structure_step(
            'quizgeist_structure',
            'quizgeist.xml'
        ));
    }

    /**
     * Replace links to this activity with portable backup tokens.
     *
     * @param string $content Content to encode.
     * @return string Encoded content.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');
        $content = preg_replace(
            '/(' . $base . '\/mod\/quizgeist\/index.php\?id\=)([0-9]+)/',
            '$@QUIZGEISTINDEX*$2@$',
            $content
        );
        $content = preg_replace(
            '/(' . $base . '\/mod\/quizgeist\/view.php\?id\=)([0-9]+)/',
            '$@QUIZGEISTVIEWBYID*$2@$',
            $content
        );

        return $content;
    }
}
