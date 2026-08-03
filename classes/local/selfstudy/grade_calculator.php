<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * One grade aggregation contract for gradebook, completion and learners.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregates normalised percentages over completed grade-bearing attempts.
 */
final class grade_calculator {

    /** Supported activity-wide aggregation methods. */
    private const METHODS = ['best', 'last', 'average'];

    /**
     * Calculate summaries for one learner or every learner in an activity.
     *
     * Semantics are intentionally attempt-based: every completed attempt of a
     * solo/test assignment that counts towards the grade contributes one
     * normalised percentage. Assignment boundaries do not add another layer
     * of weighting.
     *
     * @param \stdClass $quizgeist Activity.
     * @param int $userid One learner, or zero for all.
     * @return array<int, array{
     *     userid:int,
     *     method:string,
     *     percent:float,
     *     attemptCount:int,
     *     latestAttemptId:int
     * }>
     */
    public static function summaries(
        \stdClass $quizgeist,
        int $userid = 0
    ): array {
        global $DB;

        $params = [
            'quizgeistid' => (int)$quizgeist->id,
            'completed' => 'completed',
        ];
        $usersql = '';
        if ($userid > 0) {
            $usersql = ' AND a.userid = :userid';
            $params['userid'] = $userid;
        }
        $records = $DB->get_records_sql(
            "SELECT a.id,
                    a.userid,
                    a.score,
                    a.maxscore,
                    a.timefinished,
                    a.timemodified,
                    z.mode AS assignmentmode,
                    z.settingsjson AS assignmentsettings,
                    z.timedue AS assignmenttimedue
               FROM {quizgeist_attempts} a
               JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
              WHERE z.quizgeistid = :quizgeistid
                AND a.status = :completed
                AND a.maxscore > 0
                    {$usersql}
           ORDER BY a.userid, a.timefinished, a.timemodified, a.id",
            $params
        );
        $values = [];
        $latestids = [];
        foreach ($records as $record) {
            if (!in_array(
                (string)$record->assignmentmode,
                ['solo', 'test'],
                true
            )) {
                continue;
            }
            $settings = assignment_settings::decode(
                $record->assignmentsettings ?? null,
                (int)$record->assignmenttimedue
            );
            if (!$settings['countsTowardsGrade']) {
                continue;
            }
            $recorduserid = (int)$record->userid;
            $values[$recorduserid][] = 100.0
                * (float)$record->score
                / (float)$record->maxscore;
            $latestids[$recorduserid] = (int)$record->id;
        }

        $method = in_array(
            (string)($quizgeist->grademethod ?? ''),
            self::METHODS,
            true
        ) ? (string)$quizgeist->grademethod : 'best';
        $summaries = [];
        foreach ($values as $recorduserid => $percentages) {
            $percent = match ($method) {
                'last' => (float)end($percentages),
                'average' => array_sum($percentages) / count($percentages),
                default => max($percentages),
            };
            $summaries[$recorduserid] = [
                'userid' => $recorduserid,
                'method' => $method,
                'percent' => max(0.0, min(100.0, $percent)),
                'attemptCount' => count($percentages),
                'latestAttemptId' => $latestids[$recorduserid],
            ];
        }
        return $summaries;
    }

    /**
     * Return a learner-facing summary, including an empty state.
     *
     * @param \stdClass $quizgeist Activity.
     * @param int $userid Learner.
     * @return array{method:string,percent:?float,attemptCount:int}
     */
    public static function summary(
        \stdClass $quizgeist,
        int $userid
    ): array {
        $method = in_array(
            (string)($quizgeist->grademethod ?? ''),
            self::METHODS,
            true
        ) ? (string)$quizgeist->grademethod : 'best';
        $summary = self::summaries($quizgeist, $userid)[$userid] ?? null;
        return [
            'method' => $method,
            'percent' => $summary === null
                ? null
                : round((float)$summary['percent'], 5),
            'attemptCount' => $summary === null
                ? 0
                : (int)$summary['attemptCount'],
        ];
    }
}
