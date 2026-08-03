<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Live team configuration and scoring.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps Moodle-group and free-team rules out of the session state machine.
 */
final class team_service {

    /** Maximum persisted teams, including the Moodle-group catch-all. */
    public const MAX_TEAMS = 50;

    /**
     * List usable course groups for the host setup without exposing members.
     *
     * @param \context_module $context Activity context.
     * @return array<int, array{id:int,key:string,name:string,memberCount:int}>
     */
    public static function available_groups(\context_module $context): array {
        global $DB;

        $coursecontext = $context->get_course_context();
        $groups = $DB->get_records(
            'groups',
            ['courseid' => (int)$coursecontext->instanceid],
            'name ASC, id ASC',
            'id,name'
        );
        $result = [];
        foreach (array_values($groups) as $group) {
            $result[] = [
                'id' => (int)$group->id,
                'key' => 'group-' . (int)$group->id,
                'name' => format_string(
                    (string)$group->name,
                    true,
                    ['context' => $coursecontext]
                ),
                'memberCount' => $DB->count_records(
                    'groups_members',
                    ['groupid' => (int)$group->id]
                ),
            ];
        }
        return $result;
    }

    /**
     * Summarise Moodle-group suitability for a host before session creation.
     *
     * @return array{
     *     groupCount:int,
     *     maxGroupCount:int,
     *     tooManyGroups:bool,
     *     unassignedEnrolledCount:int
     * }
     */
    public static function group_readiness(\context_module $context): array {
        global $DB;

        $coursecontext = $context->get_course_context();
        $courseid = (int)$coursecontext->instanceid;
        $groupcount = $DB->count_records('groups', ['courseid' => $courseid]);
        $users = get_enrolled_users(
            $coursecontext,
            'mod/quizgeist:play',
            0,
            'u.id'
        );
        $userids = array_values(array_map(
            static fn(\stdClass $user): int => (int)$user->id,
            $users
        ));
        $assigned = [];
        if ($userids) {
            [$usersql, $params] = $DB->get_in_or_equal(
                $userids,
                SQL_PARAMS_NAMED,
                'teamreadyuser'
            );
            $records = $DB->get_records_sql(
                "SELECT DISTINCT gm.userid
                   FROM {groups_members} gm
                   JOIN {groups} g ON g.id = gm.groupid
                  WHERE g.courseid = :teamreadycourse
                    AND gm.userid {$usersql}",
                ['teamreadycourse' => $courseid] + $params
            );
            foreach ($records as $record) {
                $assigned[(int)$record->userid] = true;
            }
        }
        return [
            'groupCount' => $groupcount,
            'maxGroupCount' => self::MAX_TEAMS - 1,
            'tooManyGroups' => $groupcount > self::MAX_TEAMS - 1,
            'unassignedEnrolledCount' => count(array_filter(
                $userids,
                static fn(int $userid): bool => !isset($assigned[$userid])
            )),
        ];
    }

