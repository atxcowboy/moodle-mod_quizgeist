<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX action registry.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\ajax;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps public action names to their handler and security metadata.
 */
final class action_registry {

    /**
     * Registered actions.
     *
     * Keep writes explicit: dispatcher closes the Moodle session before
     * executing every action whose writes flag is false.
     */
    private const BASE_ACTIONS = [
        'ping' => [
            'capability' => [
                'mod/quizgeist:play',
                'mod/quizgeist:manage',
                'mod/quizgeist:host',
                'mod/quizgeist:viewreports',
            ],
            'handler' => ping_handler::class,
            'writes' => false,
        ],
        'player_bootstrap' => [
            'capability' => [
                'mod/quizgeist:play',
                'mod/quizgeist:manage',
                'mod/quizgeist:host',
                'mod/quizgeist:viewreports',
            ],
            'handler' => player_bootstrap_handler::class,
            'writes' => false,
        ],
        'teacher_bootstrap' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => teacher_bootstrap_handler::class,
            'writes' => false,
        ],
        'report_bootstrap' => [
            'capability' => [
                'mod/quizgeist:viewreports',
                'mod/quizgeist:play',
            ],
            'handler' => report_bootstrap_handler::class,
            'writes' => false,
        ],
        'report_data' => [
            'capability' => [
                'mod/quizgeist:viewreports',
                'mod/quizgeist:play',
            ],
            'handler' => report_data_handler::class,
            'writes' => false,
        ],
        'live_host_bootstrap' => [
            'capability' => 'mod/quizgeist:host',
            'handler' => live_host_bootstrap_handler::class,
            'writes' => false,
        ],
        'live_session_create' => [
            'capability' => 'mod/quizgeist:host',
            'handler' => live_session_create_handler::class,
            'writes' => true,
        ],
        'live_host_poll' => [
            'capability' => 'mod/quizgeist:host',
            'handler' => live_host_poll_handler::class,
            'writes' => false,
        ],
        'live_host_command' => [
            'capability' => 'mod/quizgeist:host',
            'handler' => live_host_command_handler::class,
            'writes' => true,
        ],
        'live_host_interaction' => [
            'capability' => 'mod/quizgeist:host',
            'handler' => live_host_interaction_handler::class,
            'writes' => true,
        ],
        'live_player_bootstrap' => [
            'capability' => [
                'mod/quizgeist:play',
                'mod/quizgeist:manage',
                'mod/quizgeist:host',
            ],
            'handler' => live_player_bootstrap_handler::class,
            'writes' => false,
        ],
        'live_session_lookup' => [
            'capability' => [
                'mod/quizgeist:play',
                'mod/quizgeist:manage',
                'mod/quizgeist:host',
            ],
            'handler' => live_session_lookup_handler::class,
            'writes' => false,
        ],
        'live_session_join' => [
            'capability' => [
                'mod/quizgeist:play',
                'mod/quizgeist:manage',
                'mod/quizgeist:host',
            ],
            'handler' => live_session_join_handler::class,
            'writes' => true,
        ],
        'live_player_poll' => [
            'capability' => [
                'mod/quizgeist:play',
                'mod/quizgeist:manage',
                'mod/quizgeist:host',
            ],
            'handler' => live_player_poll_handler::class,
            'writes' => false,
        ],
        'live_answer' => [
            'capability' => [
                'mod/quizgeist:play',
                'mod/quizgeist:manage',
                'mod/quizgeist:host',
            ],
            'handler' => live_answer_handler::class,
            'writes' => true,
        ],
        'editor_bootstrap' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => editor_bootstrap_handler::class,
            'writes' => false,
        ],
        'question_create' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => question_create_handler::class,
            'writes' => true,
        ],
        'question_save' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => question_save_handler::class,
            'writes' => true,
        ],
        'question_reorder' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => question_reorder_handler::class,
            'writes' => true,
        ],
        'question_duplicate' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => question_duplicate_handler::class,
            'writes' => true,
        ],
        'question_delete' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => question_delete_handler::class,
            'writes' => true,
        ],
        'quiz_save' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => quiz_save_handler::class,
            'writes' => true,
        ],
        'media_save' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => media_save_handler::class,
            'writes' => true,
        ],
        'template_search' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => template_search_handler::class,
            'writes' => false,
        ],
        'template_publish' => [
            'capability' => 'mod/quizgeist:publishtemplate',
            'handler' => template_publish_handler::class,
            'writes' => true,
        ],
        'template_import' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => template_import_handler::class,
            'writes' => true,
        ],
        'template_delete' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => template_delete_handler::class,
            'writes' => true,
        ],
        // U1 Tagging-Kern. Bewusst ohne feature-Schluessel: Tagging selbst ist
        // kostenlos, gated ist erst die Auswertung in den Zusatzpaketen.
        'tag_list' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => tag_list_handler::class,
            'writes' => false,
        ],
        'tag_save' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => tag_save_handler::class,
            'writes' => true,
        ],
        'question_tags_save' => [
            'capability' => 'mod/quizgeist:manage',
            'handler' => question_tags_save_handler::class,
            'writes' => true,
        ],
    ];

    /**
     * Look up an action definition.
     *
     * @param string $action Validated action name.
     * @return array|null Action metadata, or null for an unknown action.
     */
    public static function get(string $action): ?array {
        $actions = self::actions();
        return $actions[$action] ?? null;
    }

    /**
     * Return the validated complete action map for this request.
     *
     * @return array<string,array>
     */
    public static function actions(): array {
        static $actions = null;
        if ($actions !== null) {
            return $actions;
        }

        $addonactions = \mod_quizgeist\local\addon\registry::ajax_actions();
        $duplicates = array_intersect_key(self::BASE_ACTIONS, $addonactions);
        if ($duplicates) {
            throw new \coding_exception(
                'A Quizgeist addon attempted to replace a core AJAX action.'
            );
        }
        $actions = self::BASE_ACTIONS + $addonactions;
        foreach ($actions as $name => $definition) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/D', $name)
                    || !is_array($definition)
                    || !array_key_exists('capability', $definition)
                    || !is_string($definition['handler'] ?? null)
                    || !is_bool($definition['writes'] ?? null)
                    || (array_key_exists('feature', $definition)
                        && (!is_string($definition['feature'])
                            || \mod_quizgeist\local\licence\feature_gate::component(
                                $definition['feature']
                            ) === null))
                    || (array_key_exists('feature', $definition)
                        !== array_key_exists('operation', $definition))
                    || (array_key_exists('operation', $definition)
                        && !is_string($definition['operation']))) {
                throw new \coding_exception(
                    "Quizgeist AJAX action {$name} has an invalid definition."
                );
            }
        }
        return $actions;
    }
}
