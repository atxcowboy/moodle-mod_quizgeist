<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Facade for question-type-specific live aggregation.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\editor\question_schema;
use mod_quizgeist\local\live\qtype\registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Converts immutable answer rows into the selected qtype's aggregate DTO.
 */
final class answer_aggregator {

    /**
     * Aggregate answer rows already filtered to one exact question version.
     *
     * Live visit selection deliberately belongs to the session repository.
     * Historical reports may batch visible completed-attempt visits of that
     * same version and use the context visit as their public-handle namespace.
     * This facade receives only rows which are eligible for the aggregate.
     *
     * @param \stdClass $record Exact played question row.
     * @param \stdClass[] $answers Persisted answer rows.
     * @param aggregation_context $context Disclosure context.
     * @return array Type-specific aggregate.
     */
    public static function aggregate(
        \stdClass $record,
        array $answers,
        aggregation_context $context
    ): array {
        $record = clone $record;
        $record->timelimit = question_timing::seconds($record);
        $normalised = question_schema::normalise([
            'qtype' => (string)$record->qtype,
            'questiontext' => (string)$record->questiontext,
            'options' => question_schema::decode_options($record->optionsjson ?? null),
            'timelimit' => (int)$record->timelimit,
            'pointmode' => (string)$record->pointmode,
            'explanation' => (string)$record->explanation,
        ]);
        $question = $normalised['question'];
        return registry::get((string)$question['qtype'])->aggregate(
            $question,
            $answers,
            $context
        );
    }
}