    /**
     * Build team settings from one create-session payload.
     *
     * @param array $rawoptions Request options.
     * @param \context_module $context Activity context.
     * @return array
     */
    public static function configuration(
        array $rawoptions,
        \context_module $context
    ): array {
        $source = $rawoptions['teamSource'] ?? 'free';
        if (!is_string($source) || !in_array($source, ['free', 'groups'], true)) {
            throw new \invalid_parameter_exception('Team source is invalid.');
        }
        $teams = [];
        if ($source === 'groups') {
            $groups = self::available_groups($context);
            if (count($groups) > self::MAX_TEAMS - 1) {
                throw new live_domain_exception(
                    'too_many_groups',
                    'live:error:toomanygroups'
                );
            }
            foreach ($groups as $group) {
                $teams[] = [
                    'key' => $group['key'],
                    'name' => $group['name'],
                    'groupId' => $group['id'],
                ];
            }
            $teams[] = [
                'key' => 'group-unassigned',
                'name' => get_string('host:team:unassigned', 'mod_quizgeist'),
                'groupId' => null,
            ];
        } else {
            $rawnames = $rawoptions['teamNames'] ?? [];
            if (!is_array($rawnames) || !array_is_list($rawnames)
                    || count($rawnames) > self::MAX_TEAMS) {
                throw new \invalid_parameter_exception('Free-team names are invalid.');
            }
            $seen = [];
            foreach ($rawnames as $index => $rawname) {
                if (!is_string($rawname)) {
                    throw new \invalid_parameter_exception('Free-team names are invalid.');
                }
                $name = trim(clean_param($rawname, PARAM_TEXT));
                $name = \core_text::substr($name, 0, 80);
                $key = self::name_key($name);
                if ($name === '' || isset($seen[$key])) {
                    throw new \invalid_parameter_exception('Free-team names must be unique.');
                }
                $seen[$key] = true;
                $teams[] = [
                    'key' => 'team-' . ($index + 1),
                    'name' => $name,
                    'groupId' => null,
                ];
            }
        }
        if (count($teams) < 2) {
            throw new live_domain_exception(
                'teams_required',
                'live:error:teamsrequired'
            );
        }
        return self::normalise_configuration([
            'source' => $source,
            'teams' => $teams,
        ]);
    }

    /**
     * Validate persisted team settings.
     *
     * @param mixed $raw Raw object.
     * @return array
     */
    public static function normalise_configuration($raw): array {
        if (!is_array($raw)
                || !in_array($raw['source'] ?? null, ['free', 'groups'], true)
                || !is_array($raw['teams'] ?? null)
                || !array_is_list($raw['teams'])
                || count($raw['teams']) < 2
                || count($raw['teams']) > self::MAX_TEAMS) {
            throw new \invalid_parameter_exception('Persisted team settings are invalid.');
        }
        $source = (string)$raw['source'];
        $teams = [];
        $keys = [];
        $hascatchall = false;
        foreach ($raw['teams'] as $entry) {
            if (!is_array($entry)
                    || !is_string($entry['key'] ?? null)
                    || !preg_match(
                        '/^(?:team-[1-9][0-9]*|group-(?:[1-9][0-9]*|unassigned))$/D',
                        $entry['key']
                    )
                    || !is_string($entry['name'] ?? null)) {
                throw new \invalid_parameter_exception('Persisted team settings are invalid.');
            }
            $key = (string)$entry['key'];
            $name = trim(clean_param((string)$entry['name'], PARAM_TEXT));
            $name = \core_text::substr($name, 0, 80);
            $groupid = $entry['groupId'] ?? null;
            $canonicalkey = $source === 'free'
                ? preg_match('/^team-[1-9][0-9]*$/D', $key) === 1
                    && $groupid === null
                : ($key === 'group-unassigned'
                    ? $groupid === null
                    : is_int($groupid)
                        && $groupid > 0
                        && hash_equals('group-' . $groupid, $key));
            if ($name === '' || isset($keys[$key])
                    || !$canonicalkey) {
                throw new \invalid_parameter_exception('Persisted team settings are invalid.');
            }
            $keys[$key] = true;
            if ($key === 'group-unassigned') {
                $hascatchall = true;
            }
            $teams[] = [
                'key' => $key,
                'name' => $name,
                'groupId' => $groupid === null ? null : (int)$groupid,
            ];
        }
        if ($source === 'groups' && !$hascatchall) {
            throw new \invalid_parameter_exception(
                'Moodle-group team settings need a catch-all team.'
            );
        }
        return ['source' => $source, 'teams' => $teams];
    }

