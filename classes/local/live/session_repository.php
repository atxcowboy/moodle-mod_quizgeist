<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Relational access for live sessions.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps live-row lookups and lock ordering out of the state machine.
 */
final class session_repository {

    /**
     * Request-local canonical state and frozen snapshot rows.
     *
     * The key contains the durable version and the mirrored JSON. A mutation
     * in the same request therefore cannot reuse an older projection. Locked
     * reads deliberately bypass this memo.
     *
     * @var array<string, array{state:array,questions:array<int,\stdClass>}>
     */
    private static array $statesnapshots = [];

    /**
     * Clear request-local projections for a simulated next request.
     *
     * Normal web requests never need this: PHP resets static state at request
     * shutdown. Long-running CLI workers and acceptance probes can call it
     * between independently measured operations.
     */
    public static function reset_request_cache(): void {
        self::$statesnapshots = [];
    }

    /**
     * Load and optionally lock one session.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $sessionid Session ID.
     * @param bool $forupdate Whether to lock the row.
     * @return \stdClass
     */
    public static function session(
        int $quizgeistid,
        int $sessionid,
        bool $forupdate = false
    ): \stdClass {
        global $DB;

        if ($sessionid <= 0) {
            throw new \invalid_parameter_exception('sessionId is invalid.');
        }
        if (!$forupdate) {
            return $DB->get_record('quizgeist_sessions', [
                'id' => $sessionid,
                'quizgeistid' => $quizgeistid,
            ], '*', MUST_EXIST);
        }
        return $DB->get_record_sql(
            'SELECT *
               FROM {quizgeist_sessions}
              WHERE id = :id
                AND quizgeistid = :quizgeistid
                    FOR UPDATE',
            ['id' => $sessionid, 'quizgeistid' => $quizgeistid],
            MUST_EXIST
        );
    }

    /**
     * Load and optionally lock one joined player.
     *
     * @param int $sessionid Session ID.
     * @param int $userid Moodle user ID.
     * @param bool $forupdate Whether to lock the row.
     * @return \stdClass
     */
    public static function player(
        int $sessionid,
        int $userid,
        bool $forupdate = false
    ): \stdClass {
        global $DB;

        if (!$forupdate) {
            return $DB->get_record('quizgeist_players', [
                'sessionid' => $sessionid,
                'userid' => $userid,
            ], '*', MUST_EXIST);
        }
        return $DB->get_record_sql(
            'SELECT *
               FROM {quizgeist_players}
              WHERE sessionid = :sessionid
                AND userid = :userid
                    FOR UPDATE',
            ['sessionid' => $sessionid, 'userid' => $userid],
            MUST_EXIST
        );
    }

    /**
     * Load all players in stable lobby order.
     *
     * @param int $sessionid Session ID.
     * @return \stdClass[]
     */
    public static function players(int $sessionid): array {
        global $DB;

        return array_values($DB->get_records(
            'quizgeist_players',
            ['sessionid' => $sessionid],
            'timejoined ASC, id ASC'
        ));
    }

    /**
     * Count players without hydrating every player row.
     *
     * @param int $sessionid Session ID.
     * @return int
     */
    public static function player_count(int $sessionid): int {
        global $DB;

        return $DB->count_records('quizgeist_players', ['sessionid' => $sessionid]);
    }

    /**
     * Load one exact question version, archived or active.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $questionid Concrete question ID.
     * @return \stdClass
     */
    public static function question(int $quizgeistid, int $questionid): \stdClass {
        global $DB;

        return $DB->get_record('quizgeist_questions', [
            'id' => $questionid,
            'quizgeistid' => $quizgeistid,
        ], '*', MUST_EXIST);
    }

    /**
     * Load the frozen question row at one session position.
     *
     * @param int $sessionid Session ID.
     * @param int $sortindex Zero-based position.
     * @param bool $forupdate Whether to lock the row.
     * @return \stdClass
     */
    public static function session_question(
        int $sessionid,
        int $sortindex,
        bool $forupdate = false
    ): \stdClass {
        global $DB;

        if (!$forupdate) {
            return $DB->get_record('quizgeist_session_questions', [
                'sessionid' => $sessionid,
                'sortindex' => $sortindex,
            ], '*', MUST_EXIST);
        }
        return $DB->get_record_sql(
            'SELECT *
               FROM {quizgeist_session_questions}
              WHERE sessionid = :sessionid
                AND sortindex = :sortindex
                    FOR UPDATE',
            ['sessionid' => $sessionid, 'sortindex' => $sortindex],
            MUST_EXIST
        );
    }

    /**
     * Load all frozen questions in their immutable order.
     *
     * @param int $sessionid Session ID.
     * @return \stdClass[]
     */
    public static function session_questions(int $sessionid): array {
        global $DB;

        return array_values($DB->get_records(
            'quizgeist_session_questions',
            ['sessionid' => $sessionid],
            'sortindex ASC, id ASC'
        ));
    }

