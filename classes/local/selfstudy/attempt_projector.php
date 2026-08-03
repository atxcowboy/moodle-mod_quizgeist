<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Read model for self-study attempts.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

defined('MOODLE_INTERNAL') || die();

/**
 * Projects learner-owned state and caches immutable completed result payloads.
 */
final class attempt_projector {

    /** Cache-contract version for completed personal result projections. */
    // v3 adds the F8 closing screen; an entry cached under v2 has no
    // reviewSummary field and must not be reused.
    private const RESULT_CACHE_VERSION = 'attempt_result_v3';

    /**
     * Project an authoritative learner-owned attempt.
     *
     * Ownership is always checked before the personal cache is consulted.
     */
    public static function state(
        int $quizgeistid,
        int $userid,
        int $attemptid,
        \context_module $context
    ): array {
        $attempt = self::attempt_record(
            $quizgeistid,
            $userid,
            $attemptid,
            false
        );
        $payload = null;
        $cache = null;
        $cachekey = null;
        if ((string)$attempt->status === 'completed') {
            $cache = \cache::make('mod_quizgeist', 'liveprojection');
            $cachekey = self::cache_key($attempt, $context);
            $cached = $cache->get($cachekey);
            if (is_array($cached)
                    && (int)($cached['attemptId'] ?? 0) === $attemptid) {
                $payload = $cached;
            }
        }
        if ($payload === null) {
            $payload = self::immutable_payload($attempt, $context);
            if ($cache !== null && $cachekey !== null) {
                $cache->set($cachekey, $payload);
            }
        }

        $assignmentrecord = (object)[
            'id' => (int)$attempt->assignmentid,
            'name' => (string)$attempt->assignmentname,
            'mode' => (string)$attempt->mode,
            'status' => (string)$attempt->assignmentstatus,
            'timeopen' => (int)$attempt->assignmenttimeopen,
            'timedue' => (int)$attempt->assignmenttimedue,
            'selection' => (string)($attempt->assignmentselection ?? 'fixed'),
            'settingsjson' => $attempt->assignmentsettings ?? null,
        ];
        $assignmentsummary = self::assignment_summary(
            $assignmentrecord,
            $attempt,
            time(),
            (int)$payload['total'],
            count((array)$payload['answeredIndices']),
            (int)($attempt->attemptsused ?? $attempt->attemptnumber)
        );
        $cananswer = (string)$attempt->status === 'inprogress'
            && $assignmentsummary['available'];
        return [
            'answeredIndices' => $payload['answeredIndices'],
            'assignment' => $assignmentsummary,
            'attemptId' => (int)$attempt->id,
            'canAnswer' => $cananswer,
            'canFinish' => (string)$attempt->status === 'inprogress',
            'currentIndex' => (int)$payload['currentIndex'],
            'flashcards' => $payload['flashcards'],
            'gradeSummary' => grade_calculator::summary(
                (object)[
                    'id' => $quizgeistid,
                    'grademethod' => (string)$attempt->activitygrademethod,
                ],
                $userid
            ),
            'maxScore' => (int)$attempt->maxscore,
            'mode' => (string)$attempt->mode,
            'ownAnswer' => $payload['ownAnswer'],
            'question' => $payload['question'],
            'result' => $payload['result'],
            'results' => $payload['results'],
            'reviewSummary' => $payload['reviewSummary'],
            'score' => (int)$attempt->score,
            'serverTimeMs' => time() * 1000,
            'stateVersion' => (int)$attempt->stateversion,
            'status' => (string)$attempt->status,
            'total' => (int)$payload['total'],
        ];
    }

