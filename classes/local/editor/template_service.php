<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * School-wide Quizgeist template library.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\editor;

use mod_quizgeist\local\live\qtype\registry as question_type_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Publishes independent snapshots and copies their files through system context.
 */
final class template_service {

    /** Snapshot schema used by P2. */
    private const SCHEMA_VERSION = 1;

    /** Maximum snapshot size accepted by both publish and import. */
    private const MAX_QUESTIONS = 500;

    /** Maximum number of separately named school templates per publisher. */
    private const MAX_TEMPLATES_PER_USER = 50;

    /** Positive range reserved by this plugin for ephemeral File-API item IDs. */
    private const STAGING_ITEMID_MIN = 1073741824;

    /** Upper bound accepted by Moodle's signed 32-bit itemid consumers. */
    private const STAGING_ITEMID_MAX = 2147483647;

    /**
     * Publish the current quiz as a school template.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Source context.
     * @param int $userid Publisher.
     * @param array $payload Request.
     * @return array
     */
    public static function publish(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        array $payload
    ): array {
        $contentlocks = question_content_lock::acquire_for_activity(
            (int)$quizgeist->id
        );
        try {
            return self::publish_locked(
                $quizgeist,
                $context,
                $userid,
                $payload
            );
        } finally {
            question_content_lock::release_all($contentlocks);
        }
    }

