<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Live scoring projections and player feedback.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\live\qtype\strategy_support;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps score evaluation separate from role-safe state projection.
 */
final class standings {

    /**
     * Build/cache deterministic standings after answers have stopped changing.
     *
     * @param \stdClass $session Session row.
     * @param array $state State.
     * @param \stdClass[]|null $loadedplayers Optional already-loaded rows.
     * @return array
     */
    public static function ranking(
        \stdClass $session,
        array $state,
        ?array $loadedplayers = null
    ): array {
        $cache = \cache::make('mod_quizgeist', 'liveprojection');
        $key = 'ranking_' . (int)$session->id . '_' . (int)$session->stateversion;
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $players = $loadedplayers ?? session_repository::players((int)$session->id);
        usort($players, static function(\stdClass $left, \stdClass $right): int {
            $score = (int)$right->score <=> (int)$left->score;
            if ($score !== 0) {
                return $score;
            }
            $joined = (int)$left->timejoined <=> (int)$right->timejoined;
            return $joined !== 0 ? $joined : ((int)$left->id <=> (int)$right->id);
        });
        $deltas = visit_ledger::answer_deltas($session, $state);
        $settings = session_settings::decode($session->settingsjson ?? null);
        $ranking = [];
        foreach ($players as $index => $player) {
            $selection = reward_service::split($player->avatarkey ?? null);
            $team = self::player_team($player, $settings['team']);
            $ranking[] = [
                'playerId' => (int)$player->id,
                'displayName' => (string)$player->displayname,
                'avatarKey' => $selection['avatarKey'],
                'accessoryKey' => $selection['accessoryKey'],
                'score' => (int)$player->score,
                'delta' => (int)($deltas[(int)$player->id] ?? 0),
                'streak' => (int)$player->streak,
                'rank' => $index + 1,
                'teamId' => $team['teamKey'] ?? null,
                'teamName' => $team['teamName'] ?? null,
            ];
        }
        $cache->set($key, $ranking);
        return $ranking;
    }

    /**
     * Cache team means once per durable state version.
     *
     * @param \stdClass $session Session.
     * @param array $settings Canonical settings.
     * @param \stdClass[]|null $loadedplayers Optional player rows.
     * @return array
     */
    public static function team_ranking(
        \stdClass $session,
        array $settings,
        ?array $loadedplayers = null
    ): array {
        if ((string)$session->mode !== 'team' || $settings['team'] === null) {
            return [];
        }
        $cache = \cache::make('mod_quizgeist', 'liveprojection');
        $key = 'team_ranking_' . (int)$session->id . '_'
            . (int)$session->stateversion;
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $players = $loadedplayers
            ?? session_repository::players((int)$session->id);
        $ranking = team_service::ranking($players, $settings['team']);
        $cache->set($key, $ranking);
        return $ranking;
    }

    /**
     * Current-answer feedback; unresolved scorevoid rows are never revealed.
     *
     * @param \stdClass $answer Answer row.
     * @param \stdClass $question Exact played question.
     * @param string $visit Exact played visit.
     * @return array
     */
    public static function feedback(
        \stdClass $answer,
        \stdClass $question,
        string $visit
    ): array {
        $decoded = json_decode((string)$answer->answerjson, true);
        $publicanswer = is_array($decoded) ? $decoded : [];
        unset(
            $publicanswer['questionToken'],
            $publicanswer['scoreBefore'],
            $publicanswer['streakBefore']
        );
        $publicanswer = strategy_support::public_answer(
            answer_evaluator::canonical_question($question),
            $publicanswer,
            $visit
        );
        return [
            'correct' => $answer->iscorrect === null
                ? null
                : !empty($answer->iscorrect),
            'points' => max(0, (int)$answer->points),
            'responseTimeMs' => (int)$answer->responsetime,
            'choiceIds' => array_values(array_filter(
                $publicanswer['choiceIds'] ?? [],
                'is_string'
            )),
            'answer' => $publicanswer,
        ];
    }

    /**
     * Project one player's persisted team columns through canonical settings.
     *
     * @param \stdClass $player Player.
     * @param array|null $configuration Team configuration.
     * @return array{teamKey:string,teamName:string}|null
     */
    public static function player_team(
        \stdClass $player,
        ?array $configuration
    ): ?array {
        $teamname = trim((string)($player->teamname ?? ''));
        $teamkey = team_service::player_key($player, $configuration);
        if ($teamname === '' || $teamkey === null) {
            return null;
        }
        return [
            'teamKey' => $teamkey,
            'teamName' => $teamname,
        ];
    }
}