    /**
     * Build the immutable part of one attempt projection.
     */
    private static function immutable_payload(
        \stdClass $attempt,
        \context_module $context
    ): array {
        global $DB;

        $rows = self::attempt_questions((int)$attempt->id);
        if (!$rows) {
            throw new \coding_exception('A self-study attempt has no questions.');
        }
        $questionids = array_values(array_map(
            static fn(\stdClass $row): int => (int)$row->id,
            $rows
        ));
        $state = self::decode_attempt_state($attempt->statejson ?? null);
        $currentindex = (int)$state['currentIndex'];
        if (!isset($rows[$currentindex])) {
            $currentindex = (int)array_key_first($rows);
        }
        $answers = $DB->get_records(
            'quizgeist_answers',
            ['attemptid' => (int)$attempt->id],
            'id ASC'
        );
        $byquestion = [];
        foreach ($answers as $answer) {
            $byquestion[(int)$answer->questionid] = $answer;
        }
        $answeredindexes = [];
        foreach ($rows as $row) {
            if (in_array((string)$row->status, ['draft', 'submitted'], true)) {
                $answeredindexes[] = (int)$row->sortindex;
            }
        }

        $currentprojection = null;
        $results = [];
        if ((string)$attempt->status === 'completed') {
            foreach ($rows as $index => $row) {
                $projection = self::question_projection(
                    $attempt,
                    $row,
                    $questionids,
                    $byquestion[(int)$row->id] ?? null,
                    $context
                );
                if ((int)$index === $currentindex) {
                    $currentprojection = $projection;
                }
                if ($projection['result'] !== null) {
                    $results[] = $projection['result'];
                }
            }
        } else {
            $current = $rows[$currentindex];
            $currentprojection = self::question_projection(
                $attempt,
                $current,
                $questionids,
                $byquestion[(int)$current->id] ?? null,
                $context
            );
        }
        if ($currentprojection === null) {
            throw new \coding_exception('The current attempt position is unavailable.');
        }

        $flashcards = null;
        if ((string)$attempt->mode === 'flashcards') {
            $stacks = self::decode_flashcards($attempt->flashcardsjson ?? null);
            $current = $rows[$currentindex];
            $flashcards = [
                'known' => count(array_unique($stacks['known'])),
                'repeat' => (int)$stacks['round'] === 2
                    ? count($stacks['queue'])
                    : count($stacks['repeat']),
                'revealed' => (string)$current->status === 'revealed',
                'round' => max(0, (int)$stacks['round'] - 1),
                'total' => count($rows),
            ];
        }
        return [
            'attemptId' => (int)$attempt->id,
            'answeredIndices' => $answeredindexes,
            'currentIndex' => $currentindex,
            'flashcards' => $flashcards,
            'ownAnswer' => $currentprojection['ownAnswer'],
            'question' => $currentprojection['question'],
            'result' => $currentprojection['result'],
            'results' => $results,
            // F8: the closing screen "Alle Lösungswege". It is the only place
            // a deferred worked solution ever becomes readable.
            'reviewSummary' => review_summary::for_attempt($attempt, $rows),
            'total' => count($rows),
        ];
    }

    /**
     * Project one frozen position through the shared live presenter.
     */
    private static function question_projection(
        \stdClass $attempt,
        \stdClass $row,
        array $questionids,
        ?\stdClass $answer,
        \context_module $context
    ): array {
        $completed = (string)$attempt->status === 'completed';
        $disclose = $completed
            || (
                in_array((string)$attempt->mode, ['solo', 'practice'], true)
                && (string)$row->status === 'submitted'
            )
            || (
                (string)$attempt->mode === 'flashcards'
                && in_array((string)$row->status, ['revealed', 'submitted'], true)
            );
        $answerpayload = null;
        $iscorrect = null;
        $points = null;
        $maxpoints = null;
        if ($answer) {
            if (!in_array(
                (string)$answer->answertype,
                ['flashcard', 'scorevoid', 'view'],
                true
            )) {
                $decoded = json_decode((string)$answer->answerjson, true);
                if (is_array($decoded)) {
                    $answerpayload = $decoded;
                }
            }
            $iscorrect = $answer->iscorrect === null
                ? null
                : !empty($answer->iscorrect);
            if ((string)$answer->answertype === 'scorevoid'
                    && !review_presenter::shows_correctness($row)) {
                $iscorrect = null;
            }
            $points = (int)$answer->points;
            $maxpoints = (int)$answer->maxpoints;
        } else if ((string)$attempt->mode === 'test'
                && (string)$row->status === 'draft') {
            $decoded = json_decode((string)$row->answerjson, true);
            if (is_array($decoded)) {
                $answerpayload = $decoded;
            }
        }
        $policy = \mod_quizgeist\local\live\explanation_policy::normalise(
            $attempt->activityexplanationpolicy ?? null
        );
        $questiondto = review_presenter::present(
            $row,
            $row,
            $context,
            $questionids,
            $disclose,
            $answerpayload,
            true,
            $iscorrect,
            $points,
            $maxpoints,
            $policy
        );
        $ownanswer = $questiondto['submission']['answer'] ?? null;
        unset($questiondto['submission']);
        // F8: with `atend` or `never` question_presenter never produced an
        // explanation, so this per-question result stays free of it too.
        $explanation = (string)($questiondto['explanation'] ?? '');
        unset($questiondto['explanation']);
        $result = null;
        if ($disclose) {
            $result = [
                'answer' => $ownanswer,
                'correct' => $iscorrect,
                'explanation' => $explanation,
                'maxPoints' => $maxpoints ?? 0,
                'points' => $points ?? 0,
                'question' => $questiondto,
            ];
        }
        return [
            'question' => $questiondto,
            'ownAnswer' => $ownanswer,
            'result' => $result,
        ];
    }

