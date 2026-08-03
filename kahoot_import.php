<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Teacher-facing JSON/ZIP Kahoot importer and report history.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_quizgeist\form\kahoot_import_form;
use mod_quizgeist\local\kahoot\import_repository;
use mod_quizgeist\local\kahoot\import_service;
use mod_quizgeist\local\kahoot\source_bundle;

$cmid = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quizgeist');
require_login($course, false, $cm);
$context = context_module::instance((int)$cm->id);
require_capability('mod/quizgeist:manage', $context);

$PAGE->set_url('/mod/quizgeist/kahoot_import.php', ['id' => (int)$cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('kahoot:title', 'mod_quizgeist'));
$PAGE->set_heading(format_string($course->fullname));

$error = null;
$discardid = optional_param('discardimport', 0, PARAM_INT);
if ($discardid > 0
        && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && data_submitted()) {
    require_sesskey();
    try {
        import_service::discard_stale_import(
            $discardid,
            (int)$course->id,
            (int)$USER->id
        );
        redirect(
            new moodle_url('/mod/quizgeist/kahoot_import.php', [
                'id' => (int)$cm->id,
            ]),
            get_string('kahoot:discard:success', 'mod_quizgeist'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (Throwable $exception) {
        $error = quizgeist_kahoot_exception_message($exception);
    }
} else if ($discardid > 0) {
    $error = get_string('error:importfailed', 'mod_quizgeist');
}

$maxbytes = get_user_max_upload_file_size(
    $context,
    (int)$CFG->maxbytes,
    (int)$course->maxbytes,
    source_bundle::MAX_ZIP_BYTES
);
$form = new kahoot_import_form(null, ['maxbytes' => $maxbytes]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/quizgeist/view.php', [
        'id' => (int)$cm->id,
        'view' => 'manage',
    ]));
}

$batchreport = null;
if ($data = $form->get_data()) {
    $originalname = $form->get_new_filename('sourcefile') ?: 'kahoot.json';
    $extension = strtolower(pathinfo($originalname, PATHINFO_EXTENSION));
    $suffix = in_array($extension, ['json', 'zip'], true) ? '.' . $extension : '.bin';
    $temppath = make_request_directory() . '/kahoot-' . bin2hex(random_bytes(12)) . $suffix;
    try {
        if (!$form->save_file('sourcefile', $temppath, true)) {
            throw new moodle_exception('error:importupload', 'mod_quizgeist');
        }
        $bundle = source_bundle::open($temppath);
        $batchreport = import_service::import_bundle(
            $bundle,
            $course,
            (int)$cm->id,
            (int)$USER->id,
            !empty($data->dryrun)
        );
    } catch (Throwable $exception) {
        $error = quizgeist_kahoot_exception_message($exception);
    } finally {
        if (is_file($temppath)) {
            unlink($temppath);
        }
    }
}

/**
 * Return a concrete, localised import error without leaking debug details.
 *
 * @param Throwable $exception Import failure.
 * @return string
 */
function quizgeist_kahoot_exception_message(Throwable $exception): string {
    if ($exception instanceof moodle_exception) {
        try {
            return clean_param(
                get_string(
                    $exception->errorcode,
                    $exception->module,
                    $exception->a
                ),
                PARAM_TEXT
            );
        } catch (Throwable) {
            // Fall through to the bounded generic message.
        }
    }
    $candidate = trim($exception->getMessage());
    if (preg_match('/^[a-z][a-z0-9_:-]{1,79}$/D', $candidate)) {
        return clean_param(
            import_service::source_error_message($candidate),
            PARAM_TEXT
        );
    }
    return get_string('error:importfailed', 'mod_quizgeist');
}

/**
 * Render one solution-free import report as an accessible details block.
 *
 * @param array $report Report DTO.
 * @param bool $open Whether this one report starts expanded.
 * @param int $discardid Retryable provenance marker ID, if any.
 * @param int $cmid Current page course-module ID.
 * @return string
 */
function quizgeist_render_kahoot_report(
    array $report,
    bool $open = false,
    int $discardid = 0,
    int $cmid = 0
): string {
    $source = is_array($report['source'] ?? null) ? $report['source'] : [];
    $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
    $status = (string)($report['status'] ?? '');
    $title = format_string((string)($source['title'] ?? get_string('kahoot:untitled', 'mod_quizgeist')));
    $summary = html_writer::tag(
        'strong',
        $title
    ) . ' · ' . s(get_string('kahoot:status:' . (
        in_array($status, ['complete', 'failed', 'dry-run'], true) ? $status : 'pending'
    ), 'mod_quizgeist'));

    $rows = [
        [get_string('kahoot:report:source', 'mod_quizgeist'), (int)($totals['source'] ?? 0)],
        [get_string('kahoot:report:imported', 'mod_quizgeist'), (int)($totals['imported'] ?? 0)],
        [get_string('kahoot:report:adjusted', 'mod_quizgeist'), (int)($totals['adjusted'] ?? 0)],
        [get_string('kahoot:report:skipped', 'mod_quizgeist'), (int)($totals['skipped'] ?? 0)],
        [get_string('kahoot:report:media', 'mod_quizgeist'), (int)($totals['mediaImported'] ?? 0)],
    ];
    $table = new html_table();
    $table->attributes['class'] = 'generaltable quizgeist-kahoot-report__totals';
    $table->data = $rows;

    $failure = '';
    if (is_array($report['failure'] ?? null)) {
        $failuretable = new html_table();
        $failuretable->attributes['class'] =
            'generaltable quizgeist-kahoot-report__failure';
        $failuretable->data = [
            [
                get_string('kahoot:report:errorcode', 'mod_quizgeist'),
                s((string)($report['failure']['code'] ?? 'unexpected_error')),
            ],
            [
                get_string('kahoot:report:failure', 'mod_quizgeist'),
                s((string)($report['failure']['message']
                    ?? get_string('error:importfailed', 'mod_quizgeist'))),
            ],
        ];
        $failure = html_writer::table($failuretable);
    }
    if (!empty($report['publicationPending'])) {
        $failure .= html_writer::div(
            get_string(
                'kahoot:report:publicationpending',
                'mod_quizgeist'
            ),
            'alert alert-warning'
        );
    }

    $questionrows = [];
    foreach ($report['questions'] ?? [] as $question) {
        if (!is_array($question)) {
            continue;
        }
        $reasons = array_map(
            static fn(string $reason): string => get_string_manager()->string_exists(
                'kahoot:reason:' . $reason,
                'mod_quizgeist'
            ) ? get_string('kahoot:reason:' . $reason, 'mod_quizgeist')
                : get_string('kahoot:reason:other', 'mod_quizgeist'),
            array_values(array_filter($question['reasons'] ?? [], 'is_string'))
        );
        // The Kahoot type stays the vendor's own identifier: it is the value a
        // teacher compares against the source. The Quizgeist type is ours and
        // therefore carries our own label.
        $targettype = (string)($question['targetType'] ?? '');
        $questionrows[] = [
            (int)($question['sourceIndex'] ?? 0) + 1,
            s((string)($question['sourceType'] ?? '')),
            s($targettype !== '' && get_string_manager()->string_exists(
                'editor:qtype:' . $targettype,
                'mod_quizgeist'
            ) ? get_string('editor:qtype:' . $targettype, 'mod_quizgeist') : '—'),
            s(get_string('kahoot:outcome:' . (
                in_array($question['outcome'] ?? '', ['imported', 'adjusted', 'skipped'], true)
                    ? $question['outcome']
                    : 'skipped'
            ), 'mod_quizgeist')),
            s($reasons ? implode('; ', $reasons) : '—'),
        ];
    }
    $questions = '';
    if ($questionrows) {
        $questiontable = new html_table();
        $questiontable->attributes['class'] = 'generaltable quizgeist-kahoot-report__questions';
        $questiontable->head = [
            '#',
            get_string('kahoot:report:sourcetype', 'mod_quizgeist'),
            get_string('kahoot:report:targettype', 'mod_quizgeist'),
            get_string('kahoot:report:outcome', 'mod_quizgeist'),
            get_string('kahoot:report:reasons', 'mod_quizgeist'),
        ];
        $questiontable->data = $questionrows;
        $questions = html_writer::table($questiontable);
    }
    $attributes = ['class' => 'quizgeist-kahoot-report'];
    if ($open) {
        $attributes['open'] = 'open';
    }
    $details = html_writer::tag(
        'details',
        html_writer::tag('summary', $summary)
            . html_writer::table($table)
            . $failure
            . $questions,
        $attributes
    );
    if ($discardid < 1 || $cmid < 1 || $status === 'complete') {
        return $details;
    }
    $button = html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/mod/quizgeist/kahoot_import.php'))
            ->out(false),
        'class' => 'quizgeist-kahoot-report__discard',
    ]);
    $button .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'id',
        'value' => $cmid,
    ]);
    $button .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'sesskey',
        'value' => sesskey(),
    ]);
    $button .= html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'discardimport',
        'value' => $discardid,
    ]);
    $button .= html_writer::tag(
        'button',
        get_string('kahoot:discard', 'mod_quizgeist'),
        ['type' => 'submit', 'class' => 'btn btn-secondary']
    );
    $button .= html_writer::end_tag('form');
    return $details . $button;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('kahoot:title', 'mod_quizgeist'));
