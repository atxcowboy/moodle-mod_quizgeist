<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared UI/CLI Kahoot import application service.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\kahoot;

use mod_quizgeist\local\editor\editor_service;
use mod_quizgeist\local\editor\media_service;
use mod_quizgeist\local\editor\question_content_lock;
use mod_quizgeist\local\editor\question_schema;
use mod_quizgeist\local\transaction_scope;

defined('MOODLE_INTERNAL') || die();

/**
 * Imports bounded local bundles atomically per Kahoot source UUID.
 */
final class import_service {

    /** Stable report contract version. */
    private const REPORT_SCHEMA_VERSION = 1;

    /** Reserved course-module idnumber for crash-recoverable source targets. */
    private const TARGET_IDNUMBER_PREFIX = 'quizgeist-kahoot-';

    /**
     * Import every document in a source bundle.
     *
     * @param source_bundle $bundle Validated local JSON/ZIP/directory bundle.
     * @param \stdClass $course Target Moodle course.
     * @param int|null $targetcmid Optional existing Quizgeist CM.
     * @param int $userid Importing teacher/admin.
     * @param bool $dryrun Validate and report without writes.
     * @return array Batch report.
     */
    public static function import_bundle(
        source_bundle $bundle,
        \stdClass $course,
        ?int $targetcmid,
        int $userid,
        bool $dryrun = false
    ): array {
        global $DB;

        if ($DB->is_transaction_started()) {
            throw new \coding_exception(
                'Kahoot imports cannot join an ambient database transaction.'
            );
        }
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $reports = [];
        $seenmedia = [];
        foreach ($bundle->kahoots() as $source) {
            try {
                $sourcefailure = $source['failureCode'] ?? null;
                if (is_string($sourcefailure)) {
                    throw new \UnexpectedValueException($sourcefailure);
                }
                $report = self::import_one(
                    $bundle,
                    $source,
                    $course,
                    $targetcmid,
                    $userid,
                    $dryrun
                );
            } catch (\Throwable $exception) {
                if ($exception instanceof \coding_exception
                        || $exception instanceof \dml_connection_exception
                        || $exception instanceof \dml_transaction_exception
                        || $DB->is_transaction_started()) {
                    throw $exception;
                }
                $report = self::failed_source_report(
                    $source,
                    $course,
                    $targetcmid,
                    $dryrun,
                    $exception
                );
            }
            $reports[] = $report;
            foreach ($report['media'] ?? [] as $media) {
                if (is_array($media) && is_string($media['sourceRef'] ?? null)) {
                    $seenmedia[$media['sourceRef']] = true;
                }
            }
        }
        $failed = count(array_filter(
            $reports,
            static fn(array $report): bool =>
                ($report['status'] ?? '') === 'failed'
        ));
        $succeeded = count($reports) - $failed;
        $status = $failed === 0
            ? ($dryrun ? 'dry-run' : 'complete')
            : ($succeeded > 0 ? 'partial' : 'failed');
        return [
            'schemaVersion' => self::REPORT_SCHEMA_VERSION,
            'status' => $status,
            'dryRun' => $dryrun,
            'totals' => [
                'kahoots' => count($reports),
                'succeeded' => $succeeded,
                'failed' => $failed,
                'sourceQuestions' => array_sum(array_map(
                    static fn(array $report): int => (int)($report['totals']['source'] ?? 0),
                    $reports
                )),
                'retained' => array_sum(array_map(
                    static fn(array $report): int => (int)($report['totals']['retained'] ?? 0),
                    $reports
                )),
                'imported' => array_sum(array_map(
                    static fn(array $report): int => (int)($report['totals']['imported'] ?? 0),
                    $reports
                )),
                'adjusted' => array_sum(array_map(
                    static fn(array $report): int => (int)($report['totals']['adjusted'] ?? 0),
                    $reports
                )),
                'skipped' => array_sum(array_map(
                    static fn(array $report): int => (int)($report['totals']['skipped'] ?? 0),
                    $reports
                )),
                'uniqueMedia' => count($seenmedia),
            ],
            'reports' => $reports,
        ];
    }

    /**
     * Import one already parsed source document.
     *
     * @param source_bundle $bundle Owning validated bundle.
     * @param array $source source_bundle::kahoots() row.
     * @param \stdClass $course Target course.
     * @param int|null $targetcmid Optional existing CM.
     * @param int $userid Importing user.
     * @param bool $dryrun Whether writes are forbidden.
     * @return array Per-Kahoot report.
     */
    public static function import_one(
        source_bundle $bundle,
        array $source,
        \stdClass $course,
        ?int $targetcmid,
        int $userid,
        bool $dryrun = false
    ): array {
        global $DB;

        if ($DB->is_transaction_started()) {
            throw new \coding_exception(
                'A Kahoot import cannot join an ambient database transaction.'
            );
        }
        $prepared = self::prepare_source(
            $bundle,
            $source,
            $course,
            $targetcmid,
            $dryrun
        );
        if ($dryrun) {
            return $prepared['report'];
        }
        return self::import_prepared(
            $bundle,
            $course,
            $userid,
            $prepared
        );
    }

    /**
     * Validate, map and media-preflight one source without writing.
     */
    private static function prepare_source(
        source_bundle $bundle,
        array $source,
        \stdClass $course,
        ?int $targetcmid,
        bool $dryrun
    ): array {
        $document = $source['document'] ?? null;
        if (!is_array($document)) {
            throw new \invalid_parameter_exception('Kahoot source document is missing.');
        }
        $uuid = (string)($document['uuid'] ?? '');
        $jsonhash = (string)($source['sha256'] ?? '');
        if (!preg_match('/^[a-f0-9-]{36}$/D', $uuid)
                || !preg_match('/^[a-f0-9]{64}$/D', $jsonhash)) {
            throw new \invalid_parameter_exception('Kahoot source identity is invalid.');
        }
        $title = self::safe_source_title($document, $uuid);

        $mapped = question_mapper::map_all(
            is_array($document['questions'] ?? null) ? $document['questions'] : []
        );
        self::preflight_mapped_questions($mapped);

        $resolvedtarget = $targetcmid === null
            ? null
            : self::resolve_target((int)$course->id, $targetcmid);
        $preflightcontext = $resolvedtarget
            ? $resolvedtarget['context']
            : \context_course::instance((int)$course->id);
        [$mediabyurl, $mediareport] = self::preflight_media(
            $bundle,
            $document,
            $preflightcontext
        );
        $sourcehash = self::source_fingerprint($jsonhash, $mediareport);
        $mapped = question_mapper::reconcile_media($mapped, $mediabyurl);
        $report = self::base_report(
            $uuid,
            $title,
            $sourcehash,
            $mapped,
            $mediareport,
            $course,
            $resolvedtarget,
            $dryrun
        );
        return [
            'document' => $document,
            'uuid' => $uuid,
            'title' => $title,
            'mapped' => $mapped,
            'mediabyurl' => $mediabyurl,
            'mediareport' => $mediareport,
            'sourcehash' => $sourcehash,
            'resolvedtarget' => $resolvedtarget,
            'report' => $report,
        ];
    }