    /**
     * Build a non-secret assignment summary without repeated counts when known.
     */
    public static function assignment_summary(
        \stdClass $assignment,
        ?\stdClass $attempt,
        int $now,
        ?int $total = null,
        ?int $answered = null,
        ?int $attemptsused = null
    ): array {
        global $DB;

        $settings = assignment_settings::decode(
            $assignment->settingsjson ?? null,
            (int)$assignment->timedue
        );
        $available = (string)$assignment->status === 'open'
            && ((int)$assignment->timeopen === 0
                || (int)$assignment->timeopen <= $now)
            && (
                (int)$assignment->timedue === 0
                || (int)$assignment->timedue >= $now
                || $settings['allowLate']
            );
        $total ??= (int)$DB->count_records(
            'quizgeist_assignment_questions',
            ['assignmentid' => (int)$assignment->id]
        );
        $answered ??= $attempt
            ? (int)$DB->count_records_select(
                'quizgeist_attempt_questions',
                'attemptid = :attemptid AND (status = :draft OR status = :submitted)',
                [
                    'attemptid' => (int)$attempt->id,
                    'draft' => 'draft',
                    'submitted' => 'submitted',
                ]
            )
            : 0;
        $attemptsused ??= $attempt
            ? max(1, (int)$attempt->attemptnumber)
            : 0;
        $completed = $attempt
            && (string)$attempt->status === 'completed';
        return [
            'attemptId' => $attempt ? (int)$attempt->id : null,
            'attemptNumber' => $attempt ? (int)$attempt->attemptnumber : 0,
            'attemptsUsed' => $attemptsused,
            'available' => $available,
            'canRetry' => $completed
                && $available
                && $attemptsused < (int)$settings['maxAttempts'],
            'completed' => $completed,
            'countsTowardsGrade' => $settings['countsTowardsGrade'],
            'id' => (int)$assignment->id,
            'maxAttempts' => (int)$settings['maxAttempts'],
            'mode' => (string)$assignment->mode,
            'name' => (string)$assignment->name,
            'progress' => [
                'answered' => $answered,
                'total' => $total,
            ],
            // F3/F4: die Auswahlart und die Ziehstrategie sind Teil der
            // Zuweisung. Der Client braucht beide, um den Hinweis "Thema
            // wechselt bewusst" nur dort zu zeigen, wo er zutrifft.
            'selection' => (string)($assignment->selection ?? 'fixed'),
            'selectionStrategy' => (string)$settings['selectionStrategy'],
            'status' => (string)$assignment->status,
            'timeOpenMs' => (int)$assignment->timeopen * 1000,
            'timeDueMs' => (int)$assignment->timedue * 1000,
            'overdue' => (int)$assignment->timedue > 0
                && (int)$assignment->timedue < $now,
        ];
    }

    /**
     * Fetch a joined learner-owned attempt, optionally under row lock.
     */
    public static function attempt_record(
        int $quizgeistid,
        int $userid,
        int $attemptid,
        bool $lock = false
    ): \stdClass {
        global $DB;

        return $DB->get_record_sql(
            'SELECT a.*,
                    (SELECT COUNT(1)
                       FROM {quizgeist_attempts} otherattempt
                      WHERE otherattempt.assignmentid = a.assignmentid
                        AND otherattempt.userid = a.userid
                        AND otherattempt.status IN (
                            :usedinprogress,
                            :usedcompleted
                        )) AS attemptsused,
                    z.quizgeistid,
                    m.grademethod AS activitygrademethod,
                    m.explanationpolicy AS activityexplanationpolicy,
                    z.name AS assignmentname,
                    z.status AS assignmentstatus,
                    z.timeopen AS assignmenttimeopen,
                    z.timedue AS assignmenttimedue,
                    z.timemodified AS assignmenttimemodified,
                    z.selection AS assignmentselection,
                    z.settingsjson AS assignmentsettings
               FROM {quizgeist_attempts} a
               JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
               JOIN {quizgeist} m ON m.id = z.quizgeistid
              WHERE a.id = :attemptid
                AND a.userid = :userid
                AND z.quizgeistid = :quizgeistid'
                . ($lock ? ' FOR UPDATE' : ''),
            [
                'attemptid' => $attemptid,
                'userid' => $userid,
                'quizgeistid' => $quizgeistid,
                'usedinprogress' => 'inprogress',
                'usedcompleted' => 'completed',
            ],
            MUST_EXIST
        );
    }

