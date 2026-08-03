<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical self-study assignment settings.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

use mod_quizgeist\local\schedule\due_selector;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates the small server-owned assignment policy document.
 */
final class assignment_settings {

    /** Default number of explicitly started attempts per learner. */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /** Historical assignments without an explicit retry policy stay single-shot. */
    public const LEGACY_MAX_ATTEMPTS = 1;

    /** Upper bound for a teacher-configured retry budget. */
    public const MAX_ATTEMPTS = 10;

    /**
     * Supported self-study modes.
     *
     * F12 adds `speaking`. `quizgeist_assignments.mode` is char(32), so this is
     * a value change and not a schema change — the column was sized for exactly
     * this kind of growth.
     */
    public const MODES = ['solo', 'practice', 'test', 'flashcards', 'speaking'];

    /**
     * @var string[] Modes that need the AI addon to work at all.
     *
     * Kept as data rather than as an `if` in five places: the speaking trainer
     * needs recognition and a voice, and an assignment that promises both
     * without having them would be an empty room a class was sent into.
     */
    public const AI_MODES = ['speaking'];

    /** Question selection kinds (F3). */
    public const SELECTIONS = ['fixed', 'due'];

    /** Upper bound for the per-attempt question budget of a repetition run. */
    public const MAX_QUESTIONS = 200;

    /** Persisted assignment lifecycle states. */
    public const STATUSES = ['draft', 'open', 'closed', 'archived'];

    /** Explicitly supported assignment lifecycle transitions. */
    public const STATUS_TRANSITIONS = [
        'draft' => ['open'],
        'open' => ['closed'],
        'closed' => ['open', 'archived'],
        'archived' => [],
    ];

