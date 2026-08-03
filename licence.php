<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Offline Quizgeist licence administration.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use mod_quizgeist\form\licence_upload_form;
use mod_quizgeist\local\licence\keyring;
use mod_quizgeist\local\licence\licence_exception;
use mod_quizgeist\local\licence\service;
use mod_quizgeist\local\licence\storage;
use mod_quizgeist\local\licence\verifier;

// admin_externalpage_setup() lives in lib/adminlib.php, which Moodle does not
// load on ordinary module pages. Core module admin screens require it
// explicitly and so must this one: without it the page dies with a fatal
// "Call to undefined function" before it renders a single line.
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('modquizgeistlicence');
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_title(get_string('licence:title', 'mod_quizgeist'));
$PAGE->set_heading(get_string('licence:title', 'mod_quizgeist'));
$form = new licence_upload_form();

if ($data = $form->get_data()) {
    $content = $form->get_file_content('licencefile');
    if (!is_string($content)) {
        redirect(
            $PAGE->url,
            get_string(
                'licence:diagnosis:invalid_upload',
                'mod_quizgeist'
            ),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    try {
        $trustedkeys = keyring::production();
        if (!$trustedkeys) {
            throw new licence_exception('production_keyring_missing');
        }
        $result = (new verifier($trustedkeys))->verify(
            $content,
            (string)$CFG->wwwroot
        );
        (new storage())->install(
            $content,
            $result,
            !empty($data->confirmedrecovery)
        );
        redirect(
            $PAGE->url,
            get_string('licence:installed', 'mod_quizgeist'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (licence_exception $exception) {
        $diagnosis = $exception->diagnosis();
        $stringkey = 'licence:diagnosis:' . $diagnosis;
        $message = get_string_manager()->string_exists(
            $stringkey,
            'mod_quizgeist'
        ) ? get_string($stringkey, 'mod_quizgeist') : get_string(
            'licence:diagnosis:generic',
            'mod_quizgeist',
            $diagnosis
        );
        redirect(
            $PAGE->url,
            $message,
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

$snapshot = service::snapshot();
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('licence:statusheading', 'mod_quizgeist'), 2);

if (!keyring::production()) {
    echo $OUTPUT->notification(
        get_string(
            'licence:diagnosis:production_keyring_missing',
            'mod_quizgeist'
        ),
        \core\output\notification::NOTIFY_ERROR
    );
}

$diagnosiskey = 'licence:diagnosis:' . $snapshot['diagnosis'];
$diagnosis = get_string_manager()->string_exists(
    $diagnosiskey,
    'mod_quizgeist'
) ? get_string($diagnosiskey, 'mod_quizgeist') : get_string(
    'licence:diagnosis:generic',
    'mod_quizgeist',
    $snapshot['diagnosis']
);
echo html_writer::tag(
    'p',
    get_string('licence:localstatus', 'mod_quizgeist', $diagnosis)
);

if (is_array($snapshot['license'])) {
    $licence = $snapshot['license'];
    $summary = [
        get_string('licence:customer', 'mod_quizgeist')
            => (string)$licence['customer'],
        get_string('licence:licenseid', 'mod_quizgeist')
            => (string)$licence['license_id'],
        get_string('licence:activationid', 'mod_quizgeist')
            => (string)$licence['activation_id'],
        get_string('licence:revision', 'mod_quizgeist')
            => (string)$licence['revision'],
        get_string('licence:instancelimit', 'mod_quizgeist')
            => (string)$licence['instance_limit'],
        get_string('licence:issuedat', 'mod_quizgeist')
            => userdate(strtotime((string)$licence['issued_at'])),
    ];
    $rows = [];
    foreach ($summary as $label => $value) {
        $rows[] = [$label, s($value)];
    }
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->data = $rows;
    echo html_writer::table($table);
}

echo $OUTPUT->heading(
    get_string('licence:entitlements', 'mod_quizgeist'),
    3
);
$table = new html_table();
$table->head = [
    get_string('licence:addon', 'mod_quizgeist'),
    get_string('licence:installedcolumn', 'mod_quizgeist'),
    get_string('licence:state', 'mod_quizgeist'),
    get_string('licence:expires', 'mod_quizgeist'),
    get_string('licence:graceuntil', 'mod_quizgeist'),
];
foreach (service::COMPONENTS as $feature => $component) {
    $entry = is_array($snapshot['license'])
        ? ($snapshot['license']['entitlements'][$component] ?? null)
        : null;
    $expires = is_array($entry) ? ($entry['expires_at'] ?? null) : null;
    $graceuntil = is_array($entry) ? ($entry['grace_until'] ?? null) : null;
    $installed = \mod_quizgeist\local\addon\registry::is_installed($component);
    $table->data[] = [
        get_string('feature:' . $feature, 'mod_quizgeist'),
        get_string($installed ? 'yes' : 'no'),
        get_string(
            'licence:status:' . (
                $snapshot['entitlements'][$component] ?? 'read_only'
            ),
            'mod_quizgeist'
        ),
        is_string($expires)
            ? userdate(strtotime($expires))
            : get_string('licence:nolimit', 'mod_quizgeist'),
        is_string($graceuntil)
            ? userdate(strtotime($graceuntil))
            : get_string('licence:nolimit', 'mod_quizgeist'),
    ];
}
echo html_writer::table($table);

echo $OUTPUT->heading(get_string('licence:uploadheading', 'mod_quizgeist'), 2);
echo html_writer::tag(
    'p',
    get_string('licence:offlinehelp', 'mod_quizgeist')
);
$form->display();
echo $OUTPUT->footer();
