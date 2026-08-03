<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Same-origin Moodle file-manager dialog for editor media.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

$cmid = required_param('id', PARAM_INT);
$area = required_param('area', PARAM_ALPHA);
$itemid = optional_param('itemid', 0, PARAM_INT);
$target = optional_param('target', '', PARAM_RAW_TRIMMED);
$draftitemid = optional_param('draftitemid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('quizgeist', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quizgeist = $DB->get_record('quizgeist', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/quizgeist:manage', $context);
\mod_quizgeist\local\editor\media_service::require_module_target(
    (int)$quizgeist->id,
    $area,
    $itemid,
    $target
);

$urlparams = [
    'id' => $cm->id,
    'area' => $area,
    'itemid' => $itemid,
];
if ($target !== '') {
    $urlparams['target'] = $target;
}
$PAGE->set_url(new moodle_url('/mod/quizgeist/media.php', $urlparams));
$PAGE->set_context($context);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('file'));
$PAGE->set_heading(format_string($quizgeist->name));

if ($draftitemid <= 0) {
    $draftitemid = \mod_quizgeist\local\editor\media_service::prepare_target_draft(
        $context,
        $area,
        $itemid,
        $target
    );
}

$fileoptions = \mod_quizgeist\local\editor\media_service::file_options(
    $area,
    $context,
    true
);
$form = new \mod_quizgeist\form\media_form(null, [
    'cmid' => (int)$cm->id,
    'area' => $area,
    'itemid' => $itemid,
    'target' => $target,
    'draftitemid' => $draftitemid,
    'fileoptions' => $fileoptions,
]);
$form->set_data((object)[
    'media' => $draftitemid,
]);

$message = null;
if ($form->is_cancelled()) {
    $message = [
        'type' => 'quizgeist-media-cancelled',
        'area' => $area,
        'itemid' => $itemid,
        'target' => $target,
    ];
} else if ($data = $form->get_data()) {
    if ((int)$data->draftitemid !== (int)$data->media) {
        throw new invalid_parameter_exception('The submitted draft does not match this media dialog.');
    }
    $contentlock = $area === 'questionmedia'
        ? \mod_quizgeist\local\editor\question_content_lock::acquire_for_question(
            (int)$quizgeist->id,
            $itemid
        )
        : null;
    try {
        // Revalidate after waiting on a possible COW operation.
        \mod_quizgeist\local\editor\media_service::require_module_target(
            (int)$quizgeist->id,
            $area,
            $itemid,
            $target
        );
        $mediachanged = \mod_quizgeist\local\editor\media_service::target_draft_differs(
            $context,
            $area,
            $itemid,
            $target,
            (int)$data->media
        );
        $olditemid = $itemid;
        $questionidmap = (object)[];
        $pendingmodified = null;
        if ($mediachanged && $area === 'questionmedia') {
            // First make a required content fork and its non-playable pending
            // marker durable. Destructive file merges follow only post-commit.
            $transaction = \mod_quizgeist\local\transaction_scope::begin();
            try {
                $editable = \mod_quizgeist\local\editor\editor_service::ensure_editable_question(
                    $quizgeist,
                    $context,
                    $itemid,
                    (int)$USER->id
                );
                $itemid = (int)$editable['record']->id;
                $questionidmap = $editable['questionIdMap'];
                $pending = \mod_quizgeist\local\editor\editor_service::
                    mark_question_media_pending((int)$quizgeist->id, $itemid);
                $pendingmodified = (int)$pending->timemodified;
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } else if ($area === 'questionmedia') {
            $transaction = \mod_quizgeist\local\transaction_scope::begin();
            try {
                $record = $DB->get_record_sql(
                    'SELECT *
                       FROM {quizgeist_questions}
                      WHERE id = :id
                        AND quizgeistid = :quizgeistid
                        FOR UPDATE',
                    [
                        'id' => $itemid,
                        'quizgeistid' => (int)$quizgeist->id,
                    ],
                    MUST_EXIST
                );
                if ($record->status === 'media_pending') {
                    $pending = \mod_quizgeist\local\editor\editor_service::
                        mark_question_media_pending((int)$quizgeist->id, $itemid);
                    $pendingmodified = (int)$pending->timemodified;
                }
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        }

        try {
            if ($mediachanged) {
                \mod_quizgeist\local\editor\media_service::save_target_draft(
                    $context,
                    $area,
                    $itemid,
                    $target,
                    (int)$data->media
                );
            }
            $question = null;
            if ($area === 'questionmedia') {
                $question = \mod_quizgeist\local\editor\editor_service::
                    refresh_question_media(
                        (int)$quizgeist->id,
                        $itemid,
                        $context,
                        $pendingmodified
                    );
            }
            $files = \mod_quizgeist\local\editor\media_service::manifest(
                $context,
                $area,
                $itemid
            );
            $message = [
                'type' => 'quizgeist-media-saved',
                'area' => $area,
                'itemid' => $itemid,
                'oldquestionid' => $olditemid,
                'questionIdMap' => $questionidmap,
                'target' => $target,
                'files' => $files,
                'question' => $question,
            ];
        } catch (\Throwable $exception) {
            if ($pendingmodified !== null) {
                try {
                    \mod_quizgeist\local\editor\editor_service::
                        fail_question_media_pending(
                            (int)$quizgeist->id,
                            $itemid,
                            $pendingmodified
                        );
                } catch (\Throwable $ignored) {
                    // Preserve media_pending rather than mask the root failure.
                }
            }
            throw $exception;
        }
    } finally {
        if ($contentlock !== null) {
            $contentlock->release();
        }
    }
}

if ($message !== null) {
    $encodedmessage = json_encode(
        $message,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $PAGE->requires->js_init_code(
        "window.parent.postMessage({$encodedmessage}, window.location.origin);"
    );
}

echo $OUTPUT->header();
if ($message === null) {
    $form->display();
} else if ($message['type'] === 'quizgeist-media-saved') {
    echo $OUTPUT->notification(get_string('changessaved'), 'notifysuccess');
} else {
    echo $OUTPUT->notification(get_string('cancelled'), 'notifymessage');
}
echo $OUTPUT->footer();
