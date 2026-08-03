<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Activity viewed event.
 *
 * @package    mod_quizgeist
 * @category   event
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\event;

defined('MOODLE_INTERNAL') || die();

/**
 * A user viewed a Quizgeist activity.
 */
class course_module_viewed extends \core\event\course_module_viewed {

    /**
     * Set event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'quizgeist';
    }

    /**
     * Tell restore how to remap the event object ID.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'quizgeist', 'restore' => 'quizgeist'];
    }
}
