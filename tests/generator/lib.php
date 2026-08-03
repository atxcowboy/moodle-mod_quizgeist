<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Test data generator for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Creates Quizgeist activity instances for tests.
 *
 * Moodle refuses `create_module('quizgeist', …)` outright when this class does
 * not exist. The defaults below are deliberately the free base package: no
 * premium theme, no premium live mode, so a test never fails because an addon
 * happens to be uninstalled on the machine running it.
 */
class mod_quizgeist_generator extends testing_module_generator {

    /**
     * Create one activity instance with base-package defaults.
     *
     * @param array|stdClass|null $record Instance data.
     * @param array|null $options Generator options.
     * @return stdClass
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;
        $defaults = [
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'theme' => 'hell',
            'season' => 'herbst',
            'allowbacktrack' => 1,
            'defaultmode' => 'classic',
            'grademethod' => 'best',
            'grade' => 0,
            'completionparticipate' => 0,
            'completionpercent' => 0,
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->{$field})) {
                $record->{$field} = $value;
            }
        }
        return parent::create_instance($record, (array)$options);
    }
}
