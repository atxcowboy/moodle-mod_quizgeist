<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Transactional self-study attempt state machine.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

use mod_quizgeist\local\live\live_conflict_exception;
use mod_quizgeist\local\schedule\schedule_repository;
use mod_quizgeist\local\schedule\schedule_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Coordinates self-study navigation and flashcard interactions.
 */
final class attempt_service {

    /** Default weekly target shown before a learner saves a preference. */
    private const DEFAULT_GOAL = 20;

    /** Maximum accepted personal weekly target. */
    private const MAX_GOAL = 500;

    /**
     * Build the learner's open/completed assignment overview and weekly goal.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @return array
     */
    public static function overview(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user
    ): array {
        global $DB;

        attempt_submission_service::finalise_user_required(
            $cm,
            $quizgeist,
            $context,
            $user
        );
        $assignments = $DB->get_records_select(
            'quizgeist_assignments',
            'quizgeistid = :quizgeistid AND status <> :draft AND status <> :archived',
            [
                'quizgeistid' => (int)$quizgeist->id,
                'draft' => 'draft',
                'archived' => 'archived',
            ],
            'timedue ASC, timecreated DESC, id DESC'
        );
        $attempts = $DB->get_records_sql(
            'SELECT a.*
               FROM {quizgeist_attempts} a
               JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
              WHERE z.quizgeistid = :quizgeistid
                AND a.userid = :userid
           ORDER BY a.assignmentid ASC, a.attemptnumber DESC, a.id DESC',
            ['quizgeistid' => (int)$quizgeist->id, 'userid' => (int)$user->id]
        );
        $active = [];
        $completed = [];
        $attemptsused = [];
        foreach ($attempts as $attempt) {
            $assignmentid = (int)$attempt->assignmentid;
            if ((string)$attempt->status === 'inprogress') {
                $attemptsused[$assignmentid] =
                    ($attemptsused[$assignmentid] ?? 0) + 1;
                $active[$assignmentid] ??= $attempt;
            } else if ((string)$attempt->status === 'completed') {
                $attemptsused[$assignmentid] =
                    ($attemptsused[$assignmentid] ?? 0) + 1;
                $completed[$assignmentid] ??= $attempt;
            }
        }

        $now = time();
        $open = [];
        $done = [];
        foreach ($assignments as $assignment) {
            $assignmentid = (int)$assignment->id;
            // A newly opened retry is the actionable row. An older completed
            // attempt remains history, but must not hide the active attempt.
            $summaryattempt = $active[$assignmentid]
                ?? ($completed[$assignmentid] ?? null);
            $summary = attempt_projector::assignment_summary(
                $assignment,
                $summaryattempt,
                $now,
                null,
                null,
                $attemptsused[$assignmentid] ?? 0
            );
            if ($summaryattempt
                    && (string)$summaryattempt->status === 'completed') {
                $done[] = $summary;
            } else if ((string)$assignment->status === 'open') {
                $open[] = $summary;
            }
        }
        return [
            'openAssignments' => $open,
            'completedAssignments' => $done,
            'weeklyGoal' => self::goal_dto((int)$quizgeist->id, $user),
            'gradeSummary' => grade_calculator::summary(
                $quizgeist,
                (int)$user->id
            ),
            'serverTimeMs' => time() * 1000,
        ];
    }

