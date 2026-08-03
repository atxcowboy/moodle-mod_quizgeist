<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX action handler contract.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\ajax;

defined('MOODLE_INTERNAL') || die();

/**
 * Contract implemented by one handler per AJAX action.
 */
interface action_handler {

    /**
     * Execute the action.
     *
     * @param action_context $context Validated and authorised request context.
     * @return array JSON-serialisable response data.
     */
    public function execute(action_context $context): array;
}