    /**
     * Resolve one joining user's team.
     *
     * @param array|null $configuration Team configuration.
     * @param \stdClass $user Joining user.
     * @param string|null $submittedkey Free-team selection.
     * @return array{groupid:?int,teamname:?string,teamkey:?string}
     */
    public static function assignment(
        ?array $configuration,
        \stdClass $user,
        ?string $submittedkey
    ): array {
        global $DB;

        if ($configuration === null) {
            return ['groupid' => null, 'teamname' => null, 'teamkey' => null];
        }
        if ($configuration['source'] === 'groups') {
            foreach ($configuration['teams'] as $team) {
                if (($team['groupId'] ?? null) === null) {
                    continue;
                }
                if ($DB->record_exists('groups_members', [
                    'groupid' => (int)$team['groupId'],
                    'userid' => (int)$user->id,
                ])) {
                    return [
                        'groupid' => (int)$team['groupId'],
                        'teamname' => (string)$team['name'],
                        'teamkey' => (string)$team['key'],
                    ];
                }
            }
            foreach ($configuration['teams'] as $team) {
                if (($team['groupId'] ?? null) === null
                        && hash_equals(
                            (string)$team['key'],
                            'group-unassigned'
                        )) {
                    return [
                        'groupid' => null,
                        'teamname' => (string)$team['name'],
                        'teamkey' => (string)$team['key'],
                    ];
                }
            }
            throw new \coding_exception(
                'Moodle-group team settings have no catch-all team.'
            );
        }
        foreach ($configuration['teams'] as $team) {
            if (is_string($submittedkey)
                    && hash_equals((string)$team['key'], $submittedkey)) {
                return [
                    'groupid' => null,
                    'teamname' => (string)$team['name'],
                    'teamkey' => (string)$team['key'],
                ];
            }
        }
        throw new live_domain_exception(
            'team_required',
            'live:error:teamrequired'
        );
    }

    /**
     * Aggregate individual scores into team means and stable ranks.
     *
     * @param \stdClass[] $players Player rows.
     * @param array|null $configuration Persisted team configuration.
     * @return array
     */
    public static function ranking(
        array $players,
        ?array $configuration = null
    ): array {
        $teams = [];
        foreach ($players as $player) {
            $name = trim((string)($player->teamname ?? ''));
            if ($name === '') {
                continue;
            }
            $key = self::player_key($player, $configuration);
            if ($key === null) {
                continue;
            }
            if (!isset($teams[$key])) {
                $teams[$key] = [
                    'teamKey' => $key,
                    'teamName' => $name,
                    'scoreSum' => 0,
                    'memberCount' => 0,
                ];
            }
            $teams[$key]['scoreSum'] += (int)$player->score;
            $teams[$key]['memberCount']++;
        }
        $ranking = array_values(array_map(static function(array $team): array {
            $team['score'] = $team['memberCount'] > 0
                ? (int)round($team['scoreSum'] / $team['memberCount'])
                : 0;
            unset($team['scoreSum']);
            return $team;
        }, $teams));
        usort($ranking, static function(array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) {
                return $score;
            }
            return strnatcasecmp($left['teamName'], $right['teamName']);
        });
        foreach ($ranking as $index => &$team) {
            $team['rank'] = $index + 1;
        }
        unset($team);
        return $ranking;
    }

    /**
     * Find the public team key stored by player columns.
     *
     * @param \stdClass $player Player.
     * @param array|null $configuration Persisted team configuration.
     * @return string|null
     */
    public static function player_key(
        \stdClass $player,
        ?array $configuration = null
    ): ?string {
        if (!empty($player->groupid)) {
            return 'group-' . (int)$player->groupid;
        }
        $name = trim((string)($player->teamname ?? ''));
        if ($name === '') {
            return null;
        }
        if ($configuration !== null) {
            foreach ($configuration['teams'] ?? [] as $team) {
                if (($team['groupId'] ?? null) === null
                        && hash_equals(
                            self::name_key((string)($team['name'] ?? '')),
                            self::name_key($name)
                        )) {
                    return (string)$team['key'];
                }
            }
        }
        // Compatibility fallback for historical team rows whose settings
        // cannot be decoded after a restore.
        return 'free-' . self::name_key($name);
    }

    /**
     * Normalised key for duplicate comparison and free-team aggregation.
     *
     * @param string $name Team name.
     * @return string
     */
    private static function name_key(string $name): string {
        $key = \core_text::strtolower(trim($name));
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '-', $key);
        return trim(is_string($key) ? $key : '', '-');
    }
}
