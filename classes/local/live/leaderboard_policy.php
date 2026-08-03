<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Server-side leaderboard visibility for the stress-free standard.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * One place decides which ranks reach which role.
 *
 * The cut happens on the server. Hiding foreign ranks in the client would
 * still ship them: the payload itself must not contain what a learner may
 * not see (F1, proof in the payload, not in the DOM). The host keeps the
 * complete view in every setting — the teacher owns the session.
 */
final class leaderboard_policy {

    /** Only the learner's own standing reaches the player payload. */
    public const OWN = 'own';

    /** The learner's own team plus their own standing reach the payload. */
    public const TEAM = 'team';

    /** Every standing reaches the payload, as before P11. */
    public const FULL = 'full';

    /** @var string[] Stable persisted visibility catalogue. */
    public const VISIBILITIES = [self::OWN, self::TEAM, self::FULL];

    /**
     * The works default of the stress-free standard.
     *
     * Deliberately kept in exactly one place: switching the school-wide
     * default between `own` and `full` must remain a one-line change
     * (P11_PLAN.md, open decision E-2, decided as `own`).
     */
    public const DEFAULT_VISIBILITY = self::OWN;

    /**
     * Normalise one persisted or submitted visibility value.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    public static function normalise($value): string {
        return is_string($value) && in_array($value, self::VISIBILITIES, true)
            ? $value
            : self::DEFAULT_VISIBILITY;
    }

    /**
     * Cut a player ranking down to what this visibility allows.
     *
     * @param array<int,array> $ranking Complete ordered ranking.
     * @param string $visibility Canonical visibility.
     * @param int $playerid Player whose payload this is.
     * @return array<int,array> Ranking as it may leave the server.
     */
    public static function player_ranking(
        array $ranking,
        string $visibility,
        int $playerid
    ): array {
        $visibility = self::normalise($visibility);
        if ($visibility === self::FULL) {
            return array_values($ranking);
        }
        $own = null;
        foreach ($ranking as $entry) {
            if ((int)($entry['playerId'] ?? 0) === $playerid) {
                $own = $entry;
                break;
            }
        }
        if ($own === null) {
            return [];
        }
        if ($visibility === self::OWN) {
            return [$own];
        }
        $teamid = $own['teamId'] ?? null;
        if ($teamid === null) {
            return [$own];
        }
        return array_values(array_filter(
            $ranking,
            static fn(array $entry): bool =>
                ($entry['teamId'] ?? null) === $teamid
        ));
    }

    /**
     * Cut a podium down to what this visibility allows.
     *
     * A podium is the celebratory top of the complete ranking. With `own` or
     * `team` it collapses to the learner's own reachable standings, so no
     * foreign name and no foreign score ever leaves the server.
     *
     * @param array<int,array> $podium Complete podium.
     * @param string $visibility Canonical visibility.
     * @param int $playerid Player whose payload this is.
     * @return array<int,array>
     */
    public static function player_podium(
        array $podium,
        string $visibility,
        int $playerid
    ): array {
        $visibility = self::normalise($visibility);
        if ($visibility === self::FULL) {
            return array_values($podium);
        }
        return array_slice(
            self::player_ranking($podium, $visibility, $playerid),
            0,
            3
        );
    }

    /**
     * Cut a team ranking down to what this visibility allows.
     *
     * Team means are aggregates, never personal standings. `team` therefore
     * keeps the learner's own team, `own` keeps nothing.
     *
     * @param array<int,array> $teamranking Complete team ranking.
     * @param string $visibility Canonical visibility.
     * @param string|null $teamkey Learner's own team key.
     * @return array<int,array>
     */
    public static function player_team_ranking(
        array $teamranking,
        string $visibility,
        ?string $teamkey
    ): array {
        $visibility = self::normalise($visibility);
        if ($visibility === self::FULL) {
            return array_values($teamranking);
        }
        if ($visibility === self::OWN || $teamkey === null) {
            return [];
        }
        return array_values(array_filter(
            $teamranking,
            static fn(array $entry): bool =>
                (string)($entry['teamKey'] ?? $entry['teamId'] ?? '') === $teamkey
        ));
    }

    /**
     * Whether the complete ranking may be shown to this role at all.
     *
     * @param string $role host or player.
     * @param string $visibility Canonical visibility.
     * @return bool
     */
    public static function shows_full(string $role, string $visibility): bool {
        return $role === 'host' || self::normalise($visibility) === self::FULL;
    }
}
