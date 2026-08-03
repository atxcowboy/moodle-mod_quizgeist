<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Course activity-list viewed event.
 *
 * @package    mod_quizgeist
 * @category   event
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\event;

defined('MOODLE_INTERNAL') || die();

/**
 * A user viewed the list of Quizgeist activities in a course.
 *
 * Moodle 5.2 still consumes this compatibility event in participation
 * reports even though core has begun migrating generic resource lists.
 */
class course_module_instance_list_viewed extends \core\event\course_module_instance_list_viewed {
}
