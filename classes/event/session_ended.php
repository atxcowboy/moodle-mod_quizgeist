<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Live session ended event.
 *
 * @package    mod_quizgeist
 * @category   event
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\event;

defined('MOODLE_INTERNAL') || die();

/**
 * A host ended or completed a live Quizgeist session.
 */
class session_ended extends \core\event\base {

    /**
     * Set event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['objecttable'] = 'quizgeist_sessions';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventsessionended', 'mod_quizgeist');
    }

    /**
     * Admin-facing event description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' ended the Quizgeist live session "
            . "with id '{$this->objectid}'.";
    }

    /**
     * Link to the activity and session.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/quizgeist/view.php', [
            'id' => $this->contextinstanceid,
            'view' => 'host',
        ]);
    }

    /**
     * Tell restore how to remap the event object ID.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'quizgeist_sessions', 'restore' => 'quizgeist_session'];
    }
}
