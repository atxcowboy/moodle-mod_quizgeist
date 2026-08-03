<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Polling-based live session state machine.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\live\qtype\registry as question_type_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns live transitions while repositories, projections and visits stay split.
 */
final class session_service {

    /**
     * Highest number of repetition questions a live session may append (F3).
     *
     * A repetition block is a warm-up, not a second lesson. Ten questions are
     * roughly five minutes of class time and keep the frozen sequence readable
     * on the host stage.
     */
    public const MAX_REVIEW_BLOCK = 10;

    /**
     * Initial host data, optionally restoring one explicit session URL.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Host.
     * @param int|null $sessionid Explicit session ID.
     * @return array
     */
    public static function host_bootstrap(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        ?int $sessionid
    ): array {
        $state = null;
        if ($sessionid !== null) {
            $session = session_repository::session((int)$quizgeist->id, $sessionid);
            self::require_host($session, $context, (int)$user->id);
            $state = state_projector::host_state($session, $context);
        }
        $configuredmode = (string)($quizgeist->defaultmode ?? 'classic');
        $creatablemodes = session_settings::creatable_modes($configuredmode);
        return [
            'state' => $state,
            'readiness' => self::readiness((int)$quizgeist->id),
            'setup' => [
                'allowedModes' => $creatablemodes,
                'defaultMode' => in_array(
                    $configuredmode,
                    $creatablemodes,
                    true
                ) ? (string)$quizgeist->defaultmode : 'classic',
                'moodleGroups' => team_service::available_groups($context),
                'groupReadiness' => team_service::group_readiness($context),
                'security' => [
                    'enrolledOnly' => true,
                    'nameFilterEnabled' => true,
                ],
                // F1 Stressarm-Standard: die Werksvorgaben der Aktivität.
                // Kein Feature-Tor — die Basis darf hier an keinem Addon
                // hängen.
                'stressFree' => (function () use ($quizgeist): array {
                    $defaults = session_settings::activity_defaults($quizgeist);
                    return [
                        'pace' => $defaults['pace'],
                        'leaderboard' => $defaults['leaderboard'],
                        'timerVisible' => $defaults['timer'],
                        'soundEnabled' => $defaults['sound'],
                    ];
                })(),
            ],
        ];
    }

    /**
     * Create a lobby while locking and freezing active question versions.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Host.
     * @param string $mode Mode.
     * @param string $namemode Name policy.
     * @param array $rawoptions Team and safety settings.
     * @return array
     */
    public static function create_session(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        string $mode,
        string $namemode,
        array $rawoptions = [],
        int $reviewblock = 0
    ): array {
        return self::create_session_internal(
            $quizgeist,
            $context,
            $user,
            $mode,
            $namemode,
            $rawoptions,
            null,
            $reviewblock
        );
    }

    /**
     * Create a lobby from an explicit ordered subset of ready versions.
     *
     * This server-side API keeps the normal interactive lobby contract (all
     * active questions) unchanged while allowing trusted workflows to create
     * an independently reproducible frozen sequence.
     *
     * @internal Reserved for audited fixtures and migration acceptance flows.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Host.
     * @param string $mode Mode.
     * @param string $namemode Name policy.
     * @param int[] $questionids Ordered concrete active versions.
     * @param array $rawoptions Team and safety settings.
     * @return array
     */
    public static function create_session_for_questions(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        string $mode,
        string $namemode,
        array $questionids,
        array $rawoptions = []
    ): array {
        return self::create_session_internal(
            $quizgeist,
            $context,
            $user,
            $mode,
            $namemode,
            $rawoptions,
            self::normalise_selected_question_ids($questionids)
        );
    }

    /**
     * Shared locked lobby creation.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Host.
     * @param string $mode Mode.
     * @param string $namemode Name policy.
     * @param array $rawoptions Team and safety settings.
     * @param int[]|null $selectedquestionids Optional ordered active versions.
     * @param int $reviewblock Number of due repetition questions to append.
     * @return array
     */
    private static function create_session_internal(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        string $mode,
        string $namemode,
        array $rawoptions,
        ?array $selectedquestionids,
        int $reviewblock = 0
    ): array {
        global $DB;

        $reviewblock = max(0, min(self::MAX_REVIEW_BLOCK, $reviewblock));
        if ($reviewblock > 0) {
            // A repetition block is a new self-study creation, so it follows
            // the creation entitlement. Playing an existing session that
            // already contains one is never blocked.
            $gate = '\\mod_quizgeist\\local\\licence\\feature_gate';
            if (class_exists($gate)) {
                $gate::require('selfstudy');
            }
        }
        $settings = session_settings::create(
            $mode,
            $namemode,
            $rawoptions,
            $context,
            (string)($quizgeist->defaultmode ?? 'classic'),
            $quizgeist
        );
        $ambienttransaction = $DB->is_transaction_started();

        $contentlocks = \mod_quizgeist\local\editor\question_content_lock::
            acquire_for_activity((int)$quizgeist->id);
        try {
            $lastfailure = null;
            for ($attempt = 0; $attempt < 6; $attempt++) {
                try {
                    $sessionid = self::insert_lobby(
                        (int)$quizgeist->id,
                        (int)$user->id,
                        $mode,
                        $settings,
                        $selectedquestionids,
                        $reviewblock
                    );
                } catch (\dml_write_exception $exception) {
                    if ($ambienttransaction) {
                        // An ambient owner must decide whether its transaction
                        // can continue after a failed database statement.
                        throw $exception;
                    }
                    // A concurrent creator can win the join-code unique-index race.
                    $lastfailure = $exception;
                    continue;
                }
                $session = session_repository::session(
                    (int)$quizgeist->id,
                    $sessionid
                );
                return [
                    'state' => state_projector::host_state($session, $context),
                ];
            }
            throw $lastfailure ?? new \coding_exception('Could not allocate a join code.');
        } finally {
            \mod_quizgeist\local\editor\question_content_lock::release_all(
                $contentlocks
            );
        }
    }

