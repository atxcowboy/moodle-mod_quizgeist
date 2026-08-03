<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Printable answer-card sheet of one card set (F11a).
 *
 * Uses the TCPDF that Moodle already ships (`lib/pdflib.php`) and binds the
 * library ITSELF rather than relying on whoever happens to have loaded it —
 * the lesson of [P10-F15]. No new dependency, no external host.
 *
 * The geometry lives in card_sheet, so this file only executes drawing
 * instructions; that keeps the sheet layout provable without a printer.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/pdflib.php');

use mod_quizgeist\local\cards\card_repository;
use mod_quizgeist\local\cards\card_sheet;
use mod_quizgeist\local\cards\cardset_service;
use mod_quizgeist\local\licence\feature_gate;

$cmid = required_param('id', PARAM_INT);
$cardsetid = required_param('cardset', PARAM_INT);

$cm = get_coursemodule_from_id('quizgeist', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quizgeist = $DB->get_record('quizgeist', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('mod/quizgeist:scancards', $context);

$PAGE->set_url(new moodle_url('/mod/quizgeist/cards_pdf.php', [
    'id' => $cm->id,
    'cardset' => $cardsetid,
]));
$PAGE->set_context($context);

// Printing belongs to the AI package: without recognition a printed sheet has
// no use. It is `view_existing`, not `create_new` — reprinting a sheet a class
// already carries is not a new AI job, and an expired licence must not turn
// the cards in thirty school bags into waste paper (2.6).
feature_gate::require('ai', feature_gate::VIEW_EXISTING);

$set = card_repository::cardset((int)$quizgeist->id, $cardsetid);
if ($set === null) {
    throw new \moodle_exception('cards:error:setnotfound', 'mod_quizgeist');
}
$model = cardset_service::print_model($set);
if ($model['cards'] === []) {
    throw new \moodle_exception('cards:error:emptyset', 'mod_quizgeist');
}

$pdf = new \pdf();
$pdf->SetCreator('mod_quizgeist');
$pdf->SetTitle($model['name']);
$pdf->SetAutoPageBreak(false);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);

$perpage = card_sheet::per_page();
$total = count($model['cards']);
$pages = (int)ceil($total / $perpage);

foreach ($model['cards'] as $position => $card) {
    if ($position % $perpage === 0) {
        $pdf->AddPage('P', 'A4');
        $pdf->SetFont('freesans', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY(card_sheet::MARGIN_X, 6.0);
        $pdf->Cell(
            210.0 - (2 * card_sheet::MARGIN_X),
            5.0,
            get_string('cards:print:sheetheader', 'mod_quizgeist', (object)[
                'name' => $model['name'],
                'page' => (int)floor($position / $perpage) + 1,
                'pages' => $pages,
            ]),
            0,
            0,
            'L'
        );
    }

    [$slotx, $sloty] = card_sheet::slot_origin($position % $perpage);
    foreach (card_sheet::card_operations($slotx, $sloty, $card, $model['letters']) as $operation) {
        if ($operation['op'] === 'rect') {
            $pdf->SetLineWidth(0.4);
            $pdf->SetDrawColor(20, 20, 20);
            $pdf->Rect(
                (float)$operation['x'],
                (float)$operation['y'],
                (float)$operation['w'],
                (float)$operation['h']
            );
            continue;
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont((string)$operation['font'], '', (float)$operation['size']);
        $angle = (float)$operation['angle'];
        if ($angle !== 0.0) {
            $pdf->StartTransform();
            // TCPDF rotates counter-clockwise; the model counts clockwise.
            $pdf->Rotate(-$angle, (float)$operation['x'], (float)$operation['y']);
        }
        $pdf->SetXY(
            (float)$operation['x'] - ((float)$operation['w'] / 2),
            (float)$operation['y'] - ((float)$operation['h'] / 2)
        );
        $pdf->Cell(
            (float)$operation['w'],
            (float)$operation['h'],
            (string)$operation['text'],
            0,
            0,
            'C'
        );
        if ($angle !== 0.0) {
            $pdf->StopTransform();
        }
    }
}

// Nothing else may reach the response body: a PDF with a stray warning in
// front of it is a corrupt file, not a warning.
\core\session\manager::write_close();
$filename = clean_filename('quizgeist-karten-' . (int)$set->id . '.pdf');
$pdf->Output($filename, 'D');
