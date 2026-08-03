<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Persistence reads and writes for the repetition core.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\schedule;

defined('MOODLE_INTERNAL') || die();

/**
 * Bulk-only persistence for repetition state. No policy, no capability checks.
 */
final class schedule_repository {

    /** Hard upper bound for one drawn repetition set. */
    public const MAX_DRAW = 200;

    /**
     * Read one learner's repetition state for one question root.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $rootid Question root.
     * @param bool $lock Take a row lock for a read-modify-write.
     * @return \stdClass|null
     */
    public static function state(
        int $quizgeistid,
        int $userid,
        int $rootid,
        bool $lock = false
    ): ?\stdClass {
        global $DB;

        $record = $DB->get_record_sql(
            'SELECT *
               FROM {quizgeist_schedule}
              WHERE quizgeistid = :quizgeistid
                AND userid = :userid
                AND rootid = :rootid'
                . ($lock ? ' FOR UPDATE' : ''),
            [
                'quizgeistid' => $quizgeistid,
                'userid' => $userid,
                'rootid' => $rootid,
            ],
            IGNORE_MISSING
        );
        return $record === false ? null : $record;
    }

    /**
     * Insert one repetition state row.
     *
     * @param array $fields Complete row without ID.
     * @return int New row ID.
     */
    public static function insert_state(array $fields): int {
        global $DB;

        return (int)$DB->insert_record('quizgeist_schedule', (object)$fields);
    }

    /**
     * Update one repetition state row by ID.
     *
     * @param int $id Row ID.
     * @param array $fields Changed columns.
     * @return void
     */
    public static function update_state(int $id, array $fields): void {
        global $DB;

        $DB->update_record(
            'quizgeist_schedule',
            (object)(['id' => $id] + $fields)
        );
    }

    /**
     * Draw the candidate roots a learner may repeat in one activity.
     *
     * A root the learner has never answered has no row at all and is due
     * immediately; the query therefore starts from the activity's roots and
     * left-joins the state, rather than reading the state table alone. That is
     * the difference between "everything I got wrong" and "everything I have
     * not mastered yet".
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $now Current timestamp.
     * @param bool $onlydue Restrict to roots that are actually due.
     * @return \stdClass[] Rows with rootid, questionid, duetime, lapses,
     *     repetitions, easiness and lastreviewed.
     */
    public static function candidate_roots(
        int $quizgeistid,
        int $userid,
        int $now,
        bool $onlydue = true
    ): array {
        global $DB;

        $duefilter = $onlydue
            ? 'AND (s.id IS NULL OR s.duetime <= :now)'
            : '';
        $records = $DB->get_records_sql(
            "SELECT q.id AS questionid,
                    CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END AS rootid,
                    q.sortorder,
                    COALESCE(s.duetime, 0) AS duetime,
                    COALESCE(s.lapses, 0) AS lapses,
                    COALESCE(s.repetitions, 0) AS repetitions,
                    COALESCE(s.easiness, :defaultease) AS easiness,
                    COALESCE(s.lastreviewed, 0) AS lastreviewed,
                    COALESCE(s.lastquality, 0) AS lastquality
               FROM {quizgeist_questions} q
          LEFT JOIN {quizgeist_schedule} s
                 ON s.quizgeistid = q.quizgeistid
                AND s.userid = :userid
                AND s.rootid = CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END
              WHERE q.quizgeistid = :quizgeistid
                AND q.status = :ready
                    {$duefilter}
           ORDER BY COALESCE(s.duetime, 0) ASC, q.sortorder ASC, q.id ASC",
            [
                'quizgeistid' => $quizgeistid,
                'userid' => $userid,
                'ready' => 'ready',
                'now' => $now,
                'defaultease' => sm2::DEFAULT_EASINESS,
            ],
            0,
            self::MAX_DRAW
        );
        return array_values($records);
    }

    /**
     * Resolve the current ready version of every requested question root.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[] $rootids Question roots.
     * @return array<int,int> Root ID to current ready version ID.
     */
    public static function current_versions(int $quizgeistid, array $rootids): array {
        global $DB;

        $rootids = array_values(array_unique(array_map('intval', $rootids)));
        if (!$rootids) {
            return [];
        }
        [$rootsql, $rootparams] = $DB->get_in_or_equal(
            $rootids,
            SQL_PARAMS_NAMED,
            'root'
        );
        $records = $DB->get_records_sql(
            "SELECT q.id,
                    CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END AS rootid,
                    q.version
               FROM {quizgeist_questions} q
              WHERE q.quizgeistid = :quizgeistid
                AND q.status = :ready
                AND CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END {$rootsql}
           ORDER BY q.version ASC, q.id ASC",
            ['quizgeistid' => $quizgeistid, 'ready' => 'ready'] + $rootparams
        );
        $versions = [];
        foreach ($records as $record) {
            // Ordered ascending, so the last write wins: the newest ready
            // version of the lineage.
            $versions[(int)$record->rootid] = (int)$record->id;
        }
        return $versions;
    }