    /**
     * Validate an optional explicit frozen-sequence selection.
     *
     * @param int[]|null $rawids Ordered concrete question IDs.
     * @return int[]|null
     */
    private static function normalise_selected_question_ids(
        ?array $rawids
    ): ?array {
        if ($rawids === null) {
            return null;
        }
        if (!array_is_list($rawids) || $rawids === []) {
            throw new \invalid_parameter_exception(
                'selectedquestionids must be a non-empty list.'
            );
        }
        $questionids = [];
        foreach ($rawids as $rawid) {
            $valid = is_int($rawid)
                || (
                    is_string($rawid)
                    && preg_match('/^[1-9][0-9]*$/D', $rawid) === 1
                );
            $questionid = $valid
                ? filter_var($rawid, FILTER_VALIDATE_INT)
                : false;
            if ($questionid === false
                    || (int)$questionid <= 0
                    || isset($questionids[(int)$questionid])) {
                throw new \invalid_parameter_exception(
                    'selectedquestionids contains invalid or duplicate IDs.'
                );
            }
            $questionids[(int)$questionid] = true;
        }
        return array_values(array_map('intval', array_keys($questionids)));
    }

    /**
     * Poll host state with a state-version shortcut.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Host.
     * @param int $sessionid Session.
     * @param int $knownversion Client version.
     * @param int $knownaggregaterevision Client aggregate cursor.
     * @return array
     */
    public static function host_poll(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $sessionid,
        int $knownversion,
        int $knownaggregaterevision = 0
    ): array {
        $session = session_repository::session((int)$quizgeist->id, $sessionid);
        self::require_host($session, $context, (int)$user->id);
        $nowms = self::now_ms();
        $playercount = session_repository::player_count($sessionid);
        $dynamic = state_projector::poll_fields($session, 'host');
        $result = [
            'changed' => (int)$session->stateversion !== $knownversion,
            'stateVersion' => (int)$session->stateversion,
            'serverTimeMs' => $nowms,
            'pollAfterMs' => self::poll_after(
                (string)$session->status,
                $playercount,
                false
            ),
        ];
        if ($result['changed']) {
            $result['state'] = state_projector::host_state(
                $session,
                $context,
                $nowms
            );
        } else {
            $result['answerCount'] = $dynamic['answerCount'];
            $result['aggregateRevision'] = $dynamic['aggregateRevision'];
            if ((int)$dynamic['aggregateRevision'] !== $knownaggregaterevision) {
                $result['aggregate'] = $dynamic['aggregate'];
                $result['distribution'] = $dynamic['distribution'];
                // F5: the traffic light belongs to the aggregate and travels
                // with it. The key exists here only because this is the HOST
                // poll — player_poll() never receives it from poll_fields().
                if (array_key_exists('hingeStatus', $dynamic)) {
                    $result['hingeStatus'] = $dynamic['hingeStatus'];
                }
            }
        }
        return $result;
    }

