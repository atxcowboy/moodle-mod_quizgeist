<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Nightly compaction of the repetition state.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Removes repetition rows that can no longer describe anything.
 *
 * Repetition state is written by every answer and read by dashboards and the
 * parent digest. Nothing here recomputes learning state — that would make the
 * scheduler depend on cron, and a missed cron run would silently change what a
 * learner sees. The task only removes rows whose subject is gone: a deleted
 * question root, a deleted activity, a deleted user account.
 */
final class refresh_schedule_digest extends \core\task\scheduled_task {

    /** Rows removed per pass, so one huge site cannot block the cron queue. */
    private const BATCH = 1000;

    /**
     * Localised task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:refreshscheduledigest', 'mod_quizgeist');
    }

    /**
     * Prune orphaned repetition rows in bounded batches.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $removed = 0;
        do {
            $orphans = $DB->get_fieldset_sql(
                'SELECT s.id
                   FROM {quizgeist_schedule} s
              LEFT JOIN {quizgeist} m ON m.id = s.quizgeistid
              LEFT JOIN {user} u ON u.id = s.userid AND u.deleted = 0
                  WHERE m.id IS NULL
                     OR u.id IS NULL
                     OR NOT EXISTS (
                            SELECT 1
                              FROM {quizgeist_questions} q
                             WHERE q.quizgeistid = s.quizgeistid
                               AND CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END
                                   = s.rootid
                        )
               ORDER BY s.id ASC',
                [],
                0,
                self::BATCH
            );
            if (!$orphans) {
                break;
            }
            $DB->delete_records_list(
                'quizgeist_schedule',
                'id',
                array_map('intval', $orphans)
            );
            $removed += count($orphans);
        } while (count($orphans) === self::BATCH);

        if ($removed > 0) {
            mtrace(
                'mod_quizgeist: '
                . $removed
                . ' verwaiste Wiederholungszeilen entfernt.'
            );
        }
    }
}
