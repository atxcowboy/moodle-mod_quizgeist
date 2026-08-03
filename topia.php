<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Lightweight course-wide Geistopia roadmap.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quizgeist');
require_login($course, false, $cm);
$context = context_module::instance((int)$cm->id);
require_capability('mod/quizgeist:view', $context);
if (!\mod_quizgeist\local\licence\feature_gate::allows(
    'modes',
    \mod_quizgeist\local\licence\feature_gate::VIEW_EXISTING
)) {
    throw new moodle_exception('notavailable', 'error');
}

$PAGE->set_url('/mod/quizgeist/topia.php', ['id' => (int)$cm->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('topia:title', 'mod_quizgeist'));
$PAGE->set_heading(format_string($course->fullname));

$state = \mod_quizgeist\local\topia\course_progress::get((int)$course->id);
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('topia:title', 'mod_quizgeist'));
echo html_writer::tag('p', get_string('topia:intro', 'mod_quizgeist'));
$stars = (int)$state['stars'];
echo html_writer::tag(
    'p',
    get_string(
        $stars === 1 ? 'topia:stars:one' : 'topia:stars:other',
        'mod_quizgeist',
        $stars
    ),
    ['class' => 'lead']
);

$items = [];
foreach ($state['roadmap'] as $stage) {
    $key = clean_param($stage['key'], PARAM_ALPHANUMEXT);
    $label = get_string('topia:stage:' . $key, 'mod_quizgeist');
    $status = get_string(
        !empty($stage['unlocked']) ? 'topia:unlocked' : 'topia:locked',
        'mod_quizgeist',
        (int)$stage['threshold']
    );
    $items[] = html_writer::tag(
        'li',
        html_writer::tag('strong', $label) . html_writer::tag('span', $status),
        ['class' => !empty($stage['unlocked']) ? 'is-unlocked' : 'is-locked']
    );
}
echo html_writer::tag(
    'ol',
    implode('', $items),
    ['class' => 'quizgeist-topia-roadmap']
);
echo html_writer::tag('p', get_string('topia:nosecondcurrency', 'mod_quizgeist'));
echo $OUTPUT->footer();