    /**
     * Apply one optimistic host transition.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Host.
     * @param int $sessionid Session.
     * @param string $command Command.
     * @param int $expectedversion Optimistic version.
     * @return array
     */
    public static function host_command(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $sessionid,
        string $command,
        int $expectedversion
    ): array {
        global $DB;

        if (!in_array($command, [
            'start', 'reveal', 'scoreboard', 'next',
            'previous', 'skip', 'abort', 'end',
        ], true)) {
            throw new \invalid_parameter_exception('Unknown live command.');
        }

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        $event = null;
        $grantpodium = false;
        $grantreveal = false;
        try {
            // The session row is always the first mutable live lock. Answers,
            // reveal and navigation therefore have one authoritative order.
            $session = session_repository::session(
                (int)$quizgeist->id,
                $sessionid,
                true
            );
            self::require_host($session, $context, (int)$user->id);
            if ((int)$session->stateversion !== $expectedversion) {
                throw new live_conflict_exception(
                    state_projector::host_state($session, $context)
                );
            }
            $state = state_projector::state($session);
            $phase = (string)$session->status;
            $nowms = self::now_ms();
            $currentid = session_state::current_question_id($state);
            $joincode = $session->joincode;
            $timeended = (int)$session->timeended;
            $timestarted = (int)$session->timestarted;

            if ($phase === 'question'
                    && in_array($command, ['previous', 'skip', 'abort'], true)) {
                visit_ledger::neutralise_unrevealed($session, $state);
            }

            if ($command === 'start') {
                self::require_phase($phase, ['lobby']);
                if (!session_repository::player_count($sessionid)) {
                    throw new live_domain_exception(
                        'no_players',
                        'live:error:noplayers'
                    );
                }
                [$state, $currentid] = self::enter_snapshot_question(
                    $session,
                    $state,
                    0,
                    $nowms
                );
                $phase = 'question';
                $timestarted = time();
                $event = 'started';
            } else if ($command === 'reveal') {
                self::require_phase($phase, ['question']);
                self::require_final_interaction_stage($session, $state);
                self::mark_current_visit_revealed($session, $state);
                $phase = 'reveal';
                $state = session_state::mark_revealed($state, $nowms);
                $grantreveal = true;
            } else if ($command === 'scoreboard') {
                self::require_phase($phase, ['reveal']);
                $phase = 'scoreboard';
                $state = session_state::mark_phase($state, $nowms);
            } else if ($command === 'next') {
                self::require_phase($phase, ['scoreboard']);
                $nextindex = (int)$state['currentIndex'] + 1;
                if ($nextindex >= count($state['questionIds'])) {
                    $phase = 'podium';
                    $state = session_state::mark_phase($state, $nowms);
                    $grantpodium = true;
                } else {
                    [$state, $currentid] = self::enter_snapshot_question(
                        $session,
                        $state,
                        $nextindex,
                        $nowms
                    );
                    $phase = 'question';
                }
            } else if ($command === 'previous') {
                self::require_phase($phase, ['question', 'reveal', 'scoreboard']);
                $previousindex = (int)$state['currentIndex'] - 1;
                if ($previousindex < 0) {
                    throw new \invalid_parameter_exception('There is no previous question.');
                }
                [$state, $currentid] = self::enter_snapshot_question(
                    $session,
                    $state,
                    $previousindex,
                    $nowms
                );
                $phase = 'question';
            } else if ($command === 'skip') {
                self::require_phase($phase, ['question']);
                $nextindex = (int)$state['currentIndex'] + 1;
                if ($nextindex >= count($state['questionIds'])) {
                    $phase = 'podium';
                    $state = session_state::mark_phase($state, $nowms);
                    $grantpodium = true;
                } else {
                    [$state, $currentid] = self::enter_snapshot_question(
                        $session,
                        $state,
                        $nextindex,
                        $nowms
                    );
                    $phase = 'question';
                }
            } else if ($command === 'abort') {
                self::require_phase(
                    $phase,
                    ['lobby', 'question', 'reveal', 'scoreboard', 'podium']
                );
                $phase = 'aborted';
                $state = session_state::mark_phase($state, $nowms);
                $joincode = null;
                $timeended = time();
                $event = 'ended';
            } else {
                self::require_phase($phase, ['podium']);
                $phase = 'ended';
                $state = session_state::mark_phase($state, $nowms);
                $joincode = null;
                $timeended = time();
                $event = 'ended';
            }

            $update = (object)[
                'id' => (int)$session->id,
                'status' => $phase,
                'currentquestionid' => $currentid,
                'stateversion' => (int)$session->stateversion + 1,
                'statejson' => session_state::encode($state),
                'joincode' => $joincode,
                'timestarted' => $timestarted,
                'timeended' => $timeended,
                'timemodified' => time(),
            ];
            $DB->update_record('quizgeist_sessions', $update);
            $session = (object)array_merge((array)$session, (array)$update);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        if ($grantreveal) {
            self::grant_reveal_rewards($session);
        }
        if ($grantpodium) {
            self::grant_podium_rewards($session);
        }
        if ($event !== null) {
            self::trigger_session_event($event, $session, $context);
        }
        return ['state' => state_projector::host_state($session, $context)];
    }

    /**
     * Initial player data for one previously joined session.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Player.
     * @param int|null $sessionid Session.
     * @return array
     */
    public static function player_bootstrap(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        ?int $sessionid
    ): array {
        if ($sessionid === null) {
            return ['state' => null];
        }
        try {
            $session = session_repository::session((int)$quizgeist->id, $sessionid);
            $player = session_repository::player(
                (int)$session->id,
                (int)$user->id
            );
        } catch (\dml_missing_record_exception $missing) {
            return ['state' => null];
        }
        return ['state' => state_projector::player_state(
            $session,
            $context,
            $player
        )];
    }

    /**
     * Resolve a six-digit code to a public lobby or authorised resume DTO.
     *
     * @param \stdClass $quizgeist Activity.
     * @param string $joincode Code.
     * @param \context_module|null $context Context.
     * @param \stdClass|null $user User.
     * @return array
     */
    public static function lookup_session(
        \stdClass $quizgeist,
        string $joincode,
        ?\context_module $context = null,
        ?\stdClass $user = null
    ): array {
        global $DB;

        if (!preg_match('/^[0-9]{6}$/D', $joincode)) {
            throw new \invalid_parameter_exception('joinCode must contain six digits.');
        }
        self::assert_lookup_rate((int)$quizgeist->id, (int)($user->id ?? 0));
        $session = $DB->get_record('quizgeist_sessions', [
            'quizgeistid' => (int)$quizgeist->id,
            'joincode' => $joincode,
            'status' => 'lobby',
        ], '*', IGNORE_MISSING);
        if ($session) {
            if ($context !== null && $user !== null) {
                session_settings::assert_player_eligible(
                    (string)$session->mode,
                    $context,
                    $user
                );
            }
            self::clear_lookup_rate((int)$quizgeist->id, (int)($user->id ?? 0));
            $publicstate = state_projector::public_lobby_state($session);
            if ($user !== null) {
                $publicstate['rewards'] = reward_service::catalogue((int)$user->id);
            }
            return ['state' => $publicstate];
        }

        if ($context !== null && $user !== null) {
            $running = $DB->get_record_select(
                'quizgeist_sessions',
                'quizgeistid = :quizgeistid
                     AND joincode = :joincode
                     AND status <> :ended
                     AND status <> :aborted',
                [
                    'quizgeistid' => (int)$quizgeist->id,
                    'joincode' => $joincode,
                    'ended' => 'ended',
                    'aborted' => 'aborted',
                ],
                '*',
                IGNORE_MISSING
            );
            if ($running) {
                $player = $DB->get_record('quizgeist_players', [
                    'sessionid' => (int)$running->id,
                    'userid' => (int)$user->id,
                ], '*', IGNORE_MISSING);
                if ($player) {
                    self::clear_lookup_rate((int)$quizgeist->id, (int)$user->id);
                    return [
                        'state' => state_projector::public_lobby_state($running),
                        'resume' => state_projector::player_state(
                            $running,
                            $context,
                            $player
                        ),
                    ];
                }
            }
        }

        self::record_lookup_failure((int)$quizgeist->id, (int)($user->id ?? 0));
        throw new \dml_missing_record_exception('quizgeist_sessions');
    }

    /**
     * Join or idempotently refresh a lobby player.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Player.
     * @param int $sessionid Session.
     * @param string $joincode Code.
     * @param string|null $displayname Name.
     * @param string|null $avatarkey Avatar.
     * @param string|null $accessorykey Accessory.
     * @param string|null $teamkey Free-team key.
     * @return array
     */
    public static function join_session(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $sessionid,
        string $joincode,
        ?string $displayname,
        ?string $avatarkey,
        ?string $accessorykey = null,
        ?string $teamkey = null
    ): array {
        global $DB;

        if (!preg_match('/^[0-9]{6}$/D', $joincode)) {
            throw new \invalid_parameter_exception('joinCode must contain six digits.');
        }
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $session = session_repository::session(
                (int)$quizgeist->id,
                $sessionid,
                true
            );
            if ((string)$session->status !== 'lobby') {
                throw new live_domain_exception(
                    'round_started',
                    'live:error:roundstarted'
                );
            }
            if (!is_string($session->joincode)
                    || !hash_equals($session->joincode, $joincode)) {
                throw new \invalid_parameter_exception('The live join code is invalid.');
            }
            $settings = state_projector::settings($session);
            session_settings::assert_player_eligible(
                (string)$session->mode,
                $context,
                $user
            );
            $displayname = self::display_name(
                $settings['nameMode'],
                $displayname,
                $sessionid,
                $user
            );
            session_settings::assert_display_name($settings, $displayname);
            $team = team_service::assignment(
                $settings['team'],
                $user,
                $teamkey
            );
            $avatarkey = reward_service::selection(
                (int)$user->id,
                $avatarkey,
                $accessorykey
            );
            $existing = $DB->get_record('quizgeist_players', [
                'sessionid' => $sessionid,
                'userid' => (int)$user->id,
            ]);
            $now = time();
            $visiblechanged = !$existing
                || (string)$existing->displayname !== $displayname
                || (string)$existing->avatarkey !== $avatarkey
                || (int)($existing->groupid ?? 0) !== (int)($team['groupid'] ?? 0)
                || (string)($existing->teamname ?? '') !== (string)($team['teamname'] ?? '')
                || (string)$existing->status !== 'joined';
            if ($existing && $visiblechanged
                    && $now - (int)$existing->timemodified < 5) {
                throw new live_conflict_exception(
                    state_projector::player_state($session, $context, $existing)
                );
            }
            if ($existing) {
                $update = (object)[
                    'id' => (int)$existing->id,
                    'displayname' => $displayname,
                    'groupid' => $team['groupid'],
                    'teamname' => $team['teamname'],
                    'avatarkey' => $avatarkey,
                    'status' => 'joined',
                    'lastseen' => $now,
                ];
                if ($visiblechanged) {
                    $update->timemodified = $now;
                }
                $DB->update_record('quizgeist_players', $update);
                $player = (object)array_merge((array)$existing, (array)$update);
            } else {
                $player = (object)[
                    'sessionid' => $sessionid,
                    'userid' => (int)$user->id,
                    'displayname' => $displayname,
                    'groupid' => $team['groupid'],
                    'teamname' => $team['teamname'],
                    'avatarkey' => $avatarkey,
                    'score' => 0,
                    'streak' => 0,
                    'status' => 'joined',
                    'timejoined' => $now,
                    'lastseen' => $now,
                    'timemodified' => $now,
                ];
                $player->id = (int)$DB->insert_record('quizgeist_players', $player);
            }
            if ($visiblechanged) {
                $update = (object)[
                    'id' => $sessionid,
                    'stateversion' => (int)$session->stateversion + 1,
                    'timemodified' => $now,
                ];
                $DB->update_record('quizgeist_sessions', $update);
                $session = (object)array_merge((array)$session, (array)$update);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return ['state' => state_projector::player_state(
            $session,
            $context,
            $player
        )];
    }

    /**
     * Poll one player's role-filtered state.
     *
     * The known token is a delivery cursor: during countdown the player has
     * already seen the session stateVersion but not the question. At opening,
     * a missing/different token forces exactly one full payload.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Player.
     * @param int $sessionid Session.
     * @param int $knownversion Client version.
     * @param string $knownquestiontoken Last delivered question token.
     * @param int $knownaggregaterevision Client aggregate cursor.
     * @return array
     */
    public static function player_poll(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $sessionid,
        int $knownversion,
        string $knownquestiontoken = '',
        int $knownaggregaterevision = 0
    ): array {
        $session = session_repository::session((int)$quizgeist->id, $sessionid);
        $player = session_repository::player($sessionid, (int)$user->id);
        $state = state_projector::state($session);
        $snapshot = session_repository::current_session_question($session, $state);
        $nowms = self::now_ms();
        $dynamic = state_projector::poll_fields(
            $session,
            'player',
            $player,
            $nowms
        );
        $questionavailable = (string)$session->status === 'question'
            && $nowms >= (int)$state['phaseStartedAtMs']
            && $snapshot !== null;
        $deliverychanged = $questionavailable
            && (
                $knownquestiontoken === ''
                || !hash_equals((string)$snapshot->visit, $knownquestiontoken)
            );
        $changed = (int)$session->stateversion !== $knownversion
            || $deliverychanged;
        $playercount = session_repository::player_count($sessionid);
        self::touch_lastseen($player);
        $result = [
            'changed' => $changed,
            'stateVersion' => (int)$session->stateversion,
            'serverTimeMs' => $nowms,
            'pollAfterMs' => self::poll_after(
                (string)$session->status,
                $playercount,
                $dynamic['hasAnswered']
            ),
            'hasAnswered' => $dynamic['hasAnswered'],
            'answerCount' => $dynamic['answerCount'],
            'aggregateRevision' => $dynamic['aggregateRevision'],
        ];
        if ($changed) {
            $result['state'] = state_projector::player_state(
                $session,
                $context,
                $player,
                $nowms
            );
        } else if ((int)$dynamic['aggregateRevision'] !== $knownaggregaterevision) {
            $result['aggregate'] = $dynamic['aggregate'];
            $result['distribution'] = $dynamic['distribution'];
        }
        return $result;
    }

    /**
     * Insert one idempotent response against an indexed visit.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Player.
     * @param int $sessionid Session.
     * @param int $questionid Concrete question ID.
     * @param string $questiontoken Visit token.
     * @param array $rawanswer Submitted type-specific answer.
     * @param string|null $submissionkey Retry idempotency key.
     * @return array Lean acknowledgement.
     */
    public static function submit_answer(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $sessionid,
        int $questionid,
        string $questiontoken,
        array $rawanswer,
        ?string $submissionkey = null
    ): array {
        return live_submission_service::submit(
            $quizgeist,
            $context,
            $user,
            $sessionid,
            $questionid,
            $questiontoken,
            $rawanswer,
            $submissionkey
        );
    }

    /**
     * Apply a qtype-policy-owned host interaction without branching on qtype.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Host.
     * @param int $sessionid Session.
     * @param int $questionid Question.
     * @param string $questiontoken Visit.
     * @param string $operation advance or submit.
     * @param int $expectedversion Optimistic version.
     * @param array $data Type-owned interaction data.
     * @param string|null $submissionkey Idempotency key.
     * @param string|null $interactionkind Explicit policy submission kind.
     * @return array
     */
    public static function host_interaction(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $sessionid,
        int $questionid,
        string $questiontoken,
        string $operation,
        int $expectedversion,
        array $data = [],
        ?string $submissionkey = null,
        ?string $interactionkind = null
    ): array {
        global $DB;

        if (!preg_match('/^[a-f0-9]{32}$/D', $questiontoken)
                || !in_array($operation, ['advance', 'submit'], true)) {
            throw new \invalid_parameter_exception('Live interaction is invalid.');
        }
        $submissionkey = live_submission_service::submission_key($submissionkey);
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $session = session_repository::session(
                (int)$quizgeist->id,
                $sessionid,
                true
            );
            self::require_host($session, $context, (int)$user->id);
            if ((int)$session->stateversion !== $expectedversion) {
                throw new live_conflict_exception(
                    state_projector::host_state($session, $context)
                );
            }
            $state = state_projector::state($session);
            $snapshot = session_repository::current_session_question(
                $session,
                $state,
                true
            );
            if ((string)$session->status !== 'question'
                    || $snapshot === null
                    || (int)$snapshot->questionid !== $questionid
                    || !hash_equals((string)$snapshot->visit, $questiontoken)) {
                throw new live_conflict_exception(
                    state_projector::host_state($session, $context)
                );
            }
            $questionrecord = session_repository::question(
                (int)$quizgeist->id,
                $questionid
            );
            $question = answer_evaluator::canonical_question($questionrecord);
            $strategy = question_type_registry::get((string)$question['qtype']);
            $policy = $strategy->policy($question);
            $stage = (string)($snapshot->stage ?? $policy->initial_stage());
            if (!$policy->supports_stage($stage)) {
                throw new \coding_exception('Persisted interaction stage is invalid.');
            }

            if ($operation === 'submit') {
                $kind = $interactionkind
                    ?? $policy->default_kind($stage, 'host');
                if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind)) {
                    throw new \invalid_parameter_exception(
                        'Live interaction kind is invalid.'
                    );
                }
                $definition = $policy->submission($stage, $kind, 'host');
                if ($submissionkey === null
                        || $definition['cardinality'] !== 'multiple') {
                    throw new \invalid_parameter_exception(
                        'submissionKey is required for host interactions.'
                    );
                }
                $answertype = (string)$definition['answerType'];
                $existing = session_repository::submission(
                    $sessionid,
                    null,
                    $questionid,
                    $questiontoken,
                    $answertype,
                    $submissionkey
                );
                if ($existing === null) {
                    live_submission_service::assert_actor_limit(
                        $sessionid,
                        null,
                        $questionid,
                        $questiontoken,
                        $answertype,
                        (int)$definition['maxPerActor']
                    );
                    $allowedreferenceids = null;
                    $referenceanswers = null;
                    $referencetypes = $definition['referenceAnswerTypes'] ?? null;
                    if (is_array($referencetypes) && $referencetypes) {
                        $referenceanswers = visit_ledger::submission_references(
                            $session,
                            $state,
                            $referencetypes,
                            true
                        );
                        $allowedreferenceids = array_values(array_map(
                            static fn(array $row): int => $row['id'],
                            $referenceanswers
                        ));
                    }
                    $canonical = $strategy->validate_answer(
                        $question,
                        $data,
                        new submission_context(
                            $stage,
                            $kind,
                            'host',
                            0,
                            $questiontoken,
                            $submissionkey,
                            $allowedreferenceids,
                            $referenceanswers
                        )
                    );
                    $DB->insert_record('quizgeist_answers', (object)[
                        'sessionid' => $sessionid,
                        'playerid' => null,
                        'attemptid' => null,
                        'questionid' => $questionid,
                        'userid' => (int)$user->id,
                        'answertype' => $answertype,
                        'visit' => $questiontoken,
                        'submissionkey' => $submissionkey,
                        'answerjson' => json_encode(
                            $canonical + ['questionToken' => $questiontoken],
                            JSON_UNESCAPED_SLASHES
                                | JSON_UNESCAPED_UNICODE
                                | JSON_THROW_ON_ERROR
                        ),
                        'iscorrect' => null,
                        'points' => 0,
                        'responsetime' => 0,
                        'timecreated' => time(),
                    ]);
                }
            } else {
                $required = $policy->required_submission($stage);
                $requirementtypes = $policy->requirement_reference_types($stage);
                $requirementreferences = $requirementtypes
                    ? visit_ledger::submission_references(
                        $session,
                        $state,
                        $requirementtypes,
                        true
                    )
                    : [];
                $requirementapplies = $policy->required_submission_applies(
                    $stage,
                    $requirementreferences
                );
                $requiredsubmission = $required !== null
                        && $requirementapplies
                    ? session_repository::submission(
                        $sessionid,
                        null,
                        $questionid,
                        $questiontoken,
                        $required,
                        null,
                        true
                    )
                    : null;
                if ($required !== null && $requirementapplies
                        && $requiredsubmission === null) {
                    throw new \invalid_parameter_exception(
                        'The current interaction stage is incomplete.'
                    );
                }
                if ($requiredsubmission !== null) {
                    $requireddefinition = $policy->submission(
                        $stage,
                        $required,
                        'host'
                    );
                    $requiredreferences = null;
                    $requiredreferencetypes =
                        $requireddefinition['referenceAnswerTypes'] ?? null;
                    if (is_array($requiredreferencetypes)
                            && $requiredreferencetypes) {
                        $requiredreferences =
                            visit_ledger::submission_references(
                                $session,
                                $state,
                                $requiredreferencetypes,
                                true
                            );
                    }
                    try {
                        $requiredpayload = json_decode(
                            (string)$requiredsubmission->answerjson,
                            true,
                            128,
                            JSON_THROW_ON_ERROR
                        );
                    } catch (\JsonException $exception) {
                        throw new \coding_exception(
                            'Persisted required interaction is invalid.',
                            $exception->getMessage()
                        );
                    }
                    if (!is_array($requiredpayload)
                            || array_is_list($requiredpayload)) {
                        throw new \coding_exception(
                            'Persisted required interaction is invalid.'
                        );
                    }
                    unset($requiredpayload['questionToken']);
                    $strategy->validate_answer(
                        $question,
                        $requiredpayload,
                        new submission_context(
                            $stage,
                            $required,
                            'host',
                            0,
                            $questiontoken,
                            (string)($requiredsubmission->submissionkey ?? ''),
                            $requiredreferences === null
                                ? null
                                : array_values(array_map(
                                    static fn(array $reference): int =>
                                        $reference['id'],
                                    $requiredreferences
                                )),
                            $requiredreferences
                        )
                    );
                }
                $nextstage = $policy->next_stage($stage);
                if ($nextstage === null) {
                    throw new \invalid_parameter_exception(
                        'There is no next interaction stage.'
                    );
                }
                $state = session_state::enter_interaction(
                    $state,
                    self::now_ms(),
                    $policy->duration_seconds($question, $nextstage)
                );
                $DB->update_record('quizgeist_session_questions', (object)[
                    'id' => (int)$snapshot->id,
                    'stage' => $nextstage,
                ]);
            }
            $update = (object)[
                'id' => (int)$session->id,
                'stateversion' => (int)$session->stateversion + 1,
                'statejson' => session_state::encode($state),
                'timemodified' => time(),
            ];
            $DB->update_record('quizgeist_sessions', $update);
            $session = (object)array_merge((array)$session, (array)$update);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return ['state' => state_projector::host_state($session, $context)];
    }