    /**
     * Resolve and validate the relational current question/visit row.
     *
     * @param \stdClass $session Session row.
     * @param array $state Canonical state.
     * @param bool $forupdate Whether to lock the snapshot row.
     * @return \stdClass|null
     */
    public static function current_session_question(
        \stdClass $session,
        array $state,
        bool $forupdate = false
    ): ?\stdClass {
        $index = (int)$state['currentIndex'];
        if ($index < 0) {
            if (!empty($session->currentquestionid)) {
                throw new \coding_exception(
                    'Live currentquestionid is set while the snapshot is in the lobby.'
                );
            }
            return null;
        }
        if ($forupdate) {
            $snapshot = self::session_question((int)$session->id, $index, true);
        } else {
            $memo = self::state_snapshot($session);
            $snapshot = $memo['questions'][$index] ?? null;
            if (!$snapshot instanceof \stdClass) {
                throw new \coding_exception(
                    'Live relational question snapshot is missing.'
                );
            }
        }
        $stateid = session_state::current_question_id($state);
        if ($stateid === null
                || (int)$snapshot->questionid !== $stateid
                || (int)$session->currentquestionid !== $stateid) {
            throw new \coding_exception(
                'Live relational question snapshot is inconsistent.'
            );
        }
        $statevisit = (string)$state['questionToken'];
        $rowvisit = (string)($snapshot->visit ?? '');
        if ($statevisit === '' || $rowvisit === '' || !hash_equals($statevisit, $rowvisit)) {
            throw new \coding_exception('Live relational visit is inconsistent.');
        }
        return $snapshot;
    }

    /**
     * Decode and validate the PII-free state mirror once per request/version.
     *
     * @param \stdClass $session Session row.
     * @return array{state:array,questions:array<int,\stdClass>}
     */
    public static function state_snapshot(\stdClass $session): array {
        $key = implode(':', [
            (int)$session->id,
            (int)$session->stateversion,
            hash('sha256', (string)($session->statejson ?? '')),
        ]);
        if (isset(self::$statesnapshots[$key])) {
            return self::$statesnapshots[$key];
        }

        $state = session_state::decode($session->statejson ?? null);
        $rows = self::session_questions((int)$session->id);
        $questions = [];
        foreach ($rows as $row) {
            $index = (int)$row->sortindex;
            if ($index < 0 || isset($questions[$index])) {
                throw new \coding_exception(
                    'Live relational question snapshot order is invalid.'
                );
            }
            $questions[$index] = $row;
        }
        ksort($questions, SORT_NUMERIC);
        $snapshotids = array_map(
            static fn(\stdClass $snapshot): int => (int)$snapshot->questionid,
            array_values($questions)
        );
        if ($snapshotids !== array_map('intval', $state['questionIds'])) {
            throw new \coding_exception(
                'Live relational snapshot does not match its state mirror.'
            );
        }

        self::$statesnapshots[$key] = [
            'state' => $state,
            'questions' => $questions,
        ];
        return self::$statesnapshots[$key];
    }

    /**
     * Find one immutable answer by its indexed visit identity.
     *
     * @param int $sessionid Session ID.
     * @param int $playerid Player ID.
     * @param int $questionid Concrete question ID.
     * @param string $visit Visit identity.
     * @return \stdClass|null
     */
    public static function player_answer(
        int $sessionid,
        int $playerid,
        int $questionid,
        string $visit
    ): ?\stdClass {
        global $DB;

        return $DB->get_record('quizgeist_answers', [
            'sessionid' => $sessionid,
            'playerid' => $playerid,
            'questionid' => $questionid,
            'visit' => $visit,
            'answertype' => 'answer',
        ], '*', IGNORE_MISSING) ?: null;
    }

    /**
     * Find one idempotent type-specific live submission.
     *
     * @param int $sessionid Session.
     * @param int|null $playerid Player, or null for a host interaction.
     * @param int $questionid Question.
     * @param string $visit Visit.
     * @param string $answertype Type.
     * @param string|null $submissionkey Optional client key.
     * @param bool $latest Return the newest matching row.
     * @return \stdClass|null
     */
    public static function submission(
        int $sessionid,
        ?int $playerid,
        int $questionid,
        string $visit,
        string $answertype,
        ?string $submissionkey = null,
        bool $latest = false
    ): ?\stdClass {
        global $DB;

        $conditions = [
            'sessionid' => $sessionid,
            'playerid' => $playerid,
            'questionid' => $questionid,
            'visit' => $visit,
            'answertype' => $answertype,
        ];
        if ($submissionkey !== null) {
            $conditions['submissionkey'] = $submissionkey;
        }
        $records = $DB->get_records(
            'quizgeist_answers',
            $conditions,
            $latest ? 'id DESC' : 'id ASC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }
}
