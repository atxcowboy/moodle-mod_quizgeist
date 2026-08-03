<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Facade for the SM-2 repetition core.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\schedule;

use mod_quizgeist\local\tagging\tag_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Observes answers, draws due roots and reports repetition state.
 *
 * `observe()` is never feature-gated. An expired licence may block a new
 * repetition assignment, but it must never falsify what a learner has already
 * learnt — the scheduler is evaluation, not creation (P11_PLAN.md 2.6).
 */
final class schedule_service {

    /**
     * Record one answered question in the learner's repetition state.
     *
     * Runs inside the caller's transaction and is deliberately fault tolerant:
     * a scheduler failure must never discard an answer that has already been
     * validated and scored. The method therefore returns a boolean instead of
     * throwing, and reports through the error log.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $questionid Exact played question version.
     * @param array $question Canonical question (timelimit, options).
     * @param array $evaluation Evaluated response; see quality_mapper.
     * @param int|null $now Observation time; defaults to the wall clock.
     * @return bool Whether repetition state was written.
     */
    public static function observe(
        int $quizgeistid,
        int $userid,
        int $questionid,
        array $question,
        array $evaluation,
        ?int $now = null
    ): bool {
        try {
            return self::observe_or_fail(
                $quizgeistid,
                $userid,
                $questionid,
                $question,
                $evaluation,
                $now ?? time()
            );
        } catch (\Throwable $exception) {
            // Never write into an AJAX response body; never rethrow. The next
            // answer to the same root repairs the state on its own.
            error_log(
                '[mod_quizgeist] repetition state for user '
                . $userid
                . ' and question '
                . $questionid
                . ' could not be updated: '
                . $exception->getMessage()
            );
            return false;
        }
    }

