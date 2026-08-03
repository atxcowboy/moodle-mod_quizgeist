<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical persisted live-session state.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates the deliberately small and PII-free session JSON document.
 *
 * Player names, user IDs, answers, scores and rankings never belong in this
 * document. They are derived from quizgeist_players and quizgeist_answers. Keeping
 * the immutable concrete question-ID sequence here makes reconnects and
 * question-version history independent from later editor changes.
 */
final class session_state {

    /** Current persisted document schema. */
    public const SCHEMA_VERSION = 1;

    /** Server-authoritative countdown before every question opens. */
    private const QUESTION_COUNTDOWN_MS = 3000;

    /**
     * Create a lobby state around one immutable concrete question sequence.
     *
     * @param int[] $questionids Concrete quizgeist_questions IDs.
     * @param int $nowms Server timestamp in milliseconds.
     * @return array
     */
    public static function create(array $questionids, int $nowms): array {
        $questionids = self::question_ids($questionids);
        if (!$questionids) {
            throw new \invalid_parameter_exception('A live session needs questions.');
        }

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'questionIds' => $questionids,
            'currentIndex' => -1,
            'questionToken' => '',
            'revealedQuestionToken' => '',
            'phaseStartedAtMs' => $nowms,
            'phaseEndsAtMs' => 0,
        ];
    }

    /**
     * Decode and validate a persisted state document.
     *
     * Unknown fields are intentionally discarded. Besides keeping the runtime
     * contract stable, this prevents an older/future client from turning the
     * reconnect snapshot into an undeclared store of personal data.
     *
     * @param string|null $json Persisted JSON.
     * @return array
     */
    public static function decode(?string $json): array {
        if ($json === null || trim($json) === '') {
            throw new \invalid_parameter_exception('Live session state is missing.');
        }
        try {
            $raw = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \invalid_parameter_exception('Live session state is invalid.');
        }
        if (!is_array($raw)
                || (int)($raw['schemaVersion'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new \invalid_parameter_exception('Live session state schema is invalid.');
        }

        $questionids = self::question_ids($raw['questionIds'] ?? null);
        if (!$questionids) {
            throw new \invalid_parameter_exception('Live session question snapshot is empty.');
        }
        $currentindex = self::integer($raw['currentIndex'] ?? -1, 'currentIndex');
        if ($currentindex < -1 || $currentindex >= count($questionids)) {
            throw new \invalid_parameter_exception('Live session question index is invalid.');
        }
        $token = $raw['questionToken'] ?? '';
        if (!is_string($token)
                || ($token !== '' && !preg_match('/^[a-f0-9]{32}$/D', $token))) {
            throw new \invalid_parameter_exception('Live session question token is invalid.');
        }
        if ($currentindex >= 0 && $token === '') {
            throw new \invalid_parameter_exception('Live question token is missing.');
        }
        $revealedtoken = $raw['revealedQuestionToken'] ?? '';
        if (!is_string($revealedtoken)
                || ($revealedtoken !== ''
                    && !preg_match('/^[a-f0-9]{32}$/D', $revealedtoken))) {
            throw new \invalid_parameter_exception(
                'Live revealed-question token is invalid.'
            );
        }
        $started = self::non_negative_integer(
            $raw['phaseStartedAtMs'] ?? 0,
            'phaseStartedAtMs'
        );
        $ends = self::non_negative_integer(
            $raw['phaseEndsAtMs'] ?? 0,
            'phaseEndsAtMs'
        );

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'questionIds' => $questionids,
            'currentIndex' => $currentindex,
            'questionToken' => $token,
            'revealedQuestionToken' => $revealedtoken,
            'phaseStartedAtMs' => $started,
            'phaseEndsAtMs' => $ends,
        ];
    }

    /**
     * Encode a state after validating it through the canonical decoder.
     *
     * @param array $state State document.
     * @return string
     */
    public static function encode(array $state): string {
        $json = json_encode(
            $state,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $canonical = self::decode($json);
        return json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Enter one concrete question and create a fresh visit token.
     *
     * @param array $state Current canonical state.
     * @param int $index Snapshot index.
     * @param int $nowms Server timestamp in milliseconds.
     * @param int $timelimitseconds Question time limit.
     * @return array Updated state.
     */
    public static function enter_question(
        array $state,
        int $index,
        int $nowms,
        int $timelimitseconds
    ): array {
        self::assert_canonical($state);
        if ($index < 0 || $index >= count($state['questionIds'])) {
            throw new \invalid_parameter_exception('Live question index is invalid.');
        }
        $state['currentIndex'] = $index;
        $state['questionToken'] = bin2hex(random_bytes(16));
        $state['phaseStartedAtMs'] = $nowms + self::QUESTION_COUNTDOWN_MS;
        // The interaction policy has already normalised ordinary question
        // limits and preserved wider type-owned stage limits (for example a
        // ten-minute brainstorm collection). Do not clamp both domains here.
        $duration = max(0, $timelimitseconds);
        $state['phaseEndsAtMs'] = $duration > 0
            ? $state['phaseStartedAtMs'] + $duration * 1000
            : 0;
        return $state;
    }

    /**
     * Start a later interaction stage without replaying the question countdown.
     *
     * @param array $state Current state.
     * @param int $nowms Server timestamp in milliseconds.
     * @param int $durationseconds Zero means manually advanced.
     * @return array
     */
    public static function enter_interaction(
        array $state,
        int $nowms,
        int $durationseconds
    ): array {
        self::assert_canonical($state);
        $state['phaseStartedAtMs'] = $nowms;
        $state['phaseEndsAtMs'] = $durationseconds > 0
            ? $nowms + $durationseconds * 1000
            : 0;
        return $state;
    }

    /**
     * Move between non-question phases without changing snapshot identity.
     *
     * @param array $state Current state.
     * @param int $nowms Server timestamp in milliseconds.
     * @return array Updated state.
     */
    public static function mark_phase(array $state, int $nowms): array {
        self::assert_canonical($state);
        $state['phaseStartedAtMs'] = $nowms;
        $state['phaseEndsAtMs'] = 0;
        return $state;
    }

    /**
     * Mark the current visit as explicitly revealed.
     *
     * @param array $state Current state.
     * @param int $nowms Server timestamp in milliseconds.
     * @return array Updated state.
     */
    public static function mark_revealed(array $state, int $nowms): array {
        $state = self::mark_phase($state, $nowms);
        $state['revealedQuestionToken'] = (string)$state['questionToken'];
        return $state;
    }

    /**
     * Whether this exact question visit reached reveal.
     *
     * @param array $state Canonical state.
     * @return bool
     */
    public static function current_question_was_revealed(array $state): bool {
        $token = (string)($state['questionToken'] ?? '');
        $revealed = (string)($state['revealedQuestionToken'] ?? '');
        return $token !== ''
            && $revealed !== ''
            && hash_equals($token, $revealed);
    }

    /**
     * Return the concrete currently played question ID.
     *
     * @param array $state Canonical state.
     * @return int|null Question ID or null in the lobby.
     */
    public static function current_question_id(array $state): ?int {
        $index = (int)$state['currentIndex'];
        return $index < 0 ? null : (int)$state['questionIds'][$index];
    }

    /**
     * Remap concrete question IDs inside a backup state document.
     *
     * @param string|null $json Old persisted JSON.
     * @param callable $mapper Receives an old ID and returns a mapped ID or zero.
     * @return string|null Remapped canonical JSON, or null if it cannot be repaired.
     */
    public static function remap_question_ids(?string $json, callable $mapper): ?string {
        try {
            $state = self::decode($json);
        } catch (\invalid_parameter_exception $exception) {
            return null;
        }
        $mapped = [];
        foreach ($state['questionIds'] as $oldid) {
            $newid = (int)$mapper((int)$oldid);
            if ($newid <= 0 || in_array($newid, $mapped, true)) {
                return null;
            }
            $mapped[] = $newid;
        }
        $state['questionIds'] = $mapped;
        return self::encode($state);
    }

    /**
     * Privacy-safe canonicalisation for legacy or current state documents.
     *
     * @param string|null $json Stored state.
     * @return string|null Canonical PII-free state or null when malformed.
     */
    public static function redact_personal_data(?string $json): ?string {
        try {
            return self::encode(self::decode($json));
        } catch (\invalid_parameter_exception $exception) {
            return null;
        }
    }

    /**
     * Validate a unique list of positive question IDs.
     *
     * @param mixed $raw Raw list.
     * @return int[]
     */
    private static function question_ids($raw): array {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \invalid_parameter_exception('questionIds must be a list.');
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = self::integer($value, 'questionIds');
            if ($id <= 0 || isset($ids[$id])) {
                throw new \invalid_parameter_exception('questionIds contains invalid IDs.');
            }
            $ids[$id] = $id;
        }
        return array_values($ids);
    }

    /**
     * Parse an integer without accepting floats or decorated strings.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @return int
     */
    private static function integer($raw, string $field): int {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $raw)) {
            return (int)$raw;
        }
        throw new \invalid_parameter_exception("{$field} must be an integer.");
    }

    /**
     * Parse a non-negative integer.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @return int
     */
    private static function non_negative_integer($raw, string $field): int {
        $value = self::integer($raw, $field);
        if ($value < 0) {
            throw new \invalid_parameter_exception("{$field} must not be negative.");
        }
        return $value;
    }

    /**
     * Reject a non-canonical in-memory state without repeated encode/decode work.
     *
     * @param array $state State document.
     * @return void
     */
    private static function assert_canonical(array $state): void {
        $canonical = self::decode(json_encode(
            $state,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        if ($canonical !== $state) {
            throw new \invalid_parameter_exception('Live session state is not canonical.');
        }
    }
}
