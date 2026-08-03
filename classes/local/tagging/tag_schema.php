<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical tagging schema and validation.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\tagging;

defined('MOODLE_INTERNAL') || die();

/**
 * One canonical normalisation for tags and their question assignments.
 *
 * Every complaint is field-addressable ({field, code}), exactly like
 * question_schema. Callers never learn a reason from a free-text message.
 */
final class tag_schema {

    /** @var string[] Ownership scopes understood by the tagging core. */
    public const SCOPES = ['activity', 'course', 'site'];

    /** @var string[] Label families understood by the tagging core. */
    public const KINDS = ['topic', 'qtype', 'competence'];

    /** @var string[] Assignment states. Only the workshop ever suggests. */
    public const ASSIGNMENT_STATUSES = ['approved', 'suggested'];

    /** Reserved system tag carrying the error-friendly framing of F1. */
    public const RESERVED_NEW_KIND = 'topic';

    /** Reserved system tag key carrying the error-friendly framing of F1. */
    public const RESERVED_NEW_KEY = 'neu';

    /** Maximum human-readable label length. */
    public const MAX_LABEL = 255;

    /** Maximum stable machine key length. */
    public const MAX_KEY = 64;

    /** Maximum colour-code length. */
    public const MAX_COLORKEY = 32;

    /** Maximum external reference length. */
    public const MAX_EXTERNALREF = 255;

    /** Highest accepted sort order. */
    public const MAX_SORTORDER = 100000;

    /** Highest accepted assignment weight. */
    public const MAX_WEIGHT = 100;

    /** Largest accepted assignment list in one request. */
    public const MAX_ASSIGNMENTS = 50;

    /**
     * Return one intentionally incomplete starter tag.
     *
     * @param string $kind Label family.
     * @return array
     */
    public static function defaults(string $kind): array {
        return [
            'id' => 0,
            'scope' => 'activity',
            'scopeid' => 0,
            'kind' => in_array($kind, self::KINDS, true) ? $kind : 'topic',
            'tagkey' => '',
            'label' => '',
            'colorkey' => null,
            'externalref' => null,
            'sortorder' => 0,
        ];
    }

    /**
     * Normalise one submitted tag.
     *
     * @param array $input Raw client payload.
     * @return array{tag:array,validationErrors:array<int,array{field:string,code:string}>}
     */
    public static function normalise(array $input): array {
        $errors = [];
        $scope = self::enumerated(
            $input['scope'] ?? null,
            self::SCOPES,
            'scope',
            $errors,
            'activity'
        );
        $kind = self::enumerated(
            $input['kind'] ?? null,
            self::KINDS,
            'kind',
            $errors,
            'topic'
        );
        $scopeid = self::natural($input['scopeid'] ?? null, 'scopeid', $errors);
        if ($scope === 'site' && $scopeid !== 0) {
            // A site tag has no owner row; a non-zero owner would silently
            // create a second, unreachable uniqueness bucket.
            $errors[] = ['field' => 'scopeid', 'code' => 'invalid'];
            $scopeid = 0;
        }
        if ($scope !== 'site' && $scopeid <= 0) {
            $errors[] = ['field' => 'scopeid', 'code' => 'required'];
        }

        $tagkey = self::machine_key($input['tagkey'] ?? null, 'tagkey', $errors);
        $label = self::text(
            $input['label'] ?? null,
            'label',
            self::MAX_LABEL,
            $errors,
            true
        );
        $colorkey = self::optional_machine_key(
            $input['colorkey'] ?? null,
            'colorkey',
            self::MAX_COLORKEY,
            $errors
        );
        $externalref = self::text(
            $input['externalref'] ?? null,
            'externalref',
            self::MAX_EXTERNALREF,
            $errors,
            false
        );
        $sortorder = self::natural($input['sortorder'] ?? null, 'sortorder', $errors);
        if ($sortorder > self::MAX_SORTORDER) {
            $errors[] = ['field' => 'sortorder', 'code' => 'out_of_range'];
            $sortorder = self::MAX_SORTORDER;
        }

        return [
            'tag' => [
                'id' => self::natural($input['id'] ?? null, 'id', $errors),
                'scope' => $scope,
                'scopeid' => $scopeid,
                'kind' => $kind,
                'tagkey' => $tagkey,
                'label' => $label,
                'colorkey' => $colorkey === '' ? null : $colorkey,
                'externalref' => $externalref === '' ? null : $externalref,
                'sortorder' => $sortorder,
            ],
            'validationErrors' => self::unique_errors($errors),
        ];
    }

    /**
     * Normalise one complete assignment list for a single question root.
     *
     * @param mixed $input Raw client list.
     * @return array{
     *     assignments:array<int,array{tagId:int,weight:int}>,
     *     validationErrors:array<int,array{field:string,code:string}>
     * }
     */
    public static function normalise_assignments($input): array {
        $errors = [];
        if (!is_array($input) || !array_is_list($input)) {
            return [
                'assignments' => [],
                'validationErrors' => [
                    ['field' => 'assignments', 'code' => 'invalid'],
                ],
            ];
        }
        if (count($input) > self::MAX_ASSIGNMENTS) {
            $errors[] = ['field' => 'assignments', 'code' => 'too_many'];
            $input = array_slice($input, 0, self::MAX_ASSIGNMENTS);
        }
        $assignments = [];
        $seen = [];
        foreach ($input as $index => $entry) {
            $field = 'assignments.' . $index;
            if (!is_array($entry)) {
                $errors[] = ['field' => $field, 'code' => 'invalid'];
                continue;
            }
            $tagid = self::natural($entry['tagId'] ?? null, $field . '.tagId', $errors);
            if ($tagid <= 0) {
                $errors[] = ['field' => $field . '.tagId', 'code' => 'required'];
                continue;
            }
            if (isset($seen[$tagid])) {
                $errors[] = ['field' => $field . '.tagId', 'code' => 'duplicate'];
                continue;
            }
            $seen[$tagid] = true;
            $weight = array_key_exists('weight', $entry)
                ? self::natural($entry['weight'], $field . '.weight', $errors)
                : self::MAX_WEIGHT;
            if ($weight > self::MAX_WEIGHT) {
                $errors[] = ['field' => $field . '.weight', 'code' => 'out_of_range'];
                $weight = self::MAX_WEIGHT;
            }
            $assignments[] = ['tagId' => $tagid, 'weight' => $weight];
        }
        return [
            'assignments' => $assignments,
            'validationErrors' => self::unique_errors($errors),
        ];
    }

