<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Transactional editor application service.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\editor;

use mod_quizgeist\local\live\qtype\registry as question_type_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Implements question CRUD, ordering, activity autosave and bootstrap.
 */
final class editor_service {

    /** @var string[] Appearance themes fixed by DESIGN/DESIGN.md. */
    public const THEMES = ['hell', 'dunkel', 'weltraum', 'ozean', 'retro-arcade', 'jahreszeiten'];

    /** @var string[] Themes included in the free basis. */
    public const BASE_THEMES = ['hell', 'dunkel', 'weltraum', 'ozean', 'retro-arcade'];

    /** @var string[] Seasonal variants fixed by DESIGN/DESIGN.md. */
    public const SEASONS = ['herbst', 'winter', 'fruehling', 'sommer'];

    /** Maximum size of one confirmed, already validated document import. */
    private const MAX_IMPORT_ITEMS = 150;

    /**
     * Build the complete editor bootstrap response.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @return array
     */
    public static function bootstrap(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context
    ): array {
        global $DB;

        $records = $DB->get_records_select(
            'quizgeist_questions',
            "quizgeistid = :quizgeistid
                AND status <> :archived",
            [
                'quizgeistid' => $quizgeist->id,
                'archived' => 'archived',
            ],
            'sortorder ASC, id ASC'
        );
        $questions = [];
        $invalidcount = 0;
        foreach ($records as $record) {
            $question = self::serialise_question($record, $context);
            if ($question['validationErrors']) {
                $invalidcount++;
            }
            $questions[] = $question;
        }

        $creatabletypes = question_type_registry::creatable_types();
        $defaults = [];
        foreach ($creatabletypes as $qtype) {
            $defaults[$qtype] = question_schema::defaults($qtype);
        }

        $background = media_service::manifest($context, 'background', 0);
        $logo = media_service::manifest($context, 'logo', 0);

        return [
            'activity' => self::serialise_activity($quizgeist, $background, $logo),
            'questions' => $questions,
            'supportedTypes' => $creatabletypes,
            'liveSupportedTypes' => question_type_registry::types(),
            'questionDefaults' => $defaults,
            'themes' => self::creatable_themes((string)$quizgeist->theme),
            'seasons' => (string)$quizgeist->theme === 'jahreszeiten'
                || \mod_quizgeist\local\licence\feature_gate::can_create('modes')
                ? self::SEASONS
                : [],
            'invalidQuestionCount' => $invalidcount,
            'mediaPickerUrl' => (new \moodle_url('/mod/quizgeist/media.php', [
                'id' => (int)$cm->id,
            ]))->out(false),
            'tts' => self::tts_configuration(),
        ];
    }

    /**
     * Create a draft question at the end of the active list.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Creator.
     * @param string $qtype Type.
     * @return array
     */
    public static function create_question(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        string $qtype
    ): array {
        $structurelock = question_content_lock::acquire_structure(
            (int)$quizgeist->id
        );
        try {
            return self::create_question_locked(
                $quizgeist,
                $context,
                $userid,
                $qtype
            );
        } finally {
            $structurelock->release();
        }
    }

