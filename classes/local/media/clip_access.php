<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Who may record a clip, and who may hear one back.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\media;

defined('MOODLE_INTERNAL') || die();

/**
 * Membership rules of the clip channel, separate from the storage rules.
 *
 * The capability alone is not enough. `mod/quizgeist:recordaudio` says a person
 * may record IN THIS ACTIVITY; it does not say they are currently taking part
 * in anything. Without the membership test the endpoint would let any enrolled
 * learner deposit audio in an activity that is not running, which is both a
 * storage question and a data-protection one.
 */
final class clip_access {

    /** Live session states in which recording makes sense. */
    private const LIVE_STATES = ['lobby', 'question', 'reveal', 'leaderboard', 'running'];

    /**
     * Whether one user is currently taking part in a way that produces clips.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @param string $purpose Requested clip purpose.
     * @return bool
     */
    public static function may_record(int $quizgeistid, int $userid, string $purpose): bool {
        global $DB;

        if ($quizgeistid <= 0 || $userid <= 0 || !clip_limits::is_purpose($purpose)) {
            return false;
        }
        // `speaking` is a self-study mode, so it never comes from a live
        // session; `answer` and `reason` may come from either side.
        if ($purpose !== 'speaking' && self::in_live_session($quizgeistid, $userid)) {
            return true;
        }
        return self::in_open_attempt($quizgeistid, $userid);
    }

    /**
     * Whether a viewer may hear one specific clip.
     *
     * Two doors, deliberately narrow ones:
     *  - the owner may always hear their own recording;
     *  - a teacher may hear it exactly when they may see the report it belongs
     *    to, which is `viewreports` — the capability that already governs who
     *    gets to look at other people's answers.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $clip Clip row.
     * @param int $userid Viewing user.
     * @return bool
     */
    public static function may_listen(\context_module $context, \stdClass $clip, int $userid): bool {
        if ((int)$clip->userid === $userid) {
            return true;
        }
        return has_capability('mod/quizgeist:viewreports', $context, $userid);
    }

    /**
     * Whether one user is a player in a session of this activity that is live.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return bool
     */
    private static function in_live_session(int $quizgeistid, int $userid): bool {
        global $DB;

        [$statesql, $stateparams] = $DB->get_in_or_equal(self::LIVE_STATES, SQL_PARAMS_NAMED, 'ls');
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {quizgeist_players} p
               JOIN {quizgeist_sessions} s ON s.id = p.sessionid
              WHERE p.userid = :userid
                AND s.quizgeistid = :quizgeistid
                AND s.status {$statesql}",
            $stateparams + ['userid' => $userid, 'quizgeistid' => $quizgeistid]
        );
    }

    /**
     * Whether one user has a self-study attempt in progress in this activity.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return bool
     */
    private static function in_open_attempt(int $quizgeistid, int $userid): bool {
        global $DB;

        return $DB->record_exists_sql(
            'SELECT 1
               FROM {quizgeist_attempts} at
               JOIN {quizgeist_assignments} a ON a.id = at.assignmentid
              WHERE at.userid = :userid
                AND a.quizgeistid = :quizgeistid
                AND at.status = :status',
            [
                'userid' => $userid,
                'quizgeistid' => $quizgeistid,
                'status' => 'inprogress',
            ]
        );
    }
}
