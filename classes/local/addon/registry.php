<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Moodle-standard discovery for installed Quizgeist addons.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\addon;

defined('MOODLE_INTERNAL') || die();

/**
 * Discovers addon providers and combines their extension declarations.
 */
final class registry {

    /** Supported custom subplugin type. */
    public const SUBPLUGIN_TYPE = 'quizgeistaddon';

    /**
     * Installed addon components keyed by their short plugin name.
     *
     * A directory alone is not considered installed: its version row must
     * exist as well. This prevents code copied before an upgrade from becoming
     * active halfway through Moodle's installation transaction.
     *
     * @return array<string,string>
     */
    public static function installed_components(): array {
        $plugins = \core_component::get_plugin_list(self::SUBPLUGIN_TYPE);
        ksort($plugins, SORT_STRING);
        $components = [];
        foreach ($plugins as $name => $unusedpath) {
            $component = self::SUBPLUGIN_TYPE . '_' . $name;
            if (get_config($component, 'version') !== false) {
                $components[$name] = $component;
            }
        }
        return $components;
    }

    /**
     * Whether one exact addon component is installed and upgraded.
     *
     * @param string $component Full component name.
     * @return bool
     */
    public static function is_installed(string $component): bool {
        if (!preg_match('/^quizgeistaddon_[a-z][a-z0-9]*$/D', $component)) {
            return false;
        }
        return in_array($component, self::installed_components(), true);
    }

    /**
     * Resolve all valid provider classes.
     *
     * @return array<string,class-string<provider>> Component => provider.
     */
    public static function providers(): array {
        $providers = [];
        foreach (self::installed_components() as $name => $component) {
            $classname = '\\' . $component . '\\local\\provider';
            if (!class_exists($classname)) {
                debugging(
                    "Installed Quizgeist addon {$component} has no provider class.",
                    DEBUG_DEVELOPER
                );
                continue;
            }
            if (!is_subclass_of($classname, provider::class)) {
                debugging(
                    "Quizgeist addon provider {$classname} does not implement the provider contract.",
                    DEBUG_DEVELOPER
                );
                continue;
            }
            if ($classname::feature() !== $name) {
                debugging(
                    "Quizgeist addon {$component} declares an inconsistent feature key.",
                    DEBUG_DEVELOPER
                );
                continue;
            }
            $providers[$component] = $classname;
        }
        return $providers;
    }

    /**
     * Combine all declared AJAX actions and reject ambiguous registrations.
     *
     * @return array<string,array>
     */
    public static function ajax_actions(): array {
        return self::combine_maps('ajax_actions', 'AJAX action');
    }

    /**
     * Combine all declared question strategies.
     *
     * @return array<string,class-string<\mod_quizgeist\local\live\qtype\live_question_type>>
     */
    public static function question_type_strategies(): array {
        /** @var array<string,class-string<\mod_quizgeist\local\live\qtype\live_question_type>> $strategies */
        $strategies = self::combine_maps(
            'question_type_strategies',
            'question type'
        );
        return $strategies;
    }

    /**
     * Combine stable unique live modes.
     *
     * @return string[]
     */
    public static function live_modes(): array {
        return self::combine_lists('live_modes', 'live mode');
    }

    /**
     * Combine stable unique activity themes.
     *
     * @return string[]
     */
    public static function themes(): array {
        return self::combine_lists('themes', 'theme');
    }

    /**
     * Invoke one provider map method in deterministic component order.
     *
     * @param string $method Provider method.
     * @param string $label Human-readable declaration kind.
     * @return array
     */
    private static function combine_maps(string $method, string $label): array {
        $combined = [];
        foreach (self::providers() as $component => $providerclass) {
            $combined = self::merge_map(
                $combined,
                $component,
                $label,
                $providerclass::$method()
            );
        }
        return $combined;
    }

    /**
     * Validate and merge one provider's keyed declaration map.
     *
     * Declaring nothing is part of the provider contract: an addon that owns no
     * question type, no AJAX action or neither returns an empty array. PHP
     * reports `array_is_list([]) === true`, so the list rejection must exempt
     * the empty array explicitly — otherwise every addon without that kind of
     * declaration is refused as if it were broken. A non-empty list stays
     * invalid: it means the addon returned values where keys were required.
     *
     * @param array $combined Declarations accepted so far.
     * @param string $component Declaring component.
     * @param string $label Human-readable declaration kind.
     * @param mixed $values Raw provider return value.
     * @return array
     */
    private static function merge_map(
        array $combined,
        string $component,
        string $label,
        mixed $values
    ): array {
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new \coding_exception(
                "Quizgeist addon {$component} returned an invalid {$label} map."
            );
        }
        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '' || array_key_exists($key, $combined)) {
                throw new \coding_exception(
                    "Quizgeist addon {$component} registered an invalid or duplicate {$label}."
                );
            }
            $combined[$key] = $value;
        }
        return $combined;
    }

    /**
     * Invoke one provider list method in deterministic component order.
     *
     * @param string $method Provider method.
     * @param string $label Human-readable declaration kind.
     * @return string[]
     */
    private static function combine_lists(string $method, string $label): array {
        $combined = [];
        foreach (self::providers() as $component => $providerclass) {
            $combined = self::merge_list(
                $combined,
                $component,
                $label,
                $providerclass::$method()
            );
        }
        return array_keys($combined);
    }

    /**
     * Validate and merge one provider's ordered declaration list.
     *
     * The empty array is a valid list and therefore already the documented way
     * to declare nothing here; this method exists so that both declaration
     * shapes are validated in one named, directly testable place.
     *
     * @param array<string,true> $combined Declarations accepted so far.
     * @param string $component Declaring component.
     * @param string $label Human-readable declaration kind.
     * @param mixed $values Raw provider return value.
     * @return array<string,true>
     */
    private static function merge_list(
        array $combined,
        string $component,
        string $label,
        mixed $values
    ): array {
        if (!is_array($values) || !array_is_list($values)) {
            throw new \coding_exception(
                "Quizgeist addon {$component} returned an invalid {$label} list."
            );
        }
        foreach ($values as $value) {
            if (!is_string($value)
                    || !preg_match('/^[a-z][a-z0-9_-]*$/D', $value)
                    || isset($combined[$value])) {
                throw new \coding_exception(
                    "Quizgeist addon {$component} registered an invalid or duplicate {$label}."
                );
            }
            $combined[$value] = true;
        }
        return $combined;
    }
}
