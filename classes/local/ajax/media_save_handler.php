<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\ajax;

use mod_quizgeist\local\editor\editor_service;
use mod_quizgeist\local\editor\media_service;
use mod_quizgeist\local\editor\question_content_lock;

defined('MOODLE_INTERNAL') || die();

/**
 * Programmatically finalises a complete Moodle draft area.
 */
final class media_save_handler implements action_handler {
    public function execute(action_context $context): array {
        global $DB;

        $payload = $context->get_payload();
        $area = $payload['area'] ?? null;
        if (!is_string($area)) {
            throw new \invalid_parameter_exception('area is required.');
        }
        $rawitemid = $payload['itemid'] ?? null;
        $validitemid = is_int($rawitemid)
            || (is_string($rawitemid) && (bool)preg_match('/^(?:0|[1-9][0-9]*)$/D', $rawitemid));
        $itemidvalue = $validitemid ? filter_var($rawitemid, FILTER_VALIDATE_INT) : false;
        if ($itemidvalue === false || (int)$itemidvalue < 0) {
            throw new \invalid_parameter_exception('itemid is invalid.');
        }
        $itemid = (int)$itemidvalue;
        $draftitemid = editor_service::positive_id(
            $payload['draftitemid'] ?? null,
            'draftitemid'
        );
        $quizgeist = $context->get_instance();
        $modulecontext = $context->get_module_context();
        media_service::require_module_target((int)$quizgeist->id, $area, $itemid);
        $contentlock = $area === 'questionmedia'
            ? question_content_lock::acquire_for_question(
                (int)$quizgeist->id,
                $itemid
            )
            : null;
        try {
            // Revalidate after waiting: the concrete ID may have become an
            // archived COW source while this request was queued.
            media_service::require_module_target(
                (int)$quizgeist->id,
                $area,
                $itemid
            );
            return $this->execute_locked(
                $context,
                $area,
                $itemid,
                $draftitemid
            );
        } finally {
            if ($contentlock !== null) {
                $contentlock->release();
            }
        }
    }

    /**
     * Execute while holding the question lineage lock when applicable.
     *
     * @param action_context $context Request context.
     * @param string $area File area.
     * @param int $itemid Target item.
     * @param int $draftitemid Complete draft item.
     * @return array
     */
    private function execute_locked(
        action_context $context,
        string $area,
        int $itemid,
        int $draftitemid
    ): array {
        global $DB;

        $quizgeist = $context->get_instance();
        $modulecontext = $context->get_module_context();
        $mediachanged = media_service::complete_draft_differs(
            $modulecontext,
            $area,
            $itemid,
            $draftitemid
        );
        $olditemid = $itemid;
        $questionidmap = (object)[];
        $pendingmodified = null;

        // Own only the DB/COW phase here. Final-file merges can physically delete
        // stored files and therefore must run after a successful DB commit.
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            if ($area === 'questionmedia' && $mediachanged) {
                $editable = editor_service::ensure_editable_question(
                    $quizgeist,
                    $modulecontext,
                    $itemid,
                    (int)$context->get_user()->id
                );
                $itemid = (int)$editable['record']->id;
                $questionidmap = $editable['questionIdMap'];
                // Commit a non-playable target before final-file replacement.
                // A failed merge consequently leaves the question draft.
                $pending = editor_service::mark_question_media_pending(
                    (int)$quizgeist->id,
                    $itemid
                );
                $pendingmodified = (int)$pending->timemodified;
            } else if ($area === 'questionmedia') {
                $current = $DB->get_record_sql(
                    'SELECT id, status
                       FROM {quizgeist_questions}
                      WHERE id = :id
                        AND quizgeistid = :quizgeistid
                        FOR UPDATE',
                    [
                        'id' => $itemid,
                        'quizgeistid' => (int)$quizgeist->id,
                    ],
                    MUST_EXIST
                );
                if ($current->status === 'media_pending') {
                    $pending = editor_service::mark_question_media_pending(
                        (int)$quizgeist->id,
                        $itemid
                    );
                    $pendingmodified = (int)$pending->timemodified;
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        if ($area === 'questionmedia' && !$mediachanged) {
            $record = $DB->get_record('quizgeist_questions', [
                'id' => $itemid,
                'quizgeistid' => $quizgeist->id,
            ], '*', MUST_EXIST);
            if ($record->status === 'media_pending' && $pendingmodified === null) {
                // A previous process may have died after its durable marker.
                // The lineage lock makes validation of the extant files a safe
                // takeover of that abandoned operation.
                $pendingmodified = (int)$record->timemodified;
            }
        }

        try {
            if ($mediachanged) {
                media_service::save_complete_draft(
                    $modulecontext,
                    $area,
                    $itemid,
                    $draftitemid
                );
            }
            $response = [
                'area' => $area,
                'itemid' => $itemid,
                'changed' => $mediachanged,
            ];
            if ($area === 'questionmedia') {
                $response['oldquestionid'] = $olditemid;
                $response['questionIdMap'] = $questionidmap;
                if ($pendingmodified !== null) {
                    // Only the request owning this exact pending token may
                    // validate and promote the server manifest.
                    $response['question'] = editor_service::refresh_question_media(
                        (int)$quizgeist->id,
                        $itemid,
                        $modulecontext,
                        $pendingmodified
                    );
                } else {
                    $record = $DB->get_record('quizgeist_questions', [
                        'id' => $itemid,
                        'quizgeistid' => $quizgeist->id,
                    ], '*', MUST_EXIST);
                    $response['question'] = editor_service::serialise_question(
                        $record,
                        $modulecontext
                    );
                }
            }
            $response['files'] = media_service::manifest(
                $modulecontext,
                $area,
                $itemid
            );
            return $response;
        } catch (\Throwable $exception) {
            if ($pendingmodified !== null) {
                try {
                    editor_service::fail_question_media_pending(
                        (int)$quizgeist->id,
                        $itemid,
                        $pendingmodified
                    );
                } catch (\Throwable $ignored) {
                    // Retaining media_pending is safer than masking the
                    // original file/validation exception.
                }
            }
            throw $exception;
        }
    }
}