    /**
     * Append confirmed AI/import proposals as explicit editor drafts.
     *
     * This is the single persistence boundary used by P7. Every proposal is
     * normalised by question_schema, resolved through the live strategy
     * registry and, when present, finalised by the existing media_service.
     * Model output never controls IDs, lineage metadata or lifecycle state.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param \context_module $context Module context.
     * @param int $userid Confirming teacher.
     * @param array $items List containing question and optional mediaDraftId.
     * @param int|null $importid Optional external-import provenance.
     * @param bool $allowcompatibilitytypes Whether the free Kahoot migration
     *     path may create types retained by the compatibility runtime.
     * @return array Canonical serialised draft questions.
     */
    public static function import_confirmed_drafts(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        array $items,
        ?int $importid = null,
        bool $allowcompatibilitytypes = false
    ): array {
        global $DB;

        if (!array_is_list($items) || !$items || count($items) > self::MAX_IMPORT_ITEMS) {
            throw new \invalid_parameter_exception(
                'Question import requires between one and 150 draft items.'
            );
        }
        if ($importid !== null
                && ($importid < 1
                    || !$DB->record_exists('quizgeist_imports', [
                        'id' => $importid,
                        'quizgeistid' => (int)$quizgeist->id,
                        'status' => 'pending',
                    ]))) {
            throw new \invalid_parameter_exception(
                'External import provenance does not belong to this activity.'
            );
        }

        $prepared = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_array($item['question'] ?? null)) {
                throw new \invalid_parameter_exception('An AI draft question is malformed.');
            }
            $normalised = question_schema::normalise($item['question']);
            // Prove that runtime presentation/evaluation already has the
            // canonical strategy; no P7-specific question logic is introduced.
            \mod_quizgeist\local\live\qtype\registry::get(
                $normalised['question']['qtype']
            );
            if (!$allowcompatibilitytypes) {
                question_type_registry::assert_creatable(
                    $normalised['question']['qtype']
                );
            }
            $mediadraftid = (int)($item['mediaDraftId'] ?? 0);
            if ($mediadraftid > 0) {
                media_service::validate_complete_draft(
                    $context,
                    'questionmedia',
                    $mediadraftid
                );
            }
            // F13: auch ein Import legt einen Buehnen-Untermodus NEU an. Ohne
            // diesen Aufruf waere der Importweg das Schlupfloch am Tor vorbei.
            self::guard_stage_check([], $normalised['question']['options']);
            $prepared[] = [
                'question' => $normalised['question'],
                'validationErrors' => $normalised['validationErrors'],
                'mediaDraftId' => $mediadraftid,
            ];
        }

        $structurelock = question_content_lock::structure_is_held(
            (int)$quizgeist->id
        )
            ? null
            : question_content_lock::acquire_structure((int)$quizgeist->id);
        $createdids = [];
        try {
            $maxsort = $DB->get_field_sql(
                "SELECT MAX(sortorder)
                   FROM {quizgeist_questions}
                  WHERE quizgeistid = :quizgeistid
                    AND status <> :archived",
                ['quizgeistid' => (int)$quizgeist->id, 'archived' => 'archived']
            );
            $sortorder = $maxsort === null ? 0 : ((int)$maxsort + 1);
            $now = time();
            $transaction = \mod_quizgeist\local\transaction_scope::begin();
            try {
                foreach ($prepared as $index => $item) {
                    $question = $item['question'];
                    $record = (object)[
                        'quizgeistid' => (int)$quizgeist->id,
                        'importid' => $importid,
                        'rootid' => 0,
                        'version' => 1,
                        'sortorder' => $sortorder + $index,
                        'qtype' => $question['qtype'],
                        'questiontext' => $question['questiontext'],
                        'questionformat' => FORMAT_PLAIN,
                        'optionsjson' => question_schema::encode_options($question['options']),
                        'timelimit' => $question['timelimit'],
                        'pointmode' => $question['pointmode'],
                        'explanation' => $question['explanation'],
                        // Keep rows invisible and uneditable until every
                        // generated file has crossed the final media boundary.
                        'status' => 'media_pending',
                        'createdby' => $userid,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ];
                    $record->id = (int)$DB->insert_record('quizgeist_questions', $record);
                    $record->rootid = (int)$record->id;
                    $DB->set_field('quizgeist_questions', 'rootid', $record->rootid, [
                        'id' => $record->id,
                    ]);
                    $createdids[] = (int)$record->id;
                }
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }

            try {
                foreach ($prepared as $index => $item) {
                    $questionid = $createdids[$index];
                    $manifest = [];
                    if ($item['mediaDraftId'] > 0) {
                        $manifest = media_service::save_complete_draft(
                            $context,
                            'questionmedia',
                            $questionid,
                            $item['mediaDraftId']
                        );
                    }
                    $question = $item['question'];
                    $question['options'] = media_service::synchronise_question_options(
                        $question['options'],
                        $manifest
                    );
                    // Validate against authoritative final files. The error
                    // result is derived again by serialise_question; lifecycle
                    // deliberately remains draft until an editor autosave.
                    question_schema::validate_media(
                        $question,
                        $item['validationErrors'],
                        $manifest
                    );
                    $record = $DB->get_record(
                        'quizgeist_questions',
                        ['id' => $questionid, 'quizgeistid' => (int)$quizgeist->id],
                        '*',
                        MUST_EXIST
                    );
                    $update = (object)[
                        'id' => $questionid,
                        'optionsjson' => question_schema::encode_options($question['options']),
                        'status' => 'draft',
                        'timemodified' => self::next_modified_time(
                            (int)$record->timemodified
                        ),
                    ];
                    // Status must always leave media_pending, even when a
                    // text-only import did not change its options JSON.
                    $DB->update_record('quizgeist_questions', $update);
                }
            } catch (\Throwable $exception) {
                self::remove_failed_import($createdids, (int)$quizgeist->id, $context);
                throw $exception;
            }

            $records = $DB->get_records_list(
                'quizgeist_questions',
                'id',
                $createdids,
                'sortorder ASC, id ASC'
            );
            $questions = [];
            foreach ($createdids as $questionid) {
                if (isset($records[$questionid])) {
                    $questions[] = self::serialise_question($records[$questionid], $context);
                }
            }
            return $questions;
        } finally {
            if ($structurelock !== null) {
                $structurelock->release();
            }
        }
    }

    /**
     * Compensate a failed post-transaction media finalisation.
     *
     * Only freshly created, unreferenced P7 rows and their exact file areas are
     * targeted. Failure cleanup must never touch pre-existing quiz content.
     *
     * @param int[] $questionids Newly inserted IDs.
     * @param int $quizgeistid Activity ID.
     * @param \context_module $context Module context.
     */
    private static function remove_failed_import(
        array $questionids,
        int $quizgeistid,
        \context_module $context
    ): void {
        global $DB;

        $questionids = array_values(array_filter(
            array_map('intval', $questionids),
            static fn(int $id): bool => $id > 0
        ));
        if (!$questionids) {
            return;
        }
        $fs = get_file_storage();
        foreach ($questionids as $questionid) {
            $fs->delete_area_files(
                $context->id,
                'mod_quizgeist',
                'questionmedia',
                $questionid
            );
        }
        [$insql, $params] = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'aiqid');
        $params['quizgeistid'] = $quizgeistid;
        $DB->delete_records_select(
            'quizgeist_questions',
            "quizgeistid = :quizgeistid AND id {$insql}",
            $params
        );
    }

    /**
     * Create after the activity structure lock has been acquired.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Creator.
     * @param string $qtype Type.
     * @return array
     */
    private static function create_question_locked(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        string $qtype
    ): array {
        global $DB;

        question_type_registry::assert_creatable($qtype);
        $normalised = question_schema::normalise(question_schema::defaults($qtype));
        $now = time();
        $maxsort = $DB->get_field_sql(
            'SELECT MAX(sortorder)
               FROM {quizgeist_questions}
              WHERE quizgeistid = :quizgeistid
                AND status <> :archived',
            ['quizgeistid' => $quizgeist->id, 'archived' => 'archived']
        );
        $record = (object)[
            'quizgeistid' => (int)$quizgeist->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => $maxsort === null ? 0 : ((int)$maxsort + 1),
            'qtype' => $normalised['question']['qtype'],
            'questiontext' => $normalised['question']['questiontext'],
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => question_schema::encode_options($normalised['question']['options']),
            'timelimit' => $normalised['question']['timelimit'],
            'pointmode' => $normalised['question']['pointmode'],
            'explanation' => $normalised['question']['explanation'],
            'status' => 'draft',
            'createdby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $record->id = (int)$DB->insert_record('quizgeist_questions', $record);
            $record->rootid = (int)$record->id;
            $DB->set_field('quizgeist_questions', 'rootid', $record->rootid, [
                'id' => $record->id,
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return self::serialise_question($record, $context);
    }

    /**
     * Autosave one complete canonical question.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Current editor.
     * @param array $input Submitted question.
     * @return array
     */
    public static function save_question(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        array $input
    ): array {
        $questionid = self::positive_id($input['id'] ?? null, 'question.id');
        $contentlock = question_content_lock::acquire_for_question(
            (int)$quizgeist->id,
            $questionid
        );
        try {
            return self::save_question_locked(
                $quizgeist,
                $context,
                $userid,
                $input
            );
        } finally {
            $contentlock->release();
        }
    }

    /**
     * Apply one explicitly confirmed AI explanation through normal editor COW.
     *
     * The question payload is reconstructed from authoritative storage. The AI
     * is allowed to change only the explanation, and the resulting current
     * version deliberately returns to draft lifecycle even when otherwise
     * valid.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Confirming teacher.
     * @param int $questionid Target current question.
     * @param int $expectedmodified Generation-time concurrency token.
     * @param string $explanation Confirmed plain-text explanation.
     * @return array Canonical serialised draft question.
     */
    public static function apply_confirmed_explanation(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        int $questionid,
        int $expectedmodified,
        string $explanation
    ): array {
        global $DB;

        if ($questionid <= 0 || $expectedmodified <= 0 || trim($explanation) === '') {
            throw new \invalid_parameter_exception('The explanation draft is incomplete.');
        }
        $contentlock = question_content_lock::acquire_for_question(
            (int)$quizgeist->id,
            $questionid
        );
        try {
            $current = $DB->get_record(
                'quizgeist_questions',
                [
                    'id' => $questionid,
                    'quizgeistid' => (int)$quizgeist->id,
                ],
                '*',
                MUST_EXIST
            );
            if (in_array((string)$current->status, ['archived', 'media_pending'], true)) {
                throw new \invalid_parameter_exception('The explanation target is unavailable.');
            }
            return self::save_question_locked(
                $quizgeist,
                $context,
                $userid,
                [
                    'id' => $questionid,
                    'timemodified' => $expectedmodified,
                    'qtype' => (string)$current->qtype,
                    'questiontext' => (string)$current->questiontext,
                    'options' => question_schema::decode_options(
                        (string)$current->optionsjson
                    ),
                    'timelimit' => (int)$current->timelimit,
                    'pointmode' => (string)$current->pointmode,
                    'explanation' => $explanation,
                ],
                true
            );
        } finally {
            $contentlock->release();
        }
    }

    /**
     * Save after the stable lineage lock has been acquired.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Current editor.
     * @param array $input Submitted question.
     * @return array
     */
    private static function save_question_locked(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        array $input,
        bool $forcedraft = false
    ): array {
        global $DB;

        $questionid = self::positive_id($input['id'] ?? null, 'question.id');
        $expectedmodified = self::positive_id(
            $input['timemodified'] ?? null,
            'question.timemodified'
        );
        $normalised = question_schema::normalise($input);
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $current = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_questions}
                  WHERE id = :id
                    AND quizgeistid = :quizgeistid
                    FOR UPDATE',
                [
                    'id' => $questionid,
                    'quizgeistid' => (int)$quizgeist->id,
                ],
                MUST_EXIST
            );
            if (in_array($current->status, ['archived', 'media_pending'], true)
                    || (int)$current->timemodified !== $expectedmodified) {
                $conflict = self::current_lineage_question($current, $context);
                throw new edit_conflict_exception('question', $conflict);
            }
            if ((string)$current->qtype !== $normalised['question']['qtype']) {
                question_type_registry::assert_creatable(
                    $normalised['question']['qtype']
                );
            }

            $manifest = media_service::manifest($context, 'questionmedia', $questionid);
            $currentnormalised = question_schema::normalise([
                'qtype' => (string)$current->qtype,
                'questiontext' => (string)$current->questiontext,
                'options' => question_schema::decode_options($current->optionsjson),
                'timelimit' => (int)$current->timelimit,
                'pointmode' => (string)$current->pointmode,
                'explanation' => (string)$current->explanation,
            ]);
            $currentoptions = media_service::synchronise_question_options(
                $currentnormalised['question']['options'],
                $manifest
            );
            $options = media_service::synchronise_question_options(
                $normalised['question']['options'],
                $manifest
            );
            // F13: gated ist das ANLEGEN eines Buehnen-Untermodus, nicht sein
            // Bestand. Eine Frage, die ihn schon traegt, bleibt speicherbar,
            // auch wenn die Lizenz abgelaufen ist — Bestandsschutz (2.6).
            self::guard_stage_check($currentoptions, $options);
            $contentchanged = self::question_content_fingerprint(
                $currentnormalised['question'],
                $currentoptions
            ) !== self::question_content_fingerprint(
                $normalised['question'],
                $options
            );

            if ($contentchanged && self::is_question_referenced($questionid, true)) {
                $current = self::create_question_version(
                    $current,
                    $context,
                    $userid
                );
                $questionid = (int)$current->id;
                $manifest = media_service::manifest(
                    $context,
                    'questionmedia',
                    $questionid
                );
                $options = media_service::synchronise_question_options(
                    $normalised['question']['options'],
                    $manifest
                );
            }

            $errors = question_schema::validate_media([
                'qtype' => $normalised['question']['qtype'],
                'options' => $options,
            ], $normalised['validationErrors'], $manifest);
            // The lineage is asked, not the row: create_question_version()
            // above may already have moved us to a fresh version, and the
            // submission hangs on the root.
            $pendingsubmission = \mod_quizgeist\local\workshop\workshop_repository
                ::root_is_pending(
                    (int)$quizgeist->id,
                    (int)($current->rootid ?? 0) > 0
                        ? (int)$current->rootid
                        : (int)$current->id
                );
            $update = (object)[
                'id' => $questionid,
                'qtype' => $normalised['question']['qtype'],
                'questiontext' => $normalised['question']['questiontext'],
                'questionformat' => FORMAT_PLAIN,
                'optionsjson' => question_schema::encode_options($options),
                'timelimit' => $normalised['question']['timelimit'],
                'pointmode' => $normalised['question']['pointmode'],
                'explanation' => $normalised['question']['explanation'],
                // F7: eine eingereichte Schuelerfrage bleibt Entwurf, bis eine
                // Lehrkraft sie freigegeben hat — auch wenn ein Autospeichern
                // sie formal vollstaendig macht. Ohne diese Zeile waere die
                // Freigabe ein Klick, den ein Speichervorgang ersetzen kann.
                'status' => ($forcedraft || $errors || $pendingsubmission)
                    ? 'draft'
                    : 'ready',
            ];
            $changed = self::question_update_changed($current, $update);
            if ($changed) {
                $update->timemodified = self::next_modified_time(
                    (int)$current->timemodified
                );
                $DB->update_record('quizgeist_questions', $update);
                $saved = (object)array_merge((array)$current, (array)$update);
            } else {
                $saved = $current;
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return self::serialise_question($saved, $context);
    }

    /**
     * The one gate of the F13 stage-check sub-mode.
     *
     * It asks about the CHANGE, never about the state: switching the sub-mode
     * on is a new creation and needs an entitlement, keeping it on is existing
     * content and needs none. Modelled on the seasons theme gate above, which
     * asks the same question about the same kind of switch.
     *
     * It is deliberately ONE method for every path that can bring a stage
     * sub-mode into existence — editor save, AI/import confirmation, question
     * duplication and template publish/import. A path that creates such a
     * question without calling it would be the hole in the sales boundary, not
     * a shortcut; [P11-E4] closed exactly that kind of hole in three of them.
     * Public for the template service, which is a creation path of its own.
     *
     * @param array $previousoptions Options before the edit; empty on creation.
     * @param array $nextoptions Options the caller wants to store.
     * @return void
     */
    public static function guard_stage_check(
        array $previousoptions,
        array $nextoptions
    ): void {
        if (!empty($nextoptions['stageCheck']) && empty($previousoptions['stageCheck'])) {
            \mod_quizgeist\local\licence\feature_gate::require(
                'buehne',
                \mod_quizgeist\local\licence\feature_gate::CREATE_NEW
            );
        }
    }

    /**
     * Copy a referenced active question before it is mutated.
     *
     * The caller must include this operation and its subsequent mutation in the
     * same delegated transaction. Historical question content and media then
     * remain attached to the ID stored by answers and live sessions. Ordering is
     * deliberately mutable metadata and never enters this path.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $questionid Requested question ID.
     * @param int $userid Current editor.
     * @return array{record:\stdClass,previousid:int,questionIdMap:\stdClass}
     */
    public static function ensure_editable_question(
        \stdClass $quizgeist,
        \context_module $context,
        int $questionid,
        int $userid
    ): array {
        global $DB;

        if ($DB->is_transaction_started()) {
            $record = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_questions}
                  WHERE id = :id
                    AND quizgeistid = :quizgeistid
                    FOR UPDATE',
                [
                    'id' => $questionid,
                    'quizgeistid' => (int)$quizgeist->id,
                ],
                MUST_EXIST
            );
        } else {
            $record = $DB->get_record('quizgeist_questions', [
                'id' => $questionid,
                'quizgeistid' => $quizgeist->id,
            ], '*', MUST_EXIST);
        }
        if ($record->status === 'archived') {
            throw new \invalid_parameter_exception('Archived questions cannot be edited.');
        }
        if ($record->status === 'media_pending') {
            // A pending target was never pin-able by a live session. Under the
            // held lineage lock, a retry safely takes this concrete version
            // over in-place instead of preserving partial files via another COW.
            return [
                'record' => $record,
                'previousid' => $questionid,
                'questionIdMap' => (object)[],
            ];
        }
        if (!self::is_question_referenced(
            $questionid,
            $DB->is_transaction_started()
        )) {
            return [
                'record' => $record,
                'previousid' => $questionid,
                'questionIdMap' => (object)[],
            ];
        }

        $copy = self::create_question_version($record, $context, $userid);

        return [
            'record' => $copy,
            'previousid' => $questionid,
            'questionIdMap' => (object)[$questionid => (int)$copy->id],
        ];
    }

    /**
     * Make the final target of a question-media change non-playable.
     *
     * The caller has already resolved COW through ensure_editable_question()
     * and owns the surrounding transaction. Persisting media_pending before
     * the commit closes the interval in which a lobby could pin the target
     * while its files are being replaced after commit. The token is bumped
     * even when recovering an older pending operation.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $questionid Final (possibly forked) question ID.
     * @return \stdClass Locked and updated question.
     */
    public static function mark_question_media_pending(
        int $quizgeistid,
        int $questionid
    ): \stdClass {
        global $DB;

        if (!$DB->is_transaction_started()) {
            throw new \coding_exception(
                'Question media may only be marked pending in a transaction.'
            );
        }
        $record = $DB->get_record_sql(
            'SELECT *
               FROM {quizgeist_questions}
              WHERE id = :id
                AND quizgeistid = :quizgeistid
                FOR UPDATE',
            [
                'id' => $questionid,
                'quizgeistid' => $quizgeistid,
            ],
            MUST_EXIST
        );
        if ($record->status === 'archived') {
            throw new \invalid_parameter_exception('Archived questions cannot be edited.');
        }
        $update = (object)[
            'id' => (int)$record->id,
            'status' => 'media_pending',
            'timemodified' => self::next_modified_time(
                (int)$record->timemodified
            ),
        ];
        $DB->update_record('quizgeist_questions', $update);
        return (object)array_merge((array)$record, (array)$update);
    }

    /**
     * Whether learner/session history requires this question content to stay immutable.
     *
     * Live session snapshots store exact question IDs relationally. Snapshot
     * creation and content edits share the activity question-content lock, so
     * this precise indexed lookup needs no broad lock across session rows.
     *
     * @param int $questionid Question ID.
     * @param bool $locksessions Require a surrounding mutation transaction.
     * @return bool
     */
    public static function is_question_referenced(
        int $questionid,
        bool $locksessions = false
    ): bool {
        global $DB;

        $DB->get_field('quizgeist_questions', 'id', ['id' => $questionid], MUST_EXIST);
        if ($locksessions && !$DB->is_transaction_started()) {
            throw new \coding_exception(
                'Session references may only be checked in a transaction.'
            );
        }
        $sessionreferenced = $DB->record_exists(
            'quizgeist_session_questions',
            ['questionid' => $questionid]
        );
        return $DB->record_exists('quizgeist_answers', ['questionid' => $questionid])
            || $sessionreferenced
            || $DB->record_exists(
                'quizgeist_assignment_questions',
                ['questionid' => $questionid]
            )
            || $DB->record_exists(
                'quizgeist_attempt_questions',
                ['questionid' => $questionid]
            );
    }

    /**
     * Recalculate media references and readiness after a picker commit.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $questionid Question ID.
     * @param \context_module $context Context.
     * @param int|null $expectedpendingmodified Required media-pending token.
     * @return array
     */
    public static function refresh_question_media(
        int $quizgeistid,
        int $questionid,
        \context_module $context,
        ?int $expectedpendingmodified = null
    ): array {
        global $DB;

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $record = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_questions}
                  WHERE id = :id
                    AND quizgeistid = :quizgeistid
                    FOR UPDATE',
                [
                    'id' => $questionid,
                    'quizgeistid' => $quizgeistid,
                ],
                MUST_EXIST
            );
            if ($record->status === 'archived') {
                throw new \invalid_parameter_exception(
                    'Archived questions cannot be edited.'
                );
            }
            if ($record->status === 'media_pending') {
                if ($expectedpendingmodified === null
                        || (int)$record->timemodified !== $expectedpendingmodified) {
                    throw new edit_conflict_exception(
                        'question',
                        self::serialise_question($record, $context)
                    );
                }
            } else if ($expectedpendingmodified !== null) {
                throw new edit_conflict_exception(
                    'question',
                    self::serialise_question($record, $context)
                );
            }
            $input = [
                'qtype' => $record->qtype,
                'questiontext' => (string)$record->questiontext,
                'options' => question_schema::decode_options($record->optionsjson),
                'timelimit' => (int)$record->timelimit,
                'pointmode' => $record->pointmode,
                'explanation' => (string)$record->explanation,
            ];
            $normalised = question_schema::normalise($input);
            $manifest = media_service::manifest($context, 'questionmedia', $questionid);
            $options = media_service::synchronise_question_options(
                $normalised['question']['options'],
                $manifest
            );
            $errors = question_schema::validate_media([
                'qtype' => $record->qtype,
                'options' => $options,
            ], $normalised['validationErrors'], $manifest);
            $update = (object)[
                'id' => (int)$record->id,
                'optionsjson' => question_schema::encode_options($options),
                'status' => $errors ? 'draft' : 'ready',
            ];
            if (self::question_update_changed($record, $update)) {
                $update->timemodified = self::next_modified_time(
                    (int)$record->timemodified
                );
                $DB->update_record('quizgeist_questions', $update);
                $record = (object)array_merge((array)$record, (array)$update);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return self::serialise_question($record, $context);
    }

    /**
     * Degrade one failed, still-current media operation to an editable draft.
     *
     * A stale request must never clear a newer request's pending marker, hence
     * the status-and-token compare under the question row lock.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $questionid Final question ID.
     * @param int $expectedpendingmodified Pending token returned by mark.
     * @return void
     */
    public static function fail_question_media_pending(
        int $quizgeistid,
        int $questionid,
        int $expectedpendingmodified
    ): void {
        global $DB;

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $record = $DB->get_record_sql(
                'SELECT id, status, timemodified
                   FROM {quizgeist_questions}
                  WHERE id = :id
                    AND quizgeistid = :quizgeistid
                    FOR UPDATE',
                [
                    'id' => $questionid,
                    'quizgeistid' => $quizgeistid,
                ]
            );
            if ($record
                    && $record->status === 'media_pending'
                    && (int)$record->timemodified === $expectedpendingmodified) {
                $DB->update_record('quizgeist_questions', (object)[
                    'id' => (int)$record->id,
                    'status' => 'draft',
                    'timemodified' => self::next_modified_time(
                        (int)$record->timemodified
                    ),
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Apply a complete active-question order.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Current editor.
     * @param array $questionids Ordered IDs.
     * @return array
     */
    public static function reorder_questions(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        array $questionids
    ): array {
        $contentlocks = question_content_lock::acquire_for_activity(
            (int)$quizgeist->id
        );
        try {
            return self::reorder_questions_locked(
                $quizgeist,
                $context,
                $userid,
                $questionids
            );
        } finally {
            question_content_lock::release_all($contentlocks);
        }
    }

    /**
     * Reorder after every active lineage has been locked.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Current editor.
     * @param array $questionids Ordered IDs.
     * @return array
     */
    private static function reorder_questions_locked(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        array $questionids
    ): array {
        global $DB;

        if (!array_is_list($questionids)) {
            throw new \invalid_parameter_exception('questionids must be an ordered list.');
        }
        $ordered = [];
        foreach ($questionids as $rawid) {
            $id = self::positive_id($rawid, 'questionids');
            if (isset($ordered[$id])) {
                throw new \invalid_parameter_exception('questionids contains duplicates.');
            }
            $ordered[$id] = true;
        }
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $active = $DB->get_records_select(
                'quizgeist_questions',
                'quizgeistid = :quizgeistid AND status <> :archived',
                ['quizgeistid' => $quizgeist->id, 'archived' => 'archived'],
                '',
                '*'
            );
            $activeids = array_map('intval', array_keys($active));
            sort($activeids);
            $submittedids = array_map('intval', array_keys($ordered));
            sort($submittedids);
            if ($activeids !== $submittedids) {
                throw new \invalid_parameter_exception(
                    'questionids must contain every active question exactly once.'
                );
            }

            foreach (array_keys($ordered) as $sortorder => $questionid) {
                $current = $active[$questionid];
                if ((int)$current->sortorder === $sortorder) {
                    continue;
                }
                // Sort order is mutable presentation metadata. It must never
                // fork content versions or advance their conflict token.
                $DB->set_field('quizgeist_questions', 'sortorder', $sortorder, [
                    'id' => $questionid,
                    'quizgeistid' => (int)$quizgeist->id,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return [
            'questions' => self::list_questions((int)$quizgeist->id, $context),
        ];
    }

    /**
     * Duplicate a question and its media immediately after the source.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Current user.
     * @param int $questionid Source ID.
     * @return array
     */
    public static function duplicate_question(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        int $questionid
    ): array {
        $contentlocks = question_content_lock::acquire_for_activity(
            (int)$quizgeist->id
        );
        try {
            return self::duplicate_question_locked(
                $quizgeist,
                $context,
                $userid,
                $questionid
            );
        } finally {
            question_content_lock::release_all($contentlocks);
        }
    }

    /**
     * Duplicate after the source lineage lock has been acquired.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $userid Current user.
     * @param int $questionid Source ID.
     * @return array
     */
    private static function duplicate_question_locked(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        int $questionid
    ): array {
        global $DB;

        $source = $DB->get_record('quizgeist_questions', [
            'id' => $questionid,
            'quizgeistid' => $quizgeist->id,
        ], '*', MUST_EXIST);
        if (in_array($source->status, ['archived', 'media_pending'], true)) {
            throw new \invalid_parameter_exception(
                'Archived or media-pending questions cannot be duplicated.'
            );
        }
        question_type_registry::assert_creatable((string)$source->qtype);
        // F13: eine Kopie ist eine NEUE Frage. Traegt die Quelle den
        // Buehnen-Untermodus, entsteht hier ein zweites Buehnen-Artefakt —
        // also genau die Anlage, die das Tor bewacht. Ohne diese Zeile waere
        // „Duplizieren" der Weg, den Untermodus ohne Lizenz beliebig zu
        // vermehren. Es ist dieselbe Ueberlegung, die eine Zeile hoeher schon
        // fuer die Premium-Fragetypen gilt: Bestand bleibt, Vermehrung nicht.
        self::guard_stage_check(
            [],
            question_schema::decode_options($source->optionsjson ?? null)
        );

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $active = $DB->get_records_select(
                'quizgeist_questions',
                'quizgeistid = :quizgeistid AND status <> :archived',
                ['quizgeistid' => $quizgeist->id, 'archived' => 'archived'],
                'sortorder ASC, id ASC',
                'id,status'
            );
            foreach ($active as $activequestion) {
                if ($activequestion->status === 'media_pending') {
                    throw new \invalid_parameter_exception(
                        'Media-pending questions cannot be duplicated or reordered.'
                    );
                }
            }
            $activeids = array_map('intval', array_keys($active));
            $sourceindex = array_search($questionid, $activeids, true);
            if ($sourceindex === false) {
                throw new \invalid_parameter_exception('Archived questions cannot be duplicated.');
            }
            $copy = clone $source;
            unset($copy->id);
            $copy->rootid = 0;
            $copy->version = 1;
            $maxsort = $DB->get_field_sql(
                'SELECT MAX(sortorder)
                   FROM {quizgeist_questions}
                  WHERE quizgeistid = :quizgeistid
                    AND status <> :archived',
                ['quizgeistid' => $quizgeist->id, 'archived' => 'archived']
            );
            $copy->sortorder = $maxsort === null ? 0 : ((int)$maxsort + 1);
            $copy->createdby = $userid;
            $copy->timecreated = time();
            $copy->timemodified = $copy->timecreated;
            $newid = (int)$DB->insert_record('quizgeist_questions', $copy);
            $copy->id = $newid;
            $copy->rootid = $newid;
            $DB->set_field('quizgeist_questions', 'rootid', $newid, ['id' => $newid]);
            media_service::copy_area(
                $context,
                'questionmedia',
                $questionid,
                $context,
                'questionmedia',
                $newid
            );
            array_splice($activeids, $sourceindex + 1, 0, [$newid]);
            foreach ($activeids as $sortorder => $activeid) {
                $DB->set_field('quizgeist_questions', 'sortorder', $sortorder, [
                    'id' => $activeid,
                    'quizgeistid' => (int)$quizgeist->id,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        $questions = self::list_questions((int)$quizgeist->id, $context);
        $question = null;
        foreach ($questions as $candidate) {
            if ((int)$candidate['id'] === $newid) {
                $question = $candidate;
                break;
            }
        }
        if ($question === null) {
            throw new \coding_exception('Duplicated question missing after reorder.');
        }
        return [
            'question' => $question,
            'questions' => $questions,
        ];
    }

    /**
     * Delete an unused question or archive one referenced by history.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $questionid Question ID.
     * @return array
     */
    public static function delete_question(
        \stdClass $quizgeist,
        \context_module $context,
        int $questionid
    ): array {
        $contentlock = question_content_lock::acquire_for_question(
            (int)$quizgeist->id,
            $questionid
        );
        try {
            return self::delete_question_locked(
                $quizgeist,
                $context,
                $questionid
            );
        } finally {
            $contentlock->release();
        }
    }

    /**
     * Delete after the stable lineage lock has been acquired.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Context.
     * @param int $questionid Question ID.
     * @return array
     */
    private static function delete_question_locked(
        \stdClass $quizgeist,
        \context_module $context,
        int $questionid
    ): array {
        global $DB;

        $deletefiles = false;
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            // Session creation pins its complete active question set with the
            // same question-row lock. Whichever transaction obtains the lock
            // first therefore determines whether this row is omitted from the
            // new snapshot or retained as an archived historical version.
            $record = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_questions}
                  WHERE id = :id
                    AND quizgeistid = :quizgeistid
                    FOR UPDATE',
                [
                    'id' => $questionid,
                    'quizgeistid' => (int)$quizgeist->id,
                ],
                MUST_EXIST
            );
            if (in_array($record->status, ['archived', 'media_pending'], true)) {
                throw new \invalid_parameter_exception(
                    'Archived or media-pending questions cannot be deleted.'
                );
            }
            // Re-evaluate history only after the question lock is held. This
            // closes the delete-vs-lobby race without reversing the common
            // question -> session lock order used by session creation.
            $referenced = self::is_question_referenced($questionid, true);
            if ($referenced) {
                $DB->set_field('quizgeist_questions', 'status', 'archived', [
                    'id' => $questionid,
                ]);
            } else {
                $DB->delete_records('quizgeist_questions', ['id' => $questionid]);
                $deletefiles = true;
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        if ($deletefiles) {
            // File contents do not participate in delegated DB transactions.
            // Cleanup therefore runs only after the row deletion is durable.
            media_service::delete_area($context, 'questionmedia', $questionid);
        }
        return [
            'questionid' => $questionid,
            'archived' => $referenced,
            'questions' => self::list_questions((int)$quizgeist->id, $context),
        ];
    }

    /**
     * Save inline activity appearance/navigation settings.
     *
     * @param \stdClass $quizgeist Current activity.
     * @param array $input Activity input.
     * @return array
     */
    public static function save_activity(\stdClass $quizgeist, array $input): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quizgeist/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $expectedmodified = self::positive_id(
            $input['timemodified'] ?? null,
            'activity.timemodified'
        );
        $name = isset($input['name']) && is_scalar($input['name'])
            ? trim((string)clean_param((string)$input['name'], PARAM_TEXT))
            : '';
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new \invalid_parameter_exception('Invalid Quizgeist name.');
        }
        $theme = isset($input['theme']) && is_string($input['theme']) ? $input['theme'] : '';
        $season = isset($input['season']) && is_string($input['season']) ? $input['season'] : '';
        if (!in_array($theme, self::THEMES, true) || !in_array($season, self::SEASONS, true)) {
            throw new \invalid_parameter_exception('Invalid Quizgeist appearance setting.');
        }
        if (!array_key_exists('allowbacktrack', $input)
                || !in_array($input['allowbacktrack'], [false, true, 0, 1, '0', '1'], true)) {
            throw new \invalid_parameter_exception('Invalid Quizgeist navigation setting.');
        }
        $allowbacktrack = in_array($input['allowbacktrack'], [true, 1, '1'], true) ? 1 : 0;
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $current = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist}
                  WHERE id = :id
                    FOR UPDATE',
                ['id' => (int)$quizgeist->id],
                MUST_EXIST
            );
            if ((int)$current->timemodified !== $expectedmodified) {
                $context = self::context_for_activity((int)$current->id);
                throw new edit_conflict_exception('activity', self::serialise_activity(
                    $current,
                    media_service::manifest($context, 'background', 0),
                    media_service::manifest($context, 'logo', 0)
                ));
            }
            if ($theme === 'jahreszeiten'
                    && (string)$current->theme !== 'jahreszeiten') {
                \mod_quizgeist\local\licence\feature_gate::require('modes');
            }
            $namechanged = $name !== $current->name;
            $update = (object)[
                'id' => (int)$current->id,
                'name' => $name,
                'theme' => $theme,
                'season' => $season,
                'allowbacktrack' => $allowbacktrack,
                'timemodified' => self::next_modified_time(
                    (int)$current->timemodified
                ),
            ];
            $DB->update_record('quizgeist', $update);
            $updated = (object)array_merge((array)$current, (array)$update);
            quizgeist_grade_item_update($updated);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        if ($namechanged) {
            rebuild_course_cache((int)$updated->course, true);
        }
        $context = self::context_for_activity((int)$updated->id);
        return self::serialise_activity(
            $updated,
            media_service::manifest($context, 'background', 0),
            media_service::manifest($context, 'logo', 0)
        );
    }

    /**
     * Serialise one record and derive current validation from real files.
     *
     * @param \stdClass $record DB record.
     * @param \context_module $context Module context.
     * @return array
     */
    public static function serialise_question(
        \stdClass $record,
        \context_module $context
    ): array {
        $manifest = media_service::manifest($context, 'questionmedia', (int)$record->id);
        try {
            $normalised = question_schema::normalise([
                'qtype' => (string)$record->qtype,
                'questiontext' => (string)($record->questiontext ?? ''),
                'options' => question_schema::decode_options($record->optionsjson ?? null),
                'timelimit' => (int)($record->timelimit ?? 20),
                'pointmode' => (string)($record->pointmode ?? 'standard'),
                'explanation' => (string)($record->explanation ?? ''),
            ]);
            $normalised['question']['options'] = media_service::synchronise_question_options(
                $normalised['question']['options'],
                $manifest
            );
            $errors = question_schema::validate_media(
                $normalised['question'],
                $normalised['validationErrors'],
                $manifest
            );
        } catch (\invalid_parameter_exception $exception) {
            // A restored/future qtype must not lock the complete editor. Expose a
            // deletable draft placeholder and retain the original text for rescue.
            $normalised = question_schema::normalise([
                ...question_schema::defaults('open'),
                'questiontext' => (string)($record->questiontext ?? ''),
                'explanation' => (string)($record->explanation ?? ''),
            ]);
            $normalised['question']['options'] = media_service::synchronise_question_options(
                $normalised['question']['options'],
                $manifest
            );
            $errors = [[
                'field' => 'qtype',
                'code' => 'unsupported',
            ]];
        }
        $storedstatus = (string)($record->status ?? 'draft');
        if ($storedstatus === 'media_pending') {
            $errors[] = [
                'field' => 'media',
                'code' => 'pending',
            ];
        }
        $status = match ($storedstatus) {
            'archived' => 'archived',
            'media_pending' => 'media_pending',
            'ready' => $errors ? 'draft' : 'ready',
            default => 'draft',
        };
        return [
            'id' => (int)$record->id,
            'rootid' => (int)($record->rootid ?? $record->id),
            'version' => max(1, (int)($record->version ?? 1)),
            'sortorder' => (int)$record->sortorder,
            'qtype' => $normalised['question']['qtype'],
            'questiontext' => $normalised['question']['questiontext'],
            'questionformat' => FORMAT_PLAIN,
            'options' => $normalised['question']['options'],
            'timelimit' => $normalised['question']['timelimit'],
            'pointmode' => $normalised['question']['pointmode'],
            'explanation' => $normalised['question']['explanation'],
            'status' => $status,
            'validationErrors' => $errors,
            'files' => $manifest,
            'createdby' => isset($record->createdby) ? (int)$record->createdby : null,
            'timecreated' => (int)($record->timecreated ?? 0),
            'timemodified' => (int)($record->timemodified ?? 0),
        ];
    }

    /**
     * Return all active questions.
     *
     * @param int $quizgeistid Activity ID.
     * @param \context_module $context Context.
     * @return array
     */
    public static function list_questions(int $quizgeistid, \context_module $context): array {
        global $DB;

        $records = $DB->get_records_select(
            'quizgeist_questions',
            'quizgeistid = :quizgeistid AND status <> :archived',
            ['quizgeistid' => $quizgeistid, 'archived' => 'archived'],
            'sortorder ASC, id ASC'
        );
        return array_values(array_map(
            static fn(\stdClass $record): array => self::serialise_question($record, $context),
            $records
        ));
    }

    /**
     * Serialise activity fields and appearance files.
     *
     * @param \stdClass $quizgeist Activity.
     * @param array $background Background manifest.
     * @param array $logo Logo manifest.
     * @return array
     */
    public static function serialise_activity(
        \stdClass $quizgeist,
        array $background,
        array $logo
    ): array {
        return [
            'id' => (int)$quizgeist->id,
            'course' => (int)$quizgeist->course,
            'name' => (string)$quizgeist->name,
            'theme' => in_array($quizgeist->theme ?? '', self::THEMES, true) ? $quizgeist->theme : 'hell',
            'season' => in_array($quizgeist->season ?? '', self::SEASONS, true) ? $quizgeist->season : 'herbst',
            'allowbacktrack' => !empty($quizgeist->allowbacktrack),
            'background' => array_values($background),
            'logo' => array_values($logo),
            'timemodified' => (int)($quizgeist->timemodified ?? 0),
        ];
    }

    /**
     * Create the next content version of one referenced question.
     *
     * The caller owns the surrounding DB transaction and has locked the source
     * row. Copying to a fresh file item is non-destructive; no existing file
     * area is removed before the DB commit.
     *
     * @param \stdClass $record Locked active source row.
     * @param \context_module $context Module context.
     * @param int $userid Current editor.
     * @return \stdClass Fresh active version.
     */
    private static function create_question_version(
        \stdClass $record,
        \context_module $context,
        int $userid
    ): \stdClass {
        global $DB;

        $sourceid = (int)$record->id;
        $copy = clone $record;
        unset($copy->id);
        $copy->rootid = (int)($record->rootid ?? 0) > 0
            ? (int)$record->rootid
            : $sourceid;
        $copy->version = max(1, (int)($record->version ?? 1)) + 1;
        $copy->createdby = $userid;
        $copy->timecreated = time();
        $copy->timemodified = self::next_modified_time(
            (int)($record->timemodified ?? 0)
        );

        // Archiving is the sole lifecycle mutation allowed on historical content.
        $DB->set_field('quizgeist_questions', 'status', 'archived', ['id' => $sourceid]);
        $copy->id = (int)$DB->insert_record('quizgeist_questions', $copy);
        media_service::copy_area(
            $context,
            'questionmedia',
            $sourceid,
            $context,
            'questionmedia',
            (int)$copy->id
        );
        return $copy;
    }

    /**
     * Current active member of a question lineage for conflict responses.
     *
     * @param \stdClass $record Requested row.
     * @param \context_module $context Module context.
     * @return array
     */
    private static function current_lineage_question(
        \stdClass $record,
        \context_module $context
    ): array {
        global $DB;

        $current = $record;
        if ($record->status === 'archived') {
            $rootid = (int)($record->rootid ?? 0) > 0
                ? (int)$record->rootid
                : (int)$record->id;
            $active = $DB->get_record_sql(
                'SELECT *
                   FROM {quizgeist_questions}
                  WHERE quizgeistid = :quizgeistid
                    AND rootid = :rootid
                    AND status <> :archived
               ORDER BY version DESC, id DESC',
                [
                    'quizgeistid' => (int)$record->quizgeistid,
                    'rootid' => $rootid,
                    'archived' => 'archived',
                ],
                IGNORE_MULTIPLE
            );
            if ($active) {
                $current = $active;
            }
        }
        return self::serialise_question($current, $context);
    }

    /**
     * Stable fingerprint of all question content that may affect an assessment.
     *
     * Sort order, lifecycle state, row IDs and timestamps are intentionally not
     * included, so they can never trigger copy-on-write.
     *
     * @param array $question Normalised question.
     * @param array $options Options synchronised from server-owned media.
     * @return string
     */
    private static function question_content_fingerprint(
        array $question,
        array $options
    ): string {
        return json_encode([
            'qtype' => $question['qtype'],
            'questiontext' => $question['questiontext'],
            'options' => $options,
            'timelimit' => $question['timelimit'],
            'pointmode' => $question['pointmode'],
            'explanation' => $question['explanation'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Whether a partial question update differs from a DB record.
     *
     * @param \stdClass $record Current DB record.
     * @param \stdClass $update Partial update including id.
     * @return bool
     */
    private static function question_update_changed(
        \stdClass $record,
        \stdClass $update
    ): bool {
        foreach ((array)$update as $field => $value) {
            if ($field === 'id' || $field === 'timemodified') {
                continue;
            }
            $current = $record->{$field} ?? null;
            if (is_int($value)) {
                if ((int)$current !== $value) {
                    return true;
                }
            } else if ((string)$current !== (string)$value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Monotonic second-resolution optimistic-lock token.
     *
     * @param int $previous Previous token.
     * @return int
     */
    private static function next_modified_time(int $previous): int {
        return max(time(), $previous + 1);
    }

    /**
     * Build a defensive direct-local_voces configuration.
     *
     * @return array
     */
    private static function tts_configuration(): array {
        $systemcontext = \context_system::instance();
        if (!\mod_quizgeist\local\licence\feature_gate::can_create('ai')
                || !class_exists('\\local_voces\\api')
                || !has_capability('local/voces:use', $systemcontext)) {
            return [
                'available' => false,
                'voices' => [],
                'speakUrl' => null,
                'defaultVoiceId' => 0,
            ];
        }
        try {
            $voices = \local_voces\api::voices('de');
            $voices = is_array($voices) ? array_values($voices) : [];
            $firstvoice = $voices[0] ?? null;
            $defaultvoiceid = 0;
            if (is_array($firstvoice)) {
                $defaultvoiceid = (int)($firstvoice['id'] ?? 0);
            } else if (is_object($firstvoice)) {
                $defaultvoiceid = (int)($firstvoice->id ?? 0);
            }
            return [
                'available' => !empty($voices) && \local_voces\api::is_available(),
                'voices' => $voices,
                'speakUrl' => \local_voces\api::speak_url(),
                'defaultVoiceId' => $defaultvoiceid,
            ];
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'voices' => [],
                'speakUrl' => null,
                'defaultVoiceId' => 0,
            ];
        }
    }

    /**
     * Return basis themes plus a licensed or already persisted seasonal theme.
     *
     * @param string $currenttheme Stored activity theme.
     * @return string[]
     */
    private static function creatable_themes(string $currenttheme): array {
        $themes = self::BASE_THEMES;
        if ($currenttheme === 'jahreszeiten'
                || \mod_quizgeist\local\licence\feature_gate::can_create(
                    'modes'
                )) {
            $themes[] = 'jahreszeiten';
        }
        return $themes;
    }

    /**
     * Strict positive integer input.
     *
     * @param mixed $raw Value.
     * @param string $field Field.
     * @return int
     */
    public static function positive_id($raw, string $field): int {
        $valid = is_int($raw)
            || (is_string($raw) && (bool)preg_match('/^[1-9][0-9]*$/D', $raw));
        $number = $valid ? filter_var($raw, FILTER_VALIDATE_INT) : false;
        if ($number === false || (int)$number <= 0) {
            throw new \invalid_parameter_exception("Invalid {$field}.");
        }
        return (int)$number;
    }

    /**
     * Resolve the one module context for an activity.
     *
     * @param int $quizgeistid Activity ID.
     * @return \context_module
     */
    private static function context_for_activity(int $quizgeistid): \context_module {
        $cm = get_coursemodule_from_instance('quizgeist', $quizgeistid, 0, false, MUST_EXIST);
        return \context_module::instance($cm->id);
    }
}
