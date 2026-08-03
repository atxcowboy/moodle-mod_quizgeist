<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Persistence of the stage-check reports (F13).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\stage;

defined('MOODLE_INTERNAL') || die();

/**
 * Plain SQL over quizgeist_stage_reports; no policy, no validation.
 *
 * The table belongs to the BASIS although only the addon writes it. That is
 * the rule from ARCHITECTURE.md: a report has to stay readable, exportable and
 * erasable even when the addon code package is gone. Every read here therefore
 * works without a single addon class being loaded.
 */
final class stage_repository {

    /**
     * Store one already validated report.
     *
     * @param array $report Validated fields; see stage_service.
     * @return int New report ID.
     */
    public static function insert(array $report): int {
        global $DB;

        return (int)$DB->insert_record('quizgeist_stage_reports', (object)[
            'quizgeistid' => (int)$report['quizgeistid'],
            'userid' => (int)$report['userid'],
            'questionid' => (int)$report['questionid'],
            'answerid' => $report['answerid'] === null
                ? null
                : (int)$report['answerid'],
            'durationsecs' => (int)$report['durationsecs'],
            'metricsjson' => (string)$report['metricsjson'],
            'aiused' => (int)$report['aiused'],
            'feedbackjson' => $report['feedbackjson'] === null
                ? null
                : (string)$report['feedbackjson'],
            'timecreated' => (int)$report['timecreated'],
        ]);
    }

    /**
     * Load one report of one activity.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $reportid Report.
     * @return \stdClass|null
     */
    public static function report(int $quizgeistid, int $reportid): ?\stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $reportid <= 0) {
            return null;
        }
        $report = $DB->get_record('quizgeist_stage_reports', [
            'id' => $reportid,
            'quizgeistid' => $quizgeistid,
        ]);
        return $report ?: null;
    }

    /**
     * Reports of one learner in one activity, newest first.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $userid Learner.
     * @param int $limit Maximum rows; zero means every row.
     * @return \stdClass[]
     */
    public static function reports_for_user(
        int $quizgeistid,
        int $userid,
        int $limit = 0
    ): array {
        global $DB;

        if ($quizgeistid <= 0 || $userid <= 0) {
            return [];
        }
        return array_values($DB->get_records(
            'quizgeist_stage_reports',
            ['quizgeistid' => $quizgeistid, 'userid' => $userid],
            'timecreated DESC, id DESC',
            '*',
            0,
            max(0, $limit)
        ));
    }

    /**
     * Every report of one activity, oldest first.
     *
     * @param int $quizgeistid Owning activity.
     * @return \stdClass[]
     */
    public static function reports_for_activity(int $quizgeistid): array {
        global $DB;

        if ($quizgeistid <= 0) {
            return [];
        }
        return array_values($DB->get_records(
            'quizgeist_stage_reports',
            ['quizgeistid' => $quizgeistid],
            'timecreated ASC, id ASC'
        ));
    }

    /**
     * How many reports one learner already has for one exact question version.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $userid Learner.
     * @param int $questionid Exact question version.
     * @return int
     */
    public static function count_for_question(
        int $quizgeistid,
        int $userid,
        int $questionid
    ): int {
        global $DB;

        if ($quizgeistid <= 0 || $userid <= 0 || $questionid <= 0) {
            return 0;
        }
        return (int)$DB->count_records('quizgeist_stage_reports', [
            'quizgeistid' => $quizgeistid,
            'userid' => $userid,
            'questionid' => $questionid,
        ]);
    }

    /**
     * Delete every report of one activity (course reset, activity deletion).
     *
     * @param int $quizgeistid Owning activity.
     * @return void
     */
    public static function delete_for_activity(int $quizgeistid): void {
        global $DB;

        if ($quizgeistid <= 0) {
            return;
        }
        $DB->delete_records('quizgeist_stage_reports', [
            'quizgeistid' => $quizgeistid,
        ]);
    }

    /**
     * Delete the reports of named learners in one activity (privacy).
     *
     * @param int $quizgeistid Owning activity.
     * @param int[] $userids Learners.
     * @return void
     */
    public static function delete_for_users(int $quizgeistid, array $userids): void {
        global $DB;

        $ids = array_values(array_filter(array_map('intval', $userids)));
        if ($quizgeistid <= 0 || $ids === []) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'sruser');
        $params['quizgeistid'] = $quizgeistid;
        $DB->delete_records_select(
            'quizgeist_stage_reports',
            'quizgeistid = :quizgeistid AND userid ' . $insql,
            $params
        );
    }
}
