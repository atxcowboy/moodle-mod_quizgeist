<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The single Quizgeist premium feature decision point.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

use mod_quizgeist\local\addon\registry as addon_registry;

/**
 * Combines physical addon availability with signed entitlement state.
 */
final class feature_gate {

    public const CREATE_NEW = 'create_new';
    public const PLAY_EXISTING = 'play_existing';
    public const VIEW_EXISTING = 'view_existing';
    public const EDIT_EXISTING = 'edit_existing';
    public const EXPORT_EXISTING = 'export_existing';

    /** Operations protected by the contractual data-hostage prohibition. */
    private const EXISTING_OPERATIONS = [
        self::PLAY_EXISTING,
        self::VIEW_EXISTING,
        self::EDIT_EXISTING,
        self::EXPORT_EXISTING,
    ];

    /**
     * Decide at the only entitlement gate used by the plugin.
     */
    public static function allows(
        string $feature,
        string $operation = self::CREATE_NEW
    ): bool {
        $component = self::component($feature);
        if ($component === null || !addon_registry::is_installed($component)) {
            return false;
        }

        // Invalid, missing and expired files must never take existing content
        // hostage. Code still has to be installed to execute that content.
        if (in_array($operation, self::EXISTING_OPERATIONS, true)) {
            return true;
        }
        if ($operation !== self::CREATE_NEW) {
            throw new \coding_exception(
                "Unknown Quizgeist feature-gate operation {$operation}."
            );
        }

        $status = self::status($feature);
        return $status === 'active' || $status === 'grace';
    }

    /**
     * Short, stable helper for creation surfaces and registries.
     */
    public static function can_create(string $feature): bool {
        return self::allows($feature, self::CREATE_NEW);
    }

    /**
     * Enforce the same decision server-side.
     *
     * @throws feature_locked_exception
     */
    public static function require(
        string $feature,
        string $operation = self::CREATE_NEW
    ): void {
        if (!self::allows($feature, $operation)) {
            throw new feature_locked_exception(
                $feature,
                self::availability($feature)['status']
            );
        }
    }

    /**
     * Safe UI descriptor without customer or licence identifiers.
     *
     * @return array{
     *   installed:bool,status:string,canCreate:bool,
     *   canUseExisting:bool,diagnosis:string
     * }
     */
    public static function availability(string $feature): array {
        $component = self::component($feature);
        $installed = $component !== null
            && addon_registry::is_installed($component);
        if (!$installed) {
            return [
                'installed' => false,
                'status' => 'not_installed',
                'canCreate' => false,
                'canUseExisting' => false,
                'diagnosis' => 'addon_not_installed',
            ];
        }
        $snapshot = service::snapshot();
        $status = (string)($snapshot['entitlements'][$component]
            ?? 'read_only');
        return [
            'installed' => true,
            'status' => $status,
            'canCreate' => in_array($status, ['active', 'grace'], true),
            'canUseExisting' => true,
            'diagnosis' => (string)$snapshot['diagnosis'],
        ];
    }

    /**
     * Effective signed status; missing/invalid is always read_only.
     */
    public static function status(string $feature): string {
        $component = self::component($feature);
        if ($component === null) {
            return 'read_only';
        }
        $snapshot = service::snapshot();
        return (string)($snapshot['entitlements'][$component] ?? 'read_only');
    }

    /**
     * Resolve the one contractual entitlement key.
     */
    public static function component(string $feature): ?string {
        return service::COMPONENTS[$feature] ?? null;
    }
}
