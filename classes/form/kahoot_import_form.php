<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Multipart Kahoot import form.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Keeps JSON/ZIP bytes out of the JSON AJAX dispatcher.
 */
final class kahoot_import_form extends \moodleform {

    /**
     * Define the capability-protected upload form.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $maxbytes = (int)($this->_customdata['maxbytes'] ?? 0);
        $maxbytes = $maxbytes > 0
            ? min($maxbytes, \mod_quizgeist\local\kahoot\source_bundle::MAX_ZIP_BYTES)
            : \mod_quizgeist\local\kahoot\source_bundle::MAX_ZIP_BYTES;
        $mform->addElement(
            'filepicker',
            'sourcefile',
            get_string('kahoot:sourcefile', 'mod_quizgeist'),
            null,
            [
                'accepted_types' => ['.json', '.zip'],
                'maxbytes' => $maxbytes,
                'subdirs' => false,
                'maxfiles' => 1,
            ]
        );
        $mform->addRule('sourcefile', null, 'required', null, 'client');
        $mform->addHelpButton('sourcefile', 'kahoot:sourcefile', 'mod_quizgeist');
        $mform->addElement(
            'advcheckbox',
            'dryrun',
            get_string('kahoot:dryrun', 'mod_quizgeist'),
            get_string('kahoot:dryrun:description', 'mod_quizgeist')
        );
        $mform->setDefault('dryrun', 0);
        $this->add_action_buttons(
            true,
            get_string('kahoot:import', 'mod_quizgeist')
        );
    }
}
