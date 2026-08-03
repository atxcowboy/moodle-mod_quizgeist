<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Joins or idempotently refreshes a player in the lobby.
 */
final class live_session_join_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $displayname = array_key_exists('displayName', $payload)
            && $payload['displayName'] !== null
            ? request_validator::string($payload['displayName'], 'displayName', 255)
            : null;
        $avatarkey = array_key_exists('avatarKey', $payload)
            && $payload['avatarKey'] !== null
            ? request_validator::string($payload['avatarKey'], 'avatarKey', 64)
            : null;
        $accessorykey = array_key_exists('accessoryKey', $payload)
            && $payload['accessoryKey'] !== null
            ? request_validator::string(
                $payload['accessoryKey'],
                'accessoryKey',
                64
            )
            : null;
        $teamkey = array_key_exists('teamKey', $payload)
            && $payload['teamKey'] !== null
            ? request_validator::string($payload['teamKey'], 'teamKey', 64)
            : null;
        return session_service::join_session(
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user(),
            request_validator::positive_id($payload['sessionId'] ?? null, 'sessionId'),
            request_validator::string($payload['joinCode'] ?? null, 'joinCode', 6),
            $displayname,
            $avatarkey,
            $accessorykey,
            $teamkey
        );
    }
}