    /**
     * The observation itself, allowed to fail loudly for tests and callers.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $questionid Exact played question version.
     * @param array $question Canonical question.
     * @param array $evaluation Evaluated response.
     * @param int $now Observation time.
     * @return bool
     */
    public static function observe_or_fail(
        int $quizgeistid,
        int $userid,
        int $questionid,
        array $question,
        array $evaluation,
        int $now
    ): bool {
        if ($quizgeistid <= 0 || $userid <= 0 || $questionid <= 0) {
            return false;
        }
        $answertype = (string)($evaluation['answerType'] ?? 'answer');
        $flashcardknown = array_key_exists('flashcardKnown', $evaluation)
            && $evaluation['flashcardKnown'] !== null
            ? (bool)$evaluation['flashcardKnown']
            : null;
        $rootid = tag_repository::root_of_question($quizgeistid, $questionid);
        if ($rootid <= 0) {
            return false;
        }
        $quality = quality_mapper::from_response(
            $answertype,
            array_key_exists('iscorrect', $evaluation)
                && $evaluation['iscorrect'] !== null
                ? (bool)$evaluation['iscorrect']
                : null,
            (float)($evaluation['quality'] ?? 1.0),
            (int)($evaluation['responseTimeMs'] ?? 0),
            (int)($question['timelimit'] ?? 0),
            self::wrote_reason($quizgeistid, $userid, $questionid, $question),
            $flashcardknown,
            (int)($evaluation['flashcardRounds'] ?? 1)
        );
        if ($quality === null) {
            return false;
        }

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $state = schedule_repository::state(
                $quizgeistid,
                $userid,
                $rootid,
                true
            );
            $fresh = sm2::fresh();
            $next = sm2::next(
                (int)($state->easiness ?? $fresh['easiness']),
                (int)($state->intervaldays ?? $fresh['intervaldays']),
                (int)($state->repetitions ?? $fresh['repetitions']),
                $quality,
                $now
            );
            $columns = [
                'easiness' => $next['easiness'],
                'intervaldays' => $next['intervaldays'],
                'repetitions' => $next['repetitions'],
                'lapses' => (int)($state->lapses ?? 0) + $next['lapseincrement'],
                'duetime' => $next['duetime'],
                'lastreviewed' => $next['lastreviewed'],
                'lastquality' => $next['lastquality'],
                'timemodified' => $now,
            ];
            if ($state === null) {
                schedule_repository::insert_state($columns + [
                    'quizgeistid' => $quizgeistid,
                    'userid' => $userid,
                    'rootid' => $rootid,
                    'timecreated' => $now,
                ]);
            } else {
                schedule_repository::update_state((int)$state->id, $columns);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return true;
    }

    /**
     * Draw the roots one learner may repeat, in the requested order.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param string $strategy Draw strategy.
     * @param int $limit Maximum roots; zero means every candidate.
     * @param int $assignmentid Assignment binding the seed; zero for none.
     * @param bool $onlydue Restrict to roots that are actually due.
     * @param int|null $now Current time.
     * @param \DateTimeZone|null $timezone Learner timezone for the day seed.
     * @param int[] $restrictroots Only consider these roots; empty means all.
     * @return \stdClass[] Ordered candidate rows.
     */
    public static function draw(
        int $quizgeistid,
        int $userid,
        string $strategy,
        int $limit = 0,
        int $assignmentid = 0,
        bool $onlydue = true,
        ?int $now = null,
        ?\DateTimeZone $timezone = null,
        array $restrictroots = []
    ): array {
        $now ??= time();
        $candidates = schedule_repository::candidate_roots(
            $quizgeistid,
            $userid,
            $now,
            $onlydue
        );
        if ($restrictroots) {
            // A repetition assignment draws from the roots its teacher froze,
            // never from everything the activity happens to contain today.
            $allowed = array_fill_keys(
                array_map('intval', $restrictroots),
                true
            );
            $candidates = array_values(array_filter(
                $candidates,
                static fn(\stdClass $candidate): bool
                    => isset($allowed[(int)$candidate->rootid])
            ));
        }
        if (!$candidates) {
            return [];
        }
        $strategy = due_selector::normalise_strategy($strategy);
        if ($strategy === due_selector::STRATEGY_SEQUENTIAL) {
            // "Sequential" for a repetition draw means "most overdue first";
            // there is no teacher-authored order to preserve here.
            return due_selector::due($candidates, $limit);
        }
        return due_selector::order(
            $strategy,
            $candidates,
            $strategy === due_selector::STRATEGY_INTERLEAVED
                ? self::topic_groups($quizgeistid)
                : [],
            due_selector::seed(
                $userid,
                due_selector::day_key($now, $timezone),
                $assignmentid
            ),
            $limit
        );
    }

    /**
     * Map every question root of one activity onto its primary topic group.
     *
     * The heaviest approved topic tag wins; a root without one lands in the
     * untagged group, which takes part in the round-robin like any other.
     *
     * @param int $quizgeistid Activity ID.
     * @param string $kind Tag family used for grouping.
     * @return array<int,string> Root ID to group key.
     */
    public static function topic_groups(
        int $quizgeistid,
        string $kind = 'topic'
    ): array {
        return self::topic_index($quizgeistid, $kind)['groups'];
    }

    /**
     * Build the root-to-group map together with the human labels of the groups.
     *
     * One pass over the tag assignments answers both questions, so a dashboard
     * never has to choose between a readable label and a second query.
     *
     * @param int $quizgeistid Activity ID.
     * @param string $kind Tag family used for grouping.
     * @return array{
     *     groups:array<int,string>,
     *     labels:array<string,array{label:string,colorKey:?string}>
     * }
     */
    public static function topic_index(
        int $quizgeistid,
        string $kind = 'topic'
    ): array {
        $groups = [];
        $labels = [];
        $weights = [];
        foreach (tag_repository::assignments_by_root($quizgeistid) as $rootid => $rows) {
            foreach ($rows as $row) {
                if ((string)$row->kind !== $kind
                        || (string)$row->status !== 'approved') {
                    continue;
                }
                $labels[(string)$row->tagkey] = [
                    'label' => (string)$row->label,
                    'colorKey' => $row->colorkey !== null && $row->colorkey !== ''
                        ? (string)$row->colorkey
                        : null,
                ];
                $weight = (int)$row->weight;
                if (!isset($weights[$rootid]) || $weight > $weights[$rootid]) {
                    $weights[$rootid] = $weight;
                    $groups[(int)$rootid] = (string)$row->tagkey;
                }
            }
        }
        return ['groups' => $groups, 'labels' => $labels];
    }

    /**
     * Summarise one learner's repetition state for a card or digest.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int|null $now Current time.
     * @return array{dueCount:int,nextDue:?int,trackedCount:int}
     */
    public static function summary(
        int $quizgeistid,
        int $userid,
        ?int $now = null
    ): array {
        return schedule_repository::due_summary(
            $quizgeistid,
            $userid,
            $now ?? time()
        );
    }

    /**
     * Build the teacher dashboard of due repetitions, grouped by topic.
     *
     * Colour is never the only marker: every group also carries its label, its
     * counts and its share, so the panel remains readable without colour
     * perception (DESIGN 7).
     *
     * @param int $quizgeistid Activity ID.
     * @param string $kind Tag family to group by.
     * @param int|null $now Current time.
     * @param int[] $userids Restrict to these learners; empty means all.
     * @return array
     */
    public static function overview(
        int $quizgeistid,
        string $kind = 'topic',
        ?int $now = null,
        array $userids = []
    ): array {
        $now ??= time();
        $rows = schedule_repository::due_by_tag($quizgeistid, $now, $kind, $userids);
        $groups = [];
        $duetotal = 0;
        $trackedtotal = 0;
        $learners = 0;
        foreach ($rows as $row) {
            $due = (int)$row->duecount;
            $duetotal += $due;
            $trackedtotal += (int)$row->trackedcount;
            $learners = max($learners, (int)$row->learnercount);
            $groups[] = [
                'tagId' => (int)$row->tagid,
                'tagKey' => (string)$row->tagkey,
                'label' => (string)$row->label,
                'untagged' => (int)$row->tagid === 0,
                'colorKey' => $row->colorkey !== null && $row->colorkey !== ''
                    ? (string)$row->colorkey
                    : null,
                'dueCount' => $due,
                'trackedCount' => (int)$row->trackedcount,
                'learnerCount' => (int)$row->learnercount,
                'averageEase' => (int)round((float)$row->averageease),
            ];
        }
        foreach ($groups as $index => $group) {
            $groups[$index]['duePercent'] = $duetotal > 0
                ? (int)round(100 * $group['dueCount'] / $duetotal)
                : 0;
        }
        // Most pressing group first; a stable label order settles ties so two
        // reloads never swap two equally urgent topics.
        usort(
            $groups,
            static fn(array $left, array $right): int
                => [$right['dueCount'], $left['label']]
                    <=> [$left['dueCount'], $right['label']]
        );
        return [
            'groups' => $groups,
            'totals' => [
                'dueCount' => $duetotal,
                'trackedCount' => $trackedtotal,
                'learnerCount' => $learners,
            ],
            'generatedAt' => $now,
        ];
    }

    /**
     * Build the learner's own due card.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int|null $now Current time.
     * @param \DateTimeZone|null $timezone Learner timezone.
     * @return array
     */
    public static function due_card(
        int $quizgeistid,
        int $userid,
        ?int $now = null,
        ?\DateTimeZone $timezone = null
    ): array {
        $now ??= time();
        $summary = self::summary($quizgeistid, $userid, $now);
        $due = self::draw(
            $quizgeistid,
            $userid,
            due_selector::STRATEGY_SEQUENTIAL,
            0,
            0,
            true,
            $now,
            $timezone
        );
        $index = self::topic_index($quizgeistid);
        $bytopic = [];
        foreach ($due as $candidate) {
            $key = (string)($index['groups'][(int)$candidate->rootid]
                ?? due_selector::UNTAGGED_GROUP);
            $bytopic[$key] = ($bytopic[$key] ?? 0) + 1;
        }
        arsort($bytopic);
        $topics = [];
        foreach ($bytopic as $key => $count) {
            $key = (string)$key;
            $topics[] = [
                'tagKey' => $key,
                'label' => (string)($index['labels'][$key]['label'] ?? ''),
                'colorKey' => $index['labels'][$key]['colorKey'] ?? null,
                'untagged' => $key === due_selector::UNTAGGED_GROUP,
                'dueCount' => (int)$count,
            ];
        }
        return [
            // Never-answered roots have no state row and are therefore not in
            // trackedCount, but they are drawn: openCount is what the learner
            // can actually start right now.
            'openCount' => count($due),
            'dueCount' => $summary['dueCount'],
            'trackedCount' => $summary['trackedCount'],
            'nextDue' => $summary['nextDue'],
            'topics' => $topics,
            'generatedAt' => $now,
        ];
    }

    /**
     * Whether a written think-moment exists for this exact question version.
     *
     * The lookup only happens for questions that actually offer the reason
     * stage, so the common case costs nothing.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $questionid Exact question version.
     * @param array $question Canonical question.
     * @return bool
     */
    private static function wrote_reason(
        int $quizgeistid,
        int $userid,
        int $questionid,
        array $question
    ): bool {
        global $DB;

        if (empty($question['options']['reasonStep'])) {
            return false;
        }
        return $DB->record_exists_sql(
            'SELECT 1
               FROM {quizgeist_answers} a
               JOIN {quizgeist_questions} q ON q.id = a.questionid
              WHERE a.userid = :userid
                AND a.questionid = :questionid
                AND a.answertype = :reason
                AND q.quizgeistid = :quizgeistid',
            [
                'userid' => $userid,
                'questionid' => $questionid,
                'reason' => 'reason',
                'quizgeistid' => $quizgeistid,
            ]
        );
    }
}
