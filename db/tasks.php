<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task definitions for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\mod_quizgeist\task\close_stale_sessions',
        'blocking' => 0,
        'minute' => '17',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\mod_quizgeist\task\send_assignment_reminders',
        'blocking' => 0,
        'minute' => '23',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        'classname' => '\mod_quizgeist\task\finalise_stale_attempts',
        'blocking' => 0,
        'minute' => '29',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // U2 Wiederholungs-Kern: raeumt nur auf, rechnet nie nach. Deshalb
        // genuegt ein naechtlicher Lauf ausserhalb der Unterrichtszeit.
        'classname' => '\mod_quizgeist\task\refresh_schedule_digest',
        'blocking' => 0,
        'minute' => '41',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // U3/E-10: Rohdaten-Loeschung ist der Standard, nicht die Ausnahme.
        // Deshalb laeuft der Loeschweg VIERTELSTUENDLICH und nicht nachts —
        // bei der Werkseinstellung "sofort loeschen" darf eine Aufnahme nicht
        // bis zum naechsten Morgen liegen bleiben.
        'classname' => '\mod_quizgeist\task\purge_clips',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // F11a/E-10: Klassenfotos sind besonders schuetzenswert. Der Loeschweg
        // laeuft deshalb VIERTELSTUENDLICH und nicht nachts — ein Bild, das
        // seinen Zweck erfuellt hat, darf keine Nacht liegen bleiben.
        'classname' => '\\mod_quizgeist\\task\\purge_card_scans',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
