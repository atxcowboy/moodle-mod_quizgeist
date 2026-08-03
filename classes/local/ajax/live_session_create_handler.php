<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\live\request_validator;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates an immutable-question-snapshot lobby.
 */
final class live_session_create_handler implements action_handler {
    public function execute(action_context $context): array {
        $payload = $context->get_payload();
        $options = [
            'teamSource' => request_validator::string(
                $payload['teamSource'] ?? 'free',
                'teamSource',
                16
            ),
            'teamNames' => self::string_list(
                $payload['teamNames'] ?? [],
                'teamNames',
                12,
                80
            ),
            'blockedNames' => self::string_list(
                $payload['blockedNames'] ?? [],
                'blockedNames',
                50,
                80
            ),
        ];
        // F1 Stressarm-Standard. Diese vier Felder sind bewusst ungated;
        // session_settings normalisiert sie und lässt assert_creatable()
        // unberührt. Ein Feld, das der Aufrufer gar nicht schickt, bleibt
        // abwesend — dann gilt die Werksvorgabe der Aktivität, nicht "aus".
        if (array_key_exists('pace', $payload)) {
            $options['pace'] = request_validator::string(
                $payload['pace'],
                'pace',
                16
            );
        }
        if (array_key_exists('leaderboard', $payload)) {
            $options['leaderboard'] = request_validator::string(
                $payload['leaderboard'],
                'leaderboard',
                16
            );
        }
        foreach (['timer', 'sound'] as $switch) {
            if (array_key_exists($switch, $payload)) {
                $options[$switch] = !empty($payload[$switch]);
            }
        }
        // F3 Live-Wiederholungs-Block, Variante (b) aus Entscheidung E-1: der
        // Block wird BEIM ANLEGEN gewählt und geht regulär in die eingefrorene
        // Sequenz ein. Die Sequenz einer laufenden Session bleibt damit
        // unveränderlich — davon hängt der Reconnect-Pfad ab.
        $reviewblock = 0;
        if (array_key_exists('reviewBlock', $payload)) {
            $reviewblock = request_validator::bounded_int(
                $payload['reviewBlock'],
                'reviewBlock',
                0,
                session_service::MAX_REVIEW_BLOCK
            );
        }
        return session_service::create_session(
            $context->get_instance(),
            $context->get_module_context(),
            $context->get_user(),
            request_validator::string($payload['mode'] ?? 'classic', 'mode', 32),
            request_validator::string($payload['nameMode'] ?? 'real', 'nameMode', 16),
            $options,
            $reviewblock
        );
    }

    /**
     * Validate a bounded request list before it reaches the settings policy.
     *
     * @param mixed $raw Raw payload value.
     * @param string $field Field name.
     * @param int $maximum Maximum list size.
     * @param int $maxlength Maximum character count per value.
     * @return string[]
     */
    private static function string_list(
        $raw,
        string $field,
        int $maximum,
        int $maxlength
    ): array {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > $maximum) {
            throw new \invalid_parameter_exception("{$field} must be a list.");
        }
        $values = [];
        foreach ($raw as $index => $value) {
            $values[] = request_validator::string(
                $value,
                "{$field}[{$index}]",
                $maxlength
            );
        }
        return $values;
    }
}
