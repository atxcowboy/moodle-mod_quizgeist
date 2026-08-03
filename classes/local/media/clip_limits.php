<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Hard limits of the short-clip channel (U3).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\media;

defined('MOODLE_INTERNAL') || die();

/**
 * Every bound of the clip channel in one auditable place.
 *
 * Modelled on quizgeistaddon_ai\local\ai\document_limits: constants first,
 * settings only as a bounded override. A setting may make a limit stricter or
 * looser inside the coded corridor, never outside it — an administrator typo
 * must not be able to turn an 8 MiB channel into an unbounded one.
 */
final class clip_limits {

    /** Moodle file area holding the audio. */
    public const FILE_AREA = 'clipaudio';

    /** Default upload ceiling in bytes (8 MiB). */
    public const DEFAULT_MAX_BYTES = 8388608;

    /** Absolute corridor for the configurable byte ceiling. */
    public const MIN_MAX_BYTES = 65536;
    public const MAX_MAX_BYTES = 33554432;

    /** Default duration ceiling in seconds. */
    public const DEFAULT_MAX_SECONDS = 60;

    /** Absolute corridor for the configurable duration ceiling. */
    public const MIN_MAX_SECONDS = 5;
    public const MAX_MAX_SECONDS = 300;

    /**
     * Default retention of the AUDIO in days.
     *
     * Decision E-10 is stricter than the original plan: zero means the audio is
     * deleted as soon as it has been evaluated. A school that wants a review
     * window has to switch that on deliberately.
     */
    public const DEFAULT_RETENTION_DAYS = 0;

    /** Upper bound for the configurable retention. */
    public const MAX_RETENTION_DAYS = 365;

    /** Uploads per user and activity inside the rate window. */
    public const RATE_LIMIT = 20;

    /** Rate window in seconds; matches the TTL of the cliprate cache. */
    public const RATE_WINDOW = 60;

    /** @var string[] Purposes a clip may serve. */
    public const PURPOSES = ['answer', 'reason', 'speaking', 'dictation'];

    /** @var string[] Transcription states. */
    public const STATES = ['none', 'pending', 'done', 'failed', 'declined'];

    /**
     * @var string[] Container MIME types accepted from a recorder.
     *
     * Verbatim the list mod_redewerkstatt::transcribe() uses. The client's own
     * declaration is never trusted; this list is matched against the type the
     * server derived from the bytes.
     */
    public const MIME_TYPES = [
        'audio/webm', 'video/webm', 'audio/ogg', 'audio/mp4', 'video/mp4',
        'audio/x-m4a', 'audio/aac', 'audio/mpeg', 'audio/wav', 'audio/x-wav',
    ];

    /** Longest accepted transcript in characters. */
    public const MAX_TRANSCRIPT_CHARS = 8000;

    /**
     * Configured upload ceiling in bytes, clamped into the coded corridor.
     *
     * @return int
     */
    public static function max_bytes(): int {
        return self::bounded(
            'clip_max_bytes',
            self::DEFAULT_MAX_BYTES,
            self::MIN_MAX_BYTES,
            self::MAX_MAX_BYTES
        );
    }

    /**
     * Configured duration ceiling in seconds, clamped into the coded corridor.
     *
     * @return int
     */
    public static function max_seconds(): int {
        return self::bounded(
            'clip_max_seconds',
            self::DEFAULT_MAX_SECONDS,
            self::MIN_MAX_SECONDS,
            self::MAX_MAX_SECONDS
        );
    }

    /**
     * Configured audio retention in days; zero means delete immediately.
     *
     * @return int
     */
    public static function retention_days(): int {
        return self::bounded(
            'clip_retention_days',
            self::DEFAULT_RETENTION_DAYS,
            0,
            self::MAX_RETENTION_DAYS
        );
    }

    /**
     * Whether a purpose is one this plugin knows.
     *
     * @param string $purpose Raw purpose.
     * @return bool
     */
    public static function is_purpose(string $purpose): bool {
        return in_array($purpose, self::PURPOSES, true);
    }

    /**
     * Read and clamp an integer setting.
     *
     * @param string $name Setting suffix under mod_quizgeist.
     * @param int $default Default value.
     * @param int $minimum Lowest accepted value.
     * @param int $maximum Highest accepted value.
     * @return int
     */
    private static function bounded(string $name, int $default, int $minimum, int $maximum): int {
        $raw = get_config('mod_quizgeist', $name);
        $value = is_numeric($raw) ? (int) $raw : $default;
        return max($minimum, min($maximum, $value));
    }
}
