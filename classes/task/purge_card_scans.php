<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled deletion of evaluated card-scan pictures (E-10).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\task;

use mod_quizgeist\local\cards\card_limits;
use mod_quizgeist\local\cards\card_scan_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Removes class photographs and keeps only their result.
 *
 * A card scan is the most sensitive raw datum this plugin ever holds: a
 * photograph of a whole class. Decision E-10 therefore makes deletion the
 * default rather than an option, and this task applies it in two passes:
 *
 * 1. **Evaluated scans go immediately.** `confirmed`, `discarded` and `failed`
 *    have nothing left to show. `card_scan_service::confirm()` already deletes
 *    the picture inline; this pass is the safety net for a request that died
 *    between booking and deletion.
 * 2. **Abandoned scans go after the retention ceiling.** A scan still sitting
 *    in `pending` or `recognised` belongs to a confirmation screen somebody
 *    walked away from. It is deleted after `card_scan_retention_hours`
 *    (default 24), and the row keeps the result so the class list stays
 *    readable afterwards.
 *
 * The row always survives the picture: `imagedeleted` is what makes the
 * deletion provable, and that is the difference between data protection and
 * data loss.
 */
final class purge_card_scans extends \core\task\scheduled_task {

    /** Scans handled per run; the task is idempotent and continues next time. */
    private const BATCH = 500;

    public function get_name(): string {
        return get_string('task:purgecardscans', 'mod_quizgeist');
    }

    public function execute(): void {
        global $DB;

        $cutoff = time() - (card_limits::retention_hours() * HOURSECS);
        [$statesql, $stateparams] = $DB->get_in_or_equal(
            card_limits::TERMINAL_STATES,
            SQL_PARAMS_NAMED,
            'st'
        );
        $records = $DB->get_records_select(
            'quizgeist_card_scans',
            "imagedeleted = 0 AND (state {$statesql} OR timemodified <= :cutoff)",
            $stateparams + ['cutoff' => $cutoff],
            'id ASC',
            'id, quizgeistid, itemid, imagedeleted',
            0,
            self::BATCH
        );
        if (!$records) {
            return;
        }

        $contexts = [];
        $deleted = 0;
        foreach ($records as $scan) {
            $quizgeistid = (int)$scan->quizgeistid;
            if (!array_key_exists($quizgeistid, $contexts)) {
                $contexts[$quizgeistid] = self::module_context($quizgeistid);
            }
            $context = $contexts[$quizgeistid];
            if ($context === null) {
                // Orphaned bookkeeping without a module: the file area went
                // with the context, so only the row remains to be marked.
                $DB->set_field(
                    'quizgeist_card_scans',
                    'imagedeleted',
                    time(),
                    ['id' => (int)$scan->id]
                );
                continue;
            }
            if (card_scan_service::delete_image($context, $scan)) {
                $deleted++;
            }
        }
        mtrace("mod_quizgeist: removed {$deleted} card scan images.");
    }

    /**
     * Resolve the module context of one activity.
     *
     * @param int $quizgeistid Activity ID.
     * @return \context_module|null
     */
    private static function module_context(int $quizgeistid): ?\context_module {
        try {
            $cm = get_coursemodule_from_instance('quizgeist', $quizgeistid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                return null;
            }
            return \context_module::instance((int)$cm->id, IGNORE_MISSING) ?: null;
        } catch (\Throwable $ignored) {
            return null;
        }
    }
}
