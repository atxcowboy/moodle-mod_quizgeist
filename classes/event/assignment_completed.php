<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Self-learning assignment completed event.
 *
 * @package    mod_quizgeist
 * @category   event
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\event;

defined('MOODLE_INTERNAL') || die();

/**
 * A learner completed a self-learning assignment attempt.
 */
class assignment_completed extends \core\event\base {

    /**
     * Set event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'quizgeist_attempts';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventassignmentcompleted', 'mod_quizgeist');
    }

    /**
     * Admin-facing event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' completed the Quizgeist "
            . "self-learning attempt with id '{$this->objectid}'.";
    }

    /**
     * Link to the activity.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/quizgeist/view.php', [
            'id' => $this->contextinstanceid,
            'attemptid' => $this->objectid,
        ]);
    }

    /**
     * Require the learner to be recorded as the related user.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (empty($this->relateduserid)) {
            throw new \coding_exception('The assignment_completed event requires relateduserid.');
        }
    }

    /**
     * Tell restore how to remap the event object ID.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'quizgeist_attempts', 'restore' => 'quizgeist_attempt'];
    }
}
