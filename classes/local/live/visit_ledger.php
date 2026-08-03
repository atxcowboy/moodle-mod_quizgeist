<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Visit-scoped answer and score bookkeeping.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns indexed visit queries, masking and append-only score compensation.
 */
final class visit_ledger {

    /**
     * Count answers for the exact current visit.
     *
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @return int
     */
    public static function answer_count(\stdClass $session, array $state): int {
        return self::response_count($session, $state, ['answer']);
    }

    /**
     * Count distinct responding players for the current stage.
     *
     * @param \stdClass $session Session.
     * @param array $state State.
     * @param string[] $types Stage-owned answer types.
     * @return int
     */
    public static function response_count(
        \stdClass $session,
        array $state,
        array $types
    ): int {
        global $DB;

        $snapshot = session_repository::current_session_question($session, $state);
        if ($snapshot === null || !$types) {
            return 0;
        }
        [$typesql, $params] = $DB->get_in_or_equal(
            array_values($types),
            SQL_PARAMS_NAMED,
            'response'
        );
        return (int)$DB->count_records_sql(
            "SELECT COUNT(DISTINCT playerid)
               FROM {quizgeist_answers}
              WHERE sessionid = :sessionid
                AND questionid = :questionid
                AND visit = :visit
                AND playerid IS NOT NULL
                AND answertype {$typesql}",
            [
                'sessionid' => (int)$session->id,
                'questionid' => (int)$snapshot->questionid,
                'visit' => (string)$snapshot->visit,
            ] + $params
        );
    }

    /**
     * Latest append-only row ID for aggregate cache invalidation.
     *
     * @param \stdClass $session Session.
     * @param array $state State.
     * @param string[] $types Aggregate-owned answer types.
     * @return int
     */
    public static function answer_revision(
        \stdClass $session,
        array $state,
        array $types
    ): int {
        global $DB;

        $snapshot = session_repository::current_session_question($session, $state);
        if ($snapshot === null || !$types) {
            return 0;
        }
        [$typesql, $params] = $DB->get_in_or_equal(
            array_values($types),
            SQL_PARAMS_NAMED,
            'revision'
        );
        return (int)$DB->get_field_sql(
            "SELECT COALESCE(MAX(id), 0)
               FROM {quizgeist_answers}
              WHERE sessionid = :sessionid
                AND questionid = :questionid
                AND visit = :visit
                AND answertype {$typesql}",
            [
                'sessionid' => (int)$session->id,
                'questionid' => (int)$snapshot->questionid,
                'visit' => (string)$snapshot->visit,
            ] + $params
        );
    }

    /**
     * Load visit-scoped immutable answers in insertion order.
     *
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @param string[] $types Answer types.
     * @param bool $forupdate Whether to lock matching rows.
     * @return \stdClass[]
     */
    public static function answers(
        \stdClass $session,
        array $state,
        array $types = ['answer'],
        bool $forupdate = false
    ): array {
        global $DB;

        $snapshot = session_repository::current_session_question(
            $session,
            $state,
            $forupdate
        );
        if ($snapshot === null || !$types) {
            return [];
        }
        [$typesql, $typeparams] = $DB->get_in_or_equal(
            array_values($types),
            SQL_PARAMS_NAMED,
            'visittype'
        );
        $sql = "SELECT *
                  FROM {quizgeist_answers}
                 WHERE sessionid = :sessionid
                   AND questionid = :questionid
                   AND visit = :visit
                   AND answertype {$typesql}
              ORDER BY id ASC";
        if ($forupdate) {
            $sql .= ' FOR UPDATE';
        }
        return array_values($DB->get_records_sql($sql, [
            'sessionid' => (int)$session->id,
            'questionid' => (int)$snapshot->questionid,
            'visit' => (string)$snapshot->visit,
        ] + $typeparams));
    }

