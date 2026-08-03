<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Standard Moodle file-manager form used by the editor media dialog.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\form;

defined('MOODLE_INTERNAL') || die();

// \moodleform lives in lib/formslib.php, which Moodle does not load on
// ordinary module pages. Without this line the autoloader resolves this file
// and PHP then dies with 'Class "moodleform" not found' - the same trap that
// killed licence.php (P10-F15).
require_once($CFG->libdir . '/formslib.php');

/**
 * One-slot media picker.
 */
final class media_form extends \moodleform {

    /**
     * Define the form.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        $mform->addElement('hidden', 'id', (int)$customdata['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'area', (string)$customdata['area']);
        $mform->setType('area', PARAM_ALPHA);
        $mform->addElement('hidden', 'itemid', (int)$customdata['itemid']);
        $mform->setType('itemid', PARAM_INT);
        $mform->addElement('hidden', 'target', (string)$customdata['target']);
        $mform->setType('target', PARAM_RAW_TRIMMED);
        $mform->addElement('hidden', 'draftitemid', (int)$customdata['draftitemid']);
        $mform->setType('draftitemid', PARAM_INT);

        $mform->addElement(
            'filemanager',
            'media',
            get_string('file'),
            null,
            $customdata['fileoptions']
        );
        $mform->setDefault('media', (int)$customdata['draftitemid']);

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
