<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical live-session settings.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\addon\registry as addon_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates and serialises mode, naming, team and safety settings once.
 */
final class session_settings {

    /** Live mode available without an addon. */
    public const BASE_MODES = ['classic'];

    /** Optional modes whose runtime remains available for historical sessions. */
    public const PREMIUM_MODES = ['accuracy', 'team', 'security'];

    /** @var string[] Stable persisted live-mode catalogue. */
    public const MODES = ['classic', 'accuracy', 'team', 'security'];

    /** @var string[] Supported participant-name policies. */
    public const NAME_MODES = ['real', 'custom', 'generated'];

    /** Current settings document schema. */
    private const SCHEMA_VERSION = 4;

    /** Maximum number of custom filter fragments. */
    private const MAX_BLOCKED_NAMES = 50;

    /** Built-in impersonation protection used with every custom name mode. */
    private const BUILTIN_BLOCKED_NAMES = [
        'admin',
        'administrator',
        'lehrkraft',
        'moderator',
        'quizgeist',
    ];

    /**
     * Return every persisted mode understood by the compatibility runtime.
     *
     * @param string|null $configuredmode Existing activity default retained
     *     for read-only play.
     * @return string[]
     */
    public static function known_modes(): array {
        return self::MODES;
    }

    /**
     * Return modes whose owning addon is installed and upgraded.
     *
     * @return string[]
     */
    public static function installed_modes(): array {
        $announced = [];
        foreach (addon_registry::live_modes() as $mode) {
            if (!in_array($mode, self::PREMIUM_MODES, true)) {
                throw new \coding_exception(
                    'A Quizgeist addon announced an unsupported live mode.'
                );
            }
            $announced[$mode] = true;
        }

        return array_values(array_filter(
            self::known_modes(),
            static fn(string $mode): bool =>
                in_array($mode, self::BASE_MODES, true)
                || isset($announced[$mode])
        ));
    }

    /**
     * Return modes offered when creating a new live session.
     *
     * @param string|null $configuredmode Existing activity default retained
     *     for read-only play.
     * @return string[]
     */
    public static function creatable_modes(?string $configuredmode = null): array {
        $modes = self::premium_creation_allowed()
            ? self::installed_modes()
            : self::BASE_MODES;
        // A persisted premium default belongs to the existing activity. The
        // contractual read-only state may prevent choosing a new premium mode,
        // but it must not prevent playing that already configured activity
        // while its owning code package remains installed.
        if (is_string($configuredmode)
                && in_array($configuredmode, self::PREMIUM_MODES, true)
                && in_array(
                    $configuredmode,
                    self::installed_modes(),
                    true
                )
                && !in_array($configuredmode, $modes, true)) {
            $modes[] = $configuredmode;
        }
        return $modes;
    }

    /**
     * Whether a new session may use this mode.
     *
     * @param string $mode Requested mode.
     * @return bool
     */
    public static function is_creatable(string $mode): bool {
        return in_array($mode, self::creatable_modes(), true);
    }

    /**
     * Reject a direct premium-mode request hidden by host bootstrap.
     *
     * @param string $mode Requested mode.
     * @param string|null $configuredmode Existing activity default retained
     *     for read-only play.
     * @return void
     */
    public static function assert_creatable(
        string $mode,
        ?string $configuredmode = null
    ): void {
        if ($mode === $configuredmode
                && in_array($mode, self::PREMIUM_MODES, true)
                && in_array($mode, self::installed_modes(), true)) {
            return;
        }
        if (self::is_creatable($mode)) {
            return;
        }
        $gate = '\\mod_quizgeist\\local\\licence\\feature_gate';
        if (in_array($mode, self::installed_modes(), true)
                && class_exists($gate)) {
            // Preserve the common feature_locked AJAX contract when the addon
            // exists but its creation entitlement is unavailable.
            $gate::require('modes');
        }
        throw new \invalid_parameter_exception(
            'This live mode is not available for a new session.'
        );
    }

