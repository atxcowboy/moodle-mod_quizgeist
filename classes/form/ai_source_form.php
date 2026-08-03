<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\form;

defined('MOODLE_INTERNAL') || die();

// \moodleform lives in lib/formslib.php, which Moodle does not load on
// ordinary module pages. Without this line the autoloader resolves this file
// and PHP then dies with 'Class "moodleform" not found' - the same trap that
// killed licence.php (P10-F15).
require_once($CFG->libdir . '/formslib.php');

/**
 * One-file source picker for the P7 workshop.
 */
final class ai_source_form extends \moodleform {

    /**
     * Define the Moodle file-manager form.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $data = $this->_customdata;

        $mform->addElement('hidden', 'id', (int)$data['cmid']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'purpose', (string)$data['purpose']);
        $mform->setType('purpose', PARAM_ALPHANUMEXT);
        $mform->addElement('hidden', 'draftitemid', (int)$data['draftitemid']);
        $mform->setType('draftitemid', PARAM_INT);
        $mform->addElement('hidden', 'sourcetoken', (string)$data['sourcetoken']);
        $mform->setType('sourcetoken', PARAM_ALPHANUM);
        $mform->addElement(
            'filemanager',
            'source',
            get_string('ai:source:file', 'mod_quizgeist'),
            null,
            $data['fileoptions']
        );
        $mform->setDefault('source', (int)$data['draftitemid']);
        $this->add_action_buttons(true, get_string('ai:source:select', 'mod_quizgeist'));
    }
}
