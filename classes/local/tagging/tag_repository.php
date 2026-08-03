<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Persistence reads for the tagging core.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\tagging;

defined('MOODLE_INTERNAL') || die();

/**
 * Bulk-only persistence reads for tags. No policy, no capability checks.
 */
final class tag_repository {

    /**
     * List every tag visible to one activity: its own, its course, the site.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $courseid Owning course ID.
     * @return \stdClass[] Ordered by kind, sort order and label.
     */
    public static function visible_tags(int $quizgeistid, int $courseid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT t.id, t.scope, t.scopeid, t.kind, t.tagkey, t.label,
                    t.colorkey, t.externalref, t.sortorder,
                    t.timecreated, t.timemodified
               FROM {quizgeist_tags} t
              WHERE (t.scope = :activityscope AND t.scopeid = :quizgeistid)
                 OR (t.scope = :coursescope AND t.scopeid = :courseid)
                 OR (t.scope = :sitescope AND t.scopeid = 0)
           ORDER BY t.kind ASC, t.sortorder ASC, t.label ASC, t.id ASC",
            [
                'activityscope' => 'activity',
                'quizgeistid' => $quizgeistid,
                'coursescope' => 'course',
                'courseid' => $courseid,
                'sitescope' => 'site',
            ]
        );
        return array_values($records);
    }

    /**
     * Look up one tag by its merge identity.
     *
     * @param string $scope Ownership scope.
     * @param int $scopeid Owner ID.
     * @param string $kind Label family.
     * @param string $tagkey Machine key.
     * @return \stdClass|null
     */
    public static function find_by_identity(
        string $scope,
        int $scopeid,
        string $kind,
        string $tagkey
    ): ?\stdClass {
        global $DB;

        $record = $DB->get_record('quizgeist_tags', [
            'scope' => $scope,
            'scopeid' => $scopeid,
            'kind' => $kind,
            'tagkey' => $tagkey,
        ]);
        return $record === false ? null : $record;
    }

    /**
     * List assignments for one question root.
     *
     * @param int $rootid Question root ID.
     * @return \stdClass[]
     */
    public static function assignments_for_root(int $rootid): array {
        global $DB;

        return array_values($DB->get_records_sql(
            'SELECT qt.id, qt.quizgeistid, qt.rootid, qt.tagid, qt.weight,
                    qt.status, qt.createdby, qt.timecreated,
                    t.scope, t.scopeid, t.kind, t.tagkey, t.label,
                    t.colorkey, t.externalref, t.sortorder
               FROM {quizgeist_question_tags} qt
               JOIN {quizgeist_tags} t ON t.id = qt.tagid
              WHERE qt.rootid = :rootid
           ORDER BY t.kind ASC, t.sortorder ASC, t.label ASC, qt.id ASC',
            ['rootid' => $rootid]
        ));
    }

    /**
     * List assignments for every question root of one activity.
     *
     * @param int $quizgeistid Activity ID.
     * @return array<int, \stdClass[]> Keyed by question root ID.
     */
    public static function assignments_by_root(int $quizgeistid): array {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT qt.id, qt.quizgeistid, qt.rootid, qt.tagid, qt.weight,
                    qt.status, qt.createdby, qt.timecreated,
                    t.kind, t.tagkey, t.label, t.colorkey, t.externalref,
                    t.sortorder
               FROM {quizgeist_question_tags} qt
               JOIN {quizgeist_tags} t ON t.id = qt.tagid
              WHERE qt.quizgeistid = :quizgeistid
           ORDER BY qt.rootid ASC, t.kind ASC, t.sortorder ASC, qt.id ASC',
            ['quizgeistid' => $quizgeistid]
        );
        $byroot = [];
        foreach ($records as $record) {
            $byroot[(int)$record->rootid][] = $record;
        }
        return $byroot;
    }

    /**
     * List approved assignments of one label family for many question roots.
     *
     * The report spans several activities of a course, so the lookup is bound
     * to the roots it actually projected and not to one activity.
     *
     * @param int[] $rootids Question root IDs.
     * @param string $kind Label family.
     * @return array<int, \stdClass[]> Keyed by question root ID.
     */
    public static function assignments_for_roots(
        array $rootids,
        string $kind
    ): array {
        global $DB;

        $rootids = array_values(array_unique(array_filter(
            array_map('intval', $rootids),
            static fn(int $rootid): bool => $rootid > 0
        )));
        if (!$rootids) {
            return [];
        }
        [$rootsql, $rootparams] = $DB->get_in_or_equal(
            $rootids,
            SQL_PARAMS_NAMED,
            'tagroot'
        );
        $records = $DB->get_records_sql(
            "SELECT qt.id, qt.rootid, qt.tagid, qt.weight, qt.status,
                    t.kind, t.tagkey, t.label, t.colorkey, t.sortorder
               FROM {quizgeist_question_tags} qt
               JOIN {quizgeist_tags} t ON t.id = qt.tagid
              WHERE qt.status = :approved
                AND t.kind = :kind
                AND qt.rootid {$rootsql}
           ORDER BY qt.rootid ASC, qt.weight DESC, t.sortorder ASC,
                    t.label ASC, qt.id ASC",
            ['approved' => 'approved', 'kind' => $kind] + $rootparams
        );
        $byroot = [];
        foreach ($records as $record) {
            $byroot[(int)$record->rootid][] = $record;
        }
        return $byroot;
    }

    /**
     * Whether one question root carries a specific tag.
     *
     * Assignments are bound to the root, so this answer survives every
     * content version of the question.
     *
     * @param int $rootid Question root ID.
     * @param string $kind Label family.
     * @param string $tagkey Machine key.
     * @return bool
     */
    public static function root_has_tag(
        int $rootid,
        string $kind,
        string $tagkey
    ): bool {
        global $DB;

        if ($rootid <= 0) {
            return false;
        }
        return $DB->record_exists_sql(
            'SELECT 1
               FROM {quizgeist_question_tags} qt
               JOIN {quizgeist_tags} t ON t.id = qt.tagid
              WHERE qt.rootid = :rootid
                AND qt.status = :approved
                AND t.kind = :kind
                AND t.tagkey = :tagkey',
            [
                'rootid' => $rootid,
                'approved' => 'approved',
                'kind' => $kind,
                'tagkey' => $tagkey,
            ]
        );
    }

    /**
     * Resolve every question root of one activity in one read.
     *
     * @param int $quizgeistid Activity ID.
     * @return int[] Distinct root IDs.
     */
    public static function activity_roots(int $quizgeistid): array {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT DISTINCT COALESCE(NULLIF(q.rootid, 0), q.id) AS rootid
               FROM {quizgeist_questions} q
              WHERE q.quizgeistid = :quizgeistid',
            ['quizgeistid' => $quizgeistid]
        );
        return array_values(array_map(
            static fn(\stdClass $record): int => (int)$record->rootid,
            $records
        ));
    }

    /**
     * Resolve the stable root ID of one exact question version.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $questionid Exact question version ID.
     * @return int Root ID, or zero when the question does not belong here.
     */
    public static function root_of_question(int $quizgeistid, int $questionid): int {
        global $DB;

        $record = $DB->get_record(
            'quizgeist_questions',
            ['id' => $questionid, 'quizgeistid' => $quizgeistid],
            'id, rootid'
        );
        if ($record === false) {
            return 0;
        }
        return (int)$record->rootid > 0 ? (int)$record->rootid : (int)$record->id;
    }
}