    /**
     * Build one canonical settings document from a create-session request.
     *
     * @param string $mode Live mode.
     * @param string $namemode Name policy.
     * @param array $rawoptions Additional mode settings.
     * @param \context_module $context Activity context.
     * @param string|null $configuredmode Existing activity default retained
     *     for read-only play.
     * @return array
     */
    public static function create(
        string $mode,
        string $namemode,
        array $rawoptions,
        \context_module $context,
        ?string $configuredmode = null,
        ?\stdClass $quizgeist = null
    ): array {
        self::assert_creatable($mode, $configuredmode);
        if (!in_array($mode, self::MODES, true)
                || !in_array($namemode, self::NAME_MODES, true)) {
            throw new \invalid_parameter_exception('Live session settings are invalid.');
        }

        $team = null;
        if ($mode === 'team') {
            $team = team_service::configuration($rawoptions, $context);
        }

        $blockednames = self::BUILTIN_BLOCKED_NAMES;
        if ($mode === 'security') {
            $customblockednames = self::text_list(
                $rawoptions['blockedNames'] ?? [],
                self::MAX_BLOCKED_NAMES,
                80
            );
            $blockednames = array_values(array_unique(array_merge(
                self::BUILTIN_BLOCKED_NAMES,
                $customblockednames
            )));
        }

        // F1 Stressarm-Standard. The activity carries the works defaults; the
        // teacher may override them for this one session. None of these four
        // fields touches assert_creatable(): the stress-free standard is free
        // of charge and must never make the base depend on the modes addon.
        $defaults = self::activity_defaults($quizgeist);
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'nameMode' => $namemode,
            'team' => $team,
            'blockedNames' => $blockednames,
            // An empty string is "not submitted", not "invalid": the works
            // default of the activity then applies, never a global fallback.
            'pace' => scoring_context::normalise_pace(
                ($rawoptions['pace'] ?? '') !== ''
                    ? $rawoptions['pace']
                    : $defaults['pace']
            ),
            'leaderboard' => leaderboard_policy::normalise(
                ($rawoptions['leaderboard'] ?? '') !== ''
                    ? $rawoptions['leaderboard']
                    : $defaults['leaderboard']
            ),
            'timer' => self::flag(
                $rawoptions['timer'] ?? null,
                $defaults['timer']
            ),
            'sound' => self::flag(
                $rawoptions['sound'] ?? null,
                $defaults['sound']
            ),
        ];
    }

    /**
     * Read the stress-free works defaults from one activity record.
     *
     * @param \stdClass|null $quizgeist Activity record.
     * @return array{pace:string,leaderboard:string,timer:bool,sound:bool}
     */
    public static function activity_defaults(?\stdClass $quizgeist): array {
        return [
            'pace' => scoring_context::normalise_pace(
                $quizgeist->pacemode ?? null
            ),
            'leaderboard' => leaderboard_policy::normalise(
                $quizgeist->leaderboard ?? null
            ),
            'timer' => !isset($quizgeist->timervisible)
                || !empty($quizgeist->timervisible),
            'sound' => !isset($quizgeist->soundenabled)
                || !empty($quizgeist->soundenabled),
        ];
    }

    /**
     * Ask the central licence service only after the modes addon announced
     * at least one premium capability.
     *
     * @return bool
     */
    private static function premium_creation_allowed(): bool {
        if (!array_intersect(self::PREMIUM_MODES, self::installed_modes())) {
            return false;
        }
        $gate = '\\mod_quizgeist\\local\\licence\\feature_gate';
        return class_exists($gate) && $gate::can_create('modes');
    }

    /**
     * Encode a canonical settings document.
     *
     * @param array $settings Canonical settings.
     * @return string
     */
    public static function encode(array $settings): string {
        $canonical = self::normalise($settings);
        return json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Decode settings while retaining P3 schema-1 sessions.
     *
     * @param string|null $json Persisted document.
     * @return array
     */
    public static function decode(?string $json): array {
        try {
            $raw = json_decode((string)$json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \invalid_parameter_exception('Live session settings are invalid.');
        }
        if (!is_array($raw)) {
            throw new \invalid_parameter_exception('Live session settings are invalid.');
        }
        $schemaversion = (int)($raw['schemaVersion'] ?? 0);
        if ($schemaversion === 1) {
            $raw = [
                'schemaVersion' => self::SCHEMA_VERSION,
                'nameMode' => $raw['nameMode'] ?? null,
                'team' => null,
                'blockedNames' => [],
            ];
        } else if ($schemaversion === 2) {
            $raw['schemaVersion'] = self::SCHEMA_VERSION;
            $team = $raw['team'] ?? null;
            if (is_array($team)
                    && ($team['source'] ?? null) === 'groups'
                    && is_array($team['teams'] ?? null)
                    && array_is_list($team['teams'])) {
                $hascatchall = false;
                foreach ($team['teams'] as $entry) {
                    if (is_array($entry)
                            && ($entry['key'] ?? null) === 'group-unassigned') {
                        $hascatchall = true;
                        break;
                    }
                }
                if (!$hascatchall
                        && count($team['teams']) < team_service::MAX_TEAMS) {
                    $team['teams'][] = [
                        'key' => 'group-unassigned',
                        'name' => get_string(
                            'host:team:unassigned',
                            'mod_quizgeist'
                        ),
                        'groupId' => null,
                    ];
                }
                $raw['team'] = $team;
            }
        }
        if ($schemaversion >= 1 && $schemaversion <= 3) {
            // A session created before F1 played with the timed point axis,
            // the full leaderboard, a visible timer and audible sound. That is
            // exactly what it must keep on replay; the new stress-free works
            // defaults apply to newly created sessions only.
            $raw['schemaVersion'] = self::SCHEMA_VERSION;
            $raw['pace'] = scoring_context::PACE_TIMED;
            $raw['leaderboard'] = leaderboard_policy::FULL;
            $raw['timer'] = true;
            $raw['sound'] = true;
        }
        return self::normalise($raw);
    }

    /**
     * Reject a filtered custom name without leaking the configured list.
     *
     * @param array $settings Canonical settings.
     * @param string $displayname Already cleaned display name.
     * @return void
     */
    public static function assert_display_name(array $settings, string $displayname): void {
        if (($settings['nameMode'] ?? null) !== 'custom') {
            return;
        }
        $candidate = self::search_key($displayname);
        foreach ($settings['blockedNames'] as $blocked) {
            $fragment = self::search_key($blocked);
            if ($fragment !== '' && str_contains($candidate, $fragment)) {
                throw new live_domain_exception(
                    'name_filtered',
                    'live:error:namefiltered'
                );
            }
        }
    }

    /**
     * Ensure a safety-mode player is an active course enrollee.
     *
     * @param string $mode Session mode.
     * @param \context_module $context Activity context.
     * @param \stdClass $user Moodle user.
     * @return void
     */
    public static function assert_player_eligible(
        string $mode,
        \context_module $context,
        \stdClass $user
    ): void {
        if ($mode !== 'security') {
            return;
        }
        $coursecontext = $context->get_course_context();
        if (!is_enrolled($coursecontext, $user, '', true)) {
            throw new live_domain_exception(
                'enrolment_required',
                'live:error:enrolmentrequired',
                403
            );
        }
    }

    /**
     * Remap Moodle-group identifiers embedded in a restored settings document.
     *
     * Settings JSON is not covered by backup ID annotations. Missing source
     * groups are dropped and the remaining configuration must still contain
     * at least two teams; callers can then fail closed to classic mode.
     *
     * @param array $settings Canonical settings.
     * @param callable $mapper Receives an old group ID and returns a new ID.
     * @return array Remapped canonical settings.
     */
    public static function remap_group_ids(
        array $settings,
        callable $mapper
    ): array {
        $settings = self::normalise($settings);
        if (($settings['team']['source'] ?? null) !== 'groups') {
            return $settings;
        }
        $teams = [];
        $seen = [];
        foreach ($settings['team']['teams'] as $team) {
            if (($team['key'] ?? null) === 'group-unassigned'
                    && ($team['groupId'] ?? null) === null) {
                $teams[] = $team;
                continue;
            }
            $mappedid = (int)$mapper((int)$team['groupId']);
            if ($mappedid <= 0 || isset($seen[$mappedid])) {
                continue;
            }
            $seen[$mappedid] = true;
            $teams[] = [
                'key' => 'group-' . $mappedid,
                'name' => (string)$team['name'],
                'groupId' => $mappedid,
            ];
        }
        $settings['team']['teams'] = $teams;
        return self::normalise($settings);
    }

    /**
     * Validate a canonical or decoded settings document.
     *
     * @param array $raw Raw settings.
     * @return array
     */
    private static function normalise(array $raw): array {
        $namemode = $raw['nameMode'] ?? null;
        if ((int)($raw['schemaVersion'] ?? 0) !== self::SCHEMA_VERSION
                || !is_string($namemode)
                || !in_array($namemode, self::NAME_MODES, true)) {
            throw new \invalid_parameter_exception('Live session settings are invalid.');
        }
        $team = $raw['team'] ?? null;
        if ($team !== null) {
            $team = team_service::normalise_configuration($team);
        }
        $blockednames = self::text_list(
            $raw['blockedNames'] ?? [],
            self::MAX_BLOCKED_NAMES + count(self::BUILTIN_BLOCKED_NAMES),
            80
        );
        $blockednames = array_values(array_unique(array_merge(
            self::BUILTIN_BLOCKED_NAMES,
            $blockednames
        )));
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'nameMode' => $namemode,
            'team' => $team,
            'blockedNames' => $blockednames,
            'pace' => scoring_context::normalise_pace($raw['pace'] ?? null),
            'leaderboard' => leaderboard_policy::normalise(
                $raw['leaderboard'] ?? null
            ),
            'timer' => self::flag($raw['timer'] ?? null, true),
            'sound' => self::flag($raw['sound'] ?? null, true),
        ];
    }

    /**
     * Normalise one tri-state switch into a strict boolean.
     *
     * @param mixed $value Raw value.
     * @param bool $fallback Value used when nothing was submitted.
     * @return bool
     */
    private static function flag($value, bool $fallback): bool {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || (is_string($value) && $value !== '')) {
            return in_array((string)$value, ['1', 'true', 'on'], true);
        }
        return $fallback;
    }

    /**
     * Bound and clean one list of human-entered text fragments.
     *
     * @param mixed $raw Raw list.
     * @param int $maximum Maximum entries.
     * @param int $maxlength Maximum length per entry.
     * @return string[]
     */
    private static function text_list($raw, int $maximum, int $maxlength): array {
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > $maximum) {
            throw new \invalid_parameter_exception('Live text-list setting is invalid.');
        }
        $result = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                throw new \invalid_parameter_exception('Live text-list setting is invalid.');
            }
            $clean = trim(clean_param($value, PARAM_TEXT));
            $clean = \core_text::substr($clean, 0, $maxlength);
            if ($clean !== '') {
                $result[] = $clean;
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * Build a case- and punctuation-insensitive filter key.
     *
     * @param string $value Human text.
     * @return string
     */
    private static function search_key(string $value): string {
        $value = \core_text::strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        return is_string($value) ? $value : '';
    }
}
