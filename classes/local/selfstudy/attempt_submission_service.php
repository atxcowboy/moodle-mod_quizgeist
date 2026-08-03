<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Self-study submission pipeline and terminalisation.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

use mod_quizgeist\local\live\live_conflict_exception;
use mod_quizgeist\local\live\points_formula;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\session_repository;
use mod_quizgeist\local\live\submission_context;
use mod_quizgeist\local\live\submission_pipeline;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns the one self-study answer ledger and all terminalisation paths.
 */
final class attempt_submission_service {

    /** In-progress attempts without a deadline are terminal after this idle age. */
    public const STALE_AFTER = 7 * DAYSECS;

    /**
     * Validate and persist one response against its frozen question version.
     */
    public static function submit(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid,
        int $questionid,
        string $visit,
        array $rawanswer,
        int $expectedversion
    ): array {
        global $DB;

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $attempt = self::locked_attempt(
                (int)$quizgeist->id,
                (int)$user->id,
                $attemptid
            );
            self::require_answering_allowed($attempt);
            self::require_version(
                $attempt,
                $expectedversion,
                (int)$quizgeist->id,
                (int)$user->id,
                $context
            );
            if ((string)$attempt->mode === 'flashcards') {
                throw new \invalid_parameter_exception(
                    'Use the flashcard actions for this attempt.'
                );
            }
            $attemptquestion = self::locked_attempt_question(
                $attemptid,
                $questionid
            );
            self::require_current_question($attempt, $attemptquestion);
            self::require_visit($attemptquestion, $visit);
            if ((string)$attempt->mode !== 'test'
                    && (string)$attemptquestion->status === 'submitted') {
                throw new live_conflict_exception(
                    attempt_projector::state(
                        (int)$quizgeist->id,
                        (int)$user->id,
                        $attemptid,
                        $context
                    )
                );
            }

            $question = session_repository::question(
                (int)$quizgeist->id,
                $questionid
            );
            [$canonical, $submission, $evaluation, $validated] =
                self::evaluate(
                    $question,
                    $attemptquestion,
                    (string)$attempt->mode,
                    $rawanswer,
                    $visit,
                    $attemptid,
                    false
                );
            $now = time();
            if ((string)$attempt->mode === 'test') {
                // Store only strategy-validated canonical data. Public handles
                // are regenerated from this position's visit when projected.
                $DB->update_record(
                    'quizgeist_attempt_questions',
                    (object)[
                        'id' => (int)$attemptquestion->attemptquestionid,
                        'status' => 'draft',
                        'answerjson' => self::json_encode($validated),
                        'timestarted' => (int)$attemptquestion->timestarted > 0
                            ? (int)$attemptquestion->timestarted
                            : $now,
                        'timesubmitted' => $now,
                        'timemodified' => $now,
                    ]
                );
            } else {
                $gradeable = (string)$attempt->mode === 'solo'
                    && review_presenter::shows_correctness($question);
                $points = $gradeable ? (int)$evaluation['points'] : 0;
                $maxpoints = $gradeable
                    ? points_formula::maximum(
                        $canonical,
                        'selfstudy',
                        self::ideal_prior_streak(
                            $attemptid,
                            (int)$attemptquestion->sortindex
                        )
                    )
                    : 0;
                self::insert_answer(
                    $attempt,
                    $attemptquestion,
                    $evaluation,
                    $submission,
                    $points,
                    $maxpoints,
                    (int)$user->id,
                    $now,
                    (int)$quizgeist->id
                );
                self::observe_repetition(
                    (int)$quizgeist->id,
                    (int)$user->id,
                    $questionid,
                    $canonical,
                    $evaluation,
                    $now
                );
                $DB->update_record(
                    'quizgeist_attempt_questions',
                    (object)[
                        'id' => (int)$attemptquestion->attemptquestionid,
                        'status' => 'submitted',
                        'answerjson' => self::json_encode(
                            (array)$evaluation['answer']
                        ),
                        'timestarted' => (int)$attemptquestion->timestarted > 0
                            ? (int)$attemptquestion->timestarted
                            : $now,
                        'timesubmitted' => $now,
                        'timemodified' => $now,
                    ]
                );
                self::recompute_score_locked($attemptid);
            }
            $DB->update_record(
                'quizgeist_attempts',
                (object)[
                    'id' => $attemptid,
                    'stateversion' => (int)$attempt->stateversion + 1,
                    'timemodified' => $now,
                ]
            );
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return [
            'state' => attempt_projector::state(
                (int)$quizgeist->id,
                (int)$user->id,
                $attemptid,
                $context
            ),
        ];
    }

