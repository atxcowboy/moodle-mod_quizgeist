<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Funken avatar rewards.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Grants and validates locally rendered avatar accessories.
 */
final class reward_service {

    /** @var string[] Every locally rendered base avatar. */
    public const AVATARS = [
        'kiesel',
        'zweig',
        'federchen',
        'klecks',
        'kubus',
        'wirbel',
        'stern',
        'mondchen',
    ];

    /** @var array<string, array{rewardkey:string,labelkey:string}> */
    private const ACCESSORIES = [
        'funkenbadge' => [
            'rewardkey' => 'accessory:funkenbadge',
            'labelkey' => 'live:reward:funkenbadge',
        ],
        'flamme' => [
            'rewardkey' => 'accessory:flamme',
            'labelkey' => 'live:reward:flamme',
        ],
        'partyhut' => [
            'rewardkey' => 'accessory:partyhut',
            'labelkey' => 'live:reward:partyhut',
        ],
    ];

    /**
     * Return reward-aware avatar choices for one user.
     *
     * @param int $userid User.
     * @return array
     */
    public static function catalogue(int $userid): array {
        global $DB;

        $cache = \cache::make('mod_quizgeist', 'liveprojection');
        $cachekey = self::catalogue_cache_key($userid);
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            $unlocked = array_fill_keys($cached, true);
        } else {
            $records = $DB->get_records(
                'quizgeist_rewards',
                ['userid' => $userid],
                '',
                'id,rewardkey'
            );
            $unlocked = [];
            foreach ($records as $record) {
                $unlocked[(string)$record->rewardkey] = true;
            }
            // Cache only language-neutral keys. Labels are resolved below for
            // the current request language and can never bleed across users.
            $cache->set($cachekey, array_values(array_keys($unlocked)));
        }
        $accessories = [];
        foreach (self::ACCESSORIES as $key => $definition) {
            $accessories[] = [
                'key' => $key,
                'label' => get_string(
                    $definition['labelkey'],
                    'mod_quizgeist'
                ),
                'unlocked' => isset($unlocked[$definition['rewardkey']]),
            ];
        }
        return [
            'avatars' => self::AVATARS,
            'accessories' => $accessories,
            'unlockedRewardKeys' => array_values(array_keys($unlocked)),
        ];
    }

    /**
     * Invalidate cached unlock keys after external erasure/reset operations.
     *
     * @param int[] $userids Affected users.
     */
    public static function invalidate_catalogues(array $userids): void {
        $cache = \cache::make('mod_quizgeist', 'liveprojection');
        foreach (array_values(array_unique(array_map('intval', $userids))) as $userid) {
            if ($userid > 0) {
                $cache->delete(self::catalogue_cache_key($userid));
            }
        }
    }

    /**
     * Validate and encode one avatar/accessory selection for the player row.
     *
     * @param int $userid User.
     * @param string|null $avatarkey Avatar.
     * @param string|null $accessorykey Accessory.
     * @return string
     */
    public static function selection(
        int $userid,
        ?string $avatarkey,
        ?string $accessorykey
    ): string {
        global $DB;

        $avatar = in_array($avatarkey, self::AVATARS, true)
            ? (string)$avatarkey
            : 'kiesel';
        if ($accessorykey === null || $accessorykey === '') {
            return $avatar;
        }
        $definition = self::ACCESSORIES[$accessorykey] ?? null;
        if ($definition === null || !$DB->record_exists('quizgeist_rewards', [
            'userid' => $userid,
            'rewardkey' => $definition['rewardkey'],
        ])) {
            throw new live_domain_exception(
                'reward_locked',
                'live:error:rewardlocked'
            );
        }
        return $avatar . ':' . $accessorykey;
    }

    /**
     * Split the compact player-row selection.
     *
     * @param string|null $selection Stored value.
     * @return array{avatarKey:string,accessoryKey:?string}
     */
    public static function split(?string $selection): array {
        [$avatar, $accessory] = array_pad(
            explode(':', (string)$selection, 2),
            2,
            null
        );
        if (!in_array($avatar, self::AVATARS, true)) {
            $avatar = 'kiesel';
        }
        if (!is_string($accessory) || !isset(self::ACCESSORIES[$accessory])) {
            $accessory = null;
        }
        return ['avatarKey' => $avatar, 'accessoryKey' => $accessory];
    }

    /**
     * Grant correctness-neutral milestones after a durable answer commit.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return string[] Newly granted keys.
     */
    public static function grant_after_answer(
        int $quizgeistid,
        int $userid
    ): array {
        $granted = [];
        if (self::grant(
            $quizgeistid,
            $userid,
            'accessory:funkenbadge',
            'accessory',
            ['reason' => 'first_live_answer']
        )) {
            $granted[] = 'accessory:funkenbadge';
        }
        return $granted;
    }

    /**
     * Grant correctness-dependent milestones only after answers are revealed.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @param int $streak Revealed streak.
     * @return string[] Newly granted keys.
     */
    public static function grant_after_reveal(
        int $quizgeistid,
        int $userid,
        int $streak
    ): array {
        $granted = [];
        if ($streak >= 3 && self::grant(
            $quizgeistid,
            $userid,
            'accessory:flamme',
            'accessory',
            ['reason' => 'streak', 'threshold' => 3]
        )) {
            $granted[] = 'accessory:flamme';
        }
        return $granted;
    }

    /**
     * Grant the revealed-streak accessory without one lookup per player.
     *
     * @param int $quizgeistid Activity.
     * @param \stdClass[] $players Persisted live players.
     * @return int[] User IDs whose reward was newly granted.
     */
    public static function grant_after_reveal_batch(
        int $quizgeistid,
        array $players
    ): array {
        $userids = [];
        foreach ($players as $player) {
            if ((int)($player->streak ?? 0) >= 3) {
                $userids[] = (int)$player->userid;
            }
        }
        return self::grant_many(
            $quizgeistid,
            $userids,
            'accessory:flamme',
            'accessory',
            ['reason' => 'streak', 'threshold' => 3]
        );
    }

    /**
     * Grant a podium accessory after a durable session transition.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return bool Whether a row was newly created.
     */
    public static function grant_podium(int $quizgeistid, int $userid): bool {
        return self::grant(
            $quizgeistid,
            $userid,
            'accessory:partyhut',
            'accessory',
            ['reason' => 'podium']
        );
    }

    /**
     * Grant the podium accessory with one existing-reward lookup.
     *
     * @param int $quizgeistid Activity.
     * @param \stdClass[] $players Ordered podium players.
     * @return int[] User IDs whose reward was newly granted.
     */
    public static function grant_podium_batch(
        int $quizgeistid,
        array $players
    ): array {
        return self::grant_many(
            $quizgeistid,
            array_map(
                static fn(\stdClass $player): int => (int)$player->userid,
                $players
            ),
            'accessory:partyhut',
            'accessory',
            ['reason' => 'podium']
        );
    }

    /**
     * Insert one globally idempotent unlock.
     *
     * Calls are deliberately made after the live score transaction commits:
     * a concurrent unique-key race must never roll back an accepted answer.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @param string $rewardkey Stable reward.
     * @param string $rewardtype Type.
     * @param array $metadata Audit metadata.
     * @return bool Whether a row was inserted.
     */
    private static function grant(
        int $quizgeistid,
        int $userid,
        string $rewardkey,
        string $rewardtype,
        array $metadata
    ): bool {
        global $DB;

        if ($DB->record_exists('quizgeist_rewards', [
            'userid' => $userid,
            'rewardkey' => $rewardkey,
        ])) {
            return false;
        }
        return self::insert_grant(
            $quizgeistid,
            $userid,
            $rewardkey,
            $rewardtype,
            $metadata
        );
    }

    /**
     * Insert one reward after the caller has resolved existing rows.
     */
    private static function insert_grant(
        int $quizgeistid,
        int $userid,
        string $rewardkey,
        string $rewardtype,
        array $metadata
    ): bool {
        global $DB;

        $now = time();
        try {
            $DB->insert_record('quizgeist_rewards', (object)[
                'quizgeistid' => $quizgeistid,
                'userid' => $userid,
                'rewardkey' => $rewardkey,
                'rewardtype' => $rewardtype,
                'metadatajson' => json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                ),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            \cache::make('mod_quizgeist', 'liveprojection')->delete(
                self::catalogue_cache_key($userid)
            );
            return true;
        } catch (\dml_write_exception $exception) {
            // The unique (userid,rewardkey) index is the final race arbiter.
            if ($DB->record_exists('quizgeist_rewards', [
                'userid' => $userid,
                'rewardkey' => $rewardkey,
            ])) {
                \cache::make('mod_quizgeist', 'liveprojection')->delete(
                    self::catalogue_cache_key($userid)
                );
                return false;
            }
            throw $exception;
        }
    }

    /**
     * Resolve existing rewards in one query and insert only missing rows.
     *
     * The unique key remains the race arbiter; only an actual concurrent
     * collision takes the slower verification path in insert_grant().
     *
     * @param int $quizgeistid Activity.
     * @param int[] $userids Candidate users.
     * @param string $rewardkey Stable reward.
     * @param string $rewardtype Type.
     * @param array $metadata Audit metadata.
     * @return int[] Newly granted user IDs.
     */
    private static function grant_many(
        int $quizgeistid,
        array $userids,
        string $rewardkey,
        string $rewardtype,
        array $metadata
    ): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(
            array_map('intval', $userids),
            static fn(int $userid): bool => $userid > 0
        )));
        if (!$userids) {
            return [];
        }
        [$usersql, $params] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'rewardbatchuser'
        );
        $params['rewardbatchkey'] = $rewardkey;
        $existing = $DB->get_records_select(
            'quizgeist_rewards',
            "userid {$usersql} AND rewardkey = :rewardbatchkey",
            $params,
            '',
            'id,userid'
        );
        $existinguserids = [];
        foreach ($existing as $record) {
            $existinguserids[(int)$record->userid] = true;
        }

        $granted = [];
        foreach ($userids as $userid) {
            if (isset($existinguserids[$userid])) {
                continue;
            }
            if (self::insert_grant(
                $quizgeistid,
                $userid,
                $rewardkey,
                $rewardtype,
                $metadata
            )) {
                $granted[] = $userid;
            }
        }
        return $granted;
    }

    /**
     * Keep reward-unlock cache identities independent of session versions.
     */
    private static function catalogue_cache_key(int $userid): string {
        return 'reward_catalogue_' . $userid;
    }
}
