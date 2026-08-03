<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\report;

defined('MOODLE_INTERNAL') || die();

/**
 * Bulk-only persistence reads for reports.
 */
final class report_repository {

    /**
     * List reportable sources in one activity.
     *
     * @return array{sessions:\stdClass[],assignments:\stdClass[]}
     */
    public static function instance_sources(int $quizgeistid): array {
        global $DB;

        $sessions = $DB->get_records_sql(
            "SELECT s.id, s.quizgeistid, s.status, s.mode,
                    s.timestarted, s.timeended, s.timecreated
               FROM {quizgeist_sessions} s
              WHERE s.quizgeistid = :quizgeistid
                AND s.status IN (:ended, :aborted)
           ORDER BY s.timeended DESC, s.id DESC",
            [
                'quizgeistid' => $quizgeistid,
                'ended' => 'ended',
                'aborted' => 'aborted',
            ]
        );
        $assignments = $DB->get_records_sql(
            'SELECT z.id, z.quizgeistid, z.name, z.mode, z.status,
                    z.timeopen, z.timedue, z.timecreated, z.timemodified
               FROM {quizgeist_assignments} z
              WHERE z.quizgeistid = :quizgeistid
                AND EXISTS (
                    SELECT 1
                      FROM {quizgeist_attempts} a
                     WHERE a.assignmentid = z.id
                       AND a.status = :completed
                )
           ORDER BY z.timecreated DESC, z.id DESC',
            [
                'quizgeistid' => $quizgeistid,
                'completed' => 'completed',
            ]
        );
        return [
            'sessions' => array_values($sessions),
            'assignments' => array_values($assignments),
        ];
    }

