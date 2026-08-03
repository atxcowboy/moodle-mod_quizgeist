<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Validation rules of the F7 question workshop.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\workshop;

use mod_quizgeist\local\editor\question_schema;

defined('MOODLE_INTERNAL') || die();

/**
 * Turns a learner submission into a canonical question, or into objections.
 *
 * Everything here is a server rule, not a request to the interface. The
 * mandatory explanation is the clearest case: a submission without one is
 * refused with a field-addressable objection ({field, code}) and a readable
 * sentence from the language file — the form cannot talk its way past it.
 */
final class workshop_schema {

    /** Lifecycle states of one submission. */
    public const STATES = ['submitted', 'revising', 'approved', 'rejected'];

    /** States a curator may set. */
    public const DECISIONS = ['revising', 'approved', 'rejected'];

    /**
     * @var string[] Types a learner may submit.
     *
     * A workshop question needs a solution a peer can check. A slide, a poll
     * or a word cloud has none, so they are deliberately not submittable —
     * this is a narrowing of question_schema::QTYPES, never a widening.
     */
    public const SUBMITTABLE_TYPES = ['quiz', 'truefalse', 'shortanswer'];

    /** Minimum length of the mandatory explanation. */
    public const MIN_EXPLANATION = 10;

    /** Maximum stored curator note length. */
    public const MAX_NOTE = 1000;

    /** Maximum stored peer comment length. */
    public const MAX_COMMENT = 500;

    /** Lowest and highest rating step. */
    public const MIN_RATING = 1;

    /** Highest rating step. */
    public const MAX_RATING = 5;

    /**
     * Normalise one learner submission.
     *
     * @param array $input Raw client payload of the question.
     * @return array{question:array,validationErrors:array}
     */
    public static function normalise(array $input): array {
        $normalised = question_schema::normalise($input);
        $question = $normalised['question'];
        $errors = $normalised['validationErrors'];

        if (!in_array((string)$question['qtype'], self::SUBMITTABLE_TYPES, true)) {
            $errors[] = ['field' => 'qtype', 'code' => 'not_submittable'];
        }
        // The rule this feature stands on: explaining WHY is the learning, the
        // question is only its container.
        $explanation = trim((string)$question['explanation']);
        if ($explanation === '') {
            $errors[] = ['field' => 'explanation', 'code' => 'required'];
        } else if (\core_text::strlen($explanation) < self::MIN_EXPLANATION) {
            $errors[] = ['field' => 'explanation', 'code' => 'too_short'];
        }
        // A submitted question is never a draft placeholder: it must be
        // complete on arrival, not "completable later".
        $question['status'] = 'draft';

        return [
            'question' => $question,
            'validationErrors' => self::unique_errors($errors),
        ];
    }

    /**
     * Normalise one peer rating.
     *
     * @param array $input Raw client payload.
     * @return array{rating:array,validationErrors:array}
     */
    public static function normalise_rating(array $input): array {
        $errors = [];
        $quality = self::step($input['quality'] ?? null, 'quality', $errors);
        $difficulty = self::step($input['difficulty'] ?? null, 'difficulty', $errors);
        $comment = self::plain_text($input['comment'] ?? '', self::MAX_COMMENT);
        return [
            'rating' => [
                'quality' => $quality,
                'difficulty' => $difficulty,
                'comment' => $comment,
            ],
            'validationErrors' => self::unique_errors($errors),
        ];
    }

    /**
     * Normalise one curation decision.
     *
     * @param array $input Raw client payload.
     * @return array{decision:array,validationErrors:array}
     */
    public static function normalise_decision(array $input): array {
        $errors = [];
        $state = is_string($input['state'] ?? null) ? $input['state'] : '';
        if (!in_array($state, self::DECISIONS, true)) {
            $errors[] = ['field' => 'state', 'code' => 'invalid'];
            $state = '';
        }
        $note = self::plain_text($input['note'] ?? '', self::MAX_NOTE);
        // Sending someone back to rework without saying what to rework is not
        // curation, it is a closed door.
        if ($state === 'revising' && $note === '') {
            $errors[] = ['field' => 'note', 'code' => 'required'];
        }
        return [
            'decision' => ['state' => $state, 'note' => $note],
            'validationErrors' => self::unique_errors($errors),
        ];
    }

    /**
     * Validate one rating step.
     *
     * @param mixed $value Raw value.
     * @param string $field Field name.
     * @param array $errors Mutable objections.
     * @return int
     */
    private static function step($value, string $field, array &$errors): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            $errors[] = ['field' => $field, 'code' => 'invalid'];
            return 0;
        }
        $number = (int)$value;
        if ($number < self::MIN_RATING || $number > self::MAX_RATING) {
            $errors[] = ['field' => $field, 'code' => 'out_of_range'];
            return 0;
        }
        return $number;
    }

    /**
     * Reduce untrusted text to bounded plain text.
     *
     * @param mixed $value Raw value.
     * @param int $maximum Maximum length.
     * @return string
     */
    public static function plain_text($value, int $maximum): string {
        if (!is_string($value)) {
            return '';
        }
        $clean = clean_param($value, PARAM_TEXT);
        $clean = trim(preg_replace('/[ \t]+/u', ' ', $clean) ?? $clean);
        return \core_text::substr($clean, 0, $maximum);
    }

    /**
     * Remove duplicate objections without reordering them.
     *
     * @param array $errors Raw objections.
     * @return array
     */
    private static function unique_errors(array $errors): array {
        $seen = [];
        $unique = [];
        foreach ($errors as $error) {
            $key = ($error['field'] ?? '') . "\0" . ($error['code'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $error;
        }
        return $unique;
    }
}
