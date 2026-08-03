<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Same-origin delivery of one recorded clip (U3).
 *
 * The `require_once($CFG->libdir . '/filelib.php')` below is the lesson of
 * [P10-F15]: an entry point that calls a core file function without requiring
 * its library works while some other page happens to have loaded it first and
 * fails the moment it is opened directly.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use mod_quizgeist\local\media\clip_access;
use mod_quizgeist\local\media\clip_service;

$cmid = required_param('id', PARAM_INT);
$clipid = required_param('clip', PARAM_INT);

$cm = get_coursemodule_from_id('quizgeist', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quizgeist = $DB->get_record('quizgeist', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);

$clip = clip_service::get((int)$quizgeist->id, $clipid);
if ($clip === null) {
    throw new moodle_exception('clip:error:notfound', 'mod_quizgeist');
}
if (!clip_access::may_listen($context, $clip, (int)$USER->id)) {
    // Same message as a missing clip: whether a given ID exists is itself
    // information about other people's participation.
    throw new moodle_exception('clip:error:notfound', 'mod_quizgeist');
}

$file = clip_service::file($context, $clip);
if ($file === null) {
    // A deleted recording is the normal end of a clip's life (E-10), not an
    // incident: the transcript in the report remains.
    throw new moodle_exception('clip:error:audiodeleted', 'mod_quizgeist');
}

// forcedownload = false so the player can stream it inline; no caching in a
// shared browser, because this is someone's voice.
send_stored_file($file, 0, 0, false, [
    'cacheability' => 'private',
    'immutable' => false,
    'dontdie' => false,
]);
