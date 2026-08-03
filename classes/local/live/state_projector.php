<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Role-safe live DTO projection.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\report\misconception_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds host/player state while caching only shared immutable projections.
 */
final class state_projector {

    /** Valid session phases. */
    private const PHASES = [
        'lobby',
        'question',
        'reveal',
        'scoreboard',
        'podium',
        'ended',
        'aborted',
    ];

    /**
     * Project one immutable historical occurrence for the reports read model.
     *
     * This deliberately reuses the same presenter, interaction policy and
     * type-owned aggregate as the live state. Report code must pass answer
     * rows already filtered to the authoritative occurrence and viewer scope.
     * A self-study report may combine several completed-attempt occurrences
     * of the same exact question version; in that case the caller supplies a
     * deterministic projection visit solely as the public-handle namespace.
     *
     * @param \stdClass $question Exact played question version.
     * @param \context_module $context Owning module context.
     * @param \stdClass[] $answers Persisted semantic/moderation rows for one
     *     exact question version and viewer scope.
     * @param string $visit Occurrence visit token.
     * @param int $index Zero-based source position.
     * @param int $total Number of source positions.
     * @param string|null $stage Persisted/final interaction stage.
     * @param bool $includecorrect Whether correctness may be disclosed.
     * @param string|null $role Host or player presentation role. Null retains
     *     the legacy role inferred from correctness disclosure.
     * @return array{question:array,aggregate:array,stage:string,visit:string}
     */
    public static function historical_question(
        \stdClass $question,
        \context_module $context,
        array $answers,
        string $visit,
        int $index,
        int $total,
        ?string $stage = null,
        bool $includecorrect = true,
        ?string $role = null
    ): array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $visit)) {
            throw new \invalid_parameter_exception(
                'Historical projection namespace is invalid.'
            );
        }
        $role ??= $includecorrect ? 'host' : 'player';
        if (!in_array($role, ['host', 'player'], true)) {
            throw new \invalid_parameter_exception(
                'Historical projection role is invalid.'
            );
        }
        $total = max(1, $total);
        $index = max(0, min($index, $total - 1));
        $answerfingerprints = [];
        foreach ($answers as $answer) {
            $answerfingerprints[] = [
                (int)($answer->id ?? 0),
                (int)($answer->playerid ?? 0),
                (string)($answer->answertype ?? ''),
                $answer->iscorrect ?? null,
                (int)($answer->points ?? 0),
                (int)($answer->maxpoints ?? 0),
                (int)($answer->responsetime ?? 0),
                (string)($answer->answerjson ?? ''),
                (int)($answer->timecreated ?? 0),
            ];
        }
        $fingerprint = hash('sha256', serialize([
            'context' => (int)$context->id,
            'question' => [
                (int)($question->id ?? 0),
                (int)($question->version ?? 0),
                (int)($question->timemodified ?? 0),
                (string)($question->qtype ?? ''),
                (string)($question->questiontext ?? ''),
                (string)($question->optionsjson ?? ''),
                (int)($question->timelimit ?? 0),
                (string)($question->pointmode ?? ''),
            ],
            'answers' => $answerfingerprints,
            'visit' => $visit,
            'index' => $index,
            'total' => $total,
            'stage' => $stage,
            'correct' => $includecorrect,
            'role' => $role,
            'language' => current_language(),
        ]));
        $cache = \cache::make('mod_quizgeist', 'liveprojection');
        $cachekey = 'historical_' . $fingerprint;
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }
        $interaction = submission_pipeline::interaction(
            $question,
            null,
            'player'
        );
        $policy = $interaction['policy'];
        if ($stage === null || !$policy->supports_stage($stage)) {
            $stages = $policy->descriptor(
                $policy->initial_stage(),
                'player'
            )['stages'];
            $last = end($stages);
            $stage = is_array($last)
                ? (string)$last['key']
                : $policy->initial_stage();
        }
        $presentation = question_presenter::present(
            $question,
            $context,
            [
                'questionToken' => $visit,
                'currentIndex' => $index,
                'questionIds' => array_fill(
                    0,
                    $total,
                    (int)$question->id
                ),
            ],
            $includecorrect,
            $role,
            'reveal',
            $stage
        );
        $result = [
            'question' => $presentation,
            'aggregate' => answer_aggregator::aggregate(
                $question,
                $answers,
                new aggregation_context(
                    $includecorrect,
                    $role,
                    $stage,
                    false,
                    $visit
                )
            ),
            'stage' => $stage,
            'visit' => $visit,
        ];
        $cache->set($cachekey, $result);
        return $result;
    }

    /**
     * Build a complete host DTO.
     *
     * @param \stdClass $session Session row.
     * @param \context_module $context Module context.
     * @param int|null $nowms Shared server clock.
     * @return array
     */
    public static function host_state(
        \stdClass $session,
        \context_module $context,
        ?int $nowms = null
    ): array {
        $nowms = $nowms ?? self::now_ms();
        $state = self::state($session);
        $settings = self::settings($session);
        $question = self::current_question($session, $state);
        $players = session_repository::players((int)$session->id);
        $revealanswers = self::answers_are_revealed($session, $state);
        if (!$revealanswers
                && (string)$session->status === 'question'
                && $question !== null) {
            $players = visit_ledger::mask_players($players, $session, $state);
        }

        $dto = self::shared_state(
            $session,
            $context,
            $state,
            $question,
            $revealanswers,
            'host',
            true
        );
        $dto['serverTimeMs'] = $nowms;
        $dto['joinCode'] = (string)($session->joincode ?? '');
        $dto['players'] = array_values(array_map(
            static fn(\stdClass $player): array => self::player_dto(
                $player,
                $settings['team']
            ),
            $players
        ));
        $dto['playerCount'] = count($players);
        $dto = array_replace(
            $dto,
            self::poll_fields($session, 'host')
        );

        $showranking = in_array(
            (string)$session->status,
            ['scoreboard', 'podium', 'ended', 'aborted'],
            true
        );
        $ranking = $showranking
            ? standings::ranking($session, $state, $players)
            : [];
        $dto['ranking'] = $ranking;
        $dto['podium'] = in_array(
            (string)$session->status,
            ['podium', 'ended', 'aborted'],
            true
        ) ? array_slice($ranking, 0, 3) : [];
        $teamranking = $showranking
            ? standings::team_ranking($session, $settings, $players)
            : [];
        $dto['teamRanking'] = $teamranking;
        $dto['teamPodium'] = in_array(
            (string)$session->status,
            ['podium', 'ended', 'aborted'],
            true
        ) ? array_slice($teamranking, 0, 3) : [];
        return $dto;
    }

    /**
     * Build a complete player DTO without hydrating all players in hot phases.
     *
     * @param \stdClass $session Session row.
     * @param \context_module $context Module context.
     * @param \stdClass $player Player row.
     * @param int|null $nowms Shared server clock.
     * @return array
     */
    public static function player_state(
        \stdClass $session,
        \context_module $context,
        \stdClass $player,
        ?int $nowms = null
    ): array {
        $nowms = $nowms ?? self::now_ms();
        $state = self::state($session);
        $settings = self::settings($session);
        $question = self::current_question($session, $state);
        $answer = $question === null
            ? null
            : visit_ledger::player_answer($session, $state, (int)$player->id);
        $revealanswers = self::answers_are_revealed($session, $state);
        $questionavailable = (string)$session->status !== 'question'
            || $nowms >= (int)$state['phaseStartedAtMs'];
        $displayplayer = !$revealanswers
                && (string)$session->status === 'question'
            ? visit_ledger::mask_player($player, $answer)
            : clone $player;

        $needsranking = in_array(
            (string)$session->status,
            ['reveal', 'scoreboard', 'podium', 'ended', 'aborted'],
            true
        );
        $ranking = $needsranking ? standings::ranking($session, $state) : [];
        $ownrank = null;
        foreach ($ranking as $standing) {
            if ((int)$standing['playerId'] === (int)$player->id) {
                $ownrank = (int)$standing['rank'];
                break;
            }
        }

        $dto = self::shared_state(
            $session,
            $context,
            $state,
            $question,
            $revealanswers,
            'player',
            $questionavailable
        );
        $dto['serverTimeMs'] = $nowms;
        $dto['player'] = self::player_dto(
            $displayplayer,
            $settings['team']
        ) + ['rank' => $ownrank];
        $dto['playerCount'] = session_repository::player_count((int)$session->id);
        $dto = array_replace(
            $dto,
            self::poll_fields($session, 'player', $player, $nowms)
        );
        $dto['feedback'] = $answer && $revealanswers
            ? standings::feedback(
                $answer,
                $question,
                (string)$state['questionToken']
            )
            : null;
        $dto['ownRank'] = $ownrank;
        $showranking = in_array(
            (string)$session->status,
            ['scoreboard', 'podium', 'ended', 'aborted'],
            true
        );
        // F1: the leaderboard is cut here, on the server. Hiding foreign ranks
        // in the client would still ship them. The learner's own rank stays
        // available in every setting, so progress remains visible.
        $visibility = leaderboard_policy::normalise($settings['leaderboard']);
        $ownteam = standings::player_team($player, $settings['team']);
        $dto['ranking'] = $showranking
            ? leaderboard_policy::player_ranking(
                $ranking,
                $visibility,
                (int)$player->id
            )
            : [];
        $dto['podium'] = in_array(
            (string)$session->status,
            ['podium', 'ended', 'aborted'],
            true
        )
            ? leaderboard_policy::player_podium(
                array_slice($ranking, 0, 3),
                $visibility,
                (int)$player->id
            )
            : [];
        $teamranking = $showranking
            ? leaderboard_policy::player_team_ranking(
                standings::team_ranking($session, $settings),
                $visibility,
                $ownteam['teamKey'] ?? null
            )
            : [];
        $dto['teamRanking'] = $teamranking;
        $dto['teamPodium'] = in_array(
            (string)$session->status,
            ['podium', 'ended', 'aborted'],
            true
        ) ? array_slice($teamranking, 0, 3) : [];
        $dto['rewards'] = reward_service::catalogue((int)$player->userid);
        return $dto;
    }

    /**
     * Dynamic visit fields used by full states and unchanged-version polls.
     *
     * @param \stdClass $session Session.
     * @param string $role host or player.
     * @param \stdClass|null $player Player for hasAnswered.
     * @param int|null $nowms Shared server clock.
     * @return array
     */
    public static function poll_fields(
        \stdClass $session,
        string $role,
        ?\stdClass $player = null,
        ?int $nowms = null
    ): array {
        if (!in_array($role, ['host', 'player'], true)) {
            throw new \invalid_parameter_exception('Live projection role is invalid.');
        }
        $state = self::state($session);
        $nowms = $nowms ?? self::now_ms();
        if ($role === 'player'
                && (string)$session->status === 'question'
                && $nowms < (int)$state['phaseStartedAtMs']) {
            // Live aggregates can encode the response type (for example scale
            // steps or reaction keys), so they are question payload too.
            return [
                'answerCount' => 0,
                'aggregateRevision' => 0,
                'aggregate' => [],
                'distribution' => [],
                'hasAnswered' => false,
            ];
        }
        $question = self::current_question($session, $state);
        if ($question === null) {
            return [
                'answerCount' => 0,
                'aggregateRevision' => 0,
                'aggregate' => [],
                'distribution' => [],
                'hasAnswered' => false,
            ];
        }
        [$stage, $policy] = self::interaction($session, $state, $question);
        $playertypes = $policy->player_answer_types($stage);
        $response = $player === null
            ? null
            : visit_ledger::player_response(
                $session,
                $state,
                (int)$player->id,
                $playertypes
            );
        $aggregate = self::aggregate_projection(
            $session,
            $state,
            $question,
            $role,
            $stage,
            $policy
        );
        $fields = [
            'answerCount' => visit_ledger::response_count(
                $session,
                $state,
                $playertypes
            ),
            'aggregateRevision' => $aggregate['revision'],
            'aggregate' => $aggregate['aggregate'],
            'distribution' => $aggregate['distribution'],
            'hasAnswered' => $response !== null,
        ];
        // F5 Fehlkonzept-Radar. Die Ampel ist eine Lehrkraft-Auskunft: sie
        // sagt, wie gross der richtige Anteil ist, und verraet damit die
        // Loesung. Der Schluessel fehlt in der Spielerantwort deshalb
        // vollstaendig — nicht auf null gesetzt, abwesend.
        if (array_key_exists('hingeStatus', $aggregate)) {
            $fields['hingeStatus'] = $aggregate['hingeStatus'];
        }
        return $fields;
    }

    /**
     * Public pre-join lobby DTO without participant identities.
     *
     * @param \stdClass $session Session row.
     * @return array
     */
    public static function public_lobby_state(\stdClass $session): array {
        $state = self::state($session);
        $dto = self::base_state($session, $state);
        $dto['serverTimeMs'] = self::now_ms();
        $dto['playerCount'] = session_repository::player_count((int)$session->id);
        $dto['question'] = null;
        return $dto;
    }

    /**
     * Expose canonical state to the state machine without re-decoding it later.
     *
     * @param \stdClass $session Session row.
     * @return array
     */
    public static function state(\stdClass $session): array {
        if (!in_array((string)$session->status, self::PHASES, true)) {
            throw new \invalid_parameter_exception('Live session phase is invalid.');
        }
        return session_repository::state_snapshot($session)['state'];
    }

    /**
     * Decode canonical PII-free settings.
     *
     * @param \stdClass $session Session row.
     * @return array{schemaVersion:int,nameMode:string,team:?array,blockedNames:array}
     */
    public static function settings(\stdClass $session): array {
        return session_settings::decode($session->settingsjson ?? null);
    }

    /**
     * Resolve the exact current question through the relational snapshot.
     *
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @return \stdClass|null
     */
    private static function current_question(
        \stdClass $session,
        array $state
    ): ?\stdClass {
        $snapshot = session_repository::current_session_question($session, $state);
        return $snapshot === null
            ? null
            : session_repository::question(
                (int)$session->quizgeistid,
                (int)$snapshot->questionid
            );
    }

    /**
     * Build/cache fields identical for every client with the same role/version.
     *
     * @param \stdClass $session Session.
     * @param \context_module $context Context.
     * @param array $state State.
     * @param \stdClass|null $question Question.
     * @param bool $includecorrect Include correctness.
     * @param string $role host or player.
     * @param bool $questionavailable Whether player question content is unlocked.
     * @return array
     */
    private static function shared_state(
        \stdClass $session,
        \context_module $context,
        array $state,
        ?\stdClass $question,
        bool $includecorrect,
        string $role,
        bool $questionavailable
    ): array {
        $cache = \cache::make('mod_quizgeist', 'liveprojection');
        $language = preg_replace('/[^a-z0-9_]/', '_', strtolower(current_language()));
        $availability = $questionavailable ? 'open' : 'locked';
        $correctness = $includecorrect ? 'correct' : 'hidden';
        [$stage] = $question === null
            ? ['answer', null]
            : self::interaction($session, $state, $question);
        $key = implode('_', [
            'shared',
            (int)$session->id,
            (int)$session->stateversion,
            $role,
            $availability,
            $correctness,
            $stage,
            $language,
        ]);
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $dto = self::base_state($session, $state);
        if ($role === 'player' && !$questionavailable) {
            // Stages such as "collect" or "react" identify a question type
            // even when the question DTO itself is withheld. The duration can
            // likewise distinguish manual slides and staged interactions.
            $dto['interactionStage'] = null;
            $dto['phaseEndsAtMs'] = 0;
        }
        $dto['question'] = $question !== null && $questionavailable
            ? question_presenter::present(
                $question,
                $context,
                $state,
                $includecorrect,
                $role,
                (string)$session->status,
                $stage,
                explanation_policy::NEVER,
                self::friendly_new($session, $question)
            )
            : null;
        $cache->set($key, $dto);
        return $dto;
    }

    /**
     * Whether this question uses the error-friendly framing of F1.
     *
     * The reserved system tag lives on the question ROOT, so the answer
     * survives every edit of the question. The activity switch can turn the
     * whole framing off.
     *
     * @param \stdClass $session Session row.
     * @param \stdClass $question Exact played question row.
     * @return bool
     */
    private static function friendly_new(
        \stdClass $session,
        \stdClass $question
    ): bool {
        global $DB;

        // No request-local memo here: shared_state() is already cached per
        // state version, and a stale flag would outlive a setting change.
        $activity = $DB->get_record(
            'quizgeist',
            ['id' => (int)$session->quizgeistid],
            'id, friendlynew'
        );
        if ($activity === false) {
            return false;
        }
        $rootid = (int)($question->rootid ?? 0) > 0
            ? (int)$question->rootid
            : (int)$question->id;
        return \mod_quizgeist\local\tagging\tag_service::is_friendly_new(
            $activity,
            $rootid
        );
    }

    /**
     * Shared static state fields (serverTimeMs is deliberately excluded).
     *
     * @param \stdClass $session Session row.
     * @param array $state State.
     * @return array
     */
    private static function base_state(\stdClass $session, array $state): array {
        $settings = self::settings($session);
        $snapshot = session_repository::current_session_question($session, $state);
        $teamconfig = self::public_team_configuration($settings['team']);
        return [
            'phase' => (string)$session->status,
            'sessionId' => (int)$session->id,
            'stateVersion' => (int)$session->stateversion,
            'phaseStartedAtMs' => (int)$state['phaseStartedAtMs'],
            'phaseEndsAtMs' => (int)$state['phaseEndsAtMs'],
            'currentIndex' => (int)$state['currentIndex'],
            'totalQuestions' => count($state['questionIds']),
            'mode' => (string)$session->mode,
            'nameMode' => $settings['nameMode'],
            'teamConfig' => $teamconfig,
            'teams' => $teamconfig['teams'] ?? [],
            // F1 Stressarm-Standard. The point axis is orthogonal to the play
            // mode and belongs to the free base package. The client renders
            // only what the server has already decided.
            'pace' => (string)$settings['pace'],
            'leaderboard' => (string)$settings['leaderboard'],
            'timerVisible' => (bool)$settings['timer'],
            'soundEnabled' => (bool)$settings['sound'],
            'interactionStage' => $snapshot === null
                ? null
                : (string)($snapshot->stage ?? 'answer'),
        ];
    }

    /**
     * Build/cache a visit-revision aggregate independently of stateVersion.
     *
     * @param \stdClass $session Session.
     * @param array $state State.
     * @param \stdClass $question Exact question.
     * @param string $role host or player.
     * @param string $stage Interaction stage.
     * @param interaction_policy $policy Type policy.
     * @return array{revision:int,aggregate:array,distribution:array}
     */
    private static function aggregate_projection(
        \stdClass $session,
        array $state,
        \stdClass $question,
        string $role,
        string $stage,
        interaction_policy $policy
    ): array {
        $types = $policy->answer_types();
        $revision = visit_ledger::answer_revision($session, $state, $types);
        $revealed = self::answers_are_revealed($session, $state);
        if (!$policy->aggregate_visible($role, $stage, $revealed)) {
            return [
                'revision' => $revision,
                'aggregate' => [],
                'distribution' => [],
            ];
        }
        $cache = \cache::make('mod_quizgeist', 'liveprojection');
        $snapshot = session_repository::current_session_question($session, $state);
        // F5: only the host projection carries labels and traffic light, so
        // only the host key needs to notice a changed label. Computing the
        // marker for players would cost a query for data they never see.
        $rootid = (int)($question->rootid ?? 0) > 0
            ? (int)$question->rootid
            : (int)$question->id;
        $radar = $role === 'host'
            && \mod_quizgeist\local\licence\feature_gate::allows(
                'reports',
                \mod_quizgeist\local\licence\feature_gate::VIEW_EXISTING
            );
        $misconceptionsignature = $radar
            ? misconception_repository::signature(
                (int)$session->quizgeistid,
                $rootid
            )
            : '';
        $key = implode('_', [
            // Bump when a qtype aggregate DTO contract changes.
            'aggregate_v4',
            (int)$session->id,
            (int)$question->id,
            (string)($snapshot->visit ?? ''),
            $revision,
            $role,
            $stage,
            $revealed ? 'revealed' : 'unrevealed',
            $misconceptionsignature,
        ]);
        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $aggregate = answer_aggregator::aggregate(
            $question,
            visit_ledger::answers($session, $state, $types),
            new aggregation_context(
                $revealed,
                $role,
                $stage,
                !$revealed,
                (string)($snapshot->visit ?? '')
            )
        );
        $result = [
            'revision' => $revision,
        ];
        if ($radar) {
            // The enrichment happens before the distribution is split off, so
            // both host views describe the same rows. A player never reaches
            // this branch, which is why the payload test can be a payload test
            // and not a promise.
            [$aggregate, $hingestatus] = self::misconception_projection(
                $question,
                $aggregate,
                (int)$session->quizgeistid,
                $rootid
            );
            $result['hingeStatus'] = $hingestatus;
        }
        $result['aggregate'] = $aggregate;
        $result['distribution'] = self::choice_distribution($aggregate)
            ? $aggregate
            : [];
        $cache->set($key, $result);
        return $result;
    }

    /**
     * Attach misconception labels and the hinge light to a host aggregate.
     *
     * Live choice IDs are opaque per visit, so a label cannot be matched by
     * ID. Position is the bridge: the choice aggregate iterates the very same
     * option list that misconception_repository::answer_keys() reads.
     *
     * @param \stdClass $question Exact played question row.
     * @param array $aggregate Type-specific aggregate.
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @return array{0:array,1:?string} Enriched aggregate and traffic light.
     */
    private static function misconception_projection(
        \stdClass $question,
        array $aggregate,
        int $quizgeistid,
        int $rootid
    ): array {
        if (!self::choice_distribution($aggregate)) {
            return [$aggregate, null];
        }
        $canonical = answer_evaluator::canonical_question($question);
        $answerkeys = misconception_repository::answer_keys($canonical);
        if (count($answerkeys) !== count($aggregate)) {
            // A shape we cannot map is left alone rather than mislabelled.
            return [$aggregate, null];
        }
        $labels = misconception_repository::labels_for_root($quizgeistid, $rootid);
        $correctkeys = misconception_repository::correct_keys($canonical);
        $correct = 0;
        $sample = 0;
        foreach ($aggregate as $index => $entry) {
            $answerkey = $answerkeys[$index];
            $count = (int)($entry['count'] ?? 0);
            $sample += $count;
            if (in_array($answerkey, $correctkeys, true)) {
                $correct += $count;
            }
            $aggregate[$index]['misconceptionLabel'] = isset($labels[$answerkey])
                ? (string)$labels[$answerkey]->label
                : null;
        }
        if (!$correctkeys) {
            return [$aggregate, null];
        }
        return [
            $aggregate,
            misconception_repository::hinge_status(
                $correct,
                $sample,
                misconception_repository::question_threshold($canonical)
            ),
        ];
    }

    /**
     * Resolve a validated current stage and policy.
     *
     * @return array{0:string,1:interaction_policy}
     */
    private static function interaction(
        \stdClass $session,
        array $state,
        \stdClass $question
    ): array {
        $snapshot = session_repository::current_session_question($session, $state);
        if ($snapshot === null) {
            throw new \coding_exception('Current live interaction has no snapshot.');
        }
        $canonical = answer_evaluator::canonical_question($question);
        $policy = \mod_quizgeist\local\live\qtype\registry::get(
            (string)$canonical['qtype']
        )->policy($canonical);
        $stage = (string)($snapshot->stage ?? $policy->initial_stage());
        if (!$policy->supports_stage($stage)) {
            throw new \coding_exception('Persisted live interaction stage is invalid.');
        }
        return [$stage, $policy];
    }

    /**
     * Detect the preserved P3 choice distribution shape.
     */
    private static function choice_distribution(array $aggregate): bool {
        if (!array_is_list($aggregate) || !$aggregate) {
            return false;
        }
        foreach ($aggregate as $row) {
            if (!is_array($row) || !isset($row['choiceId'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Basic player DTO.
     *
     * @param \stdClass $player Player row.
     * @return array
     */
    private static function player_dto(
        \stdClass $player,
        ?array $teamconfiguration = null
    ): array {
        $selection = reward_service::split($player->avatarkey ?? null);
        $team = standings::player_team($player, $teamconfiguration);
        return [
            'id' => (int)$player->id,
            'displayName' => (string)$player->displayname,
            'avatarKey' => $selection['avatarKey'],
            'accessoryKey' => $selection['accessoryKey'],
            'score' => (int)$player->score,
            'streak' => (int)$player->streak,
            'status' => (string)$player->status,
            'team' => $team,
            'teamId' => $team['teamKey'] ?? null,
            'teamName' => $team['teamName'] ?? null,
        ];
    }

    /**
     * Reduce persisted team settings to the public join contract.
     *
     * @param array|null $configuration Persisted team configuration.
     * @return array|null
     */
    private static function public_team_configuration(
        ?array $configuration
    ): ?array {
        if ($configuration === null) {
            return null;
        }
        return [
            'source' => (string)$configuration['source'],
            'teams' => array_values(array_map(
                static fn(array $team): array => [
                    'id' => (string)$team['key'],
                    'key' => (string)$team['key'],
                    'name' => (string)$team['name'],
                ],
                $configuration['teams']
            )),
        ];
    }

    /**
     * Whether the exact current visit reached reveal.
     *
     * @param \stdClass $session Session.
     * @param array $state State.
     * @return bool
     */
    private static function answers_are_revealed(
        \stdClass $session,
        array $state
    ): bool {
        return in_array(
            (string)$session->status,
            ['reveal', 'scoreboard', 'podium', 'ended'],
            true
        ) && session_state::current_question_was_revealed($state);
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
