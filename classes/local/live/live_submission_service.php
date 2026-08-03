<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Generic player submission pipeline.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates policy, stage, visit, idempotency and scoring once for every type.
 */
final class live_submission_service {

    /**
     * Insert one standard or repeatable player interaction.
     *
     * @return array Lean acknowledgement.
     */
    public static function submit(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $sessionid,
        int $questionid,
        string $questiontoken,
        array $rawanswer,
        ?string $submissionkey = null
    ): array {
        global $DB;

        self::assert_token($questiontoken);
        $submissionkey = self::submission_key($submissionkey);
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            // The session lock serialises answer/reveal/navigation and is also
            // the retry arbiter before the unique submission-key index.
            $session = session_repository::session(
                (int)$quizgeist->id,
                $sessionid,
                true
            );
            $player = session_repository::player(
                $sessionid,
                (int)$user->id,
                true
            );
            $state = state_projector::state($session);
            $snapshot = session_repository::current_session_question(
                $session,
                $state,
                true
            );
            if ((string)$session->status !== 'question'
                    || $snapshot === null
                    || (int)$snapshot->questionid !== $questionid
                    || !hash_equals((string)$snapshot->visit, $questiontoken)
                    || !hash_equals((string)$state['questionToken'], $questiontoken)) {
                throw new live_conflict_exception(
                    state_projector::player_state($session, $context, $player)
                );
            }
            $nowms = self::now_ms();
            if ($nowms < (int)$state['phaseStartedAtMs']) {
                throw new live_domain_exception(
                    'question_not_open',
                    'live:error:questionnotopen'
                );
            }
            if ((int)$state['phaseEndsAtMs'] > 0
                    && $nowms > (int)$state['phaseEndsAtMs']) {
                throw new live_domain_exception(
                    'answer_too_late',
                    'live:error:answertoolate'
                );
            }

            $questionrecord = session_repository::question(
                (int)$quizgeist->id,
                $questionid
            );
            $interaction = submission_pipeline::interaction(
                $questionrecord,
                isset($snapshot->stage) ? (string)$snapshot->stage : null,
                'player'
            );
            $stage = (string)$interaction['stage'];
            $kind = (string)$interaction['kind'];
            $definition = (array)$interaction['definition'];
            $answertype = (string)$definition['answerType'];
            $multiple = $definition['cardinality'] === 'multiple';
            if ($multiple && $submissionkey === null) {
                throw new \invalid_parameter_exception(
                    'submissionKey is required for repeatable interactions.'
                );
            }

            $existing = session_repository::submission(
                $sessionid,
                (int)$player->id,
                $questionid,
                $questiontoken,
                $answertype,
                $multiple ? $submissionkey : null
            );
            if ($existing === null) {
                self::assert_actor_limit(
                    $sessionid,
                    (int)$player->id,
                    $questionid,
                    $questiontoken,
                    $answertype,
                    (int)$definition['maxPerActor']
                );
                $referenceanswers = null;
                $referencetypes = $definition['referenceAnswerTypes'] ?? null;
                if (is_array($referencetypes) && $referencetypes) {
                    $referenceanswers = visit_ledger::submission_references(
                        $session,
                        $state,
                        $referencetypes,
                        true
                    );
                }
                $submission = new submission_context(
                    $stage,
                    $kind,
                    'player',
                    (int)$player->id,
                    $questiontoken,
                    $submissionkey,
                    null,
                    $referenceanswers
                );
                $evaluation = submission_pipeline::evaluate(
                    $questionrecord,
                    $rawanswer,
                    new scoring_context(
                        (string)$session->mode,
                        max(0, $nowms - (int)$state['phaseStartedAtMs']),
                        (int)$player->streak,
                        scoring_context::normalise_pace(
                            session_settings::decode(
                                $session->settingsjson ?? null
                            )['pace'] ?? null
                        )
                    ),
                    $submission
                );
                $resolvedvisit = (string)($snapshot->resolvedvisit ?? '');
                // F2: a reason is a think moment, never a score. It carries no
                // points and no correctness under any circumstance.
                $scoreeligible = $answertype === 'answer'
                    && ($resolvedvisit === ''
                        || hash_equals($resolvedvisit, $questiontoken));
                if ($answertype === 'reason' || $answertype === 'stage') {
                    $evaluation['iscorrect'] = null;
                }
                if (!$scoreeligible) {
                    $evaluation['points'] = 0;
                    $evaluation['streak'] = (int)$player->streak;
                }
                $payload = (array)$evaluation['answer'] + [
                    'questionToken' => $questiontoken,
                    'scoreBefore' => (int)$player->score,
                    'streakBefore' => (int)$player->streak,
                ];
                $answerid = (int)$DB->insert_record(
                    'quizgeist_answers',
                    submission_pipeline::ledger_record([
                    'sessionid' => $sessionid,
                    'playerid' => (int)$player->id,
                    'questionid' => $questionid,
                    'userid' => (int)$user->id,
                    'answertype' => $answertype,
                    'visit' => $questiontoken,
                    'submissionkey' => $multiple ? $submissionkey : null,
                    'answer' => $payload,
                    'iscorrect' => $evaluation['iscorrect'],
                    'points' => (int)$evaluation['points'],
                    'maxpoints' => 0,
                    'responsetime' => (int)$evaluation['responseTimeMs'],
                    'timecreated' => time(),
                ]));
                // F9: the strategy accepted the SHAPE of a spoken answer. Only
                // here is the acting user known, so only here can the clip be
                // proved to be theirs — and inside the same transaction, so a
                // foreign clip ID leaves no answer behind at all.
                if (isset($payload['clipId']) && is_int($payload['clipId'])) {
                    \mod_quizgeist\local\media\clip_binding::attach(
                        (int)$quizgeist->id,
                        (int)$payload['clipId'],
                        $answerid,
                        (int)$user->id,
                        $answertype === 'reason' ? 'reason' : 'answer'
                    );
                }
                $now = time();
                $DB->update_record('quizgeist_players', (object)[
                    'id' => (int)$player->id,
                    'score' => (int)$player->score + (int)$evaluation['points'],
                    'streak' => (int)$evaluation['streak'],
                    'lastseen' => $now,
                    'timemodified' => $now,
                ]);
                // U2: the repetition core observes every graded answer, in live
                // play exactly as in self-study. It is never feature-gated — an
                // expired licence may block a new repetition assignment, but it
                // must never falsify what a learner has already learnt. The call
                // is fault tolerant by contract: a scheduler failure can never
                // discard this already validated answer.
                \mod_quizgeist\local\schedule\schedule_service::observe(
                    (int)$quizgeist->id,
                    (int)$user->id,
                    $questionid,
                    (array)$interaction['question'],
                    $evaluation,
                    $now
                );
            }
            $stateversion = (int)$session->stateversion;
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        try {
            reward_service::grant_after_answer(
                (int)$quizgeist->id,
                (int)$user->id
            );
        } catch (\Throwable $ignored) {
            // Unlock persistence must never turn an already committed answer
            // into a client-visible failure. The next answer retries grants.
        }

        return [
            'accepted' => true,
            'stateVersion' => $stateversion,
            'hasAnswered' => true,
        ];
    }

    public static function submission_key(?string $key): ?string {
        if ($key === null || $key === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $key)) {
            throw new \invalid_parameter_exception('submissionKey is invalid.');
        }
        return $key;
    }

    private static function assert_token(string $token): void {
        if (!preg_match('/^[a-f0-9]{32}$/D', $token)) {
            throw new \invalid_parameter_exception('questionToken is invalid.');
        }
    }

    /**
     * Bound append-only multi interactions without relying on the client.
     */
    public static function assert_actor_limit(
        int $sessionid,
        ?int $playerid,
        int $questionid,
        string $visit,
        string $answertype,
        int $maximum
    ): void {
        global $DB;

        if ($maximum <= 0 || $DB->count_records('quizgeist_answers', [
            'sessionid' => $sessionid,
            'playerid' => $playerid,
            'questionid' => $questionid,
            'visit' => $visit,
            'answertype' => $answertype,
        ]) >= $maximum) {
            throw new live_domain_exception(
                'submission_limit',
                'live:error:submissionlimit'
            );
        }
    }

    private static function now_ms(): int {
        return (int)floor(microtime(true) * 1000);
    }
}