    /**
     * Normalise authoritative reference answers for a strategy submission.
     *
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @param string[] $types Referenced answer types declared by the policy.
     * @param bool $forupdate Whether to lock matching rows.
     * @return array<int, array{id: int, answerType: string, payload: array}>
     */
    public static function submission_references(
        \stdClass $session,
        array $state,
        array $types,
        bool $forupdate = false
    ): array {
        $references = [];
        foreach (self::answers($session, $state, $types, $forupdate) as $answer) {
            try {
                $payload = json_decode(
                    (string)$answer->answerjson,
                    true,
                    128,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $exception) {
                throw new \coding_exception(
                    'Persisted live reference answer is invalid.',
                    $exception->getMessage()
                );
            }
            if (!is_array($payload) || array_is_list($payload)) {
                throw new \coding_exception(
                    'Persisted live reference answer is invalid.'
                );
            }
            $references[] = [
                'id' => (int)$answer->id,
                'answerType' => (string)$answer->answertype,
                'payload' => $payload,
            ];
        }
        return $references;
    }

    /**
     * Current-visit score deltas by player.
     *
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @return array<int, int>
     */
    public static function answer_deltas(\stdClass $session, array $state): array {
        $deltas = [];
        foreach (self::answers($session, $state, ['answer', 'scorevoid']) as $record) {
            if ($record->playerid === null) {
                continue;
            }
            $playerid = (int)$record->playerid;
            $deltas[$playerid] = (int)($deltas[$playerid] ?? 0)
                + (int)$record->points;
        }
        return $deltas;
    }

    /**
     * Find one player's answer for the exact current visit.
     *
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @param int $playerid Player ID.
     * @return \stdClass|null
     */
    public static function player_answer(
        \stdClass $session,
        array $state,
        int $playerid
    ): ?\stdClass {
        $snapshot = session_repository::current_session_question($session, $state);
        if ($snapshot === null) {
            return null;
        }
        return session_repository::player_answer(
            (int)$session->id,
            $playerid,
            (int)$snapshot->questionid,
            (string)$snapshot->visit
        );
    }

    /**
     * Find the latest current-stage response for one player.
     *
     * @param \stdClass $session Session.
     * @param array $state State.
     * @param int $playerid Player.
     * @param string[] $types Stage-owned answer types.
     * @return \stdClass|null
     */
    public static function player_response(
        \stdClass $session,
        array $state,
        int $playerid,
        array $types
    ): ?\stdClass {
        global $DB;

        $snapshot = session_repository::current_session_question($session, $state);
        if ($snapshot === null || !$types) {
            return null;
        }
        [$typesql, $params] = $DB->get_in_or_equal(
            array_values($types),
            SQL_PARAMS_NAMED,
            'playerresponse'
        );
        $record = $DB->get_record_sql(
            "SELECT *
               FROM {quizgeist_answers}
              WHERE sessionid = :sessionid
                AND playerid = :playerid
                AND questionid = :questionid
                AND visit = :visit
                AND answertype {$typesql}
           ORDER BY id DESC",
            [
                'sessionid' => (int)$session->id,
                'playerid' => $playerid,
                'questionid' => (int)$snapshot->questionid,
                'visit' => (string)$snapshot->visit,
            ] + $params,
            IGNORE_MULTIPLE
        );
        return $record ?: null;
    }

    /**
     * Mask one player's current answer back to its pre-answer baseline.
     *
     * @param \stdClass $player Persisted player.
     * @param \stdClass|null $answer Current answer.
     * @return \stdClass Projected player.
     */
    public static function mask_player(
        \stdClass $player,
        ?\stdClass $answer
    ): \stdClass {
        $copy = clone $player;
        if ($answer === null) {
            return $copy;
        }
        $baseline = self::answer_baseline($answer);
        $copy->score = $baseline['score'];
        $copy->streak = $baseline['streak'];
        return $copy;
    }

    /**
     * Mask every current-visit answer in a host projection.
     *
     * @param \stdClass[] $players Persisted players.
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @return \stdClass[]
     */
    public static function mask_players(
        array $players,
        \stdClass $session,
        array $state
    ): array {
        $projected = [];
        foreach ($players as $player) {
            $projected[(int)$player->id] = clone $player;
        }
        foreach (self::answers($session, $state) as $answer) {
            $playerid = (int)($answer->playerid ?? 0);
            if ($playerid <= 0 || !isset($projected[$playerid])) {
                continue;
            }
            $baseline = self::answer_baseline($answer);
            $projected[$playerid]->score = $baseline['score'];
            $projected[$playerid]->streak = $baseline['streak'];
        }
        return array_values($projected);
    }

    /**
     * Remove score/streak effects when an unresolved visit is left.
     *
     * @param \stdClass $session Locked session.
     * @param array $state Canonical state before the transition.
     * @return void
     */
    public static function neutralise_unrevealed(
        \stdClass $session,
        array $state
    ): void {
        $snapshot = session_repository::current_session_question(
            $session,
            $state,
            true
        );
        if ($snapshot === null || (string)$snapshot->visit === '') {
            return;
        }
        $visit = (string)$snapshot->visit;
        $resolvedvisit = (string)($snapshot->resolvedvisit ?? '');
        if ($resolvedvisit !== '' && hash_equals($resolvedvisit, $visit)) {
            throw new \coding_exception('A resolved visit cannot be neutralised.');
        }

        self::neutralise_snapshot($session, $snapshot);
    }

    /**
     * Fail closed for every unresolved visit in one terminal session.
     *
     * Restore deliberately turns live sessions into terminal history. Any
     * points written while an answer was still hidden therefore have to be
     * compensated before that history can expose a final ranking. Relational
     * visit state, rather than question type or the mutable JSON phase,
     * determines which score effects are provisional. Already skipped visits
     * are left alone because their compensation may precede later mature
     * scores; reapplying their old baseline would erase those later points.
     *
     * Processing newest visits first also repairs a malformed legacy session
     * with more than one unresolved visit: the earliest score baseline wins.
     *
     * @param int $sessionid Restored or otherwise terminal session ID.
     * @return int Number of unresolved visit rows neutralised.
     */
    public static function neutralise_unresolved_visits(int $sessionid): int {
        global $DB;

        if ($sessionid <= 0) {
            throw new \invalid_parameter_exception('sessionId is invalid.');
        }
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $session = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_sessions}
                  WHERE id = :id
                        FOR UPDATE',
                ['id' => $sessionid],
                MUST_EXIST
            );
            if (!in_array((string)$session->status, ['ended', 'aborted'], true)) {
                throw new \coding_exception(
                    'Only terminal sessions may neutralise all unresolved visits.'
                );
            }

