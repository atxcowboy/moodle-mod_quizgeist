<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Contract for one live-enabled question type.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live\qtype;

use mod_quizgeist\local\live\aggregation_context;
use mod_quizgeist\local\live\interaction_policy;
use mod_quizgeist\local\live\projection_context;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\submission_context;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns validation, evaluation, aggregation and projection for one question type.
 */
interface live_question_type {

    /**
     * Return the persisted question type handled by this strategy.
     *
     * @return string
     */
    public function type(): string;

    /**
     * Describe stages, submission cardinality and aggregate visibility.
     *
     * @param array $question Canonical question.
     * @return interaction_policy
     */
    public function policy(array $question): interaction_policy;

    /**
     * Validate submitted answer data against one canonical question.
     *
     * @param array $question Canonical question from question_schema.
     * @param array $rawanswer Submitted type-specific payload.
     * @param submission_context|null $context Server-owned interaction context.
     * @return array Canonical type-specific payload.
     */
    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array;

    /**
     * Evaluate one submitted answer.
     *
     * @param array $question Canonical question from question_schema.
     * @param array $rawanswer Submitted type-specific payload.
     * @param scoring_context $scoring Server-owned scoring context.
     * @param submission_context|null $context Server-owned interaction context.
     * @return array Canonical evaluated response.
     */
    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array;

    /**
     * Aggregate already visit-filtered canonical answers.
     *
     * @param array $question Canonical question from question_schema.
     * @param \stdClass[] $answers Visit-filtered stored response rows.
     * @param aggregation_context $context Disclosure context.
     * @return array Type-specific aggregate.
     */
    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array;

    /**
     * Project type-specific fields into the public live question DTO.
     *
     * @param array $question Serialised canonical question.
     * @param array<string, array{url:string,mimetype:string}> $mediafiles Media manifest lookup.
     * @param projection_context $context Projection context.
     * @return array Type-specific public projection.
     */
    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array;
}