    /**
     * Finish an attempt even if answering has since been closed.
     */
    public static function finish(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid,
        int $expectedversion
    ): array {
        global $DB;

        $completednow = false;
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $attempt = self::locked_attempt(
                (int)$quizgeist->id,
                (int)$user->id,
                $attemptid
            );
            if ((string)$attempt->status !== 'completed') {
                self::require_version(
                    $attempt,
                    $expectedversion,
                    (int)$quizgeist->id,
                    (int)$user->id,
                    $context
                );
                self::require_in_progress_status($attempt);
                $now = time();
                self::finalise_locked(
                    $attempt,
                    (int)$quizgeist->id,
                    (int)$user->id,
                    self::requires_server_finalisation($attempt, $now)
                        ? self::server_finalisation_time($attempt, $now)
                        : $now
                );
                $completednow = true;
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        if ($completednow) {
            self::after_completion(
                $cm,
                $quizgeist,
                $context,
                (int)$user->id,
                $attemptid
            );
        } else {
            self::synchronise_grade_and_completion(
                $cm,
                $quizgeist,
                (int)$user->id
            );
        }
        return [
            'state' => attempt_projector::state(
                (int)$quizgeist->id,
                (int)$user->id,
                $attemptid,
                $context
            ),
        ];
    }

    /**
     * Lazily terminalise every due, closed or stale attempt for one learner.
     *
     * @param int|null $assignmentid Optional assignment restriction.
     * @return int Number completed now.
     */
    public static function finalise_user_required(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        ?int $assignmentid = null,
        ?int $now = null
    ): int {
        global $DB;

        $ambienttransaction = $DB->is_transaction_started();
        $params = [
            'quizgeistid' => (int)$quizgeist->id,
            'userid' => (int)$user->id,
            'inprogress' => 'inprogress',
        ];
        $assignmentsql = '';
        if ($assignmentid !== null) {
            $assignmentsql = ' AND a.assignmentid = :assignmentid';
            $params['assignmentid'] = $assignmentid;
        }
        $attemptids = $DB->get_fieldset_sql(
            "SELECT a.id
               FROM {quizgeist_attempts} a
               JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
              WHERE z.quizgeistid = :quizgeistid
                AND a.userid = :userid
                AND a.status = :inprogress
                    {$assignmentsql}
           ORDER BY a.id ASC",
            $params
        );
        $completed = 0;
        foreach ($attemptids as $candidateid) {
            try {
                if (self::finalise_candidate(
                    (int)$candidateid,
                    $now ?? time(),
                    $cm,
                    $quizgeist,
                    $context,
                    $user
                )) {
                    $completed++;
                }
            } catch (\Throwable $exception) {
                if ($ambienttransaction) {
                    throw $exception;
                }
                // This method also runs inside AJAX handlers. Logging must
                // never write into the JSON response body.
                error_log(
                    '[mod_quizgeist] learner-owned self-study attempt '
                    . (int)$candidateid
                    . ' could not be finalised: '
                    . $exception->getMessage()
                );
            }
        }
        return $completed;
    }

    /**
     * Terminalise candidates selected by the scheduled task.
     *
     * @return int Number completed now.
     */
    public static function finalise_scheduled(?int $now = null): int {
        global $DB;

        $now ??= time();
        $ambienttransaction = $DB->is_transaction_started();
        $completed = 0;
        $cursor = 0;
        do {
            // Keyset pagination is intentional. An allowLate candidate or one
            // malformed attempt must never pin the first LIMIT page forever
            // and starve later due/closed attempts.
            $attemptids = $DB->get_fieldset_sql(
                'SELECT a.id
                   FROM {quizgeist_attempts} a
                   JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
                  WHERE a.id > :cursor
                    AND a.status = :inprogress
                    AND (
                        z.status <> :open
                        OR (z.timedue > 0 AND z.timedue < :now)
                        OR a.timemodified < :stalecutoff
                    )
               ORDER BY a.id ASC',
                [
                    'cursor' => $cursor,
                    'inprogress' => 'inprogress',
                    'open' => 'open',
                    'now' => $now,
                    'stalecutoff' => $now - self::STALE_AFTER,
                ],
                0,
                1000
            );
            foreach ($attemptids as $attemptid) {
                $cursor = max($cursor, (int)$attemptid);
                try {
                    $identity = $DB->get_record_sql(
                        'SELECT a.userid, z.quizgeistid, m.course
                           FROM {quizgeist_attempts} a
                           JOIN {quizgeist_assignments} z
                             ON z.id = a.assignmentid
                           JOIN {quizgeist} m ON m.id = z.quizgeistid
                          WHERE a.id = :attemptid',
                        ['attemptid' => (int)$attemptid],
                        IGNORE_MISSING
                    );
                    if (!$identity) {
                        continue;
                    }
                    $cm = get_coursemodule_from_instance(
                        'quizgeist',
                        (int)$identity->quizgeistid,
                        (int)$identity->course,
                        false,
                        IGNORE_MISSING
                    );
                    $context = $cm
                        ? \context_module::instance(
                            (int)$cm->id,
                            IGNORE_MISSING
                        )
                        : null;
                    $quizgeist = $DB->get_record(
                        'quizgeist',
                        ['id' => (int)$identity->quizgeistid],
                        '*',
                        IGNORE_MISSING
                    );
                    $user = $DB->get_record(
                        'user',
                        ['id' => (int)$identity->userid],
                        '*',
                        IGNORE_MISSING
                    );
                    if (!$cm || !$context || !$quizgeist || !$user) {
                        continue;
                    }
                    if (self::finalise_candidate(
                        (int)$attemptid,
                        $now,
                        $cm,
                        $quizgeist,
                        $context,
                        $user
                    )) {
                        $completed++;
                    }
                } catch (\Throwable $exception) {
                    if ($ambienttransaction) {
                        throw $exception;
                    }
                    mtrace(
                        'mod_quizgeist: self-study attempt '
                        . (int)$attemptid
                        . ' could not be finalised: '
                        . $exception->getMessage()
                    );
                }
            }
        } while (count($attemptids) === 1000);
        return $completed;
    }

    /**
     * Recheck one candidate under its attempt-row lock.
     */
    private static function finalise_candidate(
        int $attemptid,
        int $now,
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user
    ): bool {
        global $DB;

        $completed = false;
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $attempt = self::locked_attempt(
                (int)$quizgeist->id,
                (int)$user->id,
                $attemptid
            );
            if (self::requires_server_finalisation($attempt, $now)) {
                self::finalise_locked(
                    $attempt,
                    (int)$quizgeist->id,
                    (int)$user->id,
                    self::server_finalisation_time($attempt, $now)
                );
                $completed = true;
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        if ($completed) {
            self::after_completion(
                $cm,
                $quizgeist,
                $context,
                (int)$user->id,
                $attemptid
            );
        }
        return $completed;
    }

    /**
     * Whether the locked attempt must no longer remain optional.
     */
    private static function requires_server_finalisation(
        \stdClass $attempt,
        int $now
    ): bool {
        if ((string)$attempt->status !== 'inprogress') {
            return false;
        }
        if ((string)$attempt->assignmentstatus !== 'open') {
            return true;
        }
        $settings = assignment_settings::decode(
            $attempt->assignmentsettings ?? null,
            (int)$attempt->assignmenttimedue
        );
        if ((int)$attempt->assignmenttimedue > 0
                && (int)$attempt->assignmenttimedue < $now
                && !$settings['allowLate']) {
            return true;
        }
        return (int)$attempt->timemodified < $now - self::STALE_AFTER;
    }

    /**
     * Preserve the logical terminal instant when Cron runs later.
     *
     * This prevents an old stale attempt from becoming the learner's "last"
     * attempt merely because its scheduled finalisation happened after a
     * newer explicit completion.
     */
    private static function server_finalisation_time(
        \stdClass $attempt,
        int $now
    ): int {
        $candidates = [];
        if ((string)$attempt->assignmentstatus !== 'open') {
            $candidates[] = (int)$attempt->assignmenttimemodified;
        }
        $settings = assignment_settings::decode(
            $attempt->assignmentsettings ?? null,
            (int)$attempt->assignmenttimedue
        );
        if ((int)$attempt->assignmenttimedue > 0
                && (int)$attempt->assignmenttimedue < $now
                && !$settings['allowLate']) {
            $candidates[] = (int)$attempt->assignmenttimedue;
        }
        if ((int)$attempt->timemodified < $now - self::STALE_AFTER) {
            $candidates[] = (int)$attempt->timemodified + self::STALE_AFTER;
        }
        $candidates = array_values(array_filter(
            $candidates,
            static fn(int $timestamp): bool => $timestamp > 0
        ));
        return max(1, min($now, $candidates ? min($candidates) : $now));
    }

    /**
     * Finalise every position and the attempt in the current transaction.
     */
    private static function finalise_locked(
        \stdClass $attempt,
        int $quizgeistid,
        int $userid,
        int $now
    ): void {
        global $DB;

        $rows = self::locked_attempt_questions((int)$attempt->id);
        foreach ($rows as $row) {
            $question = session_repository::question(
                $quizgeistid,
                (int)$row->questionid
            );
            $canonical = submission_pipeline::interaction(
                $question,
                null,
                'player'
            )['question'];
            $gradeable = review_presenter::shows_correctness($question)
                && in_array((string)$attempt->mode, ['solo', 'test'], true);
            $maxpoints = 0;
            if ($gradeable) {
                $maxpoints = (string)$attempt->mode === 'solo'
                    ? points_formula::maximum(
                        $canonical,
                        'selfstudy',
                        self::ideal_prior_streak(
                            (int)$attempt->id,
                            (int)$row->sortindex
                        )
                    )
                    : points_formula::maximum($canonical, 'accuracy', 0);
            }
            if ((string)$row->status === 'submitted') {
                if (!$DB->record_exists('quizgeist_answers', [
                    'attemptid' => (int)$attempt->id,
                    'questionid' => (int)$row->questionid,
                ])) {
                    throw new \coding_exception(
                        'A submitted attempt position has no answer ledger row.'
                    );
                }
                continue;
            }
            if ((string)$attempt->mode === 'test'
                    && (string)$row->status === 'draft') {
                $rawanswer = json_decode((string)$row->answerjson, true);
                if (!is_array($rawanswer)) {
                    throw new \coding_exception(
                        'A validated test draft is malformed.'
                    );
                }
                [, $submission, $evaluation] = self::evaluate(
                    $question,
                    $row,
                    'test',
                    $rawanswer,
                    '',
                    (int)$attempt->id,
                    true
                );
                self::insert_answer(
                    $attempt,
                    $row,
                    $evaluation,
                    $submission,
                    $gradeable ? (int)$evaluation['points'] : 0,
                    $maxpoints,
                    $userid,
                    (int)$row->timesubmitted
                );
                // A test answer only becomes an answer when the attempt is
                // finished. Its logical instant is the moment the learner
                // submitted the draft, not the moment cron arrived.
                self::observe_repetition(
                    $quizgeistid,
                    $userid,
                    (int)$row->questionid,
                    $canonical,
                    $evaluation,
                    (int)$row->timesubmitted > 0
                        ? (int)$row->timesubmitted
                        : $now
                );
                $answerjson = self::json_encode(
                    (array)$evaluation['answer']
                );
            } else {
                self::insert_scorevoid(
                    $attempt,
                    $row,
                    $userid,
                    $maxpoints,
                    $now
                );
                $answerjson = null;
            }
            $DB->update_record(
                'quizgeist_attempt_questions',
                (object)[
                    'id' => (int)$row->attemptquestionid,
                    'status' => 'submitted',
                    'answerjson' => $answerjson,
                    'timestarted' => (int)$row->timestarted > 0
                        ? (int)$row->timestarted
                        : max(1, (int)$attempt->timestarted),
                    'timesubmitted' => (int)$row->timesubmitted > 0
                        ? (int)$row->timesubmitted
                        : $now,
                    'timemodified' => $now,
                ]
            );
        }
        $flashcardsjson = $attempt->flashcardsjson ?? null;
        if ((string)$attempt->mode === 'flashcards') {
            $stacks = attempt_projector::decode_flashcards($flashcardsjson);
            $unfinished = array_values(array_unique(array_merge(
                $stacks['queue'],
                $stacks['repeat']
            )));
            foreach ($unfinished as $index) {
                if (!in_array($index, $stacks['known'], true)
                        && !in_array($index, $stacks['missed'], true)) {
                    $stacks['missed'][] = $index;
                }
            }
            $stacks['queue'] = [];
            $stacks['repeat'] = [];
            $stacks['round'] = 2;
            $flashcardsjson = self::json_encode($stacks);
        }
        self::recompute_score_locked((int)$attempt->id);
        $DB->update_record(
            'quizgeist_attempts',
            (object)[
                'id' => (int)$attempt->id,
                'status' => 'completed',
                'flashcardsjson' => $flashcardsjson,
                'stateversion' => (int)$attempt->stateversion + 1,
                'timefinished' => $now,
                'timemodified' => $now,
            ]
        );
        self::queue_grade_synchronisation($quizgeistid, $userid);
    }

    /**
     * Evaluate through the common live/self-study qtype pipeline.
     *
     * @return array{0:array,1:submission_context,2:array,3:array}
     */
    private static function evaluate(
        \stdClass $question,
        \stdClass $attemptquestion,
        string $mode,
        array $rawanswer,
        string $visit,
        int $attemptid,
        bool $trusted
    ): array {
        $interaction = submission_pipeline::interaction(
            $question,
            null,
            'player'
        );
        $canonical = (array)$interaction['question'];
        $stage = (string)$interaction['stage'];
        $kind = (string)$interaction['kind'];
        $submission = $trusted
            ? submission_context::internal($stage, $kind)
            : new submission_context($stage, $kind, 'player', 0, $visit);
        $validated = $interaction['strategy']->validate_answer(
            $canonical,
            $rawanswer,
            $submission
        );
        $scoremode = $mode === 'solo' ? 'selfstudy' : 'accuracy';
        $priorstreak = $mode === 'solo'
            ? self::prior_streak(
                $attemptid,
                (int)$attemptquestion->sortindex
            )
            : 0;
        $now = time();
        $limitms = max(0, (int)$canonical['timelimit'] * 1000);
        $started = (int)$attemptquestion->timestarted;
        $responsetimems = $started > 0 && $started <= $now
            ? max(0, ($now - $started) * 1000)
            : $limitms;
        $evaluation = submission_pipeline::evaluate(
            $question,
            $rawanswer,
            new scoring_context($scoremode, $responsetimems, $priorstreak),
            $submission
        );
        return [$canonical, $submission, $evaluation, $validated];
    }

    /**
     * Terminalise a non-interactive reaction-free content position.
     */
    public static function terminalise_passive_question(
        \stdClass $attempt,
        \stdClass $attemptquestion,
        int $quizgeistid,
        int $userid
    ): bool {
        global $DB;

        if ((string)$attemptquestion->status !== 'pending') {
            return false;
        }
        $question = session_repository::question(
            $quizgeistid,
            (int)$attemptquestion->questionid
        );
        $interaction = submission_pipeline::interaction(
            $question,
            null,
            'player'
        );
        if ($interaction['policy']->response_type() !== 'reaction'
                || !empty($interaction['question']['options']['reactions'])) {
            return false;
        }
        $now = time();
        $DB->insert_record(
            'quizgeist_answers',
            (object)[
                'sessionid' => null,
                'playerid' => null,
                'attemptid' => (int)$attempt->id,
                'questionid' => (int)$attemptquestion->questionid,
                'userid' => $userid,
                'answertype' => 'view',
                'visit' => (string)$attemptquestion->visit,
                'submissionkey' => null,
                'answerjson' => null,
                'iscorrect' => null,
                'points' => 0,
                'maxpoints' => 0,
                'responsetime' => 0,
                'timecreated' => $now,
            ]
        );
        $DB->update_record(
            'quizgeist_attempt_questions',
            (object)[
                'id' => (int)$attemptquestion->attemptquestionid,
                'status' => 'submitted',
                'timestarted' => (int)$attemptquestion->timestarted > 0
                    ? (int)$attemptquestion->timestarted
                    : $now,
                'timesubmitted' => $now,
                'timemodified' => $now,
            ]
        );
        return true;
    }

    /**
     * Hand one evaluated self-study response to the repetition core.
     *
     * This is the only place in the self-study pipeline that talks to the
     * scheduler, so live play and self-study cannot drift apart: both pass the
     * same canonical question and the same evaluation array into the same
     * mapper. The scheduler is evaluation, never creation, and therefore never
     * feature-gated; it is also fault tolerant, so a scheduler problem can
     * never discard an answer that has already been scored.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $questionid Exact answered question version.
     * @param array $canonical Canonical question.
     * @param array $evaluation Evaluated response.
     * @param int $now Logical instant of the response.
     * @return void
     */
    public static function observe_repetition(
        int $quizgeistid,
        int $userid,
        int $questionid,
        array $canonical,
        array $evaluation,
        int $now
    ): void {
        \mod_quizgeist\local\schedule\schedule_service::observe(
            $quizgeistid,
            $userid,
            $questionid,
            $canonical,
            $evaluation,
            $now
        );
    }

    /**
     * Insert one evaluated self-study answer.
     */
    private static function insert_answer(
        \stdClass $attempt,
        \stdClass $attemptquestion,
        array $evaluation,
        submission_context $submission,
        int $points,
        int $maxpoints,
        int $userid,
        int $timecreated,
        int $quizgeistid = 0
    ): void {
        global $DB;

        $answer = (array)$evaluation['answer'];
        $answertype = (string)($evaluation['answerType'] ?? $submission->kind);
        $answerid = (int)$DB->insert_record(
            'quizgeist_answers',
            submission_pipeline::ledger_record([
                'attemptid' => (int)$attempt->id,
                'questionid' => (int)$attemptquestion->questionid,
                'userid' => $userid,
                'answertype' => $answertype,
                'visit' => (string)$attemptquestion->visit,
                'answer' => $answer,
                'iscorrect' => $evaluation['iscorrect'],
                'points' => $points,
                'maxpoints' => $maxpoints,
                'responsetime' => (int)$evaluation['responseTimeMs'],
                'timecreated' => $timecreated > 0 ? $timecreated : time(),
            ])
        );
        // F9: same rule as in live play — a clip becomes this learner's answer
        // only where the acting user is known, inside this transaction.
        if ($quizgeistid > 0 && isset($answer['clipId']) && is_int($answer['clipId'])) {
            \mod_quizgeist\local\media\clip_binding::attach(
                $quizgeistid,
                (int)$answer['clipId'],
                $answerid,
                $userid,
                $answertype === 'reason' ? 'reason' : 'answer'
            );
        }
    }

    /**
     * Insert an explicit zero-score denominator row for an unanswered position.
     */
    private static function insert_scorevoid(
        \stdClass $attempt,
        \stdClass $attemptquestion,
        int $userid,
        int $maxpoints,
        int $now
    ): void {
        global $DB;

        $DB->insert_record('quizgeist_answers', (object)[
            'sessionid' => null,
            'playerid' => null,
            'attemptid' => (int)$attempt->id,
            'questionid' => (int)$attemptquestion->questionid,
            'userid' => $userid,
            'answertype' => 'scorevoid',
            'visit' => (string)$attemptquestion->visit,
            'submissionkey' => null,
            'answerjson' => null,
            'iscorrect' => 0,
            'points' => 0,
            'maxpoints' => $maxpoints,
            'responsetime' => 0,
            'timecreated' => $now,
        ]);
    }

    /**
     * Recalculate durable totals from the exactly-once ledger.
     */
    public static function recompute_score_locked(int $attemptid): void {
        global $DB;

        $totals = $DB->get_record_sql(
            'SELECT COALESCE(SUM(points), 0) AS score,
                    COALESCE(SUM(maxpoints), 0) AS maxscore
               FROM {quizgeist_answers}
              WHERE attemptid = :attemptid',
            ['attemptid' => $attemptid]
        );
        $DB->update_record(
            'quizgeist_attempts',
            (object)[
                'id' => $attemptid,
                'score' => (int)($totals->score ?? 0),
                'maxscore' => (int)($totals->maxscore ?? 0),
            ]
        );
    }

    /**
     * Ensure every attempt position is terminal.
     */
    public static function require_all_submitted(int $attemptid): void {
        global $DB;

        if ($DB->record_exists_select(
            'quizgeist_attempt_questions',
            'attemptid = :attemptid AND status <> :submitted',
            ['attemptid' => $attemptid, 'submitted' => 'submitted']
        )) {
            throw new \invalid_parameter_exception(
                'Every question must be submitted before finish.'
            );
        }
    }

    /**
     * Count the contiguous correct solo streak before a position.
     */
    private static function prior_streak(
        int $attemptid,
        int $sortindex
    ): int {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT a.id, a.iscorrect
               FROM {quizgeist_answers} a
               JOIN {quizgeist_attempt_questions} aq
                 ON aq.attemptid = a.attemptid
                AND aq.questionid = a.questionid
              WHERE a.attemptid = :attemptid
                AND aq.sortindex < :sortindex
                AND a.iscorrect IS NOT NULL
           ORDER BY aq.sortindex DESC, a.id DESC',
            ['attemptid' => $attemptid, 'sortindex' => $sortindex]
        );
        $streak = 0;
        foreach ($rows as $row) {
            if (empty($row->iscorrect)) {
                break;
            }
            $streak++;
        }
        return $streak;
    }

    /**
     * Count earlier gradable positions for the stable ideal denominator.
     */
    private static function ideal_prior_streak(
        int $attemptid,
        int $sortindex
    ): int {
        global $DB;

        $questions = $DB->get_records_sql(
            'SELECT q.*
               FROM {quizgeist_attempt_questions} aq
               JOIN {quizgeist_questions} q ON q.id = aq.questionid
              WHERE aq.attemptid = :attemptid
                AND aq.sortindex < :sortindex
           ORDER BY aq.sortindex ASC, aq.id ASC',
            ['attemptid' => $attemptid, 'sortindex' => $sortindex]
        );
        $streak = 0;
        foreach ($questions as $question) {
            if (review_presenter::shows_correctness($question)) {
                $streak++;
            }
        }
        return $streak;
    }

    /**
     * Lock all attempt-question rows in deterministic order.
     *
     * @return \stdClass[]
     */
    private static function locked_attempt_questions(int $attemptid): array {
        global $DB;

        return array_values($DB->get_records_sql(
            'SELECT aq.id AS attemptquestionid, aq.*
               FROM {quizgeist_attempt_questions} aq
              WHERE aq.attemptid = :attemptid
           ORDER BY aq.sortindex ASC, aq.id ASC
                    FOR UPDATE',
            ['attemptid' => $attemptid]
        ));
    }

    /**
     * Lock one learner-owned attempt.
     */
    private static function locked_attempt(
        int $quizgeistid,
        int $userid,
        int $attemptid
    ): \stdClass {
        return attempt_projector::attempt_record(
            $quizgeistid,
            $userid,
            $attemptid,
            true
        );
    }

    /**
     * Lock one exact attempt position.
     */
    private static function locked_attempt_question(
        int $attemptid,
        int $questionid
    ): \stdClass {
        global $DB;

        return $DB->get_record_sql(
            'SELECT aq.id AS attemptquestionid, aq.*
               FROM {quizgeist_attempt_questions} aq
              WHERE aq.attemptid = :attemptid
                AND aq.questionid = :questionid
                    FOR UPDATE',
            ['attemptid' => $attemptid, 'questionid' => $questionid],
            MUST_EXIST
        );
    }

    /**
     * New answers require an open, available assignment.
     */
    private static function require_answering_allowed(\stdClass $attempt): void {
        self::require_in_progress_status($attempt);
        if ((string)$attempt->assignmentstatus !== 'open'
                || ((int)$attempt->assignmenttimeopen > 0
                    && (int)$attempt->assignmenttimeopen > time())) {
            throw new \invalid_parameter_exception(
                'The assignment is not open.'
            );
        }
        $settings = assignment_settings::decode(
            $attempt->assignmentsettings ?? null,
            (int)$attempt->assignmenttimedue
        );
        if ((int)$attempt->assignmenttimedue > 0
                && (int)$attempt->assignmenttimedue < time()
                && !$settings['allowLate']) {
            throw new \invalid_parameter_exception(
                'The assignment deadline has passed.'
            );
        }
    }

    /**
     * Finishing requires only an unfinished owned attempt.
     */
    private static function require_in_progress_status(
        \stdClass $attempt
    ): void {
        if ((string)$attempt->status !== 'inprogress') {
            throw new \invalid_parameter_exception(
                'The attempt is not in progress.'
            );
        }
    }

    /**
     * Enforce optimistic concurrency.
     */
    private static function require_version(
        \stdClass $attempt,
        int $expectedversion,
        int $quizgeistid,
        int $userid,
        \context_module $context
    ): void {
        if ((int)$attempt->stateversion !== $expectedversion) {
            throw new live_conflict_exception(
                attempt_projector::state(
                    $quizgeistid,
                    $userid,
                    (int)$attempt->id,
                    $context
                )
            );
        }
    }

    /**
     * Require the current frozen position.
     */
    private static function require_current_question(
        \stdClass $attempt,
        \stdClass $attemptquestion
    ): void {
        $state = attempt_projector::decode_attempt_state(
            $attempt->statejson ?? null
        );
        if ((int)$state['currentIndex'] !== (int)$attemptquestion->sortindex) {
            throw new \invalid_parameter_exception(
                'The question is not the current attempt position.'
            );
        }
    }

    /**
     * Require the exact opaque visit.
     */
    private static function require_visit(
        \stdClass $attemptquestion,
        string $visit
    ): void {
        if (!preg_match('/^[a-f0-9]{32}$/D', $visit)
                || !hash_equals((string)$attemptquestion->visit, $visit)) {
            throw new \invalid_parameter_exception(
                'The question token is invalid.'
            );
        }
    }

    /**
     * Update gradebook/completion and emit the first completion event.
     */
    public static function after_completion(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        int $attemptid
    ): void {
        self::synchronise_grade_and_completion($cm, $quizgeist, $userid);
        $attempt = attempt_projector::attempt_record(
            (int)$quizgeist->id,
            $userid,
            $attemptid
        );
        $event = \mod_quizgeist\event\assignment_completed::create([
            'objectid' => $attemptid,
            'context' => $context,
            'relateduserid' => $userid,
            'other' => [
                'assignmentid' => (int)$attempt->assignmentid,
                'score' => (int)$attempt->score,
                'maxscore' => (int)$attempt->maxscore,
            ],
        ]);
        $event->add_record_snapshot('quizgeist_attempts', $attempt);
        $event->trigger();
    }

    /**
     * Synchronise Moodle grade and custom completion after durable changes.
     */
    public static function synchronise_grade_and_completion(
        \stdClass $cm,
        \stdClass $quizgeist,
        int $userid
    ): void {
        global $CFG;

        require_once($CFG->dirroot . '/mod/quizgeist/lib.php');
        // GRADE_UPDATE_OK, \completion_info and COMPLETION_UNKNOWN live in
        // lib/gradelib.php and lib/completionlib.php. Moodle loads neither on
        // module or AJAX pages, so this method names both instead of hoping a
        // caller already pulled them in (P10-F15).
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $gradestatus = quizgeist_update_grades($quizgeist, $userid, true);
        if ($gradestatus !== GRADE_UPDATE_OK) {
            throw new \RuntimeException(
                'Moodle grade synchronisation failed with status '
                    . $gradestatus
                    . '.'
            );
        }
        $course = get_course((int)$cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }

    /**
     * Queue an aggregate resync in the caller's transaction.
     *
     * The task row commits atomically with an attempt terminalisation or grade
     * policy change. Immediate synchronisation still keeps the UI current;
     * this retry path prevents a transient post-commit side-effect failure
     * from leaving the gradebook or completion state stale forever.
     */
    public static function queue_grade_synchronisation(
        int $quizgeistid,
        int $userid
    ): void {
        $task = new \mod_quizgeist\task\synchronise_selfstudy_grade();
        $task->set_custom_data((object)[
            'quizgeistid' => $quizgeistid,
            'userid' => $userid,
        ]);
        // Never deduplicate against a task that may already be executing on
        // an older aggregate snapshot. Grade/completion sync is idempotent,
        // while suppressing this row would create a lost-wakeup race.
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Encode internal state predictably.
     */
    private static function json_encode(array $value): string {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }
}