    /**
     * Start a fresh attempt, or reconnect to the current in-progress attempt.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @param int $assignmentid Assignment.
     * @param bool $newattempt Explicitly start another attempt after completion.
     * @return array
     */
    public static function start(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $assignmentid,
        bool $newattempt
    ): array {
        global $DB;

        attempt_submission_service::finalise_user_required(
            $cm,
            $quizgeist,
            $context,
            $user,
            $assignmentid
        );
        $created = false;
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $assignment = self::locked_assignment(
                (int)$quizgeist->id,
                $assignmentid
            );

            $existing = $DB->get_records_sql(
                'SELECT *
                   FROM {quizgeist_attempts}
                  WHERE assignmentid = :assignmentid
                    AND userid = :userid
               ORDER BY attemptnumber DESC, id DESC
                    FOR UPDATE',
                [
                    'assignmentid' => $assignmentid,
                    'userid' => (int)$user->id,
                ]
            );
            $active = null;
            $completed = null;
            $maxattempt = 0;
            $attemptsused = 0;
            foreach ($existing as $candidate) {
                $maxattempt = max(
                    $maxattempt,
                    (int)$candidate->attemptnumber
                );
                if ($active === null
                        && (string)$candidate->status === 'inprogress') {
                    $attemptsused++;
                    $active = $candidate;
                } else if ((string)$candidate->status === 'inprogress') {
                    $attemptsused++;
                } else if ((string)$candidate->status === 'completed') {
                    $attemptsused++;
                    if ($completed === null) {
                        $completed = $candidate;
                    }
                }
            }
            $attempt = $active;
            if ($attempt === null && !$newattempt) {
                $attempt = $completed;
            }
            if ($attempt === null) {
                self::require_assignment_available($assignment, time());
                $assignmentsettings = assignment_settings::decode(
                    $assignment->settingsjson ?? null,
                    (int)$assignment->timedue
                );
                if ($attemptsused
                        >= (int)$assignmentsettings['maxAttempts']) {
                    throw new \invalid_parameter_exception(
                        'The maximum number of attempts has been reached.'
                    );
                }
                $snapshots = $DB->get_records(
                    'quizgeist_assignment_questions',
                    ['assignmentid' => $assignmentid],
                    'sortindex ASC, id ASC'
                );
                if (!$snapshots) {
                    throw new \coding_exception(
                        'A self-study assignment has no question snapshot.'
                    );
                }
                $now = time();
                // F3/F4: a fixed assignment plays exactly the frozen versions
                // in their frozen order. A repetition assignment resolves the
                // current version of every frozen root here, and only here.
                $positions = self::attempt_positions(
                    $quizgeist,
                    $assignment,
                    $assignmentsettings,
                    $snapshots,
                    $user,
                    $now
                );
                $indexes = array_values(array_map(
                    static fn(array $position): int => $position['sortindex'],
                    $positions
                ));
                $attemptid = (int)$DB->insert_record(
                    'quizgeist_attempts',
                    (object)[
                        'assignmentid' => $assignmentid,
                        'userid' => (int)$user->id,
                        'attemptnumber' => $maxattempt + 1,
                        'mode' => (string)$assignment->mode,
                        'status' => 'inprogress',
                        'score' => 0,
                        'maxscore' => 0,
                        'statejson' => self::json_encode(['currentIndex' => 0]),
                        'flashcardsjson' =>
                            (string)$assignment->mode === 'flashcards'
                            ? self::json_encode([
                                'queue' => $indexes,
                                'repeat' => [],
                                'known' => [],
                                'missed' => [],
                                'round' => 1,
                            ])
                            : null,
                        'stateversion' => 1,
                        'timestarted' => $now,
                        'timefinished' => 0,
                        'remindedat' => 0,
                        'timemodified' => $now,
                    ]
                );
                foreach ($positions as $position) {
                    $DB->insert_record(
                        'quizgeist_attempt_questions',
                        (object)[
                            'attemptid' => $attemptid,
                            'sortindex' => $position['sortindex'],
                            'questionid' => $position['questionid'],
                            'visit' => bin2hex(random_bytes(16)),
                            'status' => 'pending',
                            'answerjson' => null,
                            'round' => 1,
                            'timestarted' => $position['sortindex'] === 0
                                ? $now
                                : 0,
                            'timesubmitted' => 0,
                            'timemodified' => $now,
                        ]
                    );
                }
                $attempt = $DB->get_record(
                    'quizgeist_attempts',
                    ['id' => $attemptid],
                    '*',
                    MUST_EXIST
                );
                $created = true;
            }
            $assignmentsettings = assignment_settings::decode(
                $assignment->settingsjson ?? null,
                (int)$assignment->timedue
            );
            $canprocess = (string)$attempt->status === 'inprogress'
                && (string)$assignment->status === 'open'
                && ((int)$assignment->timeopen === 0
                    || (int)$assignment->timeopen <= time())
                && (
                    (int)$assignment->timedue === 0
                    || (int)$assignment->timedue >= time()
                    || $assignmentsettings['allowLate']
                );
            if ((string)$attempt->mode !== 'flashcards' && $canprocess) {
                $attemptstate = attempt_projector::decode_attempt_state(
                    $attempt->statejson ?? null
                );
                $current = self::locked_attempt_question_by_index(
                    (int)$attempt->id,
                    (int)$attemptstate['currentIndex']
                );
                if (attempt_submission_service::terminalise_passive_question(
                    $attempt,
                    $current,
                    (int)$quizgeist->id,
                    (int)$user->id
                )) {
                    attempt_submission_service::recompute_score_locked(
                        (int)$attempt->id
                    );
                    if (!$created) {
                        $DB->update_record(
                            'quizgeist_attempts',
                            (object)[
                                'id' => (int)$attempt->id,
                                'stateversion' =>
                                    (int)$attempt->stateversion + 1,
                                'timemodified' => time(),
                            ]
                        );
                    }
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        if ($created) {
            self::refresh_completion($cm, (int)$user->id);
        }
        return self::state_response(
            $quizgeist,
            $context,
            $user,
            (int)$attempt->id
        );
    }

    /**
     * Read one learner-owned attempt.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @param int $attemptid Attempt.
     * @return array
     */
    public static function state(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid
    ): array {
        $attempt = attempt_projector::attempt_record(
            (int)$quizgeist->id,
            (int)$user->id,
            $attemptid
        );
        attempt_submission_service::finalise_user_required(
            $cm,
            $quizgeist,
            $context,
            $user,
            (int)$attempt->assignmentid
        );
        return self::state_response(
            $quizgeist,
            $context,
            $user,
            $attemptid
        );
    }

    /**
     * Move to a frozen position. Test answers remain editable until finish.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @param int $attemptid Attempt.
     * @param int $index Zero-based index.
     * @param int $expectedversion Optimistic state version.
     * @return array
     */
    public static function navigate(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid,
        int $index,
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
            if ((string)$attempt->status === 'completed') {
                $transaction->allow_commit();
                return self::state_response(
                    $quizgeist,
                    $context,
                    $user,
                    $attemptid
                );
            }
            self::require_in_progress($attempt);
            self::require_version(
                $attempt,
                $expectedversion,
                (int)$quizgeist->id,
                (int)$user->id,
                $context
            );
            if ((string)$attempt->mode === 'flashcards') {
                throw new \invalid_parameter_exception(
                    'Flashcard order is server-owned.'
                );
            }
            $target = self::locked_attempt_question_by_index(
                $attemptid,
                $index
            );
            $state = attempt_projector::decode_attempt_state(
                $attempt->statejson ?? null
            );
            if ((int)$state['currentIndex'] !== $index) {
                $now = time();
                if ((int)$target->timestarted === 0) {
                    $DB->update_record(
                        'quizgeist_attempt_questions',
                        (object)[
                            'id' => (int)$target->attemptquestionid,
                            'timestarted' => $now,
                            'timemodified' => $now,
                        ]
                    );
                }
                $state['currentIndex'] = $index;
                attempt_submission_service::terminalise_passive_question(
                    $attempt,
                    $target,
                    (int)$quizgeist->id,
                    (int)$user->id
                );
                attempt_submission_service::recompute_score_locked($attemptid);
                $DB->update_record(
                    'quizgeist_attempts',
                    (object)[
                        'id' => $attemptid,
                        'statejson' => self::json_encode($state),
                        'stateversion' => (int)$attempt->stateversion + 1,
                        'timemodified' => $now,
                    ]
                );
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return self::state_response(
            $quizgeist,
            $context,
            $user,
            $attemptid
        );
    }

    /**
     * Validate and persist one response against its exact attempted version.
     *
     * Test responses are validated by the strategy but remain unscored drafts
     * until the single finish transaction evaluates and inserts them.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @param int $attemptid Attempt.
     * @param int $questionid Exact question version.
     * @param string $visit Opaque visit token.
     * @param array $rawanswer Type-specific public answer.
     * @param int $expectedversion Optimistic version.
     * @return array
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
        return attempt_submission_service::submit(
            $quizgeist,
            $context,
            $user,
            $attemptid,
            $questionid,
            $visit,
            $rawanswer,
            $expectedversion
        );
    }

    /**
     * Finish an attempt with every answer reached so far preserved.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @param int $attemptid Attempt.
     * @param int $expectedversion Optimistic version.
     * @return array
     */
    public static function finish(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid,
        int $expectedversion
    ): array {
        return attempt_submission_service::finish(
            $cm,
            $quizgeist,
            $context,
            $user,
            $attemptid,
            $expectedversion
        );
    }

    /**
     * Reveal one flashcard's strategy-owned solution.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @param int $attemptid Attempt.
     * @param int $questionid Exact question.
     * @param string $visit Opaque visit.
     * @param int $expectedversion Optimistic version.
     * @return array
     */
    public static function reveal_flashcard(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid,
        int $questionid,
        string $visit,
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
            self::require_in_progress($attempt);
            self::require_flashcards($attempt);
            self::require_version(
                $attempt,
                $expectedversion,
                (int)$quizgeist->id,
                (int)$user->id,
                $context
            );
            $attemptquestion = self::locked_attempt_question(
                $attemptid,
                $questionid
            );
            self::require_current_question($attempt, $attemptquestion);
            self::require_visit($attemptquestion, $visit);
            if ((string)$attemptquestion->status !== 'pending') {
                throw new live_conflict_exception(
                    self::projected_state(
                        $quizgeist,
                        $context,
                        $user,
                        $attemptid
                    )
                );
            }
            $now = time();
            $DB->update_record(
                'quizgeist_attempt_questions',
                (object)[
                    'id' => (int)$attemptquestion->attemptquestionid,
                    'status' => 'revealed',
                    'timestarted' => (int)$attemptquestion->timestarted > 0
                        ? (int)$attemptquestion->timestarted
                        : $now,
                    'timemodified' => $now,
                ]
            );
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
        return self::state_response(
            $quizgeist,
            $context,
            $user,
            $attemptid
        );
    }

    /**
     * Place a flashcard into known/not-known stacks and one repeat round.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param \stdClass $user Learner.
     * @param int $attemptid Attempt.
     * @param int $questionid Exact question.
     * @param string $visit Opaque visit.
     * @param bool $known Self-assessment.
     * @param int $expectedversion Optimistic version.
     * @return array
     */
    public static function mark_flashcard(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid,
        int $questionid,
        string $visit,
        bool $known,
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
            self::require_in_progress($attempt);
            self::require_flashcards($attempt);
            self::require_version(
                $attempt,
                $expectedversion,
                (int)$quizgeist->id,
                (int)$user->id,
                $context
            );
            $attemptquestion = self::locked_attempt_question(
                $attemptid,
                $questionid
            );
            self::require_current_question($attempt, $attemptquestion);
            self::require_visit($attemptquestion, $visit);
            if ((string)$attemptquestion->status !== 'revealed') {
                throw new live_conflict_exception(
                    self::projected_state(
                        $quizgeist,
                        $context,
                        $user,
                        $attemptid
                    )
                );
            }

            $stacks = attempt_projector::decode_flashcards(
                $attempt->flashcardsjson ?? null
            );
            $index = (int)$attemptquestion->sortindex;
            if (!$stacks['queue'] || (int)$stacks['queue'][0] !== $index) {
                throw new \coding_exception(
                    'The persisted flashcard queue is inconsistent.'
                );
            }
            array_shift($stacks['queue']);
            $round = max(1, (int)$attemptquestion->round);
            $now = time();
            if (!$known && $round === 1) {
                if (!in_array($index, $stacks['repeat'], true)) {
                    $stacks['repeat'][] = $index;
                }
                $DB->update_record(
                    'quizgeist_attempt_questions',
                    (object)[
                        'id' => (int)$attemptquestion->attemptquestionid,
                        'status' => 'pending',
                        'answerjson' => null,
                        'round' => 2,
                        'timemodified' => $now,
                    ]
                );
            } else {
                if ($known) {
                    $stacks['known'][] = $index;
                } else {
                    $stacks['missed'][] = $index;
                }
                $answer = [
                    'known' => $known,
                    'rounds' => $round,
                ];
                $DB->insert_record(
                    'quizgeist_answers',
                    (object)[
                        'sessionid' => null,
                        'playerid' => null,
                        'attemptid' => $attemptid,
                        'questionid' => $questionid,
                        'userid' => (int)$user->id,
                        'answertype' => 'flashcard',
                        'visit' => $visit,
                        'submissionkey' => null,
                        'answerjson' => self::json_encode($answer),
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
                        'answerjson' => self::json_encode($answer),
                        'timesubmitted' => $now,
                        'timemodified' => $now,
                    ]
                );
                // U2: a flashcard verdict is the learner's own recall judgement
                // and therefore the cleanest possible repetition signal. It is
                // routed through the single self-study observation point so
                // flashcards, solo and test cannot drift apart.
                attempt_submission_service::observe_repetition(
                    (int)$quizgeist->id,
                    (int)$user->id,
                    $questionid,
                    \mod_quizgeist\local\live\answer_evaluator::canonical_question(
                        \mod_quizgeist\local\live\session_repository::question(
                            (int)$quizgeist->id,
                            $questionid
                        )
                    ),
                    [
                        'answerType' => 'flashcard',
                        'flashcardKnown' => $known,
                        'flashcardRounds' => $round,
                    ],
                    $now
                );
            }

            if (!$stacks['queue'] && $stacks['repeat']) {
                $stacks['queue'] = array_values($stacks['repeat']);
                $stacks['repeat'] = [];
                $stacks['round'] = 2;
            }
            $state = attempt_projector::decode_attempt_state(
                $attempt->statejson ?? null
            );
            if ($stacks['queue']) {
                $state['currentIndex'] = (int)$stacks['queue'][0];
                $next = self::locked_attempt_question_by_index(
                    $attemptid,
                    (int)$state['currentIndex']
                );
                if ((int)$next->timestarted === 0) {
                    $DB->update_record(
                        'quizgeist_attempt_questions',
                        (object)[
                            'id' => (int)$next->attemptquestionid,
                            'timestarted' => $now,
                            'timemodified' => $now,
                        ]
                    );
                }
            } else {
                attempt_submission_service::require_all_submitted($attemptid);
                $completednow = true;
            }
            $update = (object)[
                'id' => $attemptid,
                'statejson' => self::json_encode($state),
                'flashcardsjson' => self::json_encode($stacks),
                'stateversion' => (int)$attempt->stateversion + 1,
                'timemodified' => $now,
            ];
            if ($completednow) {
                $update->status = 'completed';
                $update->timefinished = $now;
            }
            $DB->update_record('quizgeist_attempts', $update);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        if ($completednow) {
            attempt_submission_service::after_completion(
                $cm,
                $quizgeist,
                $context,
                (int)$user->id,
                $attemptid
            );
        }
        return self::state_response(
            $quizgeist,
            $context,
            $user,
            $attemptid
        );
    }

    /**
     * Save the learner's personal weekly target.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $user Learner.
     * @param int $target Target question count.
     * @return array
     */
    public static function save_goal(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $target
    ): array {
        global $DB;

        if ($target < 1 || $target > self::MAX_GOAL) {
            throw new \invalid_parameter_exception('target is invalid.');
        }
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $record = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_goals}
                  WHERE quizgeistid = :quizgeistid
                    AND userid = :userid
                        FOR UPDATE',
                [
                    'quizgeistid' => (int)$quizgeist->id,
                    'userid' => (int)$user->id,
                ],
                IGNORE_MISSING
            );
            $now = time();
            if ($record) {
                $DB->update_record(
                    'quizgeist_goals',
                    (object)[
                        'id' => (int)$record->id,
                        'target' => $target,
                        'timemodified' => $now,
                    ]
                );
            } else {
                $DB->insert_record(
                    'quizgeist_goals',
                    (object)[
                        'quizgeistid' => (int)$quizgeist->id,
                        'userid' => (int)$user->id,
                        'target' => $target,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]
                );
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return self::overview($cm, $quizgeist, $context, $user);
    }

    /**
     * Resolve the positions one new attempt will play.
     *
     * A `fixed` assignment plays exactly what its teacher froze: the same
     * question versions, in the same order, for every learner and every
     * attempt. That is the whole point of a snapshot and it stays untouched.
     *
     * A `due` assignment froze question ROOTS instead. Its versions are
     * resolved here, at start, so a question corrected after the assignment was
     * handed out is played in its corrected form. Which roots a learner sees
     * depends on that learner's own repetition state; the order follows the
     * teacher's chosen draw strategy (F4).
     *
     * @param \stdClass $quizgeist Activity.
     * @param \stdClass $assignment Locked assignment.
     * @param array $settings Canonical assignment settings.
     * @param \stdClass[] $snapshots Frozen assignment rows.
     * @param \stdClass $user Learner.
     * @param int $now Current timestamp.
     * @return array<int,array{sortindex:int,questionid:int}>
     */
    private static function attempt_positions(
        \stdClass $quizgeist,
        \stdClass $assignment,
        array $settings,
        array $snapshots,
        \stdClass $user,
        int $now
    ): array {
        if ((string)($assignment->selection ?? 'fixed') !== 'due') {
            return array_values(array_map(
                static fn(\stdClass $snapshot): array => [
                    'sortindex' => (int)$snapshot->sortindex,
                    'questionid' => (int)$snapshot->questionid,
                ],
                $snapshots
            ));
        }

        $rootids = [];
        foreach ($snapshots as $snapshot) {
            $rootid = (int)($snapshot->rootid ?? 0);
            if ($rootid > 0) {
                $rootids[$rootid] = true;
            }
        }
        $rootids = array_keys($rootids);
        if (!$rootids) {
            throw new \coding_exception(
                'A repetition assignment has no frozen question root.'
            );
        }
        $limit = (int)($settings['maxQuestions'] ?? 0);
        $timezone = \core_date::get_user_timezone_object($user->timezone ?? 99);
        $draw = schedule_service::draw(
            (int)$quizgeist->id,
            (int)$user->id,
            (string)($settings['selectionStrategy'] ?? 'sequential'),
            $limit,
            (int)$assignment->id,
            true,
            $now,
            $timezone,
            $rootids
        );
        if (!$draw) {
            // Nothing is due yet. Refusing to start would punish a learner for
            // being up to date, so the whole frozen pool is offered, ordered by
            // how close each root is to becoming due.
            $draw = schedule_service::draw(
                (int)$quizgeist->id,
                (int)$user->id,
                (string)($settings['selectionStrategy'] ?? 'sequential'),
                $limit,
                (int)$assignment->id,
                false,
                $now,
                $timezone,
                $rootids
            );
        }
        $versions = schedule_repository::current_versions(
            (int)$quizgeist->id,
            array_map(
                static fn(\stdClass $candidate): int => (int)$candidate->rootid,
                $draw
            )
        );
        $positions = [];
        foreach ($draw as $candidate) {
            $questionid = (int)($versions[(int)$candidate->rootid] ?? 0);
            if ($questionid <= 0) {
                continue;
            }
            $positions[] = [
                'sortindex' => count($positions),
                'questionid' => $questionid,
            ];
        }
        if (!$positions) {
            throw new \invalid_parameter_exception(
                'The repetition assignment currently has no playable question.'
            );
        }
        return $positions;
    }

    /**
     * Project state together with the grade aggregation shown in the overview.
     */
    private static function projected_state(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid
    ): array {
        return attempt_projector::state(
            (int)$quizgeist->id,
            (int)$user->id,
            $attemptid,
            $context
        );
    }

    /**
     * Wrap a projected attempt in the standard action response.
     */
    private static function state_response(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        int $attemptid
    ): array {
        return [
            'state' => self::projected_state(
                $quizgeist,
                $context,
                $user,
                $attemptid
            ),
        ];
    }

    /**
     * Ask Moodle to recalculate custom completion for one learner.
     *
     * @param \stdClass $cm Course module.
     * @param int $userid Learner.
     * @return void
     */
    private static function refresh_completion(
        \stdClass $cm,
        int $userid
    ): void {
        global $CFG;

        // \completion_info and COMPLETION_UNKNOWN live in
        // lib/completionlib.php, which Moodle loads on neither module nor AJAX
        // pages. Name the dependency instead of inheriting it from a caller
        // (P10-F15).
        require_once($CFG->libdir . '/completionlib.php');

        $course = get_course((int)$cm->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }

    /**
     * Build a weekly-goal DTO using the learner's local ISO week.
     *
     * @param int $quizgeistid Activity.
     * @param \stdClass $user Learner.
     * @return array
     */
    private static function goal_dto(
        int $quizgeistid,
        \stdClass $user
    ): array {
        global $DB;

        $goal = $DB->get_record(
            'quizgeist_goals',
            ['quizgeistid' => $quizgeistid, 'userid' => (int)$user->id]
        );
        [$weekstart, $weekend] = self::week_bounds($user);
        $progress = (int)$DB->count_records_sql(
            'SELECT COUNT(DISTINCT COALESCE(NULLIF(q.rootid, 0), q.id))
               FROM {quizgeist_answers} ans
               JOIN {quizgeist_attempts} a ON a.id = ans.attemptid
               JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
               JOIN {quizgeist_questions} q ON q.id = ans.questionid
              WHERE z.quizgeistid = :quizgeistid
                AND ans.userid = :userid
                AND ans.attemptid IS NOT NULL
                AND ans.answertype = :answer
                AND ans.timecreated >= :weekstart
                AND ans.timecreated < :weekend',
            [
                'quizgeistid' => $quizgeistid,
                'userid' => (int)$user->id,
                'answer' => 'answer',
                'weekstart' => $weekstart,
                'weekend' => $weekend,
            ]
        );
        $target = $goal ? (int)$goal->target : self::DEFAULT_GOAL;
        return [
            'configured' => $goal !== false,
            'target' => $target,
            'progress' => $progress,
            'percent' => min(100, (int)floor(100 * $progress / $target)),
            'weekStartMs' => $weekstart * 1000,
            'weekEndMs' => $weekend * 1000,
        ];
    }

    /**
     * Calculate local Monday boundaries without assuming a fixed DST offset.
     *
     * @param \stdClass $user Learner.
     * @return array{0:int,1:int}
     */
    private static function week_bounds(\stdClass $user): array {
        $timezone = \core_date::get_user_timezone_object(
            $user->timezone ?? 99
        );
        $now = new \DateTimeImmutable('now', $timezone);
        $monday = $now->modify('monday this week')->setTime(0, 0, 0);
        return [
            $monday->getTimestamp(),
            $monday->modify('+7 days')->getTimestamp(),
        ];
    }

    /**
     * Fetch and lock a learner-owned attempt.
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
     * Lock one activity-owned assignment.
     *
     * @param int $quizgeistid Activity.
     * @param int $assignmentid Assignment.
     * @return \stdClass
     */
    private static function locked_assignment(
        int $quizgeistid,
        int $assignmentid
    ): \stdClass {
        global $DB;

        return $DB->get_record_sql(
            'SELECT *
               FROM {quizgeist_assignments}
              WHERE id = :id
                AND quizgeistid = :quizgeistid
                    FOR UPDATE',
            ['id' => $assignmentid, 'quizgeistid' => $quizgeistid],
            MUST_EXIST
        );
    }

    /**
     * Lock one attempt question by exact version.
     *
     * @param int $attemptid Attempt.
     * @param int $questionid Exact question.
     * @return \stdClass
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
     * Lock one frozen position.
     *
     * @param int $attemptid Attempt.
     * @param int $index Index.
     * @return \stdClass
     */
    private static function locked_attempt_question_by_index(
        int $attemptid,
        int $index
    ): \stdClass {
        global $DB;

        return $DB->get_record_sql(
            'SELECT aq.id AS attemptquestionid, aq.*
               FROM {quizgeist_attempt_questions} aq
              WHERE aq.attemptid = :attemptid
                AND aq.sortindex = :sortindex
                    FOR UPDATE',
            ['attemptid' => $attemptid, 'sortindex' => $index],
            MUST_EXIST
        );
    }

    /**
     * Require a currently available assignment.
     *
     * @param \stdClass $assignment Assignment.
     * @param int $now Current timestamp.
     * @return void
     */
    private static function require_assignment_available(
        \stdClass $assignment,
        int $now
    ): void {
        if ((string)$assignment->status !== 'open'
                || ((int)$assignment->timeopen > 0
                    && (int)$assignment->timeopen > $now)) {
            throw new \invalid_parameter_exception(
                'The assignment is not open.'
            );
        }
        $settings = assignment_settings::decode(
            $assignment->settingsjson ?? null,
            (int)$assignment->timedue
        );
        if ((int)$assignment->timedue > 0
                && (int)$assignment->timedue < $now
                && !$settings['allowLate']) {
            throw new \invalid_parameter_exception(
                'The assignment deadline has passed.'
            );
        }
    }

    /**
     * Require an unfinished attempt.
     *
     * @param \stdClass $attempt Attempt.
     * @return void
     */
    private static function require_in_progress(\stdClass $attempt): void {
        if ((string)$attempt->status !== 'inprogress') {
            throw new \invalid_parameter_exception(
                'The attempt is not in progress.'
            );
        }
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
     * Require flashcard mode.
     */
    private static function require_flashcards(\stdClass $attempt): void {
        if ((string)$attempt->mode !== 'flashcards') {
            throw new \invalid_parameter_exception(
                'The attempt is not a flashcard attempt.'
            );
        }
    }

    /**
     * Require the request's optimistic state version.
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
     * Require the current navigation position.
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
     * Require the stable opaque visit token.
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
     * Encode internal state predictably.
     *
     * @param array $value Value.
     * @return string
     */
    private static function json_encode(array $value): string {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }
}
