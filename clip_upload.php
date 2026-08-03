<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The only multipart entry point of mod_quizgeist (U3, decision E-3).
 *
 * ajax.php promises "JSON in, JSON out" (HOUSE_RULES). A multipart branch there
 * would quietly retract that promise for every future reader of the dispatcher,
 * so the clip upload gets its own page instead — modelled on media.php, and
 * registered in checks/p10_runtime.php so entrypointHttp checks it against an
 * expected status rather than only in general.
 *
 * The RESPONSE is still JSON: only the request body is multipart.
 *
 * **P11/C5 (F11a):** the card scan travels through THIS page too, under
 * `kind=cardscan`. That is deliberate and it is the whole point of the
 * sentence above: a second multipart page would mean two upload hardenings,
 * two rate limiters and two places to forget one of them. The two kinds share
 * the transport and the method-first rule, and nothing else — each brings its
 * own capability, its own participation test and its own content rules.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use mod_quizgeist\local\cards\card_exception;
use mod_quizgeist\local\cards\card_scan_service;
use mod_quizgeist\local\licence\feature_gate;
use mod_quizgeist\local\media\clip_exception;
use mod_quizgeist\local\media\clip_limits;
use mod_quizgeist\local\media\clip_service;

/**
 * Emit one JSON document and stop.
 *
 * @param array<string,mixed> $payload Response payload.
 * @param int $status HTTP status.
 * @return void
 */
function quizgeist_clip_upload_respond(array $payload, int $status = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
    die();
}

// The method check comes FIRST, before any parameter or session handling, so
// this page answers a GET exactly as ajax.php does: 405, with no side effects
// and without touching the session. That also makes it a well-defined entry in
// checks/p10_runtime.php P10_RUNTIME_ENTRYPOINT_KNOWN.
if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') {
    quizgeist_clip_upload_respond([
        'error' => [
            'code' => 'clip_upload_failed',
            'message' => get_string('clip:error:uploadfailed', 'mod_quizgeist'),
        ],
    ], 405);
}

$cmid = required_param('id', PARAM_INT);
$kind = optional_param('kind', 'clip', PARAM_ALPHA);
$purpose = $kind === 'cardscan' ? 'cardscan' : required_param('purpose', PARAM_ALPHA);
$language = optional_param('language', clip_service::normalise_language(''), PARAM_ALPHANUMEXT);

$cm = get_coursemodule_from_id('quizgeist', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quizgeist = $DB->get_record('quizgeist', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_sesskey();

// F11a: a class photograph is not a voice recording. It has its own
// capability, its own gate and its own hardening; only the transport is
// shared. The branch returns before the audio path is even reached.
if ($kind === 'cardscan') {
    require_capability('mod/quizgeist:scancards', $context);
    try {
        // Recognising a scan is a NEW AI job, so it is gated on create_new.
        // Existing scans stay visible without a licence — that is 2.6, and it
        // is enforced by the poll handler, not here.
        feature_gate::require('ai', feature_gate::CREATE_NEW);
        $scan = card_scan_service::accept_upload(
            $context,
            $quizgeist,
            (int)$USER->id,
            [
                'sessionId' => required_param('sessionId', PARAM_INT),
                'questionId' => required_param('questionId', PARAM_INT),
                'visit' => required_param('visit', PARAM_ALPHANUM),
                'cardSetId' => required_param('cardSetId', PARAM_INT),
            ],
            isset($_FILES['scan']) && is_array($_FILES['scan']) ? $_FILES['scan'] : []
        );
        quizgeist_clip_upload_respond(['scan' => card_scan_service::project($scan)]);
    } catch (card_exception $exception) {
        quizgeist_clip_upload_respond([
            'error' => [
                'code' => $exception->get_error_code(),
                'message' => get_string($exception->get_string_key(), 'mod_quizgeist'),
            ],
        ], $exception->get_http_status());
    } catch (\mod_quizgeist\local\licence\feature_locked_exception $exception) {
        quizgeist_clip_upload_respond([
            'error' => [
                'code' => 'card_scan_locked',
                'message' => get_string('cards:error:scanlocked', 'mod_quizgeist'),
            ],
        ], 403);
    } catch (\moodle_exception $exception) {
        quizgeist_clip_upload_respond([
            'error' => [
                'code' => 'card_scan_upload_failed',
                'message' => get_string('cards:error:scanuploadfailed', 'mod_quizgeist'),
            ],
        ], 400);
    }
}

require_capability('mod/quizgeist:recordaudio', $context);

try {
    // Dictation is a teaching act in the editor, not a learner submission, so
    // it is bound to :manage instead of to a session or an attempt.
    if ($purpose === 'dictation') {
        require_capability('mod/quizgeist:manage', $context);
    } else if (!\mod_quizgeist\local\media\clip_access::may_record(
        (int)$quizgeist->id,
        (int)$USER->id,
        $purpose
    )) {
        throw new clip_exception('clip_not_recording');
    }

    $clip = clip_service::accept_upload(
        $context,
        (int)$quizgeist->id,
        (int)$USER->id,
        $purpose,
        $language,
        isset($_FILES['clip']) && is_array($_FILES['clip']) ? $_FILES['clip'] : []
    );

    quizgeist_clip_upload_respond([
        'clip' => clip_service::project($clip),
        'limits' => [
            'maxBytes' => clip_limits::max_bytes(),
            'maxSeconds' => clip_limits::max_seconds(),
        ],
    ]);
} catch (clip_exception $exception) {
    quizgeist_clip_upload_respond([
        'error' => [
            // A stable code for the client's message family and a ready-made
            // sentence beside it: the browser never has to render the code
            // itself (F16).
            'code' => $exception->get_error_code(),
            'message' => get_string($exception->get_string_key(), 'mod_quizgeist'),
        ],
    ], $exception->get_http_status());
} catch (\moodle_exception $exception) {
    quizgeist_clip_upload_respond([
        'error' => [
            'code' => 'clip_upload_failed',
            'message' => get_string('clip:error:uploadfailed', 'mod_quizgeist'),
        ],
    ], 400);
}
