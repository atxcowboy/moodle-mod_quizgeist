<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Registry for live-enabled question type strategies.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live\qtype;

use mod_quizgeist\local\addon\registry as addon_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves each persisted qtype to its one strategy object.
 */
final class registry {

    /** Question types available without an addon. */
    public const BASE_TYPES = [
        'quiz',
        'truefalse',
        'shortanswer',
        'poll',
        'wordcloud',
        'slide',
    ];

    /**
     * Optional types retained in the basis runtime for imported and existing
     * content. Their presence here must never make them creatable.
     */
    public const PREMIUM_TYPES = [
        'puzzle',
        'scale',
        'slider',
        'pin',
        'reveal',
        'brainstorm',
        'open',
    ];

    /** @var array<string, class-string<live_question_type>> */
    private const STRATEGIES = [
        'quiz' => quiz::class,
        'truefalse' => truefalse::class,
        'shortanswer' => shortanswer::class,
        'puzzle' => puzzle::class,
        'poll' => poll::class,
        'wordcloud' => wordcloud::class,
        'scale' => scale::class,
        'slider' => slider::class,
        'pin' => pin::class,
        'reveal' => reveal::class,
        'brainstorm' => brainstorm::class,
        'open' => open::class,
        'slide' => slide::class,
    ];

    /**
     * Resolve one live-enabled question type.
     *
     * @param string $type Persisted qtype.
     * @return live_question_type
     */
    public static function get(string $type): live_question_type {
        $classname = self::STRATEGIES[$type] ?? null;
        if ($classname === null) {
            throw new \invalid_parameter_exception('Question type is not live-enabled.');
        }
        static $instances = [];
        if (!isset($instances[$type])) {
            $instances[$type] = new $classname();
        }
        return $instances[$type];
    }

    /**
     * Return every known persisted type in stable compatibility order.
     *
     * This catalogue is intentionally independent from addon installation and
     * licensing. Backup, restore, Kahoot imports and existing content must keep
     * their semantic type even when a creation entitlement is unavailable.
     *
     * @return string[]
     */
    public static function known_types(): array {
        return array_keys(self::STRATEGIES);
    }

    /**
     * Return types whose owning code package is installed.
     *
     * The seven optional strategies deliberately remain in the basis as an
     * inert compatibility runtime. The addon provider therefore announces
     * ownership/availability without replacing those strategy classes.
     *
     * @return string[]
     */
    public static function installed_types(): array {
        $announced = [];
        foreach (addon_registry::question_type_strategies() as $type => $classname) {
            if (!isset(self::STRATEGIES[$type])
                    || !in_array($type, self::PREMIUM_TYPES, true)
                    || !is_a($classname, live_question_type::class, true)) {
                throw new \coding_exception(
                    'A Quizgeist addon announced an unsupported question type.'
                );
            }
            $announced[$type] = true;
        }

        return array_values(array_filter(
            self::known_types(),
            static fn(string $type): bool =>
                in_array($type, self::BASE_TYPES, true)
                || isset($announced[$type])
        ));
    }

    /**
     * Return the types offered for creation now.
     *
     * Existing/imported premium content never passes through this list. A
     * missing licence service fails closed for premium creation while the
     * licence implementation is being installed or upgraded.
     *
     * @return string[]
     */
    public static function creatable_types(): array {
        if (!self::premium_creation_allowed()) {
            return self::BASE_TYPES;
        }
        return self::installed_types();
    }

    /**
     * Whether a new question of this type may be created.
     *
     * @param string $type Persisted qtype.
     * @return bool
     */
    public static function is_creatable(string $type): bool {
        return in_array($type, self::creatable_types(), true);
    }

    /**
     * Reject a direct create/type-change request not exposed by bootstrap.
     *
     * @param string $type Requested qtype.
     * @return void
     */
    public static function assert_creatable(string $type): void {
        if (self::is_creatable($type)) {
            return;
        }
        $gate = '\\mod_quizgeist\\local\\licence\\feature_gate';
        if (in_array($type, self::installed_types(), true)
                && class_exists($gate)) {
            // Reuse the central typed denial so AJAX returns the clear,
            // privacy-safe licence response instead of a generic bad request.
            $gate::require('qtypes');
        }
        throw new \invalid_parameter_exception(
            'This question type is not available for new content.'
        );
    }

    /**
     * Legacy runtime catalogue used by live/self-study consumers.
     *
     * @return string[]
     */
    public static function types(): array {
        return self::known_types();
    }

    /**
     * Ask the central licence service only after the addon announced itself.
     *
     * @return bool
     */
    private static function premium_creation_allowed(): bool {
        if (!array_intersect(self::PREMIUM_TYPES, self::installed_types())) {
            return false;
        }
        $gate = '\\mod_quizgeist\\local\\licence\\feature_gate';
        return class_exists($gate) && $gate::can_create('qtypes');
    }
}