    /**
     * Count the roots that are due for one learner in one activity.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $now Current timestamp.
     * @return array{dueCount:int,nextDue:?int,trackedCount:int}
     */
    public static function due_summary(
        int $quizgeistid,
        int $userid,
        int $now
    ): array {
        global $DB;

        $summary = $DB->get_record_sql(
            'SELECT COUNT(1) AS trackedcount,
                    SUM(CASE WHEN s.duetime <= :now THEN 1 ELSE 0 END) AS duecount,
                    MIN(CASE WHEN s.duetime > :nextnow THEN s.duetime ELSE NULL END)
                        AS nextdue
               FROM {quizgeist_schedule} s
              WHERE s.quizgeistid = :quizgeistid
                AND s.userid = :userid',
            [
                'quizgeistid' => $quizgeistid,
                'userid' => $userid,
                'now' => $now,
                'nextnow' => $now,
            ]
        );
        $nextdue = $summary && $summary->nextdue !== null
            ? (int)$summary->nextdue
            : null;
        return [
            'dueCount' => (int)($summary->duecount ?? 0),
            'nextDue' => $nextdue,
            'trackedCount' => (int)($summary->trackedcount ?? 0),
        ];
    }

    /**
     * Aggregate due repetition state per tag for one activity.
     *
     * Used by the teacher dashboard and by the competence digest. Rows without
     * an approved tag are reported under the empty tag key so a class with no
     * tagging at all still sees a truthful total.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $now Current timestamp.
     * @param string $kind Tag family to group by.
     * @param int[] $userids Restrict to these learners; empty means all.
     * @return \stdClass[] Rows with tagid, tagkey, label, colorkey, duecount,
     *     trackedcount, learnercount and averageease.
     */
    public static function due_by_tag(
        int $quizgeistid,
        int $now,
        string $kind = 'topic',
        array $userids = []
    ): array {
        global $DB;

        $params = [
            'quizgeistid' => $quizgeistid,
            'now' => $now,
            'kind' => $kind,
            'approved' => 'approved',
        ];
        $userfilter = '';
        if ($userids) {
            [$usersql, $userparams] = $DB->get_in_or_equal(
                array_values(array_unique(array_map('intval', $userids))),
                SQL_PARAMS_NAMED,
                'scheduleuser'
            );
            $userfilter = "AND s.userid {$usersql}";
            $params += $userparams;
        }
        $records = $DB->get_records_sql(
            "SELECT COALESCE(t.id, 0) AS tagid,
                    COALESCE(t.tagkey, '') AS tagkey,
                    COALESCE(t.label, '') AS label,
                    t.colorkey,
                    COUNT(1) AS trackedcount,
                    SUM(CASE WHEN s.duetime <= :now THEN 1 ELSE 0 END) AS duecount,
                    COUNT(DISTINCT s.userid) AS learnercount,
                    AVG(s.easiness) AS averageease
               FROM {quizgeist_schedule} s
          LEFT JOIN {quizgeist_question_tags} qt
                 ON qt.rootid = s.rootid
                AND qt.status = :approved
          LEFT JOIN {quizgeist_tags} t
                 ON t.id = qt.tagid
                AND t.kind = :kind
              WHERE s.quizgeistid = :quizgeistid
                    {$userfilter}
           GROUP BY COALESCE(t.id, 0), COALESCE(t.tagkey, ''),
                    COALESCE(t.label, ''), t.colorkey
           ORDER BY COALESCE(t.label, ''), COALESCE(t.id, 0)",
            $params
        );
        return array_values($records);
    }

    /**
     * Draw the roots most learners of one activity owe a repetition on.
     *
     * A live session belongs to a class, not to one learner. "Due" is therefore
     * counted across every learner who already carries repetition state here.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $now Current timestamp.
     * @param int $limit Maximum roots.
     * @return \stdClass[] Rows with rootid and learnercount.
     */
    public static function class_due_roots(
        int $quizgeistid,
        int $now,
        int $limit
    ): array {
        global $DB;

        $limit = max(0, min(self::MAX_DRAW, $limit));
        if ($limit === 0) {
            return [];
        }
        $records = $DB->get_records_sql(
            'SELECT s.rootid,
                    COUNT(DISTINCT s.userid) AS learnercount,
                    MIN(s.duetime) AS earliestdue
               FROM {quizgeist_schedule} s
              WHERE s.quizgeistid = :quizgeistid
                AND s.duetime <= :now
           GROUP BY s.rootid
           ORDER BY COUNT(DISTINCT s.userid) DESC, MIN(s.duetime) ASC, s.rootid ASC',
            ['quizgeistid' => $quizgeistid, 'now' => $now],
            0,
            $limit
        );
        return array_values($records);
    }

