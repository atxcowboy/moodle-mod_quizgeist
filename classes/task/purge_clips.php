<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled deletion of evaluated clip audio (E-10).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\task;

use mod_quizgeist\local\media\clip_limits;
use mod_quizgeist\local\media\clip_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Removes the AUDIO of clips that have been evaluated, and keeps the text.
 *
 * Decision E-10 makes immediate deletion the default: as soon as a clip has
 * reached a terminal transcription state, the recording has served its purpose
 * and the transcript carries everything a teacher needs. A school that wants a
 * review window sets `clip_retention_days` deliberately.
 *
 * Two properties matter more than throughput here:
 *
 * 1. A clip that has NOT been evaluated yet is never touched, no matter how
 *    old it is. Deleting audio that still owes someone a transcript would
 *    destroy a learner's answer rather than protect their data.
 * 2. The row survives the audio. `audiodeleted` is what makes the deletion
 *    provable afterwards, which is the difference between data protection and
 *    data loss.
 */
final class purge_clips extends \core\task\scheduled_task {

    /** Clips handled per run; the task is idempotent and simply continues next time. */
    private const BATCH = 500;

    public function get_name(): string {
        return get_string('task:purgeclips', 'mod_quizgeist');
    }

    public function execute(): void {
        global $DB;

        $retentiondays = clip_limits::retention_days();
        $cutoff = time() - ($retentiondays * DAYSECS);

        // Terminal states only: a pending clip still owes its owner a result.
        // `declined` counts as terminal — the learner refused transcription,
        // so the recording has no remaining purpose either.
        [$statesql, $stateparams] = $DB->get_in_or_equal(
            ['done', 'failed', 'declined'],
            SQL_PARAMS_NAMED,
            'st'
        );
        $records = $DB->get_records_select(
            'quizgeist_clips',
            "audiodeleted = 0 AND transcriptstate {$statesql} AND timemodified <= :cutoff",
            $stateparams + ['cutoff' => $cutoff],
            'id ASC',
            'id, quizgeistid, itemid, audiodeleted',
            0,
            self::BATCH
        );
        if (!$records) {
            return;
        }

        $contexts = [];
        $deleted = 0;
        foreach ($records as $clip) {
            $quizgeistid = (int)$clip->quizgeistid;
            if (!array_key_exists($quizgeistid, $contexts)) {
                $contexts[$quizgeistid] = self::module_context($quizgeistid);
            }
            $context = $contexts[$quizgeistid];
            if ($context === null) {
                // Orphaned bookkeeping without a module: the file area is gone
                // with the context, so only the row remains to be cleared.
                $DB->set_field('quizgeist_clips', 'audiodeleted', time(), ['id' => (int)$clip->id]);
                continue;
            }
            if (clip_service::delete_audio($context, $clip)) {
                $deleted++;
            }
        }
        mtrace("mod_quizgeist: removed audio of {$deleted} evaluated clips.");
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
