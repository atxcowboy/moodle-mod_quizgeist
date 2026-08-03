<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Contract implemented by every Quizgeist addon provider.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\addon;

defined('MOODLE_INTERNAL') || die();

/**
 * Small discovery surface shared by all addon kinds.
 *
 * The empty-returning methods intentionally keep the parent registry generic:
 * adding a future addon never requires another hard-coded class lookup.
 *
 * Every declaration method below is therefore required to accept "nothing to
 * declare" as a normal answer and returns an empty array for it. An addon that
 * ships no question type, no AJAX action, no live mode or no theme is complete,
 * not broken; only a wrongly *shaped* return value is a contract violation.
 */
interface provider {

    /**
     * Feature-gate key owned by this addon.
     *
     * @return string
     */
    public static function feature(): string;

    /**
     * Additional AJAX action descriptors, keyed by action name.
     *
     * Empty when the addon adds no action.
     *
     * @return array<string,array>
     */
    public static function ajax_actions(): array;

    /**
     * Additional live question strategies, keyed by persisted qtype.
     *
     * Empty when the addon brings no question type of its own.
     *
     * @return array<string,class-string<\mod_quizgeist\local\live\qtype\live_question_type>>
     */
    public static function question_type_strategies(): array;

    /**
     * Additional live modes in stable display order.
     *
     * Empty when the addon adds no live mode.
     *
     * @return string[]
     */
    public static function live_modes(): array;

    /**
     * Additional activity themes in stable display order.
     *
     * Empty when the addon adds no theme.
     *
     * @return string[]
     */
    public static function themes(): array;
}
