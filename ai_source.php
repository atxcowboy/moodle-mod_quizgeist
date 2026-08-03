<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Same-origin source-file picker for the P7 workshop.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/repository/lib.php');

$cmid = required_param('id', PARAM_INT);
$purpose = required_param('purpose', PARAM_ALPHANUMEXT);
$draftitemid = optional_param('draftitemid', 0, PARAM_INT);
$sourcetoken = optional_param('sourcetoken', '', PARAM_ALPHANUM);
$purposes = ['pdf', 'pdf_questions', 'slides', 'handwriting'];
if (!in_array($purpose, $purposes, true)) {
    throw new invalid_parameter_exception('Invalid AI source purpose.');
}

$cm = get_coursemodule_from_id('quizgeist', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quizgeist = $DB->get_record('quizgeist', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, false, $cm);
require_capability('mod/quizgeist:manage', $context);

if (!\mod_quizgeist\local\addon\registry::is_installed('quizgeistaddon_ai')) {
    throw new moodle_exception('notavailable', 'error');
}
if (!\mod_quizgeist\local\licence\feature_gate::can_create('ai')) {
    throw new moodle_exception(
        'licence:featurelocked',
        'mod_quizgeist'
    );
}

$PAGE->set_url('/mod/quizgeist/ai_source.php', [
    'id' => $cm->id,
    'purpose' => $purpose,
]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(get_string('ai:source:title', 'mod_quizgeist'));
$PAGE->set_heading(format_string($quizgeist->name));

if ($sourcetoken === '') {
    // Never bind a caller-supplied numeric draft ID. A fresh server-selected
    // ID and opaque binding are the provenance boundary for this picker.
    $draftitemid = file_get_unused_draft_itemid();
    $sourcetoken = \quizgeistaddon_ai\local\ai\source_store::create(
        (int)$USER->id,
        (int)$cm->id,
        $purpose,
        $draftitemid
    );
} else {
    $binding = \quizgeistaddon_ai\local\ai\source_store::get(
        $sourcetoken,
        (int)$USER->id,
        (int)$cm->id,
        $purpose
    );
    if ($draftitemid > 0 && $draftitemid !== $binding['draftItemId']) {
        throw new invalid_parameter_exception('The AI source binding does not match this picker.');
    }
    $draftitemid = $binding['draftItemId'];
}
$isvision = $purpose === 'handwriting';
$fileoptions = [
    'subdirs' => false,
    'maxfiles' => 1,
    'maxbytes' => $isvision ? 10 * 1024 * 1024 : 50 * 1024 * 1024,
    'accepted_types' => $isvision
        ? ['.png', '.jpg', '.jpeg', '.webp']
        : ($purpose === 'slides' ? ['.pdf', '.pptx'] : ['.pdf']),
    'return_types' => FILE_INTERNAL,
];
$form = new \mod_quizgeist\form\ai_source_form(null, [
    'cmid' => (int)$cm->id,
    'purpose' => $purpose,
    'draftitemid' => $draftitemid,
    'sourcetoken' => $sourcetoken,
    'fileoptions' => $fileoptions,
]);
$form->set_data((object)[
    'source' => $draftitemid,
    'sourcetoken' => $sourcetoken,
]);

$message = null;
$rejection = null;
if ($form->is_cancelled()) {
    get_file_storage()->delete_area_files(
        context_user::instance((int)$USER->id)->id,
        'user',
        'draft',
        $draftitemid
    );
    \quizgeistaddon_ai\local\ai\source_store::discard($sourcetoken);
    $message = [
        'type' => 'quizgeist-ai-source-cancelled',
        'purpose' => $purpose,
        'draftItemId' => $draftitemid,
        'sourceToken' => $sourcetoken,
    ];
} else if ($data = $form->get_data()) {
    if ((int)$data->draftitemid !== (int)$data->source
            || (int)$data->draftitemid !== $draftitemid
            || !is_string($data->sourcetoken)
            || !hash_equals($sourcetoken, $data->sourcetoken)) {
        throw new invalid_parameter_exception(
            'The submitted source draft does not match this picker.'
        );
    }
    \quizgeistaddon_ai\local\ai\source_store::get(
        $sourcetoken,
        (int)$USER->id,
        (int)$cm->id,
        $purpose
    );
    $sourcekind = match ($purpose) {
        'pdf', 'pdf_questions' => 'pdf',
        'slides' => 'slides',
        default => 'handwriting',
    };
    $source = null;
    try {
        $source = \quizgeistaddon_ai\local\ai\source_document::from_draft(
            (int)$data->source,
            (int)$USER->id,
            $sourcekind
        );
    } catch (\quizgeistaddon_ai\local\ai\document_import_exception $exception) {
        // Same systematics as the AJAX dispatcher (P10-F16): this page is not
        // dispatched, so the reason would otherwise collapse into Moodle's generic
        // "invalid parameter" page. The teacher learns the reason in their own
        // language; the machine reason stays in developer debugging.
        $rejection = get_string($exception->user_string_key(), 'mod_quizgeist');
        debugging(
            'Quizgeist AI source rejected: ' . $exception->reason(),
            DEBUG_DEVELOPER
        );
    }
    if ($source !== null) {
        $message = [
            'type' => 'quizgeist-ai-source-selected',
            'purpose' => $purpose,
            'draftItemId' => (int)$data->source,
            'sourceToken' => $sourcetoken,
            'file' => $source->metadata(),
        ];
    }
}

$postmessage = $message ?? [
    'type' => 'quizgeist-ai-source-ready',
    'purpose' => $purpose,
    'draftItemId' => $draftitemid,
    'sourceToken' => $sourcetoken,
];
$encoded = json_encode(
    $postmessage,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
);
$PAGE->requires->js_init_code(
    "window.parent.postMessage({$encoded}, window.location.origin);"
);

echo $OUTPUT->header();
if ($message === null) {
    echo $OUTPUT->heading(get_string('ai:source:title', 'mod_quizgeist'), 3);
    echo html_writer::tag('p', get_string('ai:source:description', 'mod_quizgeist'));
    if ($rejection !== null) {
        // Keep the rejected draft file so the teacher can replace it in the file picker.
        echo $OUTPUT->notification($rejection, 'notifyproblem');
    }
    $form->display();
} else if ($message['type'] === 'quizgeist-ai-source-selected') {
    echo $OUTPUT->notification(get_string('ai:source:selected', 'mod_quizgeist'), 'notifysuccess');
} else {
    echo $OUTPUT->notification(get_string('cancelled'), 'notifymessage');
}
echo $OUTPUT->footer();