    /**
     * Fetch frozen attempt questions joined to exact content.
     *
     * @return \stdClass[] Indexed by zero-based sort index.
     */
    public static function attempt_questions(int $attemptid): array {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT aq.id AS attemptquestionid,
                    q.id,
                    q.quizgeistid,
                    q.rootid,
                    q.version,
                    q.sortorder,
                    q.qtype,
                    q.questiontext,
                    q.questionformat,
                    q.optionsjson,
                    q.timelimit,
                    q.pointmode,
                    q.explanation,
                    q.createdby,
                    q.timecreated,
                    q.status AS questionstatus,
                    q.timemodified AS questiontimemodified,
                    aq.attemptid,
                    aq.sortindex,
                    aq.questionid,
                    aq.visit,
                    aq.status,
                    aq.answerjson,
                    aq.round,
                    aq.timestarted,
                    aq.timesubmitted,
                    aq.timemodified
               FROM {quizgeist_attempt_questions} aq
               JOIN {quizgeist_questions} q ON q.id = aq.questionid
              WHERE aq.attemptid = :attemptid
           ORDER BY aq.sortindex ASC, aq.id ASC',
            ['attemptid' => $attemptid]
        );
        $byindex = [];
        foreach ($records as $record) {
            $byindex[(int)$record->sortindex] = $record;
        }
        ksort($byindex, SORT_NUMERIC);
        return $byindex;
    }

    /**
     * Decode the small navigation document.
     *
     * @return array{currentIndex:int}
     */
    public static function decode_attempt_state($raw): array {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $index = is_array($decoded) && is_int($decoded['currentIndex'] ?? null)
            && $decoded['currentIndex'] >= 0
            ? $decoded['currentIndex']
            : 0;
        return ['currentIndex' => $index];
    }

    /**
     * Decode a server-owned flashcard stack.
     *
     * @return array{queue:int[],repeat:int[],known:int[],missed:int[],round:int}
     */
    public static function decode_flashcards($raw): array {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new \coding_exception('A flashcard attempt has no stack state.');
        }
        $result = [];
        foreach (['queue', 'repeat', 'known', 'missed'] as $key) {
            $values = $decoded[$key] ?? [];
            if (!is_array($values) || !array_is_list($values)) {
                throw new \coding_exception('A flashcard stack is malformed.');
            }
            $normalised = [];
            foreach ($values as $value) {
                if (!is_int($value) || $value < 0) {
                    throw new \coding_exception('A flashcard stack is malformed.');
                }
                $normalised[] = $value;
            }
            $result[$key] = $normalised;
        }
        $round = $decoded['round'] ?? 1;
        $result['round'] = is_int($round) && in_array($round, [1, 2], true)
            ? $round
            : 1;
        return $result;
    }

    /**
     * Remove one known completed projection (primarily for deterministic tests).
     */
    public static function purge_attempt(
        \stdClass $attempt,
        \context_module $context
    ): void {
        \cache::make('mod_quizgeist', 'liveprojection')->delete(
            self::cache_key($attempt, $context)
        );
    }

    /**
     * Personal result-cache key, including context and language.
     */
    private static function cache_key(
        \stdClass $attempt,
        \context_module $context
    ): string {
        return implode('_', [
            self::RESULT_CACHE_VERSION,
            (int)$attempt->id,
            (int)$attempt->stateversion,
            (int)$context->id,
            // F8: the worked-solution policy is an activity setting, not an
            // attempt column. Without it in the key, switching the policy to
            // `never` would leave an already cached explanation readable.
            \mod_quizgeist\local\live\explanation_policy::normalise(
                $attempt->activityexplanationpolicy ?? null
            ),
            sha1(current_language()),
        ]);
    }
}
