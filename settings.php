<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Site administration settings for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$quizgeistcomponent = 'mod_quizgeist';
$ADMIN->add('modsettings', new admin_externalpage(
    'modquizgeistlicence',
    get_string('licence:title', $quizgeistcomponent),
    new moodle_url('/mod/quizgeist/licence.php'),
    'moodle/site:config'
));

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'mod_quizgeist/licence_heading',
        get_string('licence:title', $quizgeistcomponent),
        get_string(
            'licence:settingslink',
            $quizgeistcomponent,
            (new moodle_url('/mod/quizgeist/licence.php'))->out()
        )
    ));

    // U3 Kurzclip-Kanal. Bewusst im BASIS-Plugin und nicht im KI-Addon:
    // Aufnahme und Ablage gehoeren zur Basis, damit vorhandene Aufnahmen
    // auch ohne Addon hoer- und loeschbar bleiben (P11_PLAN.md 3/U3).
    $settings->add(new admin_setting_heading(
        'mod_quizgeist/clip_heading',
        get_string('settings:clip_heading', $quizgeistcomponent),
        get_string('settings:clip_heading:description', $quizgeistcomponent)
    ));
    $settings->add(new admin_setting_configtext(
        'mod_quizgeist/clip_max_bytes',
        get_string('settings:clip_max_bytes', $quizgeistcomponent),
        get_string('settings:clip_max_bytes:description', $quizgeistcomponent),
        (string)\mod_quizgeist\local\media\clip_limits::DEFAULT_MAX_BYTES,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'mod_quizgeist/clip_max_seconds',
        get_string('settings:clip_max_seconds', $quizgeistcomponent),
        get_string('settings:clip_max_seconds:description', $quizgeistcomponent),
        (string)\mod_quizgeist\local\media\clip_limits::DEFAULT_MAX_SECONDS,
        PARAM_INT
    ));
    // E-10: Werkseinstellung 0 = sofort loeschen. Eine Aufbewahrung ist eine
    // bewusste Entscheidung der Schule, kein stiller Standard.
    $settings->add(new admin_setting_configtext(
        'mod_quizgeist/clip_retention_days',
        get_string('settings:clip_retention_days', $quizgeistcomponent),
        get_string('settings:clip_retention_days:description', $quizgeistcomponent),
        (string)\mod_quizgeist\local\media\clip_limits::DEFAULT_RETENTION_DAYS,
        PARAM_INT
    ));

    // F11a Karten-Modus. Wie beim Kurzclip liegen Ablage und Loeschung in der
    // BASIS: ein bereits aufgenommenes Klassenfoto muss auch dann verschwinden
    // koennen, wenn das KI-Addon entfernt wurde.
    $settings->add(new admin_setting_heading(
        'mod_quizgeist/cardscan_heading',
        get_string('settings:cardscan_heading', $quizgeistcomponent),
        get_string('settings:cardscan_heading:description', $quizgeistcomponent)
    ));
    $settings->add(new admin_setting_configtext(
        'mod_quizgeist/card_scan_max_bytes',
        get_string('settings:card_scan_max_bytes', $quizgeistcomponent),
        get_string('settings:card_scan_max_bytes:description', $quizgeistcomponent),
        (string)\mod_quizgeist\local\cards\card_limits::DEFAULT_MAX_BYTES,
        PARAM_INT
    ));
    // E-10: Ein ausgewerteter Scan verliert sein Bild SOFORT, unabhaengig von
    // diesem Wert. Die Stunden sind die Obergrenze fuer einen abgebrochenen
    // Scan, den niemand mehr bestaetigt hat.
    $settings->add(new admin_setting_configtext(
        'mod_quizgeist/card_scan_retention_hours',
        get_string('settings:card_scan_retention_hours', $quizgeistcomponent),
        get_string('settings:card_scan_retention_hours:description', $quizgeistcomponent),
        (string)\mod_quizgeist\local\cards\card_limits::DEFAULT_RETENTION_HOURS,
        PARAM_INT
    ));
}