    /**
     * Execute a prepared source under its course/UUID lock.
     */
    private static function import_prepared(
        source_bundle $bundle,
        \stdClass $course,
        int $userid,
        array $prepared
    ): array {
        $factory = \core\lock\lock_config::get_lock_factory('mod_quizgeist');
        $lock = $factory->get_lock(
            'kahoot:' . hash(
                'sha256',
                (int)$course->id . ':' . $prepared['uuid']
            ),
            30
        );
        if (!$lock) {
            throw new \moodle_exception('error:importlocked', 'mod_quizgeist');
        }

        try {
            $identity = self::resolve_target_and_identity(
                $course,
                $prepared['resolvedtarget'],
                $prepared['uuid'],
                $prepared['title'],
                $prepared['sourcehash'],
                $userid
            );
            if ($identity['report'] !== null) {
                return $identity['report'];
            }
            $target = $identity['target'];
            $lockedactivityids = [(int)$target['quizgeist']->id];
            if ($identity['staletarget'] !== null) {
                $lockedactivityids[] =
                    (int)$identity['staletarget']['quizgeist']->id;
            }
            return question_content_lock::with_activity_locks(
                $lockedactivityids,
                function() use (
                    $bundle,
                    $course,
                    $identity,
                    $prepared,
                    $target,
                    $userid,
                ): array {
                    return self::import_locked(
                        $bundle,
                        $course,
                        $prepared,
                        $identity,
                        $userid,
                        $target
                    );
                }
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Run one import while all involved activity content locks are held.
     */
    private static function import_locked(
        source_bundle $bundle,
        \stdClass $course,
        array $prepared,
        array $identity,
        int $userid,
        array $target
    ): array {
        $disposabletarget = false;
        $committed = false;
        try {
            if ($identity['sourcecreatedtarget']) {
                // A parallel editor must never make a crash target disposable.
                $target = self::assert_reusable_reserved_target(
                    $target,
                    $prepared['uuid']
                );
                $disposabletarget = true;
            }
            $staged = self::stage_content(
                $bundle,
                $course,
                $prepared,
                $identity,
                $target,
                $userid
            );
            $committed = true;
            return self::finalise(
                $staged,
                $identity['staletarget'],
                $target,
                $identity['sourcecreatedtarget']
            );
        } catch (\Throwable $exception) {
            if (!$committed && $disposabletarget) {
                self::remove_created_target($target);
            }
            throw $exception;
        }
    }

    /**
     * Resolve the locked provenance ledger and the exact target activity.
     *
     * @return array{
     *     report:?array,
     *     target:?array,
     *     staleimport:?\stdClass,
     *     staletarget:?array,
     *     sourcecreatedtarget:bool
     * }
     */
    private static function resolve_target_and_identity(
        \stdClass $course,
        ?array $resolvedtarget,
        string $uuid,
        string $title,
        string $sourcehash,
        int $userid
    ): array {
        $existing = import_repository::find_in_course(
            (int)$course->id,
            $uuid
        );
        if ($existing && $existing->status === 'complete') {
            return [
                'report' => self::idempotent_report(
                    $existing,
                    (int)$course->id,
                    $uuid,
                    $sourcehash,
                    $userid
                ),
                'target' => null,
                'staleimport' => null,
                'staletarget' => null,
                'sourcecreatedtarget' => false,
            ];
        }

        $target = $resolvedtarget;
        $staleimport = $existing ?: null;
        $staletarget = null;
        if ($staleimport !== null) {
            $staletarget = self::target_for_instance(
                (int)$course->id,
                (int)$staleimport->quizgeistid
            );
            self::require_manage_capability($staletarget, $userid);
            $target ??= $staletarget;
        }

        $sourcecreatedtarget = false;
        if ($target === null) {
            $target = self::find_reserved_target(
                (int)$course->id,
                $uuid
            );
            if ($target === null) {
                $target = self::create_target($course, $title, $uuid);
            }
            $sourcecreatedtarget = true;
        }
        self::require_manage_capability($target, $userid);
        return [
            'report' => null,
            'target' => $target,
            'staleimport' => $staleimport,
            'staletarget' => $staletarget,
            'sourcecreatedtarget' => $sourcecreatedtarget,
        ];
    }

    /**
     * Return a completed import, healing its post-commit publication if needed.
     */
    private static function idempotent_report(
        \stdClass $existing,
        int $courseid,
        string $uuid,
        string $sourcehash,
        int $userid
    ): array {
        $existingtarget = self::target_for_instance(
            $courseid,
            (int)$existing->quizgeistid
        );
        self::require_manage_capability($existingtarget, $userid);
        $stored = import_repository::report($existing);
        $publicationpending = !empty(
            $stored['_targetPublicationPending']
        );
        if ($publicationpending
                && self::is_reserved_target($existingtarget, $uuid)) {
            try {
                // Replay the complete Core publication chain after a
                // post-commit interruption.
                self::publish_created_target($existingtarget);
                $stored = self::clear_publication_pending(
                    (int)$existing->id,
                    $stored
                );
                $publicationpending = false;
            } catch (\Throwable $exception) {
                self::debug_post_commit_failure(
                    'target publication recovery',
                    $exception
                );
            }
        }
        if (!hash_equals((string)$existing->sourcehash, $sourcehash)) {
            throw new \moodle_exception(
                $publicationpending
                    ? 'error:importpublicationpending'
                    : 'error:importchanged',
                'mod_quizgeist'
            );
        }

        // Internal recovery state never enters the public report contract.
        unset($stored['_targetPublicationPending']);
        if ($publicationpending) {
            $stored['publicationPending'] = true;
        }
        $stored['idempotent'] = true;
        return $stored;
    }

    /**
     * Store all source-owned content in one transaction.
     *
     * Rollback compensation deliberately remains inside the activity locks.
     *
     * @return array{report:array,importid:int,stalefiles:?array}
     */
    private static function stage_content(
        source_bundle $bundle,
        \stdClass $course,
        array $prepared,
        array $identity,
        array $target,
        int $userid
    ): array {
        $transaction = null;
        $import = null;
        try {
            // Nested editor scopes join this transaction without changing the
            // global content-lock-before-transaction order.
            $transaction = transaction_scope::begin();
            $stalefiles = $identity['staleimport'] === null
                ? null
                : self::remove_stale_import_records(
                    $identity['staleimport']
                );
            $import = import_repository::create_pending(
                (int)$target['quizgeist']->id,
                (int)$course->id,
                $prepared['uuid'],
                $prepared['title'],
                $prepared['sourcehash']
            );
            [$completedmapping, $storedmedia] = self::store_import_content(
                $bundle,
                $prepared,
                $target,
                $userid,
                (int)$import->id,
                $identity['sourcecreatedtarget']
            );
            $report = self::completed_report(
                $course,
                $prepared,
                $completedmapping,
                $storedmedia,
                $target,
                $identity['sourcecreatedtarget']
            );
            import_repository::finish(
                (int)$import->id,
                'complete',
                $report
            );
            $transaction->allow_commit();
            return [
                'report' => $report,
                'importid' => (int)$import->id,
                'stalefiles' => $stalefiles,
            ];
        } catch (\Throwable $exception) {
            self::rollback_staged_content(
                $exception,
                $transaction,
                $import,
                $target
            );
        }
    }

    /**
     * Create questions and attach all source-owned media.
     *
     * @return array{0:array,1:array}
     */
    private static function store_import_content(
        source_bundle $bundle,
        array $prepared,
        array $target,
        int $userid,
        int $importid,
        bool $sourcecreatedtarget
    ): array {
        $mapped = $prepared['mapped'];
        $items = [];
        foreach ($mapped as $maprow) {
            if (is_array($maprow['question'] ?? null)) {
                $items[] = ['question' => $maprow['question']];
            }
        }

        $created = [];
        if ($items) {
            $created = editor_service::import_confirmed_drafts(
                $target['quizgeist'],
                $target['context'],
                $userid,
                $items,
                $importid,
                true
            );
        }
        $storedmedia = self::store_provenance_media(
            $bundle,
            $prepared['mediabyurl'],
            $target['context'],
            $importid
        );
        self::attach_question_media(
            $mapped,
            $created,
            $storedmedia,
            $target
        );
        self::attach_cover(
            $prepared['document'],
            $storedmedia,
            $target,
            $sourcecreatedtarget
        );
        $completedmapping = self::complete_question_mapping(
            $mapped,
            $created,
            $target
        );
        return [$completedmapping, $storedmedia];
    }

    /**
     * Build the final public report plus an internal publication sentinel.
     */
    private static function completed_report(
        \stdClass $course,
        array $prepared,
        array $completedmapping,
        array $storedmedia,
        array $target,
        bool $sourcecreatedtarget
    ): array {
        $report = self::base_report(
            $prepared['uuid'],
            $prepared['title'],
            $prepared['sourcehash'],
            $completedmapping,
            self::completed_media_report(
                $prepared['mediareport'],
                $storedmedia
            ),
            $course,
            $target,
            false
        );
        $report['status'] = 'complete';
        if ($sourcecreatedtarget) {
            $report['_targetPublicationPending'] = true;
        }
        return $report;
    }

    /**
     * Refresh imported media and bind mapped rows to their new question IDs.
     */
    private static function complete_question_mapping(
        array $mapped,
        array $created,
        array $target
    ): array {
        $createdindex = 0;
        foreach ($mapped as &$maprow) {
            if (!is_array($maprow['question'] ?? null)) {
                continue;
            }
            $questionid = (int)$created[$createdindex]['id'];
            $refreshed = editor_service::refresh_question_media(
                (int)$target['quizgeist']->id,
                $questionid,
                $target['context']
            );
            if (($refreshed['status'] ?? '') !== 'ready') {
                throw new \moodle_exception(
                    'error:importquestioninvalid',
                    'mod_quizgeist'
                );
            }
            $maprow['targetQuestionId'] = $questionid;
            $createdindex++;
        }
        unset($maprow);
        return $mapped;
    }

    /**
     * Roll back the exact current marker and its file areas, then rethrow.
     */
    private static function rollback_staged_content(
        \Throwable $exception,
        ?transaction_scope $transaction,
        ?\stdClass $import,
        array $target
    ): never {
        if ($transaction !== null) {
            try {
                $transaction->rollback($exception);
            } catch (\Throwable $rolledback) {
                $exception = $rolledback;
            }
        }
        if ($import !== null) {
            // Normally rollback removed these records. Exact-area cleanup is
            // defence for file drivers that wrote outside SQL.
            self::remove_import_questions(
                (int)$import->id,
                (int)$target['quizgeist']->id,
                $target['context'],
                []
            );
            self::remove_import_marker((int)$import->id);
        }
        throw $exception;
    }

    /**
     * Complete post-commit file cleanup and target publication.
     */
    private static function finalise(
        array $staged,
        ?array $staletarget,
        array $target,
        bool $sourcecreatedtarget
    ): array {
        if ($staged['stalefiles'] !== null && $staletarget !== null) {
            self::remove_stale_import_files_safely(
                $staged['stalefiles'],
                $staletarget['context']
            );
        }
        $report = $staged['report'];
        if (!$sourcecreatedtarget) {
            return $report;
        }

        // Core cache rebuilding remains outside SQL. The next idempotent run
        // heals an interruption through the retained publication sentinel.
        try {
            self::publish_created_target($target);
            return self::clear_publication_pending(
                (int)$staged['importid'],
                $report
            );
        } catch (\Throwable $exception) {
            self::debug_post_commit_failure(
                'target publication',
                $exception
            );
            unset($report['_targetPublicationPending']);
            $report['publicationPending'] = true;
            return $report;
        }
    }

    /**
     * Explicitly discard one retryable provenance marker.
     *
     * The operation is used by the capability-protected teacher UI. It shares
     * the course/UUID and content locks with normal imports, rechecks the exact
     * target capability, and never permits a complete ledger to be discarded.
     *
     * @param int $importid Marker ID.
     * @param int $courseid Owning course.
     * @param int $userid Acting user.
     * @return void
     */
    public static function discard_stale_import(
        int $importid,
        int $courseid,
        int $userid
    ): void {
        global $DB;

        if ($DB->is_transaction_started()) {
            throw new \coding_exception(
                'Kahoot cleanup cannot join an ambient database transaction.'
            );
        }
        $import = $DB->get_record('quizgeist_imports', [
            'id' => $importid,
            'courseid' => $courseid,
            'sourceformat' => 'kahoot',
        ]);
        if (!$import) {
            throw new \moodle_exception(
                'error:importnotfound',
                'mod_quizgeist'
            );
        }
        if ((string)$import->status === 'complete') {
            throw new \moodle_exception(
                'error:importdiscardcomplete',
                'mod_quizgeist'
            );
        }

        $factory = \core\lock\lock_config::get_lock_factory('mod_quizgeist');
        $lock = $factory->get_lock(
            'kahoot:' . hash(
                'sha256',
                $courseid . ':' . (string)$import->sourceuuid
            ),
            30
        );
        if (!$lock) {
            throw new \moodle_exception(
                'error:importlocked',
                'mod_quizgeist'
            );
        }
        try {
            $import = $DB->get_record('quizgeist_imports', [
                'id' => $importid,
                'courseid' => $courseid,
                'sourceformat' => 'kahoot',
            ], '*', MUST_EXIST);
            if ((string)$import->status === 'complete') {
                throw new \moodle_exception(
                    'error:importdiscardcomplete',
                    'mod_quizgeist'
                );
            }
            $target = self::target_for_instance(
                $courseid,
                (int)$import->quizgeistid
            );
            self::require_manage_capability($target, $userid);
            question_content_lock::with_activity_locks(
                [(int)$target['quizgeist']->id],
                static function() use ($import, $target): void {
                    $transaction = transaction_scope::begin();
                    try {
                        $stalefiles =
                            self::remove_stale_import_records($import);
                        $transaction->allow_commit();
                        self::remove_stale_import_files_safely(
                            $stalefiles,
                            $target['context']
                        );
                    } catch (\Throwable $exception) {
                        $transaction->rollback($exception);
                    }
                }
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Validate every canonical question with the runtime strategy registry.
     *
     * @param array $mapped Mapper rows.
     * @return void
     */
    private static function preflight_mapped_questions(array $mapped): void {
        foreach ($mapped as $row) {
            if (!is_array($row['question'] ?? null)) {
                continue;
            }
            $normalised = question_schema::normalise($row['question']);
            \mod_quizgeist\local\live\qtype\registry::get(
                $normalised['question']['qtype']
            );
        }
    }

    /**
     * Recursively identify locally mapped source URLs and validate their bytes.
     *
     * @param source_bundle $bundle Source bundle.
     * @param array $document Kahoot document.
     * @param \context $context Limit context.
     * @return array{0:array<string,array>,1:array}
     */
    private static function preflight_media(
        source_bundle $bundle,
        array $document,
        \context $context
    ): array {
        $urls = [];
        self::collect_media_urls($document, $bundle, $urls);
        ksort($urls, SORT_STRING);
        $media = [];
        $report = [];
        foreach (array_keys($urls) as $url) {
            $descriptor = $bundle->media_descriptor($url);
            $content = $bundle->read_media($url);
            if (!is_array($descriptor) || !is_string($content)) {
                $report[] = [
                    'sourceRef' => $url,
                    'outcome' => 'missing',
                    'reason' => 'media_missing',
                ];
                continue;
            }
            $actualhash = hash('sha256', $content);
            if (!hash_equals((string)$descriptor['sha256'], $actualhash)
                    || (int)$descriptor['size'] !== strlen($content)) {
                throw new \moodle_exception('error:importmediachecksum', 'mod_quizgeist');
            }
            $mimetype = media_service::preflight_import_content(
                $context,
                (string)$descriptor['filename'],
                $content
            );
            $media[$url] = [
                'descriptor' => $descriptor,
                'mimetype' => $mimetype,
            ];
            $report[] = [
                'sourceRef' => $url,
                'sourceName' => (string)$descriptor['sourceName'],
                'filename' => (string)$descriptor['filename'],
                'size' => (int)$descriptor['size'],
                'sha256' => $actualhash,
                'outcome' => 'validated',
            ];
            unset($content);
        }
        return [$media, $report];
    }

    /**
     * Bind idempotence to the JSON and every locally resolved media object.
     *
     * The media preflight runs before the ledger lookup, so changed bytes with
     * an unchanged Kahoot UUID can never be mistaken for an identical rerun.
     */
    private static function source_fingerprint(string $jsonhash, array $mediareport): string {
        $media = [];
        foreach ($mediareport as $row) {
            $media[] = [
                'sourceRef' => (string)($row['sourceRef'] ?? ''),
                'size' => (int)($row['size'] ?? 0),
                'sha256' => (string)($row['sha256'] ?? ''),
                'outcome' => (string)($row['outcome'] ?? ''),
            ];
        }
        usort(
            $media,
            static fn(array $left, array $right): int =>
                strcmp($left['sourceRef'], $right['sourceRef'])
        );
        return hash('sha256', json_encode(
            ['jsonSha256' => $jsonhash, 'media' => $media],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * @param mixed $value Recursive document value.
     * @param source_bundle $bundle Bundle.
     * @param array<string,bool> $urls Mutable URL set.
     */
    private static function collect_media_urls($value, source_bundle $bundle, array &$urls): void {
        if (is_string($value)) {
            if ($bundle->has_media($value)) {
                $urls[$value] = true;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $child) {
            self::collect_media_urls($child, $bundle, $urls);
        }
    }

    /**
     * Build a complete stable report DTO.
     */
    private static function base_report(
        string $uuid,
        string $title,
        string $sourcehash,
        array $mapped,
        array $media,
        \stdClass $course,
        ?array $target,
        bool $dryrun
    ): array {
        $retained = count(array_filter(
            $mapped,
            static fn(array $row): bool => is_array($row['question'] ?? null)
        ));
        $imported = count(array_filter(
            $mapped,
            static fn(array $row): bool => ($row['outcome'] ?? '') === 'imported'
        ));
        $adjusted = count(array_filter(
            $mapped,
            static fn(array $row): bool => ($row['outcome'] ?? '') === 'adjusted'
        ));
        $skipped = count(array_filter(
            $mapped,
            static fn(array $row): bool => ($row['outcome'] ?? '') === 'skipped'
        ));
        $importedmedia = count(array_filter(
            $media,
            static fn(array $row): bool => ($row['outcome'] ?? '') === 'imported'
        ));
        return [
            'schemaVersion' => self::REPORT_SCHEMA_VERSION,
            'status' => $dryrun ? 'dry-run' : 'pending',
            'dryRun' => $dryrun,
            'source' => [
                'uuid' => $uuid,
                'title' => $title,
                'sha256' => $sourcehash,
            ],
            'target' => [
                'courseId' => (int)$course->id,
                'cmid' => $target ? (int)$target['cm']->id : null,
                'instanceId' => $target ? (int)$target['quizgeist']->id : null,
            ],
            'totals' => [
                'source' => count($mapped),
                'retained' => $retained,
                'imported' => $imported,
                'adjusted' => $adjusted,
                'skipped' => $skipped,
                'mediaExpected' => count($media),
                'mediaImported' => $importedmedia,
            ],
            'questions' => array_map(
                static fn(array $row): array => self::public_question_report($row),
                $mapped
            ),
            'media' => $media,
        ];
    }

    /**
     * Build a solution-free per-source failure result for an isolated batch.
     *
     * @param array $source Parsed source row.
     * @param \stdClass $course Course.
     * @param int|null $targetcmid Requested target.
     * @param bool $dryrun Dry-run flag.
     * @param \Throwable $exception Failure.
     * @return array
     */
    private static function failed_source_report(
        array $source,
        \stdClass $course,
        ?int $targetcmid,
        bool $dryrun,
        \Throwable $exception
    ): array {
        $document = is_array($source['document'] ?? null)
            ? $source['document']
            : [];
        $uuid = is_string($document['uuid'] ?? null)
            ? substr($document['uuid'], 0, 64)
            : '';
        $sourcename = is_string($source['sourceName'] ?? null)
            ? $source['sourceName']
            : '';
        $title = self::safe_source_title($document, $uuid, $sourcename);
        $questions = is_array($document['questions'] ?? null)
                && array_is_list($document['questions'])
            ? $document['questions']
            : [];
        $sourcequestioncount = is_int($source['sourceQuestionCount'] ?? null)
                && $source['sourceQuestionCount'] >= 0
            ? $source['sourceQuestionCount']
            : count($questions);
        return [
            'schemaVersion' => self::REPORT_SCHEMA_VERSION,
            'status' => 'failed',
            'dryRun' => $dryrun,
            'source' => [
                'uuid' => $uuid,
                'title' => $title,
                'sha256' => is_string($source['sha256'] ?? null)
                    ? substr($source['sha256'], 0, 64)
                    : '',
            ],
            'target' => [
                'courseId' => (int)$course->id,
                'cmid' => $targetcmid,
                'instanceId' => null,
            ],
            'totals' => [
                'source' => $sourcequestioncount,
                'retained' => 0,
                'imported' => 0,
                'adjusted' => 0,
                'skipped' => 0,
                'mediaExpected' => 0,
                'mediaImported' => 0,
            ],
            // A runtime failure is not a mapping decision; do not misreport
            // every source row as a deliberate skip.
            'questions' => [],
            'media' => [],
            'failure' => self::failure_details($exception),
        ];
    }

    /**
     * Stable source-reader codes grouped into the sentence a teacher needs.
     *
     * source_bundle deliberately speaks a machine vocabulary that grows with
     * every new reader guard. Grouping families keeps the wording maintainable
     * while nothing but a readable sentence reaches the screen; the code itself
     * survives as a support reference in the report's error-code row.
     */
    private const SOURCE_ERROR_FAMILIES = [
        'toolarge' => [
            'kahoot_json_total_too_large',
            'media_map_too_large',
            'media_size_limit',
            'zip_entry_limit',
            'zip_member_size_limit',
            'zip_ratio_limit',
            'zip_size_limit',
        ],
        'archive' => [
            'archive_closed',
            'zip_invalid',
            'zip_member_duplicate',
            'zip_member_invalid',
            'zip_member_read_failed',
            'zip_method_unsupported',
            'zip_unavailable',
        ],
        'layout' => [
            'kahoot_count_invalid',
            'kahoot_directory_unreadable',
            'zip_directory_has_content',
            'zip_layout_ambiguous',
            'zip_layout_unsupported',
        ],
        'sourcetype' => [
            'source_type_unsupported',
        ],
        'unreadable' => [
            'mapped_question_invalid',
            'source_index_invalid',
            'source_not_found',
            'source_not_readable',
            'source_questions_invalid',
            'source_read_failed',
        ],
        'media' => [
            'media_changed_after_validation',
            'media_extension_unsupported',
            'media_map_invalid',
            'media_map_target_missing',
            'media_map_unreadable',
            'media_read_failed',
        ],
        'unsafe' => [
            'unsafe_kahoot_directory',
            'unsafe_kahoot_file',
            'unsafe_media_anchor',
            'unsafe_media_map',
            'unsafe_media_path',
            'unsafe_media_symlink',
            'unsafe_relative_path',
            'unsafe_source_path',
            'zip_member_unsafe_type',
        ],
    ];

    /**
     * Translate one stable source-reader code into a teacher-facing sentence.
     *
     * An unknown code never reaches the screen on its own: it is carried inside
     * an explanatory sentence that names it as a support reference.
     *
     * @param string $code Stable reader code.
     * @return string
     */
    public static function source_error_message(string $code): string {
        foreach (self::SOURCE_ERROR_FAMILIES as $family => $codes) {
            if (in_array($code, $codes, true)) {
                return get_string(
                    'error:importsource:' . $family,
                    'mod_quizgeist'
                );
            }
        }

        return get_string(
            'error:importsourceinvalid',
            'mod_quizgeist',
            $code
        );
    }

    /**
     * Convert an exception into a bounded, teacher-safe failure description.
     *
     * @param \Throwable $exception Failure.
     * @return array{code:string,component:string,message:string,retryable:bool}
     */
    private static function failure_details(\Throwable $exception): array {
        $code = 'unexpected_error';
        $component = 'mod_quizgeist';
        $message = get_string('error:importfailed', 'mod_quizgeist');

        if ($exception instanceof \moodle_exception) {
            $candidatecode = (string)$exception->errorcode;
            $candidatecomponent = (string)$exception->module;
            if ($candidatecode !== '') {
                $code = substr($candidatecode, 0, 80);
            }
            if ($candidatecomponent !== '') {
                $component = substr($candidatecomponent, 0, 80);
            }
            try {
                $message = get_string(
                    $exception->errorcode,
                    $exception->module,
                    $exception->a
                );
            } catch (\Throwable) {
                $message = get_string(
                    'error:importfailed',
                    'mod_quizgeist'
                );
            }
        } else {
            $candidate = trim($exception->getMessage());
            if (preg_match('/^[a-z][a-z0-9_:-]{1,79}$/D', $candidate)) {
                $code = $candidate;
                $message = self::source_error_message($candidate);
            }
        }

        return [
            'code' => $code,
            'component' => $component,
            'message' => clean_param($message, PARAM_TEXT),
            'retryable' => !in_array($code, [
                'error:importchanged',
                'error:importtargetforbidden',
                'error:importtargetoccupied',
            ], true),
        ];
    }

    /**
     * Derive one bounded title without trusting the decoded source shape.
     *
     * @param array $document Decoded source document.
     * @param string $uuid Already bounded source UUID, possibly empty.
     * @param string $sourcename Stable source name used only as a failure fallback.
     * @return string
     */
    private static function safe_source_title(
        array $document,
        string $uuid,
        string $sourcename = ''
    ): string {
        $rawtitle = $document['title'] ?? '';
        $title = (is_string($rawtitle)
                || is_int($rawtitle)
                || is_float($rawtitle))
            ? trim(clean_param((string)$rawtitle, PARAM_TEXT))
            : '';
        if ($title !== '') {
            return trim(\core_text::substr($title, 0, 255));
        }
        $fallbacksource = trim(clean_param($sourcename, PARAM_TEXT));
        if ($fallbacksource !== '') {
            return trim(\core_text::substr($fallbacksource, 0, 255));
        }
        $fallbackuuid = trim(clean_param($uuid, PARAM_TEXT));
        return \core_text::substr(
            $fallbackuuid !== '' ? 'Kahoot ' . $fallbackuuid : 'Kahoot',
            0,
            255
        );
    }

    /**
     * Strip canonical solutions/options from the teacher report row.
     */
    private static function public_question_report(array $row): array {
        return [
            'sourceIndex' => (int)($row['sourceIndex'] ?? 0),
            'sourceType' => (string)($row['sourceType'] ?? ''),
            'targetType' => isset($row['targetType']) ? (string)$row['targetType'] : null,
            'outcome' => (string)($row['outcome'] ?? 'skipped'),
            'reasons' => array_values(array_filter(
                $row['reasons'] ?? [],
                'is_string'
            )),
            'targetQuestionId' => isset($row['targetQuestionId'])
                ? (int)$row['targetQuestionId']
                : null,
        ];
    }

    /**
     * Store every unique referenced source medium in the private import area.
     *
     * @return array<string,\stored_file> Source URL to stored file.
     */
    private static function store_provenance_media(
        source_bundle $bundle,
        array $media,
        \context_module $context,
        int $importid
    ): array {
        $stored = [];
        foreach ($media as $url => $entry) {
            $descriptor = $entry['descriptor'];
            $content = $bundle->read_media($url);
            if (!is_string($content)
                    || (int)$descriptor['size'] !== strlen($content)
                    || !hash_equals(
                        (string)$descriptor['sha256'],
                        hash('sha256', $content)
                    )) {
                throw new \moodle_exception(
                    'error:importmediachecksum',
                    'mod_quizgeist'
                );
            }
            $storedfilename = self::provenance_filename(
                $url,
                (string)$descriptor['filename']
            );
            $file = media_service::store_verified_import_content(
                $context,
                media_service::IMPORT_AREA,
                $importid,
                '/source/',
                $storedfilename,
                $content
            );
            unset($content);
            if (!hash_equals((string)$descriptor['sha256'], hash('sha256', $file->get_content()))) {
                $file->delete();
                throw new \moodle_exception('error:importmediachecksum', 'mod_quizgeist');
            }
            $stored[$url] = $file;
        }
        return $stored;
    }

    /**
     * Attach mapper bindings to the newly created questions.
     */
    private static function attach_question_media(
        array $mapped,
        array $created,
        array $storedmedia,
        array $target
    ): void {
        $createdindex = 0;
        foreach ($mapped as $row) {
            if (!is_array($row['question'] ?? null)) {
                continue;
            }
            $questionid = (int)$created[$createdindex]['id'];
            foreach ($row['media'] ?? [] as $binding) {
                $sourceurl = (string)($binding['sourceUrl'] ?? '');
                if (!isset($storedmedia[$sourceurl])) {
                    continue;
                }
                $semantic = (string)($binding['target'] ?? 'question');
                $filepath = $semantic === 'question'
                    ? '/question/'
                    : '/answers/' . clean_param(
                        substr($semantic, strlen('answers/')),
                        PARAM_ALPHANUMEXT
                    ) . '/';
                media_service::copy_verified_import_file(
                    $storedmedia[$sourceurl],
                    $target['context'],
                    'questionmedia',
                    $questionid,
                    $filepath
                );
            }
            $createdindex++;
        }
    }

    /**
     * Import a cover only for a newly created activity with an empty background.
     */
    private static function attach_cover(
        array $document,
        array $storedmedia,
        array $target,
        bool $newtarget
    ): void {
        if (!$newtarget || media_service::manifest($target['context'], 'background', 0)) {
            return;
        }
        $cover = is_string($document['cover'] ?? null)
            ? $document['cover']
            : (is_string($document['coverMedia']['url'] ?? null)
                ? $document['coverMedia']['url']
                : '');
        if ($cover !== '' && isset($storedmedia[$cover])) {
            media_service::copy_verified_import_file(
                $storedmedia[$cover],
                $target['context'],
                'background',
                0,
                '/'
            );
        }
    }

    /**
     * Turn validated rows into imported report rows.
     */
    private static function completed_media_report(array $report, array $stored): array {
        foreach ($report as &$row) {
            if (isset($stored[$row['sourceRef'] ?? ''])) {
                $row['outcome'] = 'imported';
                $row['filename'] = $stored[$row['sourceRef']]->get_filename();
            }
        }
        unset($row);
        return $report;
    }

    /**
     * Avoid collisions when unrelated source directories share a basename.
     */
    private static function provenance_filename(string $sourceurl, string $filename): string {
        $prefix = substr(hash('sha256', $sourceurl), 0, 24) . '-';
        $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
        $suffix = $extension === '' ? '' : '.' . $extension;
        $stem = (string)pathinfo($filename, PATHINFO_FILENAME);
        $maximumstem = max(
            1,
            255 - \core_text::strlen($prefix) - \core_text::strlen($suffix)
        );
        return $prefix . \core_text::substr($stem, 0, $maximumstem) . $suffix;
    }

    /**
     * Resolve and verify an exact existing target CM.
     */
    private static function resolve_target(int $courseid, int $cmid): array {
        global $DB;

        $cm = get_coursemodule_from_id('quizgeist', $cmid, $courseid, false, MUST_EXIST);
        $quizgeist = $DB->get_record('quizgeist', ['id' => (int)$cm->instance], '*', MUST_EXIST);
        return [
            'cm' => $cm,
            'quizgeist' => $quizgeist,
            'context' => \context_module::instance((int)$cm->id),
        ];
    }

    /**
     * Resolve a target by activity instance.
     */
    private static function target_for_instance(int $courseid, int $quizgeistid): array {
        $cm = get_coursemodule_from_instance(
            'quizgeist',
            $quizgeistid,
            $courseid,
            false,
            MUST_EXIST
        );
        return self::resolve_target($courseid, (int)$cm->id);
    }

    /**
     * Recover the one empty hidden module reserved for an interrupted source.
     *
     * The idnumber index is not unique in Moodle. Ambiguous, visible or
     * non-empty matches therefore fail closed instead of attaching content to
     * a module whose ownership cannot be proven.
     *
     * @param int $courseid Course ID.
     * @param string $uuid Source UUID.
     * @return array|null Resolved target.
     */
    private static function find_reserved_target(
        int $courseid,
        string $uuid
    ): ?array {
        global $DB;

        $records = array_values($DB->get_records_sql(
            "SELECT cm.id, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.idnumber = :idnumber
           ORDER BY cm.id ASC",
            [
                'courseid' => $courseid,
                'idnumber' => self::TARGET_IDNUMBER_PREFIX . $uuid,
            ],
            0,
            2
        ));
        if (!$records) {
            if ($DB->record_exists('grade_items', [
                'courseid' => $courseid,
                'idnumber' => self::TARGET_IDNUMBER_PREFIX . $uuid,
            ])) {
                throw new \moodle_exception(
                    'error:importtargetoccupied',
                    'mod_quizgeist'
                );
            }
            return null;
        }
        if (count($records) !== 1
                || (string)$records[0]->modulename !== 'quizgeist') {
            throw new \moodle_exception(
                'error:importtargetoccupied',
                'mod_quizgeist'
            );
        }

        $target = self::resolve_target($courseid, (int)$records[0]->id);
        return self::assert_reusable_reserved_target($target, $uuid);
    }

    /**
     * Assert that a locked source-owned target remains hidden and empty.
     *
     * @param array $target Resolved target.
     * @param string $uuid Source UUID.
     * @return array Freshly resolved target.
     */
    private static function assert_reusable_reserved_target(
        array $target,
        string $uuid
    ): array {
        global $DB;

        $target = self::resolve_target(
            (int)$target['cm']->course,
            (int)$target['cm']->id
        );
        if (!empty($target['cm']->visible)
                || !empty($target['cm']->deletioninprogress)
                || !self::is_reserved_target($target, $uuid)) {
            throw new \moodle_exception(
                'error:importtargetoccupied',
                'mod_quizgeist'
            );
        }
        $quizgeistid = (int)$target['quizgeist']->id;
        foreach ([
            'quizgeist_imports',
            'quizgeist_questions',
            'quizgeist_sessions',
            'quizgeist_assignments',
            'quizgeist_goals',
            'quizgeist_rewards',
        ] as $table) {
            if ($DB->record_exists($table, ['quizgeistid' => $quizgeistid])) {
                throw new \moodle_exception(
                    'error:importtargetoccupied',
                    'mod_quizgeist'
                );
            }
        }
        if ($DB->record_exists_select(
            'files',
            'contextid = :contextid AND component = :component '
                . 'AND filename <> :directory',
            [
                'contextid' => (int)$target['context']->id,
                'component' => 'mod_quizgeist',
                'directory' => '.',
            ]
        )) {
            throw new \moodle_exception(
                'error:importtargetoccupied',
                'mod_quizgeist'
            );
        }
        return $target;
    }

    /**
     * Whether a target carries this source's reserved idnumber.
     *
     * @param array $target Resolved target.
     * @param string $uuid Source UUID.
     * @return bool
     */
    private static function is_reserved_target(
        array $target,
        string $uuid
    ): bool {
        return (string)($target['cm']->idnumber ?? '')
            === self::TARGET_IDNUMBER_PREFIX . $uuid;
    }

    /**
     * Require the same manage capability in the target being mutated.
     *
     * @param array $target Resolved target.
     * @param int $userid Acting user.
     * @return void
     */
    private static function require_manage_capability(
        array $target,
        int $userid
    ): void {
        if (!has_capability(
            'mod/quizgeist:manage',
            $target['context'],
            $userid
        )) {
            throw new \moodle_exception(
                'error:importtargetforbidden',
                'mod_quizgeist'
            );
        }
    }

    /**
     * Create one ordinary Quizgeist module for a source.
     */
    private static function create_target(\stdClass $course, string $title, string $uuid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        $module = $DB->get_record('modules', ['name' => 'quizgeist'], '*', MUST_EXIST);
        $info = (object)[
            'course' => (int)$course->id,
            'module' => (int)$module->id,
            'modulename' => 'quizgeist',
            'section' => 0,
            // A source-created module remains hidden until its complete import
            // transaction has succeeded.
            'visible' => 0,
            'visibleoncoursepage' => 1,
            'cmidnumber' => self::TARGET_IDNUMBER_PREFIX . $uuid,
            'name' => $title,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'showdescription' => 0,
            'completion' => COMPLETION_TRACKING_NONE,
            'availabilityconditionsjson' => '',
            'groupmode' => NOGROUPS,
            'groupingid' => 0,
            'grade' => 0,
        ];
        $created = add_moduleinfo($info, $course, null);
        return self::resolve_target((int)$course->id, (int)$created->coursemodule);
    }

    /**
     * Reveal a source-created module after its ready questions have committed.
     */
    private static function publish_created_target(array $target): void {
        global $CFG;

        require_once($CFG->dirroot . '/course/lib.php');
        $cm = get_coursemodule_from_id(
            'quizgeist',
            (int)$target['cm']->id,
            (int)$target['cm']->course,
            false,
            MUST_EXIST
        );
        if (!empty($cm->visible) && !empty($cm->visibleoncoursepage)) {
            // set_visibility() returns early when the requested state already
            // matches. A retained recovery sentinel means a previous attempt
            // may have crashed midway through cache/calendar/grade side
            // effects, so replay the complete Core transition.
            set_coursemodule_visible((int)$cm->id, 0);
        }
        set_coursemodule_visible((int)$cm->id, 1);
        $cm = get_coursemodule_from_id(
            'quizgeist',
            (int)$target['cm']->id,
            (int)$target['cm']->course,
            false,
            MUST_EXIST
        );
        \core\event\course_module_updated::create_from_cm($cm)->trigger();
    }

    /**
     * Persist that the post-commit course-module publication completed.
     *
     * The sentinel distinguishes the narrow crash-recovery window from a
     * teacher intentionally hiding an already published activity later.
     *
     * @param int $importid Complete marker ID.
     * @param array $report Stored report with internal sentinel.
     * @return array Public report without internal recovery state.
     */
    private static function clear_publication_pending(
        int $importid,
        array $report
    ): array {
        global $DB;

        unset(
            $report['_targetPublicationPending'],
            $report['publicationPending']
        );
        $DB->set_field(
            'quizgeist_imports',
            'reportjson',
            json_encode(
                $report,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            ),
            ['id' => $importid, 'status' => 'complete']
        );
        return $report;
    }

    /**
     * Record a bounded developer-only note for an already committed import.
     *
     * @param string $operation Stable operation label.
     * @param \Throwable $exception Post-commit failure.
     * @return void
     */
    private static function debug_post_commit_failure(
        string $operation,
        \Throwable $exception
    ): void {
        debugging(
            'Quizgeist ' . $operation . ' remains retryable after commit ('
                . get_class($exception) . ').',
            DEBUG_DEVELOPER
        );
    }

    /**
     * Compensate a module that was created solely for a failed source import.
     */
    private static function remove_created_target(array $target): void {
        global $CFG, $DB;

        if (!$DB->record_exists(
            'course_modules',
            ['id' => (int)$target['cm']->id]
        )) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        \core_courseformat\formatactions::cm(
            (int)$target['cm']->course
        )->delete((int)$target['cm']->id, false);
    }

    /**
     * Remove only the marker owned by the failed transaction.
     */
    private static function remove_import_marker(int $importid): void {
        global $DB;

        $DB->delete_records('quizgeist_imports', ['id' => $importid]);
    }

    /**
     * Remove an interrupted/failed exact marker before a retry.
     */
    private static function remove_stale_import_records(
        \stdClass $import
    ): array {
        global $DB;

        $questionids = array_map(
            'intval',
            $DB->get_fieldset_select(
                'quizgeist_questions',
                'id',
                'importid = :importid AND quizgeistid = :quizgeistid',
                [
                    'importid' => (int)$import->id,
                    'quizgeistid' => (int)$import->quizgeistid,
                ]
            )
        );
        $DB->delete_records('quizgeist_questions', [
            'importid' => (int)$import->id,
            'quizgeistid' => (int)$import->quizgeistid,
        ]);
        $DB->delete_records('quizgeist_imports', ['id' => (int)$import->id]);
        return [
            'importid' => (int)$import->id,
            'questionids' => $questionids,
        ];
    }

    /**
     * Remove stale file areas only after their database replacement committed.
     *
     * @param array{importid:int,questionids:int[]} $cleanup Captured IDs.
     * @param \context_module $context Owning module context.
     * @return void
     */
    private static function remove_stale_import_files_safely(
        array $cleanup,
        \context_module $context
    ): void {
        try {
            foreach ($cleanup['questionids'] as $questionid) {
                media_service::delete_area(
                    $context,
                    'questionmedia',
                    (int)$questionid
                );
            }
            media_service::delete_area(
                $context,
                media_service::IMPORT_AREA,
                (int)$cleanup['importid']
            );
        } catch (\Throwable $exception) {
            debugging(
                'Quizgeist could not remove every stale import file area: '
                    . clean_param($exception->getMessage(), PARAM_TEXT),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Compensate only rows and files owned by the current pending marker.
     *
     * @param int $importid Import marker.
     * @param int $quizgeistid Activity.
     * @param \context_module $context Context.
     * @param int[] $knownids Fast-path IDs.
     */
    private static function remove_import_questions(
        int $importid,
        int $quizgeistid,
        \context_module $context,
        array $knownids
    ): void {
        global $DB;

        $ids = $knownids ?: array_map(
            'intval',
            $DB->get_fieldset_select(
                'quizgeist_questions',
                'id',
                'importid = :importid AND quizgeistid = :quizgeistid',
                ['importid' => $importid, 'quizgeistid' => $quizgeistid]
            )
        );
        foreach ($ids as $id) {
            media_service::delete_area($context, 'questionmedia', $id);
        }
        $DB->delete_records('quizgeist_questions', [
            'importid' => $importid,
            'quizgeistid' => $quizgeistid,
        ]);
        media_service::delete_area($context, media_service::IMPORT_AREA, $importid);
    }
}