            $snapshots = $DB->get_records_sql(
                'SELECT *
                   FROM {quizgeist_session_questions}
                  WHERE sessionid = :sessionid
                    AND visit IS NOT NULL
               ORDER BY sortindex DESC, id DESC
                     FOR UPDATE',
                ['sessionid' => $sessionid]
            );
            $neutralised = 0;
            foreach ($snapshots as $snapshot) {
                $visit = (string)($snapshot->visit ?? '');
                $visitstate = (string)($snapshot->visitstate ?? '');
                if ($visit === ''
                        || !in_array($visitstate, ['active', 'pending'], true)) {
                    continue;
                }
                self::neutralise_snapshot($session, $snapshot);
                $neutralised++;
            }
            $transaction->allow_commit();
            return $neutralised;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Compensate the answer rows and player totals for one exact visit.
     *
     * @param \stdClass $session Locked terminal or live session.
     * @param \stdClass $snapshot Locked relational visit row.
     * @return void
     */
    private static function neutralise_snapshot(
        \stdClass $session,
        \stdClass $snapshot
    ): void {
        global $DB;

        $visit = (string)($snapshot->visit ?? '');
        if ($visit === '') {
            return;
        }
        [$typesql, $typeparams] = $DB->get_in_or_equal(
            ['answer', 'scorevoid'],
            SQL_PARAMS_NAMED,
            'neutraltype'
        );
        $records = array_values($DB->get_records_sql(
            "SELECT *
               FROM {quizgeist_answers}
              WHERE sessionid = :sessionid
                AND questionid = :questionid
                AND visit = :visit
                AND answertype {$typesql}
           ORDER BY id ASC
                 FOR UPDATE",
            [
                'sessionid' => (int)$session->id,
                'questionid' => (int)$snapshot->questionid,
                'visit' => $visit,
            ] + $typeparams
        ));
        $voidedplayers = [];
        $answers = [];
        foreach ($records as $record) {
            $playerid = (int)($record->playerid ?? 0);
            if ($playerid <= 0) {
                continue;
            }
            if ((string)$record->answertype === 'scorevoid') {
                $voidedplayers[$playerid] = true;
            } else {
                $answers[$playerid] = $record;
            }
        }

        ksort($answers, SORT_NUMERIC);
        foreach ($answers as $playerid => $answer) {
            $baseline = self::answer_baseline($answer);
            if (!isset($voidedplayers[$playerid])) {
                $DB->insert_record('quizgeist_answers', (object)[
                    'sessionid' => (int)$session->id,
                    'playerid' => $playerid,
                    'attemptid' => null,
                    'questionid' => (int)$snapshot->questionid,
                    'userid' => (int)$answer->userid,
                    'answertype' => 'scorevoid',
                    'visit' => $visit,
                    'answerjson' => json_encode([
                        'questionToken' => $visit,
                        'reason' => 'skipped',
                    ], JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR),
                    'iscorrect' => null,
                    'points' => -(int)$answer->points,
                    'responsetime' => 0,
                    'timecreated' => time(),
                ]);
            }
            $player = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_players}
                  WHERE id = :id
                    AND sessionid = :sessionid
                    FOR UPDATE',
                ['id' => $playerid, 'sessionid' => (int)$session->id],
                MUST_EXIST
            );
            $DB->update_record('quizgeist_players', (object)[
                'id' => (int)$player->id,
                'score' => $baseline['score'],
                'streak' => $baseline['streak'],
                'timemodified' => time(),
            ]);
        }

        $DB->update_record('quizgeist_session_questions', (object)[
            'id' => (int)$snapshot->id,
            'visitstate' => 'skipped',
        ]);
    }

    /**
     * Decode mandatory server-owned pre-answer values.
     *
     * @param \stdClass $answer Answer row.
     * @return array{score:int,streak:int}
     */
    private static function answer_baseline(\stdClass $answer): array {
        $decoded = json_decode((string)$answer->answerjson, true);
        $score = self::non_negative_integer(
            is_array($decoded) ? ($decoded['scoreBefore'] ?? null) : null
        );
        $streak = self::non_negative_integer(
            is_array($decoded) ? ($decoded['streakBefore'] ?? null) : null
        );
        if ($score === null || $streak === null) {
            throw new \coding_exception(
                'Live answer is missing its server-owned score baseline.'
            );
        }
        return ['score' => $score, 'streak' => $streak];
    }

    /**
     * Parse a non-negative integer without PHP numeric coercion.
     *
     * @param mixed $value Raw value.
     * @return int|null
     */
    private static function non_negative_integer($value): ?int {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return (int)$value;
        }
        return null;
    }
}
