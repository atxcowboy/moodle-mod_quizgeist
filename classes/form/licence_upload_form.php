<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Offline licence upload form.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Accepts one bounded signed JSON envelope.
 */
final class licence_upload_form extends \moodleform {

    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement(
            'filepicker',
            'licencefile',
            get_string('licence:file', 'mod_quizgeist'),
            null,
            [
                'accepted_types' => ['.json', '.licence'],
                'maxbytes' => 131072,
                'subdirs' => 0,
            ]
        );
        $mform->addRule(
            'licencefile',
            get_string('required'),
            'required',
            null,
            'client'
        );
        $mform->addElement(
            'advcheckbox',
            'confirmedrecovery',
            get_string('licence:recovery', 'mod_quizgeist'),
            get_string('licence:recovery:description', 'mod_quizgeist')
        );
        $mform->setType('confirmedrecovery', PARAM_BOOL);
        $this->add_action_buttons(
            false,
            get_string('licence:install', 'mod_quizgeist')
        );
    }
}
