<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Capability-safe Moodle-dataformat report download.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);
$scope = required_param('scope', PARAM_ALPHA);
$groupid = optional_param('groupid', 0, PARAM_INT);
$sourcekeys = optional_param_array('sourcekeys', [], PARAM_RAW_TRIMMED);

if (!in_array($format, ['csv', 'xlsx'], true)) {
    throw new invalid_parameter_exception('Report format is invalid.');
}

$cm = get_coursemodule_from_id('quizgeist', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$quizgeist = $DB->get_record(
    'quizgeist',
    ['id' => $cm->instance],
    '*',
    MUST_EXIST
);
$context = context_module::instance((int)$cm->id);

require_login($course, false, $cm);
require_sesskey();
$canviewreports = has_capability(
    'mod/quizgeist:viewreports',
    $context
);
if (!$canviewreports
        && !has_capability('mod/quizgeist:play', $context)) {
    require_capability('mod/quizgeist:viewreports', $context);
}
try {
    if ($format === 'xlsx') {
        \mod_quizgeist\local\licence\feature_gate::require(
            'reports',
            \mod_quizgeist\local\licence\feature_gate::EXPORT_EXISTING
        );
    }
    $selection = \mod_quizgeist\local\report\source_selection::from_values(
        $scope,
        array_values($sourcekeys),
        $groupid
    );
    $rows = \mod_quizgeist\local\report\report_service::export_rows_for_selection(
        $cm,
        $quizgeist,
        $context,
        $USER,
        $selection
    );
} catch (\mod_quizgeist\local\licence\feature_locked_exception $exception) {
    if (!$canviewreports) {
        throw new moodle_exception('notavailable', 'error');
    }
    throw new moodle_exception(
        'licence:featurelocked',
        'mod_quizgeist',
        '',
        null,
        $exception->getMessage()
    );
}

$filename = clean_filename(
    format_string((string)$quizgeist->name)
        . '-report-' . gmdate('Ymd-His')
);
if ($format === 'csv') {
    \mod_quizgeist\local\report\report_export::download_csv_rows(
        $filename,
        $rows
    );
    exit;
}
\core\dataformat::download_data(
    $filename,
    'excel',
    \mod_quizgeist\local\report\report_service::export_columns(),
    $rows
);
exit;
