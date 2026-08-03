<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Safety-net cleanup for generated media whose MUC draft expired untouched.
 */
final class cleanup_ai_media_task extends \core\task\adhoc_task {
    public function get_name(): string {
        return get_string('task:cleanupaimedia', 'mod_quizgeist');
    }

    public function execute(): void {
        $data = $this->get_custom_data();
        $userid = isset($data->userid) ? (int)$data->userid : 0;
        $drafts = isset($data->drafts) && is_array($data->drafts)
            ? $data->drafts
            : [];
        if ($userid <= 0) {
            return;
        }
        $context = \context_user::instance($userid);
        $fs = get_file_storage();
        foreach ($drafts as $draft) {
            $draft = (array)$draft;
            $draftid = isset($draft['id']) ? (int)$draft['id'] : 0;
            $expectedsignature = isset($draft['signature'])
                ? (string)$draft['signature']
                : '';
            $currentsignature = self::draft_signature($userid, $draftid);
            if ($draftid > 0
                    && $expectedsignature !== ''
                    && $currentsignature !== ''
                    && hash_equals($expectedsignature, $currentsignature)) {
                $fs->delete_area_files(
                    $context->id,
                    'user',
                    'draft',
                    $draftid
                );
            }
        }
    }

    /**
     * Queue exact generated draft areas slightly after their MUC lifetime.
     *
     * @param int $userid Draft owner.
     * @param int[] $draftids Server-created draft IDs.
     */
    public static function queue(int $userid, array $draftids): void {
        $draftids = array_values(array_unique(array_filter(
            array_map('intval', $draftids),
            static fn(int $id): bool => $id > 0
        )));
        if ($userid <= 0 || !$draftids) {
            return;
        }
        $drafts = [];
        foreach ($draftids as $draftid) {
            $signature = self::draft_signature($userid, $draftid);
            if ($signature !== '') {
                $drafts[] = [
                    'id' => $draftid,
                    'signature' => $signature,
                ];
            }
        }
        if (!$drafts) {
            return;
        }
        $task = new self();
        $task->set_component('mod_quizgeist');
        $task->set_custom_data([
            'userid' => $userid,
            // A draft item ID can eventually be reused. The delayed task may
            // delete only the exact file set that existed when it was queued.
            'drafts' => $drafts,
        ]);
        $task->set_next_run_time(
            time() + \quizgeistaddon_ai\local\ai\draft_store::TTL + 60
        );
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Fingerprint exact stored-file rows so delayed cleanup cannot delete a
     * newer, unrelated Moodle draft that happens to reuse the numeric item ID.
     */
    private static function draft_signature(int $userid, int $draftid): string {
        if ($userid <= 0 || $draftid <= 0) {
            return '';
        }
        $context = \context_user::instance($userid);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'user',
            'draft',
            $draftid,
            'id ASC',
            false
        );
        if (!$files) {
            return '';
        }
        $rows = [];
        foreach ($files as $file) {
            if (!$file instanceof \stored_file || $file->is_directory()) {
                continue;
            }
            $rows[] = [
                (int)$file->get_id(),
                (string)$file->get_filepath(),
                (string)$file->get_filename(),
                (string)$file->get_contenthash(),
                (int)$file->get_filesize(),
            ];
        }
        if (!$rows) {
            return '';
        }
        return hash(
            'sha256',
            json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
