<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Close abandoned live sessions.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Aborts lobbies and rounds whose state has not changed for a safe interval.
 */
final class close_stale_sessions extends \core\task\scheduled_task {

    /** Maximum idle time for a lobby. */
    private const LOBBY_MAX_AGE = 4 * HOURSECS;

    /** Maximum idle time for a running round. */
    private const RUNNING_MAX_AGE = 12 * HOURSECS;

    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:closestalesessions', 'mod_quizgeist');
    }

    /**
     * Abort every session that is still stale under its row lock.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $ambienttransaction = $DB->is_transaction_started();
        $candidates = $DB->get_fieldset_sql(
            'SELECT id
               FROM {quizgeist_sessions}
              WHERE (status = :lobby AND timemodified < :lobbycutoff)
                 OR (
                        status IN (:question, :reveal, :scoreboard, :podium)
                    AND timemodified < :runningcutoff
                 )
           ORDER BY id ASC',
            [
                'lobby' => 'lobby',
                'lobbycutoff' => $now - self::LOBBY_MAX_AGE,
                'question' => 'question',
                'reveal' => 'reveal',
                'scoreboard' => 'scoreboard',
                'podium' => 'podium',
                'runningcutoff' => $now - self::RUNNING_MAX_AGE,
            ]
        );

        foreach ($candidates as $sessionid) {
            try {
                $session = $this->abort_if_still_stale(
                    (int)$sessionid,
                    $now
                );
                if ($session !== null) {
                    $this->trigger_ended_event($session);
                }
            } catch (\Throwable $exception) {
                if ($ambienttransaction) {
                    // No candidate-level rollback is available while a caller
                    // owns the transaction, so partial work must not be hidden.
                    throw $exception;
                }
                mtrace(
                    'mod_quizgeist: stale session '
                    . (int)$sessionid
                    . ' could not be aborted: '
                    . $exception->getMessage()
                );
            }
        }
    }

    /**
     * Recheck and abort one candidate under its session-row lock.
     *
     * @param int $sessionid Session ID.
     * @param int $now Shared task timestamp.
     * @return \stdClass|null Updated session, or null after a concurrent change.
     */
    private function abort_if_still_stale(
        int $sessionid,
        int $now
    ): ?\stdClass {
        global $DB;

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $session = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_sessions}
                  WHERE id = :id
                        FOR UPDATE',
                ['id' => $sessionid],
                IGNORE_MISSING
            );
            if (!$session || !$this->is_stale($session, $now)) {
                $transaction->allow_commit();
                return null;
            }

            $update = (object)[
                'id' => (int)$session->id,
                'status' => 'aborted',
                'joincode' => null,
                'stateversion' => (int)$session->stateversion + 1,
                'timeended' => $now,
                'timemodified' => $now,
            ];
            $state = null;
            try {
                $state = \mod_quizgeist\local\live\session_state::decode(
                    $session->statejson ?? null
                );
            } catch (\invalid_parameter_exception $exception) {
                // Preserve malformed historical state for diagnosis. The
                // authoritative relational snapshot remains available.
            }

            if ($state !== null) {
                if ((string)$session->status === 'question') {
                    // Match a manual move away from an unresolved question:
                    // append scorevoid rows and restore every player baseline
                    // before the session becomes terminal.
                    \mod_quizgeist\local\live\visit_ledger::neutralise_unrevealed(
                        $session,
                        $state
                    );
                }
                $state = \mod_quizgeist\local\live\session_state::mark_phase(
                    $state,
                    $now * 1000
                );
                $update->statejson =
                    \mod_quizgeist\local\live\session_state::encode($state);
            }

            if ((string)$session->status === 'question' && $state === null) {
                // A malformed legacy state cannot be reconciled through the
                // ledger, but its active relational visit must still become
                // explicitly non-scorable as the stale session is aborted.
                $params = [
                    'sessionid' => (int)$session->id,
                    'active' => 'active',
                ];
                $where = 'sessionid = :sessionid AND visitstate = :active';
                if (!empty($session->currentquestionid)) {
                    $params['questionid'] = (int)$session->currentquestionid;
                    $where .= ' AND questionid = :questionid';
                }
                $DB->set_field_select(
                    'quizgeist_session_questions',
                    'visitstate',
                    'skipped',
                    $where,
                    $params
                );
            }

            $DB->update_record('quizgeist_sessions', $update);
            $session = (object)array_merge((array)$session, (array)$update);
            $transaction->allow_commit();
            return $session;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Whether a locked session still satisfies its phase-specific cutoff.
     *
     * @param \stdClass $session Session row.
     * @param int $now Shared task timestamp.
     * @return bool
     */
    private function is_stale(\stdClass $session, int $now): bool {
        if ((string)$session->status === 'lobby') {
            return (int)$session->timemodified
                < $now - self::LOBBY_MAX_AGE;
        }
        return in_array(
            (string)$session->status,
            ['question', 'reveal', 'scoreboard', 'podium'],
            true
        ) && (int)$session->timemodified < $now - self::RUNNING_MAX_AGE;
    }

    /**
     * Emit the normal lifecycle event after the abort commit is durable.
     *
     * @param \stdClass $session Updated session row.
     * @return void
     */
    private function trigger_ended_event(\stdClass $session): void {
        $cm = get_coursemodule_from_instance(
            'quizgeist',
            (int)$session->quizgeistid,
            0,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return;
        }
        $context = \context_module::instance((int)$cm->id, IGNORE_MISSING);
        if (!$context) {
            return;
        }
        $eventdata = [
            'objectid' => (int)$session->id,
            'context' => $context,
        ];
        if (!empty($session->hostuserid)) {
            $eventdata['userid'] = (int)$session->hostuserid;
        }
        $event = \mod_quizgeist\event\session_ended::create($eventdata);
        $event->add_record_snapshot('quizgeist_sessions', $session);
        $event->trigger();
    }
}
