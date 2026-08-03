<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Bulk-only persistence reads for the F7 question workshop.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\workshop;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads submissions and their peer ratings without N+1 queries.
 *
 * The submission hangs on the question ROOT (unique index root_uix), so it
 * survives every later edit of the question exactly like a tag or a scheduler
 * state (P11_PLAN.md 2.3).
 */
final class workshop_repository {

    /** States in which a submission has not yet been released. */
    public const PENDING_STATES = ['submitted', 'revising'];

    /**
     * Whether a question root is an undecided workshop submission.
     *
     * This is the guard that makes acceptance criterion 5 hold through the
     * real path: an ordinary editor autosave may complete such a question, but
     * it must not thereby make it playable.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @return bool
     */
    public static function root_is_pending(int $quizgeistid, int $rootid): bool {
        global $DB;

        if ($quizgeistid <= 0 || $rootid <= 0) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal(
            self::PENDING_STATES,
            SQL_PARAMS_NAMED,
            'pendingstate'
        );
        $params['pendingquiz'] = $quizgeistid;
        $params['pendingroot'] = $rootid;
        return $DB->record_exists_select(
            'quizgeist_workshop',
            "quizgeistid = :pendingquiz
               AND rootid = :pendingroot
               AND state {$insql}",
            $params
        );
    }

    /**
     * Load one submission by ID, bound to its activity.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $workshopid Submission ID.
     * @return \stdClass|null
     */
    public static function submission(int $quizgeistid, int $workshopid): ?\stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $workshopid <= 0) {
            return null;
        }
        $record = $DB->get_record('quizgeist_workshop', [
            'id' => $workshopid,
            'quizgeistid' => $quizgeistid,
        ]);
        return $record === false ? null : $record;
    }

    /**
     * Load the submission of one question root.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @return \stdClass|null
     */
    public static function submission_for_root(int $quizgeistid, int $rootid): ?\stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $rootid <= 0) {
            return null;
        }
        $record = $DB->get_record('quizgeist_workshop', [
            'quizgeistid' => $quizgeistid,
            'rootid' => $rootid,
        ]);
        return $record === false ? null : $record;
    }

    /**
     * List submissions of one activity, newest first.
     *
     * @param int $quizgeistid Activity ID.
     * @param string[] $states Optional state filter.
     * @return \stdClass[]
     */
    public static function submissions(int $quizgeistid, array $states = []): array {
        global $DB;

        if ($quizgeistid <= 0) {
            return [];
        }
        $params = ['quizgeistid' => $quizgeistid];
        $where = 'w.quizgeistid = :quizgeistid';
        $states = array_values(array_filter(
            $states,
            static fn($state): bool => is_string($state)
                && in_array($state, workshop_schema::STATES, true)
        ));
        if ($states) {
            [$insql, $stateparams] = $DB->get_in_or_equal(
                $states,
                SQL_PARAMS_NAMED,
                'liststate'
            );
            $where .= " AND w.state {$insql}";
            $params += $stateparams;
        }
        return array_values($DB->get_records_sql(
            "SELECT w.*,
                    q.qtype, q.questiontext, q.explanation, q.status AS questionstatus
               FROM {quizgeist_workshop} w
               JOIN {quizgeist_questions} q ON q.id = w.questionid
              WHERE {$where}
           ORDER BY w.timesubmitted DESC, w.id DESC",
            $params
        ));
    }

    /**
     * Aggregate peer ratings of several submissions in one read.
     *
     * @param int[] $workshopids Submission IDs.
     * @return array<int,array{count:int,quality:float,difficulty:float}>
     */
    public static function rating_summary(array $workshopids): array {
        global $DB;

        $workshopids = array_values(array_unique(array_filter(
            array_map('intval', $workshopids),
            static fn(int $id): bool => $id > 0
        )));
        if (!$workshopids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $workshopids,
            SQL_PARAMS_NAMED,
            'ratingworkshop'
        );
        $rows = $DB->get_records_sql(
            "SELECT r.workshopid,
                    COUNT(1) AS ratingcount,
                    AVG(r.quality) AS avgquality,
                    AVG(r.difficulty) AS avgdifficulty
               FROM {quizgeist_workshop_ratings} r
              WHERE r.workshopid {$insql}
           GROUP BY r.workshopid",
            $params
        );
        $summary = [];
        foreach ($rows as $row) {
            $summary[(int)$row->workshopid] = [
                'count' => (int)$row->ratingcount,
                'quality' => round((float)$row->avgquality, 1),
                'difficulty' => round((float)$row->avgdifficulty, 1),
            ];
        }
        return $summary;
    }

    /**
     * Ratings written by one learner across several submissions.
     *
     * @param int[] $workshopids Submission IDs.
     * @param int $userid Learner.
     * @return array<int,\stdClass>
     */
    public static function own_ratings(array $workshopids, int $userid): array {
        global $DB;

        $workshopids = array_values(array_unique(array_filter(
            array_map('intval', $workshopids),
            static fn(int $id): bool => $id > 0
        )));
        if (!$workshopids || $userid <= 0) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $workshopids,
            SQL_PARAMS_NAMED,
            'ownratingworkshop'
        );
        $params['ownratinguser'] = $userid;
        $rows = $DB->get_records_sql(
            "SELECT r.*
               FROM {quizgeist_workshop_ratings} r
              WHERE r.workshopid {$insql}
                AND r.userid = :ownratinguser",
            $params
        );
        $byworkshop = [];
        foreach ($rows as $row) {
            $byworkshop[(int)$row->workshopid] = $row;
        }
        return $byworkshop;
    }

    /**
     * Bulk-load display names of submission authors and curators.
     *
     * @param \stdClass[] $submissions Submission rows.
     * @return array<int,\stdClass>
     */
    public static function people(array $submissions): array {
        global $DB;

        $userids = [];
        foreach ($submissions as $submission) {
            foreach (['authorid', 'curatorid'] as $field) {
                $userid = (int)($submission->{$field} ?? 0);
                if ($userid > 0) {
                    $userids[$userid] = $userid;
                }
            }
        }
        if (!$userids) {
            return [];
        }
        return $DB->get_records_list(
            'user',
            'id',
            array_values($userids),
            '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, '
                . 'middlename, alternatename'
        );
    }

    /**
     * Count submissions a learner still owes a rating.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @return int
     */
    public static function unrated_count(int $quizgeistid, int $userid): int {
        global $DB;

        if ($quizgeistid <= 0 || $userid <= 0) {
            return 0;
        }
        return (int)$DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {quizgeist_workshop} w
              WHERE w.quizgeistid = :quizgeistid
                AND (w.authorid IS NULL OR w.authorid <> :authoruser)
                AND NOT EXISTS (
                    SELECT 1
                      FROM {quizgeist_workshop_ratings} r
                     WHERE r.workshopid = w.id
                       AND r.userid = :ratinguser
                )',
            [
                'quizgeistid' => $quizgeistid,
                'authoruser' => $userid,
                'ratinguser' => $userid,
            ]
        );
    }
}
