<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Hard limits of the card mode (F11a).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

defined('MOODLE_INTERNAL') || die();

/**
 * Every bound of the card mode in one auditable place.
 *
 * Twin of clip_limits (U3), for the same reason: constants first, settings
 * only as a bounded override inside a coded corridor. An administrator typo
 * must not be able to turn a 6 MiB channel into an unbounded one, and it must
 * not be able to turn "delete the classroom photograph" into "keep it".
 */
final class card_limits {

    /**
     * Moodle file area holding a scan.
     *
     * This area is deliberately ABSENT from quizgeist_pluginfile(). A class
     * photograph must not be retrievable through any URL of this plugin; the
     * confirmation screen shows the local ObjectURL of the picture the
     * teacher's own camera just produced.
     */
    public const FILE_AREA = 'cardscan';

    /** Card layouts a set can be printed in. */
    public const LAYOUTS = ['abcd', 'abcdef', 'truefalse'];

    /** @var array<string,string[]> Answer letters per layout, in print order. */
    public const LAYOUT_LETTERS = [
        'abcd' => ['A', 'B', 'C', 'D'],
        'abcdef' => ['A', 'B', 'C', 'D', 'E', 'F'],
        'truefalse' => ['A', 'B'],
    ];

    /** Scan lifecycle states. */
    public const STATES = ['pending', 'recognised', 'confirmed', 'failed', 'discarded'];

    /**
     * States after which the picture has served its purpose (E-10).
     *
     * `recognised` is deliberately NOT terminal: the teacher is still looking
     * at the confirmation screen and may want to retry the recognition.
     */
    public const TERMINAL_STATES = ['confirmed', 'failed', 'discarded'];

    /** Default upload ceiling for one scan in bytes (6 MiB). */
    public const DEFAULT_MAX_BYTES = 6291456;

    /** Absolute corridor for the configurable byte ceiling. */
    public const MIN_MAX_BYTES = 65536;
    public const MAX_MAX_BYTES = 10485760;

    /**
     * Pixel ceiling against a decompression bomb.
     *
     * A 6 MiB PNG can decode to gigabytes. The byte ceiling alone therefore
     * bounds the transfer and not the memory; this bounds the memory. 40
     * megapixels is far above any phone camera and far below trouble.
     */
    public const MAX_PIXELS = 40000000;

    /** Smallest edge a scan may have; below that no code is readable anyway. */
    public const MIN_EDGE_PIXELS = 320;

    /**
     * Retention ceiling of a scan PICTURE in hours (E-10).
     *
     * This is the CEILING for an abandoned scan, not the normal path. A scan
     * that reached a terminal state loses its picture immediately, regardless
     * of this value — see purge_card_scans and card_scan_service::confirm().
     * The setting exists because E-10 requires the retention to be
     * configurable, not because a school should need it.
     */
    public const DEFAULT_RETENTION_HOURS = 24;

    /** Upper bound for the configurable retention. */
    public const MAX_RETENTION_HOURS = 720;

    /** Scan uploads per user and activity inside the rate window. */
    public const RATE_LIMIT = 30;

    /** Rate window in seconds; matches the TTL of the cardscanrate cache. */
    public const RATE_WINDOW = 60;

    /**
     * @var string[] Image MIME types accepted from a camera.
     *
     * Exactly the three raster types generate_vision() accepts. SVG is not an
     * image here, it is a script container, and it is not on this list.
     */
    public const MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** Cards a single set may hold; a class plus reserves, not a school. */
    public const MAX_CARDS = 200;

    /** Recognised entries accepted from one model answer. */
    public const MAX_RESULT_ENTRIES = 250;

    /**
     * Configured upload ceiling in bytes, clamped into the coded corridor.
     *
     * @return int
     */
    public static function max_bytes(): int {
        return self::bounded(
            'card_scan_max_bytes',
            self::DEFAULT_MAX_BYTES,
            self::MIN_MAX_BYTES,
            self::MAX_MAX_BYTES
        );
    }

    /**
     * Configured picture retention in hours.
     *
     * @return int
     */
    public static function retention_hours(): int {
        return self::bounded(
            'card_scan_retention_hours',
            self::DEFAULT_RETENTION_HOURS,
            0,
            self::MAX_RETENTION_HOURS
        );
    }

    /**
     * Whether a layout is one this plugin knows.
     *
     * @param string $layout Raw layout.
     * @return bool
     */
    public static function is_layout(string $layout): bool {
        return in_array($layout, self::LAYOUTS, true);
    }

    /**
     * Answer letters of one layout.
     *
     * @param string $layout Layout key.
     * @return string[]
     */
    public static function letters(string $layout): array {
        return self::LAYOUT_LETTERS[$layout] ?? self::LAYOUT_LETTERS['abcd'];
    }

    /**
     * Whether a state is one this plugin knows.
     *
     * @param string $state Raw state.
     * @return bool
     */
    public static function is_state(string $state): bool {
        return in_array($state, self::STATES, true);
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
