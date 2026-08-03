<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cache definitions for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'svgsafety' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
    ],
    'liveprojection' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600,
    ],
    'lookuprate' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 60,
    ],
    // U3: Uploadfrequenz je Nutzer und Aktivitaet. Gleicher Zuschnitt wie
    // lookuprate — ein Zaehler mit Fensterstart, sonst nichts.
    // F11a: Ratenzaehler des Kartenscans, Zwilling von cliprate.
    'cardscanrate' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 60,
    ],
    'cliprate' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 60,
    ],
    'aidrafts' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        // Canonical questions and options are deeply nested arrays.
        'simpledata' => false,
        'ttl' => 3600,
    ],
    'licence' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        // The cached snapshot contains nested entitlement metadata.
        'simpledata' => false,
        // Signed transitions additionally bound each cached value exactly.
        'ttl' => 3600,
    ],
];
