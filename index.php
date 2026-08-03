<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * List all Quizgeist activities in a course.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_course_login($course);

$event = \mod_quizgeist\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/quizgeist/index.php', ['id' => $course->id]);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('modulenameplural', 'mod_quizgeist'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('modulenameplural', 'mod_quizgeist'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_quizgeist'));

$instances = get_all_instances_in_course('quizgeist', $course);
if (!$instances) {
    echo $OUTPUT->notification(get_string('nonewmodules', 'mod_quizgeist'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';
$table->head = [
    get_string('name'),
    get_string('description'),
];
$table->data = [];

foreach ($instances as $instance) {
    if (empty($instance->visible) && !has_capability('moodle/course:viewhiddenactivities', context_course::instance($course->id))) {
        continue;
    }
    $link = html_writer::link(
        new moodle_url('/mod/quizgeist/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name)
    );
    $intro = format_module_intro('quizgeist', $instance, $instance->coursemodule);
    $table->data[] = [$link, $intro];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