echo html_writer::tag('p', get_string('kahoot:intro', 'mod_quizgeist'));
if ($error !== null) {
    echo $OUTPUT->notification($error, 'notifyproblem');
}
if ($batchreport !== null) {
    $failed = (int)($batchreport['totals']['failed'] ?? 0);
    $succeeded = (int)($batchreport['totals']['succeeded'] ?? 0);
    $publicationpending = count(array_filter(
        $batchreport['reports'] ?? [],
        static fn(array $report): bool =>
            !empty($report['publicationPending'])
    ));
    $notificationtype = $failed === 0 && $publicationpending === 0
        ? 'notifysuccess'
        : ($succeeded > 0 ? 'notifywarning' : 'notifyproblem');
    $notificationkey = $failed === 0 && $publicationpending > 0
        ? 'kahoot:import:publicationpending'
        : ($failed === 0
        ? (!empty($batchreport['dryRun'])
            ? 'kahoot:dryrun:complete'
            : 'kahoot:import:complete')
        : 'kahoot:import:partial');
    $notificationvalue = $failed === 0 && $publicationpending > 0
        ? (object)[
            'succeeded' => $succeeded,
            'pending' => $publicationpending,
        ]
        : ($failed === 0
        ? (int)($batchreport['totals']['kahoots'] ?? 0)
        : (object)['succeeded' => $succeeded, 'failed' => $failed]);
    echo $OUTPUT->notification(
        get_string(
            $notificationkey,
            'mod_quizgeist',
            $notificationvalue
        ),
        $notificationtype
    );
    foreach ($batchreport['reports'] ?? [] as $index => $report) {
        echo quizgeist_render_kahoot_report($report, $index === 0);
    }
}
$form->display();