    /**
     * Whether this pair addresses the reserved error-friendly system tag.
     *
     * @param string $kind Label family.
     * @param string $tagkey Machine key.
     * @return bool
     */
    public static function is_reserved_new(string $kind, string $tagkey): bool {
        return $kind === self::RESERVED_NEW_KIND
            && $tagkey === self::RESERVED_NEW_KEY;
    }

    /**
     * Build the merge identity used by restore and upsert.
     *
     * @param array $tag Canonical tag.
     * @return string
     */
    public static function identity(array $tag): string {
        return implode("\0", [
            (string)$tag['scope'],
            (string)(int)$tag['scopeid'],
            (string)$tag['kind'],
            (string)$tag['tagkey'],
        ]);
    }

    /**
     * Validate one member of a closed value list.
     *
     * @param mixed $value Raw value.
     * @param string[] $allowed Allowed values.
     * @param string $field Field path.
     * @param array $errors Collected complaints.
     * @param string $fallback Value used after a complaint.
     * @return string
     */
    private static function enumerated(
        $value,
        array $allowed,
        string $field,
        array &$errors,
        string $fallback
    ): string {
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }
        $errors[] = [
            'field' => $field,
            'code' => $value === null || $value === '' ? 'required' : 'invalid',
        ];
        return $fallback;
    }

    /**
     * Validate one non-negative integer.
     *
     * @param mixed $value Raw value.
     * @param string $field Field path.
     * @param array $errors Collected complaints.
     * @return int
     */
    private static function natural($value, string $field, array &$errors): int {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_bool($value) || !is_numeric($value) || (int)$value != $value) {
            $errors[] = ['field' => $field, 'code' => 'invalid'];
            return 0;
        }
        $number = (int)$value;
        if ($number < 0) {
            $errors[] = ['field' => $field, 'code' => 'out_of_range'];
            return 0;
        }
        return $number;
    }

    /**
     * Validate one stable machine key.
     *
     * @param mixed $value Raw value.
     * @param string $field Field path.
     * @param array $errors Collected complaints.
     * @return string
     */
    private static function machine_key($value, string $field, array &$errors): string {
        if (!is_string($value) || trim($value) === '') {
            $errors[] = ['field' => $field, 'code' => 'required'];
            return '';
        }
        $candidate = \core_text::strtolower(trim($value));
        if (\core_text::strlen($candidate) > self::MAX_KEY
                || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $candidate) !== 1) {
            $errors[] = ['field' => $field, 'code' => 'invalid'];
            return '';
        }
        return $candidate;
    }

    /**
     * Validate one optional machine key.
     *
     * @param mixed $value Raw value.
     * @param string $field Field path.
     * @param int $maxlength Maximum length.
     * @param array $errors Collected complaints.
     * @return string
     */
    private static function optional_machine_key(
        $value,
        string $field,
        int $maxlength,
        array &$errors
    ): string {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            $errors[] = ['field' => $field, 'code' => 'invalid'];
            return '';
        }
        $candidate = \core_text::strtolower(trim($value));
        if ($candidate === '') {
            return '';
        }
        if (\core_text::strlen($candidate) > $maxlength
                || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $candidate) !== 1) {
            $errors[] = ['field' => $field, 'code' => 'invalid'];
            return '';
        }
        return $candidate;
    }

    /**
     * Validate one bounded plain-text field.
     *
     * @param mixed $value Raw value.
     * @param string $field Field path.
     * @param int $maxlength Maximum length.
     * @param array $errors Collected complaints.
     * @param bool $required Whether an empty value is a complaint.
     * @return string
     */
    private static function text(
        $value,
        string $field,
        int $maxlength,
        array &$errors,
        bool $required
    ): string {
        if ($value === null) {
            if ($required) {
                $errors[] = ['field' => $field, 'code' => 'required'];
            }
            return '';
        }
        if (!is_string($value)) {
            $errors[] = ['field' => $field, 'code' => 'invalid'];
            return '';
        }
        $clean = trim(clean_param($value, PARAM_TEXT));
        if ($clean === '') {
            if ($required) {
                $errors[] = ['field' => $field, 'code' => 'required'];
            }
            return '';
        }
        if (\core_text::strlen($clean) > $maxlength) {
            $errors[] = ['field' => $field, 'code' => 'out_of_range'];
            $clean = \core_text::substr($clean, 0, $maxlength);
        }
        return $clean;
    }

    /**
     * Remove duplicate complaints while keeping their order.
     *
     * @param array<int,array{field:string,code:string}> $errors Complaints.
     * @return array<int,array{field:string,code:string}>
     */
    private static function unique_errors(array $errors): array {
        $seen = [];
        $result = [];
        foreach ($errors as $error) {
            $identity = $error['field'] . "\0" . $error['code'];
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $result[] = $error;
        }
        return $result;
    }
}