    /**
     * Insert a lobby and its relational frozen sequence atomically.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $hostuserid Host ID.
     * @param string $mode Mode.
     * @param array $settings Canonical settings.
     * @param int[]|null $selectedquestionids Optional ordered active versions.
     * @return int Session ID.
     */
    private static function insert_lobby(
        int $quizgeistid,
        int $hostuserid,
        string $mode,
        array $settings,
        ?array $selectedquestionids,
        int $reviewblock = 0
    ): int {
        global $DB;

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            if ($selectedquestionids === null) {
                $questions = $DB->get_records_sql(
                    'SELECT *
                       FROM {quizgeist_questions}
                      WHERE quizgeistid = :quizgeistid
                        AND status <> :archived
                   ORDER BY sortorder ASC, id ASC
                        FOR UPDATE',
                    ['quizgeistid' => $quizgeistid, 'archived' => 'archived']
                );
            } else {
                [$questionsql, $questionparams] = $DB->get_in_or_equal(
                    $selectedquestionids,
                    SQL_PARAMS_NAMED,
                    'livequestion'
                );
                $selected = $DB->get_records_sql(
                    "SELECT *
                       FROM {quizgeist_questions}
                      WHERE quizgeistid = :quizgeistid
                        AND status <> :archived
                        AND id {$questionsql}
                        FOR UPDATE",
                    [
                        'quizgeistid' => $quizgeistid,
                        'archived' => 'archived',
                    ] + $questionparams
                );
                $questions = [];
                foreach ($selectedquestionids as $questionid) {
                    if (!isset($selected[$questionid])) {
                        throw new live_domain_exception(
                            'no_playable_questions',
                            'live:error:noplayablequestions'
                        );
                    }
                    $questions[$questionid] = $selected[$questionid];
                }
            }
            if (!$questions) {
                throw new live_domain_exception(
                    'no_playable_questions',
                    'live:error:noplayablequestions'
                );
            }
            $supported = question_type_registry::types();
            foreach ($questions as $question) {
                if ((string)$question->status !== 'ready'
                        || !in_array((string)$question->qtype, $supported, true)) {
                    throw new live_domain_exception(
                        'no_playable_questions',
                        'live:error:noplayablequestions'
                    );
                }
            }
            $questionids = array_values(array_map('intval', array_keys($questions)));
            if ($reviewblock > 0) {
                // E-1, Variante (b): der Wiederholungs-Block wird hier, beim
                // Anlegen, an das Ende der Sequenz gehängt. Danach ist die
                // Sequenz unveränderlich wie eh und je.
                foreach (self::review_block_questions(
                    $quizgeistid,
                    $questionids,
                    $reviewblock
                ) as $reviewquestionid) {
                    $questions[$reviewquestionid] = $DB->get_record(
                        'quizgeist_questions',
                        ['id' => $reviewquestionid, 'quizgeistid' => $quizgeistid],
                        '*',
                        MUST_EXIST
                    );
                    $questionids[] = $reviewquestionid;
                }
            }
            $now = time();
            $record = (object)[
                'quizgeistid' => $quizgeistid,
                'hostuserid' => $hostuserid,
                'joincode' => self::join_code(),
                'status' => 'lobby',
                'mode' => $mode,
                'currentquestionid' => null,
                'stateversion' => 1,
                'statejson' => session_state::encode(
                    session_state::create($questionids, self::now_ms())
                ),
                'settingsjson' => session_settings::encode($settings),
                'timestarted' => 0,
                'timeended' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $sessionid = (int)$DB->insert_record('quizgeist_sessions', $record);
            foreach ($questionids as $sortindex => $questionid) {
                $canonical = answer_evaluator::canonical_question(
                    $questions[$questionid]
                );
                $stage = question_type_registry::get(
                    (string)$canonical['qtype']
                )->policy($canonical)->initial_stage();
                $DB->insert_record('quizgeist_session_questions', (object)[
                    'sessionid' => $sessionid,
                    'sortindex' => $sortindex,
                    'questionid' => $questionid,
                    'visit' => null,
                    'visitstate' => 'pending',
                    'resolvedvisit' => null,
                    'stage' => $stage,
                ]);
            }
            $transaction->allow_commit();
            return $sessionid;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Pick the repetition questions appended to a new live session.
     *
     * "Due" belongs to a learner, a live session belongs to a class. The block
     * therefore draws the roots that the largest number of learners of this
     * activity currently owe, and resolves each root to its current ready
     * version. Questions already contained in the sequence are skipped: a
     * repetition block must lengthen the lesson, not duplicate it.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[] $alreadyplanned Question versions already in the sequence.
     * @param int $limit Maximum number of appended questions.
     * @return int[] Ordered question version IDs.
     */
    private static function review_block_questions(
        int $quizgeistid,
        array $alreadyplanned,
        int $limit
    ): array {
        global $DB;

        $now = time();
        $rows = \mod_quizgeist\local\schedule\schedule_repository::class_due_roots(
            $quizgeistid,
            $now,
            // Ask for more roots than needed: some resolve to a version that is
            // already in the sequence or no longer ready.
            min(
                \mod_quizgeist\local\schedule\schedule_repository::MAX_DRAW,
                $limit * 4
            )
        );
        if (!$rows) {
            return [];
        }
        $versions = \mod_quizgeist\local\schedule\schedule_repository::current_versions(
            $quizgeistid,
            array_map(
                static fn(\stdClass $row): int => (int)$row->rootid,
                $rows
            )
        );
        $planned = array_fill_keys(array_map('intval', $alreadyplanned), true);
        $supported = question_type_registry::types();
        $selected = [];
        foreach ($rows as $row) {
            if (count($selected) >= $limit) {
                break;
            }
            $questionid = (int)($versions[(int)$row->rootid] ?? 0);
            if ($questionid <= 0 || isset($planned[$questionid])) {
                continue;
            }
            $record = $DB->get_record(
                'quizgeist_questions',
                ['id' => $questionid, 'quizgeistid' => $quizgeistid],
                'id, qtype, status',
                IGNORE_MISSING
            );
            if (!$record
                    || (string)$record->status !== 'ready'
                    || !in_array((string)$record->qtype, $supported, true)) {
                continue;
            }
            $planned[$questionid] = true;
            $selected[] = $questionid;
        }
        return $selected;
    }

    /**
     * Enter a frozen position and persist its fresh visit identity.
     *
     * @param \stdClass $session Locked session.
     * @param array $state State.
     * @param int $index Position.
     * @param int $nowms Clock.
     * @return array{0:array,1:int}
     */
    private static function enter_snapshot_question(
        \stdClass $session,
        array $state,
        int $index,
        int $nowms
    ): array {
        global $DB;

        if ($index < 0 || $index >= count($state['questionIds'])) {
            throw new \invalid_parameter_exception('Live question index is invalid.');
        }
        $snapshot = session_repository::session_question(
            (int)$session->id,
            $index,
            true
        );
        $questionid = (int)$state['questionIds'][$index];
        if ((int)$snapshot->questionid !== $questionid) {
            throw new \coding_exception('Frozen live question order is inconsistent.');
        }
        $question = session_repository::question(
            (int)$session->quizgeistid,
            $questionid
        );
        $canonical = answer_evaluator::canonical_question($question);
        $policy = question_type_registry::get(
            (string)$canonical['qtype']
        )->policy($canonical);
        $stage = $policy->initial_stage();
        $state = session_state::enter_question(
            $state,
            $index,
            $nowms,
            $policy->duration_seconds($canonical, $stage)
        );
        $DB->update_record('quizgeist_session_questions', (object)[
            'id' => (int)$snapshot->id,
            'visit' => (string)$state['questionToken'],
            'visitstate' => 'active',
            'stage' => $stage,
        ]);
        return [$state, $questionid];
    }

    /**
     * Prevent the standard reveal command from bypassing staged interactions.
     *
     * @param \stdClass $session Locked session.
     * @param array $state State.
     * @return void
     */
    private static function require_final_interaction_stage(
        \stdClass $session,
        array $state
    ): void {
        $snapshot = session_repository::current_session_question(
            $session,
            $state,
            true
        );
        if ($snapshot === null) {
            throw new \coding_exception('Cannot reveal a missing interaction.');
        }
        $questionrecord = session_repository::question(
            (int)$session->quizgeistid,
            (int)$snapshot->questionid
        );
        $question = answer_evaluator::canonical_question($questionrecord);
        $policy = question_type_registry::get(
            (string)$question['qtype']
        )->policy($question);
        $stage = (string)($snapshot->stage ?? $policy->initial_stage());
        if (!$policy->final_stage($stage)) {
            throw new \invalid_parameter_exception(
                'The current interaction has more stages.'
            );
        }
    }

    /**
     * Resolve the first scoring visit exactly once.
     *
     * @param \stdClass $session Locked session.
     * @param array $state State.
     * @return void
     */
    private static function mark_current_visit_revealed(
        \stdClass $session,
        array $state
    ): void {
        global $DB;

        $snapshot = session_repository::current_session_question(
            $session,
            $state,
            true
        );
        if ($snapshot === null) {
            throw new \coding_exception('Cannot reveal a lobby visit.');
        }
        $update = (object)[
            'id' => (int)$snapshot->id,
            'visitstate' => 'revealed',
        ];
        if ((string)($snapshot->resolvedvisit ?? '') === '') {
            $update->resolvedvisit = (string)$snapshot->visit;
        }
        $DB->update_record('quizgeist_session_questions', $update);
    }

    /**
     * Enforce host ownership, allowing activity managers to recover a session.
     *
     * @param \stdClass $session Session.
     * @param \context_module $context Context.
     * @param int $userid User.
     * @return void
     */
    private static function require_host(
        \stdClass $session,
        \context_module $context,
        int $userid
    ): void {
        if ((int)($session->hostuserid ?? 0) !== $userid
                && !has_capability('mod/quizgeist:manage', $context)) {
            throw new \required_capability_exception(
                $context,
                'mod/quizgeist:manage',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Require one of a command's legal source phases.
     *
     * @param string $phase Phase.
     * @param string[] $allowed Allowed phases.
     * @return void
     */
    private static function require_phase(string $phase, array $allowed): void {
        if (!in_array($phase, $allowed, true)) {
            throw new \invalid_parameter_exception(
                'Live command is invalid in this phase.'
            );
        }
    }

    /**
     * Build readiness information for the setup screen.
     *
     * @param int $quizgeistid Activity ID.
     * @return array
     */
    private static function readiness(int $quizgeistid): array {
        global $DB;

        $questions = $DB->get_records_select(
            'quizgeist_questions',
            'quizgeistid = :quizgeistid AND status <> :archived',
            ['quizgeistid' => $quizgeistid, 'archived' => 'archived'],
            'sortorder ASC, id ASC',
            'id,qtype,status'
        );
        $supported = question_type_registry::types();
        $playable = 0;
        foreach ($questions as $question) {
            if ((string)$question->status === 'ready'
                    && in_array((string)$question->qtype, $supported, true)) {
                $playable++;
            }
        }
        return [
            'ready' => $playable > 0 && $playable === count($questions),
            'questionCount' => count($questions),
            'playableQuestionCount' => $playable,
        ];
    }

    /**
     * Choose a display name under the server-owned session policy.
     *
     * @param string $namemode Policy.
     * @param string|null $submitted Submitted name.
     * @param int $sessionid Session.
     * @param \stdClass $user User.
     * @return string
     */
    private static function display_name(
        string $namemode,
        ?string $submitted,
        int $sessionid,
        \stdClass $user
    ): string {
        if ($namemode === 'real') {
            return fullname($user);
        }
        if ($namemode === 'custom') {
            $clean = trim(clean_param((string)$submitted, PARAM_TEXT));
            $clean = \core_text::substr($clean, 0, 80);
            if ($clean === '') {
                throw new \invalid_parameter_exception('A display name is required.');
            }
            return $clean;
        }
        $adjectives = ['Mutiger', 'Heller', 'Flotter', 'Leiser', 'Wacher', 'Bunter'];
        $nouns = ['Kiesel', 'Funke', 'Wirbel', 'Stern', 'Zweig', 'Klecks'];
        $seed = abs(crc32($sessionid . ':' . (int)$user->id));
        return $adjectives[$seed % count($adjectives)]
            . ' '
            . $nouns[intdiv($seed, count($adjectives)) % count($nouns)]
            . ' '
            . (1 + ($seed % 99));
    }

    /**
     * Grant the local podium accessory without risking the committed session.
     *
     * @param \stdClass $session Durable podium session.
     * @return void
     */
    private static function grant_podium_rewards(\stdClass $session): void {
        $players = session_repository::players((int)$session->id);
        usort($players, static function(\stdClass $left, \stdClass $right): int {
            $score = (int)$right->score <=> (int)$left->score;
            if ($score !== 0) {
                return $score;
            }
            $joined = (int)$left->timejoined <=> (int)$right->timejoined;
            return $joined !== 0
                ? $joined
                : ((int)$left->id <=> (int)$right->id);
        });
        try {
            reward_service::grant_podium_batch(
                (int)$session->quizgeistid,
                array_slice($players, 0, 3)
            );
        } catch (\Throwable $ignored) {
            // The podium transition is authoritative even if an optional
            // cosmetic unlock cannot be persisted right now.
        }
    }

    /**
     * Persist streak accessories only after correctness is public.
     *
     * @param \stdClass $session Durable reveal session.
     * @return void
     */
    private static function grant_reveal_rewards(\stdClass $session): void {
        try {
            reward_service::grant_after_reveal_batch(
                (int)$session->quizgeistid,
                session_repository::players((int)$session->id)
            );
        } catch (\Throwable $ignored) {
            // The reveal remains authoritative if a cosmetic grant fails.
        }
    }

    /**
     * Generate an unused six-digit join code.
     *
     * @return string
     */
    private static function join_code(): string {
        global $DB;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = (string)random_int(100000, 999999);
            if (!$DB->record_exists('quizgeist_sessions', ['joincode' => $code])) {
                return $code;
            }
        }
        throw new \dml_write_exception('Could not allocate a join code.');
    }

    /**
     * Fire lifecycle events after their state commit is durable.
     *
     * @param string $type started or ended.
     * @param \stdClass $session Session.
     * @param \context_module $context Context.
     * @return void
     */
    private static function trigger_session_event(
        string $type,
        \stdClass $session,
        \context_module $context
    ): void {
        $eventclass = $type === 'started'
            ? \mod_quizgeist\event\session_started::class
            : \mod_quizgeist\event\session_ended::class;
        $event = $eventclass::create([
            'objectid' => (int)$session->id,
            'context' => $context,
        ]);
        $event->add_record_snapshot('quizgeist_sessions', $session);
        $event->trigger();
    }

    /**
     * Adapt polling to phase, round size and whether this player is done.
     *
     * @param string $phase Phase.
     * @param int $playercount Player count.
     * @param bool $hasanswered Whether this player answered.
     * @return int Milliseconds.
     */
    private static function poll_after(
        string $phase,
        int $playercount,
        bool $hasanswered
    ): int {
        if (!in_array($phase, ['lobby', 'question'], true)) {
            return 2000;
        }
        $delay = 1100 + min(1900, max(0, $playercount) * 5);
        return $hasanswered ? max(2000, $delay) : $delay;
    }

    /**
     * Throttled last-seen update (at most once per 30 seconds).
     *
     * @param \stdClass $player Player.
     * @return void
     */
    private static function touch_lastseen(\stdClass $player): void {
        global $DB;

        $now = time();
        if ((int)$player->lastseen >= $now - 30) {
            return;
        }
        $DB->execute(
            'UPDATE {quizgeist_players}
                SET lastseen = :now
              WHERE id = :id
                AND lastseen < :cutoff',
            [
                'now' => $now,
                'id' => (int)$player->id,
                'cutoff' => $now - 30,
            ]
        );
    }

    /**
     * Reject a sixth failed lookup within one cache window.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return void
     */
    private static function assert_lookup_rate(int $quizgeistid, int $userid): void {
        if ($userid <= 0) {
            return;
        }
        $entry = \cache::make('mod_quizgeist', 'lookuprate')->get(
            self::lookup_rate_key($quizgeistid, $userid)
        );
        if (is_array($entry)
                && (int)($entry['started'] ?? 0) >= time() - 60
                && (int)($entry['failures'] ?? 0) >= 5) {
            throw new live_domain_exception(
                'rate_limited',
                'live:error:ratelimited',
                429
            );
        }
    }

    /**
     * Record one failed join-code lookup.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return void
     */
    private static function record_lookup_failure(int $quizgeistid, int $userid): void {
        if ($userid <= 0) {
            return;
        }
        $cache = \cache::make('mod_quizgeist', 'lookuprate');
        $key = self::lookup_rate_key($quizgeistid, $userid);
        $entry = $cache->get($key);
        if (!is_array($entry) || (int)($entry['started'] ?? 0) < time() - 60) {
            $entry = ['started' => time(), 'failures' => 0];
        }
        $entry['failures'] = (int)$entry['failures'] + 1;
        $cache->set($key, $entry);
    }

    /**
     * Reset failures after a successful lookup.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return void
     */
    private static function clear_lookup_rate(int $quizgeistid, int $userid): void {
        if ($userid > 0) {
            \cache::make('mod_quizgeist', 'lookuprate')->delete(
                self::lookup_rate_key($quizgeistid, $userid)
            );
        }
    }

    /**
     * Build a simple MUC key.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return string
     */
    private static function lookup_rate_key(int $quizgeistid, int $userid): string {
        return 'lookup_' . $quizgeistid . '_' . $userid;
    }

    /**
     * Current wall clock in integer milliseconds.
     *
     * @return int
     */
    private static function now_ms(): int {
        return (int)floor(microtime(true) * 1000);
    }
}