    /**
     * Build canonical settings from an untrusted request.
     *
     * @param mixed $raw Raw settings object.
     * @param int $timedue Canonical deadline.
     * @return array{
     *     allowLate:bool,
     *     reminderEnabled:bool,
     *     maxAttempts:int,
     *     countsTowardsGrade:bool,
     *     selectionStrategy:string,
     *     maxQuestions:int
     * }
     */
    public static function create($raw, int $timedue): array {
        if ($raw === null) {
            $raw = [];
        }
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new \invalid_parameter_exception('settings must be an object.');
        }
        $known = [
            'allowLate',
            'reminderEnabled',
            'maxAttempts',
            'countsTowardsGrade',
            'selectionStrategy',
            'maxQuestions',
        ];
        foreach (array_keys($raw) as $key) {
            if (!is_string($key) || !in_array($key, $known, true)) {
                throw new \invalid_parameter_exception('Unknown assignment setting.');
            }
        }
        $maxattempts = $raw['maxAttempts'] ?? self::DEFAULT_MAX_ATTEMPTS;
        if (!is_int($maxattempts)
                || $maxattempts < 1
                || $maxattempts > self::MAX_ATTEMPTS) {
            throw new \invalid_parameter_exception(
                'maxAttempts must be a bounded integer.'
            );
        }
        // F4: an unknown or misspelled strategy is refused rather than
        // silently downgraded — a teacher who asked for mixed practice must
        // never get block practice without being told.
        $strategy = $raw['selectionStrategy'] ?? due_selector::STRATEGY_SEQUENTIAL;
        if (!is_string($strategy)
                || !in_array($strategy, due_selector::STRATEGIES, true)) {
            throw new \invalid_parameter_exception(
                'selectionStrategy is invalid.'
            );
        }
        $maxquestions = $raw['maxQuestions'] ?? 0;
        if (!is_int($maxquestions)
                || $maxquestions < 0
                || $maxquestions > self::MAX_QUESTIONS) {
            throw new \invalid_parameter_exception(
                'maxQuestions must be a bounded integer.'
            );
        }
        return [
            'allowLate' => self::boolean($raw['allowLate'] ?? false, 'allowLate'),
            'reminderEnabled' => self::boolean(
                $raw['reminderEnabled'] ?? ($timedue > 0),
                'reminderEnabled'
            ),
            'maxAttempts' => $maxattempts,
            'countsTowardsGrade' => self::boolean(
                $raw['countsTowardsGrade'] ?? true,
                'countsTowardsGrade'
            ),
            'selectionStrategy' => $strategy,
            'maxQuestions' => $maxquestions,
        ];
    }

    /**
     * Validate one question-selection kind.
     *
     * @param mixed $raw Raw value.
     * @return string
     */
    public static function selection($raw): string {
        if (!is_string($raw) || !in_array($raw, self::SELECTIONS, true)) {
            throw new \invalid_parameter_exception(
                'Assignment selection is invalid.'
            );
        }
        return $raw;
    }

    /**
     * Decode a persisted settings document without trusting historical JSON.
     *
     * @param mixed $raw Persisted JSON.
     * @param int $timedue Assignment deadline.
     * @return array{
     *     allowLate:bool,
     *     reminderEnabled:bool,
     *     maxAttempts:int,
     *     countsTowardsGrade:bool,
     *     selectionStrategy:string,
     *     maxQuestions:int
     * }
     */
    public static function decode($raw, int $timedue): array {
        $decoded = is_string($raw) && $raw !== ''
            ? json_decode($raw, true)
            : [];
        if (!is_array($decoded) || array_is_list($decoded)) {
            $decoded = [];
        }
        $maxattempts = $decoded['maxAttempts'] ?? self::LEGACY_MAX_ATTEMPTS;
        $maxattempts = is_int($maxattempts)
                && $maxattempts >= 1
                && $maxattempts <= self::MAX_ATTEMPTS
            ? $maxattempts
            : self::LEGACY_MAX_ATTEMPTS;
        return [
            'allowLate' => is_bool($decoded['allowLate'] ?? null)
                ? $decoded['allowLate']
                : false,
            'reminderEnabled' => is_bool($decoded['reminderEnabled'] ?? null)
                ? $decoded['reminderEnabled']
                : $timedue > 0,
            'maxAttempts' => $maxattempts,
            'countsTowardsGrade' =>
                is_bool($decoded['countsTowardsGrade'] ?? null)
                    ? $decoded['countsTowardsGrade']
                    : true,
            // An assignment created before F4 was played in the authored
            // order. That is what it must keep on replay.
            'selectionStrategy' => due_selector::normalise_strategy(
                $decoded['selectionStrategy'] ?? null
            ),
            'maxQuestions' => is_int($decoded['maxQuestions'] ?? null)
                    && $decoded['maxQuestions'] >= 0
                    && $decoded['maxQuestions'] <= self::MAX_QUESTIONS
                ? $decoded['maxQuestions']
                : 0,
        ];
    }

    /**
     * Encode canonical settings.
     *
     * @param array $settings Canonical settings.
     * @return string
     */
    public static function encode(array $settings): string {
        return json_encode(
            self::create($settings, 0),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Validate an assignment mode.
     *
     * @param mixed $raw Raw mode.
     * @return string
     */
    public static function mode($raw): string {
        if (!is_string($raw) || !in_array($raw, self::MODES, true)) {
            throw new \invalid_parameter_exception('Assignment mode is invalid.');
        }
        return $raw;
    }

    /**
     * Validate an assignment lifecycle state.
     *
     * @param mixed $raw Raw state.
     * @return string
     */
    public static function status($raw): string {
        if (!is_string($raw) || !in_array($raw, self::STATUSES, true)) {
            throw new \invalid_parameter_exception('Assignment status is invalid.');
        }
        return $raw;
    }

    /**
     * Validate an assignment lifecycle transition.
     *
     * Re-sending the current status is an idempotent metadata update. Historical
     * attempts prevent moving an assignment back out of the active lifecycle.
     *
     * @param mixed $from Persisted current state.
     * @param mixed $to Requested target state.
     * @param bool $hasattempts Whether the assignment has attempt history.
     * @return string Canonical target state.
     */
    public static function transition($from, $to, bool $hasattempts): string {
        $from = self::status($from);
        $to = self::status($to);
        if ($from === $to) {
            return $to;
        }
        if (!in_array($to, self::STATUS_TRANSITIONS[$from], true)) {
            throw new \invalid_parameter_exception(
                "Assignment status transition {$from} -> {$to} is invalid."
            );
        }
        if ($hasattempts && in_array($to, ['draft', 'archived'], true)) {
            throw new \invalid_parameter_exception(
                'An assignment with attempts cannot become draft or archived.'
            );
        }
        return $to;
    }

    /**
     * Strictly validate a JSON boolean.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @return bool
     */
    public static function boolean($raw, string $field): bool {
        if (!is_bool($raw)) {
            throw new \invalid_parameter_exception("{$field} must be boolean.");
        }
        return $raw;
    }
}