    /**
     * List all reportable sources in a course, including their CM IDs.
     *
     * @return array{sessions:\stdClass[],assignments:\stdClass[]}
     */
    public static function course_sources(int $courseid): array {
        global $DB;

        $moduleid = (int)$DB->get_field(
            'modules',
            'id',
            ['name' => 'quizgeist'],
            MUST_EXIST
        );
        $common = ' JOIN {quizgeist} m ON m.id = %s.quizgeistid
                    JOIN {course_modules} cm
                      ON cm.instance = m.id
                     AND cm.module = :moduleid
                     AND cm.course = :courseid
                     AND cm.deletioninprogress = 0';
        $sessions = $DB->get_records_sql(
            'SELECT s.id, s.quizgeistid, s.status, s.mode,
                    s.timestarted, s.timeended, s.timecreated,
                    m.name AS quizgeistname, cm.id AS cmid
               FROM {quizgeist_sessions} s'
                . sprintf($common, 's')
                . ' WHERE s.status IN (:ended, :aborted)
                 ORDER BY s.timeended ASC, s.id ASC',
            [
                'moduleid' => $moduleid,
                'courseid' => $courseid,
                'ended' => 'ended',
                'aborted' => 'aborted',
            ]
        );
        $assignments = $DB->get_records_sql(
            'SELECT z.id, z.quizgeistid, z.name, z.mode, z.status,
                    z.timeopen, z.timedue, z.timecreated, z.timemodified,
                    m.name AS quizgeistname, cm.id AS cmid
               FROM {quizgeist_assignments} z'
                . sprintf($common, 'z')
                . " WHERE EXISTS (
                        SELECT 1
                          FROM {quizgeist_attempts} a
                         WHERE a.assignmentid = z.id
                           AND a.status = :completed
                    )
                 ORDER BY z.timecreated ASC, z.id ASC",
            [
                'moduleid' => $moduleid,
                'courseid' => $courseid,
                'completed' => 'completed',
            ]
        );
        return [
            'sessions' => array_values($sessions),
            'assignments' => array_values($assignments),
        ];
    }

    /**
     * Bulk participation lookup used to hide unrelated learner sources.
     *
     * @param array<int,array{kind:string,id:int}> $sources
     * @return array{
     *     sessions:array<int,true>,
     *     assignments:array<int,true>
     * }
     */
    public static function viewer_participation(
        array $sources,
        int $userid
    ): array {
        global $DB;

        $sessionids = [];
        $assignmentids = [];
        foreach ($sources as $source) {
            if (($source['kind'] ?? '') === 'session') {
                $sessionids[] = (int)$source['id'];
            } else if (($source['kind'] ?? '') === 'assignment') {
                $assignmentids[] = (int)$source['id'];
            }
        }
        $result = ['sessions' => [], 'assignments' => []];
        if ($sessionids) {
            [$insql, $params] = $DB->get_in_or_equal(
                array_values(array_unique($sessionids)),
                SQL_PARAMS_NAMED,
                'participationsession'
            );
            $params['participationuserid'] = $userid;
            $records = $DB->get_records_sql(
                "SELECT DISTINCT p.sessionid
                   FROM {quizgeist_players} p
                  WHERE p.sessionid {$insql}
                    AND p.userid = :participationuserid",
                $params
            );
            foreach ($records as $record) {
                $result['sessions'][(int)$record->sessionid] = true;
            }
        }
        if ($assignmentids) {
            [$insql, $params] = $DB->get_in_or_equal(
                array_values(array_unique($assignmentids)),
                SQL_PARAMS_NAMED,
                'participationassignment'
            );
            $params['participationuserid'] = $userid;
            $params['participationcompleted'] = 'completed';
            $records = $DB->get_records_sql(
                "SELECT DISTINCT a.assignmentid
                   FROM {quizgeist_attempts} a
                  WHERE a.assignmentid {$insql}
                    AND a.userid = :participationuserid
                    AND a.status = :participationcompleted",
                $params
            );
            foreach ($records as $record) {
                $result['assignments'][(int)$record->assignmentid] = true;
            }
        }
        return $result;
    }

    /**
     * Load players for selected sessions.
     *
     * @param int[] $sessionids
     * @return \stdClass[]
     */
    public static function players(array $sessionids): array {
        global $DB;
        if (!$sessionids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $sessionids,
            SQL_PARAMS_NAMED,
            'playersession'
        );
        return array_values($DB->get_records_sql(
            "SELECT p.*
               FROM {quizgeist_players} p
              WHERE p.sessionid {$insql}
           ORDER BY p.id ASC",
            $params
        ));
    }

    /**
     * Load completed attempts for selected assignments.
     *
     * @param int[] $assignmentids
     * @return \stdClass[]
     */
    public static function attempts(array $assignmentids): array {
        global $DB;
        if (!$assignmentids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $assignmentids,
            SQL_PARAMS_NAMED,
            'attemptassignment'
        );
        $params['attemptcompleted'] = 'completed';
        return array_values($DB->get_records_sql(
            "SELECT a.*
               FROM {quizgeist_attempts} a
              WHERE a.assignmentid {$insql}
                AND a.status = :attemptcompleted
           ORDER BY a.id ASC",
            $params
        ));
    }

    /**
     * Frozen live question occurrences with exact content versions.
     *
     * @param int[] $sessionids
     * @return \stdClass[]
     */
    public static function live_questions(array $sessionids): array {
        global $DB;
        if (!$sessionids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $sessionids,
            SQL_PARAMS_NAMED,
            'questionsession'
        );
        return array_values($DB->get_records_sql(
            "SELECT sq.id AS occurrenceid, sq.sessionid, sq.sortindex,
                    sq.visit, sq.visitstate, sq.resolvedvisit, sq.stage,
                    q.id AS questionid, q.quizgeistid, q.rootid, q.version,
                    q.qtype, q.questiontext, q.questionformat, q.optionsjson,
                    q.timelimit, q.pointmode, q.explanation, q.status,
                    q.createdby, q.timecreated, q.timemodified
               FROM {quizgeist_session_questions} sq
               JOIN {quizgeist_questions} q ON q.id = sq.questionid
              WHERE sq.sessionid {$insql}
           ORDER BY sq.sessionid ASC, sq.sortindex ASC",
            $params
        ));
    }

    /**
     * Frozen completed-attempt question occurrences.
     *
     * @param int[] $attemptids
     * @return \stdClass[]
     */
    public static function attempt_questions(array $attemptids): array {
        global $DB;
        if (!$attemptids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $attemptids,
            SQL_PARAMS_NAMED,
            'questionattempt'
        );
        return array_values($DB->get_records_sql(
            "SELECT aq.id AS occurrenceid, aq.attemptid, aq.sortindex,
                    aq.visit, aq.status AS attemptquestionstatus,
                    aq.timestarted, aq.timesubmitted,
                    q.id AS questionid, q.quizgeistid, q.rootid, q.version,
                    q.qtype, q.questiontext, q.questionformat, q.optionsjson,
                    q.timelimit, q.pointmode, q.explanation, q.status,
                    q.createdby, q.timecreated, q.timemodified
               FROM {quizgeist_attempt_questions} aq
               JOIN {quizgeist_questions} q ON q.id = aq.questionid
              WHERE aq.attemptid {$insql}
           ORDER BY aq.attemptid ASC, aq.sortindex ASC",
            $params
        ));
    }

    /**
     * Append-only ledger rows for selected live sessions.
     *
     * @param int[] $sessionids
     * @return \stdClass[]
     */
    public static function session_answers(
        array $sessionids,
        array $visibleplayerids,
        bool $includehost
    ): array {
        global $DB;
        if (!$sessionids) {
            return [];
        }
        [$sessionsql, $params] = $DB->get_in_or_equal(
            $sessionids,
            SQL_PARAMS_NAMED,
            'answersession'
        );
        $actorclauses = [];
        if ($visibleplayerids) {
            [$playersql, $playerparams] = $DB->get_in_or_equal(
                array_values(array_unique(array_map(
                    'intval',
                    $visibleplayerids
                ))),
                SQL_PARAMS_NAMED,
                'answerplayer'
            );
            $params += $playerparams;
            $actorclauses[] = "a.playerid {$playersql}";
        }
        if ($includehost) {
            $actorclauses[] = 'a.playerid IS NULL';
        }
        if (!$actorclauses) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT a.*
               FROM {quizgeist_answers} a
              WHERE a.sessionid {$sessionsql}
                AND (" . implode(' OR ', $actorclauses) . ')
           ORDER BY a.id ASC',
            $params
        ));
    }

    /**
     * Append-only ledger rows for selected attempts.
     *
     * @param int[] $attemptids
     * @return \stdClass[]
     */
    public static function attempt_answers(array $attemptids): array {
        return self::answers('attemptid', $attemptids, 'answerattempt');
    }

    /**
     * Bulk-load users without N+1 profile reads.
     *
     * @param int[] $userids
     * @return array<int,\stdClass>
     */
    public static function users(array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_filter(
            array_map('intval', $userids)
        )));
        if (!$userids) {
            return [];
        }
        return $DB->get_records_list(
            'user',
            'id',
            $userids,
            '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, '
                . 'middlename, alternatename, idnumber, username'
        );
    }

    /**
     * @param string $field Whitelisted ownership field.
     * @param int[] $ids
     * @return \stdClass[]
     */
    private static function answers(
        string $field,
        array $ids,
        string $prefix
    ): array {
        global $DB;
        if (!$ids || !in_array($field, ['sessionid', 'attemptid'], true)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $ids,
            SQL_PARAMS_NAMED,
            $prefix
        );
        return array_values($DB->get_records_sql(
            "SELECT a.*
               FROM {quizgeist_answers} a
              WHERE a.{$field} {$insql}
           ORDER BY a.id ASC",
            $params
        ));
    }
}