    /**
     * Publish after every active source lineage has been locked.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Source context.
     * @param int $userid Publisher.
     * @param array $payload Request.
     * @return array
     */
    private static function publish_locked(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        array $payload
    ): array {
        global $DB;

        $name = self::required_text($payload['name'] ?? null, 255, 'template.name');
        $description = self::optional_text($payload['description'] ?? '', 4000);
        $tags = self::normalise_tags($payload['tags'] ?? []);
        $questions = editor_service::list_questions((int)$quizgeist->id, $context);
        if (!$questions) {
            throw new \invalid_parameter_exception('An empty quiz cannot be published.');
        }
        if (count($questions) > self::MAX_QUESTIONS) {
            throw new \invalid_parameter_exception(
                'A template cannot contain more than ' . self::MAX_QUESTIONS . ' questions.'
            );
        }
        foreach ($questions as $question) {
            if ($question['validationErrors']) {
                throw new \invalid_parameter_exception('Only valid questions can be published.');
            }
            question_type_registry::assert_creatable(
                (string)$question['qtype']
            );
            // F13: eine Vorlage ist die Bauanleitung fuer beliebig viele neue
            // Buehnen-Fragen. Sie zu veroeffentlichen ist deshalb dieselbe
            // Anlage wie das Einschalten des Untermodus selbst — genau wie
            // beim Jahreszeiten-Thema drei Zeilen weiter unten.
            editor_service::guard_stage_check(
                [],
                (array)($question['options'] ?? [])
            );
        }
        if ((string)($quizgeist->theme ?? '') === 'jahreszeiten') {
            \mod_quizgeist\local\licence\feature_gate::require(
                'modes',
                \mod_quizgeist\local\licence\feature_gate::CREATE_NEW
            );
        }

        $now = time();
        $systemcontext = \context_system::instance();
        $snapshotquestions = [];
        foreach ($questions as $question) {
            $sourceid = (int)$question['id'];
            media_service::validate_copy_area($context, 'questionmedia', $sourceid);
            $snapshotquestions[] = [
                'sourceid' => $sourceid,
                'qtype' => $question['qtype'],
                'questiontext' => $question['questiontext'],
                'questionformat' => FORMAT_PLAIN,
                'options' => $question['options'],
                'timelimit' => $question['timelimit'],
                'pointmode' => $question['pointmode'],
                'explanation' => $question['explanation'],
            ];
        }
        media_service::validate_copy_area($context, 'background', 0);
        media_service::validate_copy_area($context, 'logo', 0);

        $snapshot = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'appearance' => [
                'theme' => in_array($quizgeist->theme ?? '', editor_service::THEMES, true)
                    ? $quizgeist->theme
                    : 'hell',
                'season' => in_array($quizgeist->season ?? '', editor_service::SEASONS, true)
                    ? $quizgeist->season
                    : 'herbst',
                'allowbacktrack' => !empty($quizgeist->allowbacktrack),
            ],
            'questions' => $snapshotquestions,
        ];
        $contentjson = json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $tagsjson = json_encode(
            $tags,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $existingrecords = $DB->get_records('quizgeist_templates', [
            'name' => $name,
            'createdby' => $userid,
            'visibility' => 'school',
        ], 'timemodified DESC, id DESC', '*', 0, 1);
        $existing = $existingrecords ? reset($existingrecords) : false;
        if (!$existing
                && $DB->count_records('quizgeist_templates', [
                    'createdby' => $userid,
                    'visibility' => 'school',
                ]) >= self::MAX_TEMPLATES_PER_USER) {
            throw new \invalid_parameter_exception(
                'You may publish at most ' . self::MAX_TEMPLATES_PER_USER . ' school templates.'
            );
        }

        // Copy every source file into an unserved temporary item first. This
        // validates the complete media set and proves the copy before an existing
        // template area is replaced after the DB commit.
        $stagingitemid = self::new_staging_itemid($systemcontext, 'templatemedia');
        $backupitemid = $existing
            ? self::new_staging_itemid($systemcontext, 'templatemedia', [$stagingitemid])
            : null;
        try {
            foreach ($questions as $question) {
                $sourceid = (int)$question['id'];
                media_service::copy_area(
                    $context,
                    'questionmedia',
                    $sourceid,
                    $systemcontext,
                    'templatemedia',
                    $stagingitemid,
                    '/questions/' . $sourceid . '/'
                );
            }
            media_service::copy_area(
                $context,
                'background',
                0,
                $systemcontext,
                'templatemedia',
                $stagingitemid,
                '/background/'
            );
            media_service::copy_area(
                $context,
                'logo',
                0,
                $systemcontext,
                'templatemedia',
                $stagingitemid,
                '/logo/'
            );
            if ($existing && $backupitemid !== null) {
                media_service::copy_area(
                    $systemcontext,
                    'templatemedia',
                    (int)$existing->id,
                    $systemcontext,
                    'templatemedia',
                    $backupitemid
                );
            }
        } catch (\Throwable $exception) {
            // Only newly created, unreferenced staging files are removed here.
            media_service::delete_area($systemcontext, 'templatemedia', $stagingitemid);
            if ($backupitemid !== null) {
                media_service::delete_area($systemcontext, 'templatemedia', $backupitemid);
            }
            throw $exception;
        }

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            if ($existing) {
                $templateid = (int)$existing->id;
                $DB->update_record('quizgeist_templates', (object)[
                    'id' => $templateid,
                    'name' => $name,
                    'description' => $description,
                    'contentjson' => $contentjson,
                    'tags' => $tagsjson,
                    'timemodified' => $now,
                ]);
            } else {
                $templateid = (int)$DB->insert_record('quizgeist_templates', (object)[
                    'name' => $name,
                    'description' => $description,
                    'contentjson' => $contentjson,
                    'tags' => $tagsjson,
                    'visibility' => 'school',
                    'createdby' => $userid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            try {
                $transaction->rollback($exception);
            } finally {
                media_service::delete_area($systemcontext, 'templatemedia', $stagingitemid);
                if ($backupitemid !== null) {
                    media_service::delete_area($systemcontext, 'templatemedia', $backupitemid);
                }
            }
        }

        self::replace_template_media_after_commit(
            $systemcontext,
            $templateid,
            $stagingitemid,
            $backupitemid
        );

        $template = $DB->get_record('quizgeist_templates', ['id' => $templateid], '*', MUST_EXIST);
        return self::serialise_template($template, $userid, (int)$context->instanceid);
    }

    /**
     * Search school templates.
     *
     * @param string $query Query.
     * @param int $userid Current user.
     * @param int $accesscmid Course-module ID authorising template-media access.
     * @return array
     */
    public static function search(string $query, int $userid, int $accesscmid): array {
        global $DB;

        $query = self::optional_text($query, 255);
        $params = ['visibility' => 'school'];
        $where = 'visibility = :visibility';
        if ($query !== '') {
            $like = '%' . $DB->sql_like_escape($query) . '%';
            $where .= ' AND ('
                . $DB->sql_like('name', ':namequery', false)
                . ' OR ' . $DB->sql_like('description', ':descriptionquery', false)
                . ' OR ' . $DB->sql_like('tags', ':tagsquery', false)
                . ')';
            $params['namequery'] = $like;
            $params['descriptionquery'] = $like;
            $params['tagsquery'] = $like;
        }
        $records = $DB->get_records_select(
            'quizgeist_templates',
            $where,
            $params,
            'timemodified DESC, id DESC',
            '*',
            0,
            50
        );
        return [
            'query' => $query,
            'templates' => array_values(array_map(
                static fn(\stdClass $record): array => self::serialise_template(
                    $record,
                    $userid,
                    $accesscmid
                ),
                $records
            )),
        ];
    }

    /**
     * Import a template into the target activity.
     *
     * @param \stdClass $quizgeist Target activity.
     * @param \context_module $context Target context.
     * @param int $userid Importer.
     * @param int $templateid Template.
     * @param string $mode append or replace.
     * @param bool $includeappearance Copy appearance and files.
     * @return array
     */
    public static function import(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        int $templateid,
        string $mode,
        bool $includeappearance
    ): array {
        if (!in_array($mode, ['append', 'replace'], true)) {
            throw new \invalid_parameter_exception('Invalid template import mode.');
        }
        $contentlocks = question_content_lock::acquire_for_activity(
            (int)$quizgeist->id
        );
        try {
            return self::import_locked(
                $quizgeist,
                $context,
                $userid,
                $templateid,
                $mode,
                $includeappearance
            );
        } finally {
            question_content_lock::release_all($contentlocks);
        }
    }

    /**
     * Import after the target structure and active lineages have been locked.
     *
     * @param \stdClass $quizgeist Target activity.
     * @param \context_module $context Target context.
     * @param int $userid Importer.
     * @param int $templateid Template.
     * @param string $mode append or replace.
     * @param bool $includeappearance Copy appearance and files.
     * @return array
     */
    private static function import_locked(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        int $templateid,
        string $mode,
        bool $includeappearance
    ): array {
        global $DB;

        $template = $DB->get_record('quizgeist_templates', [
            'id' => $templateid,
            'visibility' => 'school',
        ], '*', MUST_EXIST);
        $snapshot = self::decode_snapshot($template->contentjson);
        $systemcontext = \context_system::instance();
        // Validate every stored source file, not only paths referenced by JSON.
        // This rejects unsafe restore artefacts before any target mutation.
        media_service::validate_copy_area($systemcontext, 'templatemedia', $templateid);
        $templatemedia = media_service::manifest($systemcontext, 'templatemedia', $templateid);
        $validated = self::validate_snapshot_questions($snapshot['questions'], $templatemedia);
        self::assert_snapshot_media($validated, $templatemedia);

        $appearanceincomingid = null;
        $appearancebackupid = null;
        if ($includeappearance) {
            $appearanceincomingid = self::new_staging_itemid($context, 'background');
            $appearancebackupid = self::new_staging_itemid(
                $context,
                'background',
                [$appearanceincomingid]
            );
            try {
                media_service::copy_area(
                    $systemcontext,
                    'templatemedia',
                    $templateid,
                    $context,
                    'background',
                    $appearanceincomingid,
                    '/',
                    '/background/'
                );
                media_service::copy_area(
                    $systemcontext,
                    'templatemedia',
                    $templateid,
                    $context,
                    'logo',
                    $appearanceincomingid,
                    '/',
                    '/logo/'
                );
                media_service::copy_area(
                    $context,
                    'background',
                    0,
                    $context,
                    'background',
                    $appearancebackupid
                );
                media_service::copy_area(
                    $context,
                    'logo',
                    0,
                    $context,
                    'logo',
                    $appearancebackupid
                );
            } catch (\Throwable $exception) {
                self::delete_appearance_staging(
                    $context,
                    [$appearanceincomingid, $appearancebackupid]
                );
                throw $exception;
            }
        }

        $newids = [];
        $harddeletedids = [];
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $currentactivity = $DB->get_record_sql(
                'SELECT id, theme, timemodified
                   FROM {quizgeist}
                  WHERE id = :id
                  FOR UPDATE',
                ['id' => (int)$quizgeist->id],
                MUST_EXIST
            );
            if ($includeappearance
                    && $snapshot['appearance']['theme'] === 'jahreszeiten'
                    && (string)$currentactivity->theme
                        !== 'jahreszeiten') {
                \mod_quizgeist\local\licence\feature_gate::require(
                    'modes',
                    \mod_quizgeist\local\licence\feature_gate::CREATE_NEW
                );
            }
            $activitymodified = max(
                time(),
                (int)$currentactivity->timemodified + 1
            );
            if ($mode === 'replace') {
                // Match lobby creation's question lock and ordering. A lobby
                // that wins these locks first becomes visible to the current
                // session-reference read below; a replace that wins first
                // removes/archives the old set before it can be pinned.
                $existing = $DB->get_records_sql(
                    'SELECT *
                       FROM {quizgeist_questions}
                      WHERE quizgeistid = :quizgeistid
                        AND status <> :archived
                   ORDER BY sortorder ASC, id ASC
                        FOR UPDATE',
                    [
                        'quizgeistid' => (int)$quizgeist->id,
                        'archived' => 'archived',
                    ]
                );
                foreach ($existing as $record) {
                    if ($record->status === 'media_pending') {
                        throw new \invalid_parameter_exception(
                            'Media-pending questions cannot be replaced.'
                        );
                    }
                    $referenced = editor_service::is_question_referenced(
                        (int)$record->id,
                        true
                    );
                    if ($referenced) {
                        $DB->set_field('quizgeist_questions', 'status', 'archived', [
                            'id' => $record->id,
                        ]);
                    } else {
                        $DB->delete_records('quizgeist_questions', ['id' => $record->id]);
                        $harddeletedids[] = (int)$record->id;
                    }
                }
            }

            $startsort = (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(sortorder), -1)
                   FROM {quizgeist_questions}
                  WHERE quizgeistid = :quizgeistid
                    AND status <> :archived',
                ['quizgeistid' => $quizgeist->id, 'archived' => 'archived']
            ) + 1;
            $now = time();
            foreach ($validated as $offset => $item) {
                $question = $item['question'];
                $record = (object)[
                    'quizgeistid' => (int)$quizgeist->id,
                    'sortorder' => $startsort + $offset,
                    'qtype' => $question['qtype'],
                    'questiontext' => $question['questiontext'],
                    'questionformat' => FORMAT_PLAIN,
                    'optionsjson' => question_schema::encode_options($question['options']),
                    'timelimit' => $question['timelimit'],
                    'pointmode' => $question['pointmode'],
                    'explanation' => $question['explanation'],
                    'status' => 'ready',
                    'createdby' => $userid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $newid = (int)$DB->insert_record('quizgeist_questions', $record);
                $DB->update_record('quizgeist_questions', (object)[
                    'id' => $newid,
                    'rootid' => $newid,
                    'version' => 1,
                ]);
                $newids[] = $newid;
                media_service::copy_area(
                    $systemcontext,
                    'templatemedia',
                    $templateid,
                    $context,
                    'questionmedia',
                    $newid,
                    '/',
                    '/questions/' . $item['sourceid'] . '/'
                );
            }

            if ($includeappearance) {
                $appearance = $snapshot['appearance'];
                $DB->update_record('quizgeist', (object)[
                    'id' => (int)$quizgeist->id,
                    'theme' => $appearance['theme'],
                    'season' => $appearance['season'],
                    'allowbacktrack' => $appearance['allowbacktrack'] ? 1 : 0,
                    'timemodified' => $activitymodified,
                ]);
            } else {
                $DB->set_field(
                    'quizgeist',
                    'timemodified',
                    $activitymodified,
                    ['id' => $quizgeist->id]
                );
            }

            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            try {
                $transaction->rollback($exception);
            } finally {
                // rollback() reverts the DB before rethrowing; this finally block
                // therefore removes only media belonging to reverted new rows.
                foreach ($newids as $newid) {
                    media_service::delete_area($context, 'questionmedia', $newid);
                }
                if ($includeappearance) {
                    self::delete_appearance_staging(
                        $context,
                        [$appearanceincomingid, $appearancebackupid]
                    );
                }
            }
        }

        if ($includeappearance) {
            self::replace_appearance_after_commit(
                $context,
                (int)$appearanceincomingid,
                (int)$appearancebackupid
            );
        }
        foreach ($harddeletedids as $deletedid) {
            media_service::delete_area($context, 'questionmedia', $deletedid);
        }
        $updated = $DB->get_record('quizgeist', ['id' => $quizgeist->id], '*', MUST_EXIST);
        return [
            'templateid' => $templateid,
            'mode' => $mode,
            'importedQuestionIds' => $newids,
            'activity' => editor_service::serialise_activity(
                $updated,
                media_service::manifest($context, 'background', 0),
                media_service::manifest($context, 'logo', 0)
            ),
            'questions' => editor_service::list_questions((int)$quizgeist->id, $context),
        ];
    }

    /**
     * Delete a template if the current user owns it or manages the library.
     *
     * @param int $templateid Template ID.
     * @param int $userid Current user.
     * @return array
     */
    public static function delete(int $templateid, int $userid): array {
        global $DB;

        $template = $DB->get_record('quizgeist_templates', [
            'id' => $templateid,
            'visibility' => 'school',
        ], '*', MUST_EXIST);
        $systemcontext = \context_system::instance();
        $allowed = ((int)($template->createdby ?? 0) === $userid)
            || has_capability('mod/quizgeist:managealltemplates', $systemcontext);
        if (!$allowed) {
            throw new \required_capability_exception(
                $systemcontext,
                'mod/quizgeist:managealltemplates',
                'nopermissions',
                ''
            );
        }
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $DB->delete_records('quizgeist_templates', ['id' => $templateid]);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        media_service::delete_area($systemcontext, 'templatemedia', $templateid);
        return ['templateid' => $templateid, 'deleted' => true];
    }

    /**
     * Serialise safe template metadata.
     *
     * @param \stdClass $template Record.
     * @param int $userid Current user.
     * @param int $accesscmid Course-module ID authorising template-media access.
     * @return array
     */
    private static function serialise_template(
        \stdClass $template,
        int $userid,
        int $accesscmid
    ): array {
        $snapshot = self::decode_snapshot($template->contentjson, false);
        $appearance = $snapshot['appearance'] ?? [];
        $tags = json_decode((string)$template->tags, true);
        if (!is_array($tags)) {
            $tags = [];
        }
        $systemcontext = \context_system::instance();
        $isowner = (int)($template->createdby ?? 0) === $userid;
        return [
            'id' => (int)$template->id,
            'name' => format_string($template->name, true, ['context' => $systemcontext]),
            'description' => format_string(
                (string)$template->description,
                true,
                ['context' => $systemcontext]
            ),
            'tags' => array_values(array_map(
                static fn(string $tag): string => format_string(
                    $tag,
                    true,
                    ['context' => $systemcontext]
                ),
                array_filter($tags, 'is_string')
            )),
            'visibility' => 'school',
            'timecreated' => (int)$template->timecreated,
            'timemodified' => (int)$template->timemodified,
            'questionCount' => isset($snapshot['questions']) && is_array($snapshot['questions'])
                ? count($snapshot['questions'])
                : 0,
            'theme' => $appearance['theme'] ?? 'hell',
            'season' => $appearance['season'] ?? 'herbst',
            'media' => media_service::manifest(
                $systemcontext,
                'templatemedia',
                (int)$template->id,
                $accesscmid
            ),
            'isOwn' => $isowner,
            'canDelete' => $isowner
                || has_capability('mod/quizgeist:managealltemplates', $systemcontext),
        ];
    }

    /**
     * Decode and structurally validate a snapshot.
     *
     * @param string $json JSON.
     * @param bool $strict Throw for malformed snapshots.
     * @return array
     */
    private static function decode_snapshot(string $json, bool $strict = true): array {
        try {
            $snapshot = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            if ($strict) {
                throw new \invalid_parameter_exception('Template snapshot JSON is invalid.');
            }
            return [];
        }
        if (!is_array($snapshot)
                || (int)($snapshot['schemaVersion'] ?? 0) !== self::SCHEMA_VERSION
                || !isset($snapshot['questions'])
                || !is_array($snapshot['questions'])
                || !array_is_list($snapshot['questions'])
                || count($snapshot['questions']) > self::MAX_QUESTIONS) {
            if ($strict) {
                throw new \invalid_parameter_exception('Template snapshot shape is invalid.');
            }
            return is_array($snapshot) ? $snapshot : [];
        }
        $appearance = is_array($snapshot['appearance'] ?? null) ? $snapshot['appearance'] : [];
        $snapshot['appearance'] = [
            'theme' => in_array($appearance['theme'] ?? '', editor_service::THEMES, true)
                ? $appearance['theme']
                : 'hell',
            'season' => in_array($appearance['season'] ?? '', editor_service::SEASONS, true)
                ? $appearance['season']
                : 'herbst',
            'allowbacktrack' => !empty($appearance['allowbacktrack']),
        ];
        return $snapshot;
    }

    /**
     * Re-normalise every imported question and reject draft content.
     *
     * @param array $questions Snapshot questions.
     * @param array $templatemedia Complete system template-media manifest.
     * @return array
     */
    private static function validate_snapshot_questions(array $questions, array $templatemedia): array {
        if (!$questions) {
            throw new \invalid_parameter_exception('Template contains no questions.');
        }
        $validated = [];
        $sourceids = [];
        foreach ($questions as $index => $raw) {
            if (!is_array($raw)) {
                throw new \invalid_parameter_exception('Template question is malformed.');
            }
            $sourceid = editor_service::positive_id($raw['sourceid'] ?? null, 'template sourceid');
            if (isset($sourceids[$sourceid])) {
                throw new \invalid_parameter_exception('Template source IDs are duplicated.');
            }
            $sourceids[$sourceid] = true;
            $normalised = question_schema::normalise($raw);
            question_type_registry::assert_creatable(
                $normalised['question']['qtype']
            );
            // F13: ein Vorlagenimport legt Buehnen-Fragen NEU an, genau wie der
            // KI-/Kahoot-Import in editor_service. Ohne diese Zeile waere die
            // Vorlagenbibliothek der Weg, den Untermodus ohne Lizenz in jeden
            // Kurs zu tragen; der Bestand im Quellkurs bleibt unberuehrt.
            editor_service::guard_stage_check(
                [],
                $normalised['question']['options']
            );
            $errors = question_schema::validate_media(
                $normalised['question'],
                $normalised['validationErrors'],
                self::question_manifest($templatemedia, $sourceid)
            );
            if ($errors) {
                throw new \invalid_parameter_exception("Template question {$index} is incomplete.");
            }
            $validated[] = [
                'sourceid' => $sourceid,
                'question' => $normalised['question'],
            ];
        }
        return $validated;
    }

    /**
     * Scope one system template manifest to a snapshot question.
     *
     * The returned paths use the same /question/ and /answers/<id>/ form as
     * module-context manifests, so both import and editor use identical media
     * validation.
     *
     * @param array $templatemedia Complete system template-media manifest.
     * @param int $sourceid Snapshot source question ID.
     * @return array
     */
    private static function question_manifest(array $templatemedia, int $sourceid): array {
        $prefix = '/questions/' . $sourceid;
        $manifest = [];
        foreach ($templatemedia as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)) {
                continue;
            }
            if (!str_starts_with($file['path'], $prefix . '/')) {
                continue;
            }
            $file['path'] = substr($file['path'], strlen($prefix));
            $manifest[] = $file;
        }
        return $manifest;
    }

    /**
     * Ensure every media path claimed in JSON exists below its source folder.
     *
     * @param array $questions Validated questions.
     * @param array $templatemedia Complete system template-media manifest.
     * @return void
     */
    private static function assert_snapshot_media(array $questions, array $templatemedia): void {
        $paths = array_column($templatemedia, 'path');
        $lookup = array_fill_keys($paths, true);
        foreach ($questions as $item) {
            $prefix = '/questions/' . $item['sourceid'];
            $options = $item['question']['options'];
            $claimed = [];
            if (!empty($options['media'])) {
                $claimed[] = $options['media'];
            }
            foreach (['answers', 'items'] as $collection) {
                foreach ($options[$collection] ?? [] as $row) {
                    if (!empty($row['media'])) {
                        $claimed[] = $row['media'];
                    }
                }
            }
            foreach ($claimed as $path) {
                if (!isset($lookup[$prefix . $path])) {
                    throw new \invalid_parameter_exception('Template media reference is missing.');
                }
            }
        }
    }

    /**
     * Allocate a positive, unserved item ID for temporary media copies.
     *
     * Moodle's File API rejects negative item IDs. The high positive range is
     * checked across every relevant file area and both persistent ID domains
     * before it is used.
     *
     * @param \context $context File context.
     * @param string $area Primary area (documentational).
     * @param int[] $excluded IDs already allocated in this operation.
     * @return int
     */
    private static function new_staging_itemid(
        \context $context,
        string $area,
        array $excluded = []
    ): int {
        global $DB;

        $fs = get_file_storage();
        $areas = array_values(array_unique([
            $area,
            'templatemedia',
            'background',
            'logo',
            'questionmedia',
        ]));
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $itemid = random_int(
                self::STAGING_ITEMID_MIN,
                self::STAGING_ITEMID_MAX
            );
            if (in_array($itemid, $excluded, true)) {
                continue;
            }
            if ($DB->record_exists('quizgeist_questions', ['id' => $itemid])
                    || $DB->record_exists('quizgeist_templates', ['id' => $itemid])) {
                continue;
            }
            $empty = true;
            foreach ($areas as $candidatearea) {
                if (!$fs->is_area_empty(
                    $context->id,
                    'mod_quizgeist',
                    $candidatearea,
                    $itemid
                )) {
                    $empty = false;
                    break;
                }
            }
            if ($empty) {
                return $itemid;
            }
        }
        throw new \coding_exception('Unable to allocate a temporary media item ID.');
    }

    /**
     * Atomically-as-practical replace a committed template file area from staging.
     *
     * @param \context_system $context System context.
     * @param int $templateid Final template item ID.
     * @param int $incomingid Validated incoming staging item ID.
     * @param int|null $backupid Previous-area staging item ID, when updating.
     * @return void
     */
    private static function replace_template_media_after_commit(
        \context_system $context,
        int $templateid,
        int $incomingid,
        ?int $backupid
    ): void {
        media_service::delete_area($context, 'templatemedia', $templateid);
        try {
            media_service::copy_area(
                $context,
                'templatemedia',
                $incomingid,
                $context,
                'templatemedia',
                $templateid
            );
        } catch (\Throwable $exception) {
            media_service::delete_area($context, 'templatemedia', $templateid);
            if ($backupid !== null) {
                try {
                    media_service::copy_area(
                        $context,
                        'templatemedia',
                        $backupid,
                        $context,
                        'templatemedia',
                        $templateid
                    );
                } catch (\Throwable $restoreexception) {
                    throw new \coding_exception(
                        'Template media replacement and recovery both failed.',
                        $restoreexception->getMessage()
                    );
                }
                media_service::delete_area($context, 'templatemedia', $incomingid);
                media_service::delete_area($context, 'templatemedia', $backupid);
            }
            throw $exception;
        }
        media_service::delete_area($context, 'templatemedia', $incomingid);
        if ($backupid !== null) {
            media_service::delete_area($context, 'templatemedia', $backupid);
        }
    }

    /**
     * Replace appearance media only after the import DB transaction committed.
     *
     * @param \context_module $context Target module context.
     * @param int $incomingid Incoming background/logo staging item.
     * @param int $backupid Previous background/logo staging item.
     * @return void
     */
    private static function replace_appearance_after_commit(
        \context_module $context,
        int $incomingid,
        int $backupid
    ): void {
        media_service::delete_area($context, 'background', 0);
        media_service::delete_area($context, 'logo', 0);
        try {
            foreach (['background', 'logo'] as $area) {
                media_service::copy_area(
                    $context,
                    $area,
                    $incomingid,
                    $context,
                    $area,
                    0
                );
            }
        } catch (\Throwable $exception) {
            media_service::delete_area($context, 'background', 0);
            media_service::delete_area($context, 'logo', 0);
            try {
                foreach (['background', 'logo'] as $area) {
                    media_service::copy_area(
                        $context,
                        $area,
                        $backupid,
                        $context,
                        $area,
                        0
                    );
                }
            } catch (\Throwable $restoreexception) {
                throw new \coding_exception(
                    'Appearance media replacement and recovery both failed.',
                    $restoreexception->getMessage()
                );
            }
            self::delete_appearance_staging($context, [$incomingid, $backupid]);
            throw $exception;
        }
        self::delete_appearance_staging($context, [$incomingid, $backupid]);
    }

    /**
     * Remove temporary background/logo item IDs.
     *
     * @param \context_module $context Module context.
     * @param array $itemids Staging item IDs, optionally containing null.
     * @return void
     */
    private static function delete_appearance_staging(
        \context_module $context,
        array $itemids
    ): void {
        foreach (array_unique($itemids, SORT_REGULAR) as $itemid) {
            if (!is_int($itemid)
                    || $itemid < self::STAGING_ITEMID_MIN
                    || $itemid > self::STAGING_ITEMID_MAX) {
                continue;
            }
            media_service::delete_area($context, 'background', $itemid);
            media_service::delete_area($context, 'logo', $itemid);
        }
    }

    /**
     * Normalise tags.
     *
     * @param mixed $raw Tags list or comma-separated string.
     * @return array
     */
    private static function normalise_tags($raw): array {
        if (is_string($raw)) {
            $raw = preg_split('/[,;]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \invalid_parameter_exception('Template tags must be a list.');
        }
        $tags = [];
        foreach (array_slice($raw, 0, 12) as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $clean = \core_text::strtolower(self::optional_text((string)$tag, 64));
            if ($clean !== '') {
                $tags[$clean] = $clean;
            }
        }
        return array_values($tags);
    }

    /**
     * Clean required text.
     *
     * @param mixed $raw Value.
     * @param int $max Maximum characters.
     * @param string $field Field.
     * @return string
     */
    private static function required_text($raw, int $max, string $field): string {
        $value = self::optional_text($raw, $max);
        if ($value === '') {
            throw new \invalid_parameter_exception("{$field} is required.");
        }
        return $value;
    }

    /**
     * Clean optional text.
     *
     * @param mixed $raw Value.
     * @param int $max Maximum characters.
     * @return string
     */
    private static function optional_text($raw, int $max): string {
        if (!is_scalar($raw) && $raw !== null) {
            throw new \invalid_parameter_exception('Text value must be scalar.');
        }
        $value = trim((string)clean_param((string)($raw ?? ''), PARAM_TEXT));
        return \core_text::strlen($value) > $max
            ? \core_text::substr($value, 0, $max)
            : $value;
    }
}
