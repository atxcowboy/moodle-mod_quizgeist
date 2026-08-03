<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cluster-aware locks for question-content lineages.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\editor;

defined('MOODLE_INTERNAL') || die();

/**
 * Serialises non-transactional file changes with question snapshots/mutations.
 */
final class question_content_lock {

    /** Maximum wait for another request to finish its short content operation. */
    private const TIMEOUT_SECONDS = 30;

    /**
     * Activity structure locks held through with_activity_locks() in this request.
     *
     * @var array<int,int>
     */
    private static array $heldstructures = [];

    /**
     * Acquire the stable lineage lock for one concrete question version.
     *
     * Archived versions deliberately remain resolvable here: a request that
     * started before a COW fork must wait on the same root lock and can then be
     * rejected against the current DB state.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $questionid Concrete question ID.
     * @return \core\lock\lock
     */
    public static function acquire_for_question(
        int $quizgeistid,
        int $questionid
    ): \core\lock\lock {
        global $DB;

        $record = $DB->get_record('quizgeist_questions', [
            'id' => $questionid,
            'quizgeistid' => $quizgeistid,
        ], 'id,rootid', MUST_EXIST);
        $rootid = (int)($record->rootid ?? 0) > 0
            ? (int)$record->rootid
            : (int)$record->id;
        return self::acquire_root($quizgeistid, $rootid);
    }

    /**
     * Acquire the stable activity-structure lock used before creating roots.
     *
     * @param int $quizgeistid Activity ID.
     * @return \core\lock\lock
     */
    public static function acquire_structure(int $quizgeistid): \core\lock\lock {
        return self::acquire_resource('structure:' . $quizgeistid);
    }

    /**
     * Acquire all currently active lineages in deterministic root order.
     *
     * This is used by operations that snapshot or replace a complete quiz.
     * The structure lock prevents a new root from appearing between this read
     * and acquisition of the existing root locks.
     *
     * @param int $quizgeistid Activity ID.
     * @return \core\lock\lock[]
     */
    public static function acquire_for_activity(int $quizgeistid): array {
        global $DB;

        // Fixed global order: activity structure, lineage roots, DB rows.
        // Reading the active root set only after the structure lock prevents a
        // newly created lineage from escaping a whole-activity operation.
        $locks = [self::acquire_structure($quizgeistid)];
        try {
            $records = $DB->get_records_select(
                'quizgeist_questions',
                'quizgeistid = :quizgeistid AND status <> :archived',
                ['quizgeistid' => $quizgeistid, 'archived' => 'archived'],
                'rootid ASC, id ASC',
                'id,rootid'
            );
            $rootids = [];
            foreach ($records as $record) {
                $rootid = (int)($record->rootid ?? 0) > 0
                    ? (int)$record->rootid
                    : (int)$record->id;
                $rootids[$rootid] = true;
            }
            ksort($rootids, SORT_NUMERIC);
            foreach (array_keys($rootids) as $rootid) {
                $locks[] = self::acquire_root($quizgeistid, (int)$rootid);
            }
        } catch (\Throwable $exception) {
            self::release_all($locks);
            throw $exception;
        }
        return $locks;
    }

    /**
     * Run one operation while complete activity lock sets are held.
     *
     * IDs are sorted so callers touching more than one activity retain one
     * deterministic global order. The request-local marker lets a nested
     * editor persistence boundary reuse the already-held structure lock
     * without trying to acquire the same non-reentrant lock again.
     *
     * @param int[] $quizgeistids Activity IDs.
     * @param callable $operation Operation to run.
     * @return mixed Callback result.
     */
    public static function with_activity_locks(
        array $quizgeistids,
        callable $operation
    ) {
        $quizgeistids = array_values(array_unique(array_filter(
            array_map('intval', $quizgeistids),
            static fn(int $id): bool => $id > 0
        )));
        sort($quizgeistids, SORT_NUMERIC);
        if (!$quizgeistids) {
            throw new \coding_exception(
                'At least one Quizgeist activity lock is required.'
            );
        }

        $locks = [];
        $marked = [];
        try {
            foreach ($quizgeistids as $quizgeistid) {
                $activitylocks = self::acquire_for_activity($quizgeistid);
                foreach ($activitylocks as $lock) {
                    $locks[] = $lock;
                }
                self::$heldstructures[$quizgeistid] =
                    (self::$heldstructures[$quizgeistid] ?? 0) + 1;
                $marked[] = $quizgeistid;
            }
            return $operation();
        } finally {
            foreach (array_reverse($marked) as $quizgeistid) {
                self::$heldstructures[$quizgeistid]--;
                if (self::$heldstructures[$quizgeistid] < 1) {
                    unset(self::$heldstructures[$quizgeistid]);
                }
            }
            self::release_all($locks);
        }
    }

    /**
     * Whether this request already owns an activity structure lock.
     *
     * @param int $quizgeistid Activity ID.
     * @return bool
     */
    public static function structure_is_held(int $quizgeistid): bool {
        return (self::$heldstructures[$quizgeistid] ?? 0) > 0;
    }

    /**
     * Release a deterministically acquired lock set in reverse order.
     *
     * @param \core\lock\lock[] $locks Locks.
     * @return void
     */
    public static function release_all(array $locks): void {
        foreach (array_reverse($locks) as $lock) {
            if ($lock instanceof \core\lock\lock) {
                $lock->release();
            }
        }
    }

    /**
     * Acquire one namespaced root lock.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Stable lineage root.
     * @return \core\lock\lock
     */
    private static function acquire_root(
        int $quizgeistid,
        int $rootid
    ): \core\lock\lock {
        return self::acquire_resource(
            'lineage:' . $quizgeistid . ':' . $rootid
        );
    }

    /**
     * Acquire one namespaced resource lock.
     *
     * @param string $resource Resource key.
     * @return \core\lock\lock
     */
    private static function acquire_resource(string $resource): \core\lock\lock {
        global $DB;

        if ($DB->is_transaction_started()) {
            debugging(
                'Quizgeist question-content locks must be acquired before '
                    . 'starting a database transaction.',
                DEBUG_DEVELOPER
            );
        }
        $factory = \core\lock\lock_config::get_lock_factory(
            'mod_quizgeist_question_content'
        );
        $lock = $factory->get_lock(
            $resource,
            self::TIMEOUT_SECONDS
        );
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        return $lock;
    }
}