    /**
     * Count answered questions of one learner inside a half-open time window.
     *
     * Digest input, deliberately counting distinct roots: answering the same
     * question five times is practice, not five questions.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $from Inclusive lower bound.
     * @param int $until Exclusive upper bound.
     * @return int
     */
    public static function answered_roots_between(
        int $quizgeistid,
        int $userid,
        int $from,
        int $until
    ): int {
        global $DB;

        return (int)$DB->count_records_sql(
            'SELECT COUNT(DISTINCT CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END)
               FROM {quizgeist_answers} a
               JOIN {quizgeist_questions} q ON q.id = a.questionid
              WHERE q.quizgeistid = :quizgeistid
                AND a.userid = :userid
                AND a.answertype = :answer
                AND a.timecreated >= :from
                AND a.timecreated < :until',
            [
                'quizgeistid' => $quizgeistid,
                'userid' => $userid,
                'answer' => 'answer',
                'from' => $from,
                'until' => $until,
            ]
        );
    }

    /**
     * List the timestamps of one learner's answers, newest first.
     *
     * The caller turns them into local calendar days; a timezone is a viewer
     * property and has no business inside SQL.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @param int $since Inclusive lower bound.
     * @param int $limit Maximum rows.
     * @return int[]
     */
    public static function answer_times_since(
        int $quizgeistid,
        int $userid,
        int $since,
        int $limit = 2000
    ): array {
        global $DB;

        $times = $DB->get_fieldset_sql(
            'SELECT a.timecreated
               FROM {quizgeist_answers} a
               JOIN {quizgeist_questions} q ON q.id = a.questionid
              WHERE q.quizgeistid = :quizgeistid
                AND a.userid = :userid
                AND a.timecreated >= :since
           ORDER BY a.timecreated DESC',
            [
                'quizgeistid' => $quizgeistid,
                'userid' => $userid,
                'since' => $since,
            ],
            0,
            max(1, $limit)
        );
        return array_map('intval', $times);
    }

    /**
     * List every activity of one course that carries repetition state or goals.
     *
     * The learner appears in TWO subqueries, so the learner id is bound twice
     * under two names. Moodle counts every occurrence of a named placeholder
     * and refuses a statement that reuses one — `:userid` twice against a
     * single parameter raised `invalidqueryparam` on every single call, and
     * `digest::for_user()` swallowed it into the empty form, so the parent
     * tile could never show data ([P11-E11]). Same shape as `due_summary()`
     * above, which binds `:now` and `:nextnow` for exactly this reason.
     *
     * @param int $userid Learner.
     * @param int $courseid Course; zero means every course.
     * @return \stdClass[] Rows with id, course and name.
     */
    public static function learner_activities(int $userid, int $courseid = 0): array {
        global $DB;

        $params = [
            'userid' => $userid,
            'userid2' => $userid,
            'answer' => 'answer',
        ];
        $coursefilter = '';
        if ($courseid > 0) {
            $coursefilter = 'AND m.course = :courseid';
            $params['courseid'] = $courseid;
        }
        $records = $DB->get_records_sql(
            "SELECT m.id, m.course, m.name
               FROM {quizgeist} m
              WHERE (
                        EXISTS (
                            SELECT 1
                              FROM {quizgeist_schedule} s
                             WHERE s.quizgeistid = m.id
                               AND s.userid = :userid
                        )
                     OR EXISTS (
                            SELECT 1
                              FROM {quizgeist_answers} a
                              JOIN {quizgeist_questions} q ON q.id = a.questionid
                             WHERE q.quizgeistid = m.id
                               AND a.userid = :userid2
                               AND a.answertype = :answer
                        )
                    )
                    {$coursefilter}
           ORDER BY m.course ASC, m.id ASC",
            $params
        );
        return array_values($records);
    }

    /**
     * Read the personal weekly target of one learner, if configured.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $userid Learner.
     * @return int|null
     */
    public static function weekly_goal(int $quizgeistid, int $userid): ?int {
        global $DB;

        $goal = $DB->get_record(
            'quizgeist_goals',
            ['quizgeistid' => $quizgeistid, 'userid' => $userid],
            'id, target',
            IGNORE_MISSING
        );
        return $goal === false ? null : (int)$goal->target;
    }

    /**
     * Delete every repetition row of one activity.
     *
     * @param int $quizgeistid Activity ID.
     * @return void
     */
    public static function delete_activity(int $quizgeistid): void {
        global $DB;

        $DB->delete_records('quizgeist_schedule', ['quizgeistid' => $quizgeistid]);
    }
}