$history = import_repository::list_for_target((int)$cm->instance);
$batchkeys = [];
foreach ($batchreport['reports'] ?? [] as $report) {
    if (($report['status'] ?? '') !== 'complete') {
        continue;
    }
    $source = is_array($report['source'] ?? null) ? $report['source'] : [];
    $batchkeys[hash(
        'sha256',
        (string)($source['uuid'] ?? '')
            . "\0"
            . (string)($source['sha256'] ?? '')
    )] = true;
}
$historyreports = [];
foreach ($history as $record) {
    $report = import_repository::report($record);
    $source = is_array($report['source'] ?? null) ? $report['source'] : [];
    $key = hash(
        'sha256',
        (string)($source['uuid'] ?? '')
            . "\0"
            . (string)($source['sha256'] ?? '')
    );
    if ((string)$record->status === 'complete' && isset($batchkeys[$key])) {
        continue;
    }
    $historyreports[] = [$record, $report];
}
if ($historyreports) {
    echo $OUTPUT->heading(get_string('kahoot:history', 'mod_quizgeist'), 3);
    foreach ($historyreports as [$record, $report]) {
        echo quizgeist_render_kahoot_report(
            $report,
            false,
            (string)$record->status === 'complete' ? 0 : (int)$record->id,
            (int)$cm->id
        );
    }
}
echo $OUTPUT->footer();
