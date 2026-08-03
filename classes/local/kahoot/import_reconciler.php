<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Destination-side reconciliation for restored external imports.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\kahoot;

defined('MOODLE_INTERNAL') || die();

/**
 * Rewrites restored import reports and validates their destination evidence.
 */
final class import_reconciler {

    /**
     * Diagnostics that prove restored playable content is incomplete.
     *
     * Report provenance and destination identifiers are derived restore
     * metadata. A mismatch there must be diagnosed, but must never by itself
     * authorize the destructive quarantine path.
     */
    private const DEGRADATION_REASONS = [
        'import.status',
        'question.refresh.media_missing',
        'media.file_missing',
        'media.file_size',
        'media.file_sha256',
    ];

    /**
     * Rewrite solution-free report references to the restored activity IDs.
     *
     * @param int $quizgeistid Restored activity instance.
     * @param int $courseid Destination course.
     * @param int $cmid Restored course-module ID.
     * @param callable $mapquestionid Maps an old question ID to its restored ID.
     * @return void
     */
    public static function remap_reports(
        int $quizgeistid,
        int $courseid,
        int $cmid,
        callable $mapquestionid
    ): void {
        global $DB;

        $imports = $DB->get_records(
            'quizgeist_imports',
            ['quizgeistid' => $quizgeistid],
            'id ASC'
        );
        foreach ($imports as $import) {
            try {
                $report = json_decode(
                    (string)($import->reportjson ?? ''),
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException) {
                $report = null;
            }
            if (!is_array($report)) {
                continue;
            }
            $report['target'] = [
                'courseId' => $courseid,
                'cmid' => $cmid > 0 ? $cmid : null,
                'instanceId' => $quizgeistid,
            ];
            if (is_array($report['questions'] ?? null)) {
                foreach ($report['questions'] as &$question) {
                    if (!is_array($question)) {
                        continue;
                    }
                    $oldid = (int)($question['targetQuestionId'] ?? 0);
                    $mappedid = $oldid > 0
                        ? $mapquestionid($oldid)
                        : null;
                    $question['targetQuestionId'] = $mappedid === null
                        ? null
                        : (int)$mappedid;
                }
                unset($question);
            }
            $DB->set_field(
                'quizgeist_imports',
                'reportjson',
                json_encode(
                    $report,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                ),
                ['id' => (int)$import->id, 'quizgeistid' => $quizgeistid]
            );
        }
    }

    /**
     * Revalidate restored import ledgers after the file security boundary.
     *
     * File restoration is deliberately followed by allowlist filtering. A
     * marker must therefore not retain its idempotent "complete" promise until
     * its solution-free report, retained question references and provenance
     * files have all been checked against the destination records. A broken
     * marker becomes a precise failed ledger; the normal importer can then
     * remove that exact marker and retry the identical source atomically.
     *
     * @param int $quizgeistid Restored activity instance.
     * @param int $contextid Restored module context ID.
     * @param int $cmid Restored course-module ID.
     * @param callable $warninglogger Restore warning logger.
     * @return void
     */
    public static function reconcile(
        int $quizgeistid,
        int $contextid,
        int $cmid,
        callable $warninglogger
    ): void {
        global $DB;

        $context = \context::instance_by_id($contextid, MUST_EXIST);
        if (!($context instanceof \context_module)) {
            throw new \coding_exception(
                'Quizgeist import media must restore into a module context.'
            );
        }

        $imports = $DB->get_records(
            'quizgeist_imports',
            ['quizgeistid' => $quizgeistid],
            'id ASC'
        );
        foreach ($imports as $import) {
            $questions = $DB->get_records(
                'quizgeist_questions',
                [
                    'quizgeistid' => $quizgeistid,
                    'importid' => (int)$import->id,
                ],
                'id ASC'
            );
            $firstdiagnostic = null;
            $degradationreason = null;
            if ((string)$import->status !== 'complete') {
                $firstdiagnostic = 'import.status';
                $degradationreason = 'import.status';
            } else {
                foreach ($questions as $question) {
                    // Draft and archived rows can be legitimate post-import
                    // edits. A row restored as ready must retain the exact
                    // media bindings that made it playable at backup time.
                    if ((string)$question->status !== 'ready') {
                        continue;
                    }
                    $inspection = self::inspect_option_refresh(
                        $question,
                        $context
                    );
                    $firstdiagnostic ??= $inspection['diagnostic'];
                    if ($inspection['degradation'] !== null) {
                        $degradationreason = $inspection['degradation'];
                        break;
                    }
                }
                if (count($questions) < (int)$import->questioncount) {
                    $firstdiagnostic ??= 'question.count';
                }
            }

            if ($degradationreason === null) {
                $consistencyfailure = null;
                if (self::restored_import_is_consistent(
                        $import,
                        $questions,
                        $context,
                        $cmid,
                        $consistencyfailure
                    )) {
                    if ($firstdiagnostic !== null) {
                        self::log_diagnostic(
                            $warninglogger,
                            $firstdiagnostic
                        );
                    }
                    continue;
                }
                $consistencyfailure ??= 'consistency.unknown';
                $firstdiagnostic ??= $consistencyfailure;
                $medialoss = self::import_media_loss_reason(
                    $import,
                    $context
                );
                if ($medialoss !== null) {
                    $degradationreason = $medialoss;
                } else {
                    self::log_diagnostic(
                        $warninglogger,
                        $firstdiagnostic
                    );
                    $warninglogger(
                        'Preserved a complete restored Quizgeist import '
                            . 'because no content-loss evidence was found.'
                    );
                    continue;
                }
            }

            self::log_diagnostic($warninglogger, $firstdiagnostic);
            self::degrade_restored_import(
                $import,
                $questions,
                $context,
                $cmid,
                $warninglogger,
                $degradationreason
            );
        }
    }

    /**
     * Validate one complete marker against its destination-side evidence.
     *
     * Extra rows in an imported question lineage are allowed because teachers
     * can edit a completed import after the fact. Every immutable report
     * target and every imported provenance file must still exist and match.
     *
     * @param \stdClass $import Restored import marker.
     * @param \stdClass[] $questions Questions carrying this marker.
     * @param \context_module $context Restored module context.
     * @param int $cmid Restored course-module ID.
     * @param string|null $failure First failed stable subcondition.
     * @return bool
     */
    private static function restored_import_is_consistent(
        \stdClass $import,
        array $questions,
        \context_module $context,
        int $cmid,
        ?string &$failure = null
    ): bool {
        $failure = null;
        try {
            $report = json_decode(
                (string)($import->reportjson ?? ''),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return self::fail($failure, 'report.json');
        }
        if (!is_array($report)) {
            return self::fail($failure, 'report.object');
        }
        if ((int)($report['schemaVersion'] ?? 0) !== 1) {
            return self::fail($failure, 'report.schema_version');
        }
        if (($report['status'] ?? null) !== 'complete') {
            return self::fail($failure, 'report.status');
        }
        if (($report['dryRun'] ?? null) !== false) {
            return self::fail($failure, 'report.dry_run');
        }
        if (!is_array($report['source'] ?? null)) {
            return self::fail($failure, 'report.source');
        }
        if (!is_array($report['target'] ?? null)) {
            return self::fail($failure, 'report.target');
        }
        if (!is_array($report['totals'] ?? null)) {
            return self::fail($failure, 'report.totals');
        }
        if (!is_array($report['questions'] ?? null)) {
            return self::fail($failure, 'report.questions');
        }
        if (!array_is_list($report['questions'])) {
            return self::fail($failure, 'report.questions_list');
        }
        if (!is_array($report['media'] ?? null)) {
            return self::fail($failure, 'report.media');
        }
        if (!array_is_list($report['media'])) {
            return self::fail($failure, 'report.media_list');
        }

        $source = $report['source'];
        $target = $report['target'];
        $totals = $report['totals'];
        if (($source['uuid'] ?? null) !== (string)$import->sourceuuid) {
            return self::fail($failure, 'report.source.uuid');
        }
        if (($source['title'] ?? null) !== (string)$import->sourcename) {
            return self::fail($failure, 'report.source.title');
        }
        if (($source['sha256'] ?? null) !== (string)$import->sourcehash) {
            return self::fail($failure, 'report.source.sha256');
        }
        if (($target['courseId'] ?? null) !== (int)$import->courseid) {
            return self::fail($failure, 'report.target.course_id');
        }
        if (($target['instanceId'] ?? null)
                !== (int)$import->quizgeistid) {
            return self::fail($failure, 'report.target.instance_id');
        }
        if (($target['cmid'] ?? null) !== $cmid) {
            return self::fail($failure, 'report.target.cmid');
        }

        $retained = 0;
        $imported = 0;
        $adjusted = 0;
        $skipped = 0;
        $targetids = [];
        foreach ($report['questions'] as $row) {
            if (!is_array($row)) {
                return self::fail($failure, 'report.question.row');
            }
            if (!is_int($row['sourceIndex'] ?? null)) {
                return self::fail(
                    $failure,
                    'report.question.source_index'
                );
            }
            if (!is_string($row['sourceType'] ?? null)) {
                return self::fail(
                    $failure,
                    'report.question.source_type'
                );
            }
            if (!is_array($row['reasons'] ?? null)) {
                return self::fail($failure, 'report.question.reasons');
            }
            if (!array_is_list($row['reasons'])) {
                return self::fail(
                    $failure,
                    'report.question.reasons_list'
                );
            }
            $outcome = $row['outcome'] ?? null;
            if (!in_array($outcome, ['imported', 'adjusted', 'skipped'], true)) {
                return self::fail($failure, 'report.question.outcome');
            }
            if ($outcome === 'skipped') {
                if (($row['targetQuestionId'] ?? null) !== null
                        || ($row['targetType'] ?? null) !== null) {
                    return self::fail(
                        $failure,
                        'report.question.skipped_target'
                    );
                }
                $skipped++;
                continue;
            }
            $questionid = $row['targetQuestionId'] ?? null;
            if (!is_int($questionid) || $questionid <= 0) {
                return self::fail(
                    $failure,
                    'report.question.target_id'
                );
            }
            if (isset($targetids[$questionid])) {
                return self::fail(
                    $failure,
                    'report.question.duplicate_target'
                );
            }
            if (!isset($questions[$questionid])) {
                return self::fail(
                    $failure,
                    'report.question.target_missing'
                );
            }
            if (($row['targetType'] ?? null)
                    !== (string)$questions[$questionid]->qtype) {
                return self::fail(
                    $failure,
                    'report.question.target_type'
                );
            }
            $targetids[$questionid] = true;
            $retained++;
            if ($outcome === 'imported') {
                $imported++;
            } else {
                $adjusted++;
            }
        }
        if (($totals['source'] ?? null) !== count($report['questions'])) {
            return self::fail($failure, 'report.totals.source');
        }
        if (($totals['retained'] ?? null) !== $retained) {
            return self::fail($failure, 'report.totals.retained');
        }
        if (($totals['imported'] ?? null) !== $imported) {
            return self::fail($failure, 'report.totals.imported');
        }
        if (($totals['adjusted'] ?? null) !== $adjusted) {
            return self::fail($failure, 'report.totals.adjusted');
        }
        if (($totals['skipped'] ?? null) !== $skipped) {
            return self::fail($failure, 'report.totals.skipped');
        }
        if ((int)$import->questioncount !== $retained) {
            return self::fail($failure, 'import.question_count');
        }
        if ((int)$import->adaptedcount !== $adjusted) {
            return self::fail($failure, 'import.adapted_count');
        }
        if ((int)$import->skippedcount !== $skipped) {
            return self::fail($failure, 'import.skipped_count');
        }

        $fs = get_file_storage();
        $files = array_values($fs->get_area_files(
            $context->id,
            'mod_quizgeist',
            'importmedia',
            (int)$import->id,
            'filename ASC',
            false
        ));
        $filesbyname = [];
        foreach ($files as $file) {
            if ($file->get_filepath() !== '/source/') {
                return self::fail($failure, 'media.file_path');
            }
            if (isset($filesbyname[$file->get_filename()])) {
                return self::fail($failure, 'media.file_duplicate');
            }
            $filesbyname[$file->get_filename()] = $file;
        }

        $mediaexpected = count($report['media']);
        $mediaimported = 0;
        $seenrefs = [];
        $seenfiles = [];
        foreach ($report['media'] as $row) {
            if (!is_array($row)) {
                return self::fail($failure, 'report.media.row');
            }
            if (!is_string($row['sourceRef'] ?? null)
                    || $row['sourceRef'] === '') {
                return self::fail($failure, 'report.media.source_ref');
            }
            if (isset($seenrefs[$row['sourceRef']])) {
                return self::fail(
                    $failure,
                    'report.media.duplicate_source_ref'
                );
            }
            if (!in_array(
                    $row['outcome'] ?? null,
                    ['imported', 'missing'],
                    true
                )) {
                return self::fail($failure, 'report.media.outcome');
            }
            $seenrefs[$row['sourceRef']] = true;
            if ($row['outcome'] === 'missing') {
                continue;
            }
            $filename = $row['filename'] ?? null;
            if (!is_string($filename) || $filename === '') {
                return self::fail($failure, 'report.media.filename');
            }
            if (isset($seenfiles[$filename])) {
                return self::fail(
                    $failure,
                    'report.media.duplicate_filename'
                );
            }
            if (!is_int($row['size'] ?? null) || $row['size'] < 0) {
                return self::fail($failure, 'report.media.size');
            }
            if (!is_string($row['sha256'] ?? null)
                    || !preg_match(
                        '/^[a-f0-9]{64}$/D',
                        $row['sha256']
                    )) {
                return self::fail($failure, 'report.media.sha256');
            }
            if (!isset($filesbyname[$filename])) {
                return self::fail($failure, 'media.file_missing');
            }
            $file = $filesbyname[$filename];
            // MariaDB may expose Moodle's files.filesize through stored_file as
            // a numeric string. The report contract deliberately stores an
            // integer, so compare numeric bytes rather than PHP storage types.
            if ((int)$file->get_filesize() !== $row['size']) {
                return self::fail($failure, 'media.file_size');
            }
            if (!hash_equals(
                    $row['sha256'],
                    hash('sha256', $file->get_content())
                )) {
                return self::fail($failure, 'media.file_sha256');
            }
            $seenfiles[$filename] = true;
            $mediaimported++;
        }
        if (count($files) !== $mediaimported) {
            return self::fail($failure, 'media.file_count');
        }
        if (($totals['mediaExpected'] ?? null) !== $mediaexpected) {
            return self::fail($failure, 'report.totals.media_expected');
        }
        if (($totals['mediaImported'] ?? null) !== $mediaimported) {
            return self::fail($failure, 'report.totals.media_imported');
        }
        if ((int)$import->mediacount !== $mediaimported) {
            return self::fail($failure, 'import.media_count');
        }
        return true;
    }

    /**
     * Find hard import-provenance media loss independently of report metadata.
     *
     * The complete media contract is validated before any missing-file or byte
     * mismatch can authorize quarantine. This second pass deliberately still
     * runs after an earlier harmless source/target/ID diagnostic, so metadata
     * drift cannot conceal real destination content loss.
     *
     * @param \stdClass $import Restored import marker.
     * @param \context_module $context Restored module context.
     * @return string|null Stable content-loss code, or null without hard proof.
     */
    private static function import_media_loss_reason(
        \stdClass $import,
        \context_module $context
    ): ?string {
        try {
            $report = json_decode(
                (string)($import->reportjson ?? ''),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($report)
                || (int)($report['schemaVersion'] ?? 0) !== 1
                || ($report['status'] ?? null) !== 'complete'
                || ($report['dryRun'] ?? null) !== false
                || !is_array($report['totals'] ?? null)
                || !is_array($report['media'] ?? null)
                || !array_is_list($report['media'])) {
            return null;
        }

        $expected = [];
        $seenrefs = [];
        foreach ($report['media'] as $row) {
            if (!is_array($row)
                    || !is_string($row['sourceRef'] ?? null)
                    || $row['sourceRef'] === ''
                    || isset($seenrefs[$row['sourceRef']])
                    || !in_array(
                        $row['outcome'] ?? null,
                        ['imported', 'missing'],
                        true
                    )) {
                return null;
            }
            $seenrefs[$row['sourceRef']] = true;
            if ($row['outcome'] === 'missing') {
                continue;
            }
            $filename = $row['filename'] ?? null;
            if (!is_string($filename)
                    || $filename === ''
                    || isset($expected[$filename])
                    || !is_int($row['size'] ?? null)
                    || $row['size'] < 0
                    || !is_string($row['sha256'] ?? null)
                    || !preg_match('/^[a-f0-9]{64}$/D', $row['sha256'])) {
                return null;
            }
            $expected[$filename] = [
                'size' => $row['size'],
                'sha256' => $row['sha256'],
            ];
        }
        $totals = $report['totals'];
        if (($totals['mediaExpected'] ?? null)
                    !== count($report['media'])
                || ($totals['mediaImported'] ?? null) !== count($expected)
                || (int)$import->mediacount !== count($expected)) {
            return null;
        }

        $filesbyname = [];
        foreach (get_file_storage()->get_area_files(
            $context->id,
            'mod_quizgeist',
            'importmedia',
            (int)$import->id,
            'filename ASC',
            false
        ) as $file) {
            if ($file->get_filepath() !== '/source/'
                    || isset($filesbyname[$file->get_filename()])) {
                continue;
            }
            $filesbyname[$file->get_filename()] = $file;
        }
        foreach ($expected as $filename => $evidence) {
            if (!isset($filesbyname[$filename])) {
                return 'media.file_missing';
            }
            $file = $filesbyname[$filename];
            if ((int)$file->get_filesize() !== $evidence['size']) {
                return 'media.file_size';
            }
            if (!hash_equals(
                    $evidence['sha256'],
                    hash('sha256', $file->get_content())
                )) {
                return 'media.file_sha256';
            }
        }
        return null;
    }

    /**
     * Derive the editor media refresh without changing the restored row.
     *
     * A refresh is intentionally inspected in memory. Canonical JSON key order,
     * legacy scalar representations and future normaliser changes may alter the
     * encoded bytes without proving that restore lost content. The backed-up
     * bytes therefore remain authoritative. Only a backed-up server-owned media
     * reference that is absent from the filtered destination manifest proves an
     * incomplete restore.
     *
     * @param \stdClass $question Restored ready question.
     * @param \context_module $context Restored module context.
     * @return array{diagnostic: ?string, degradation: ?string}
     */
    private static function inspect_option_refresh(
        \stdClass $question,
        \context_module $context
    ): array {
        $optionsjson = $question->optionsjson === null
            ? null
            : (string)$question->optionsjson;
        try {
            if ($optionsjson === null || trim($optionsjson) === '') {
                $options = [];
            } else {
                $options = json_decode(
                    $optionsjson,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($options)) {
                    return [
                        'diagnostic' => 'question.refresh.options_object',
                        'degradation' => null,
                    ];
                }
            }
            $referenceinfo = self::option_media_references(
                $options,
                (string)$question->qtype
            );
            $diagnostic = !$referenceinfo['shapevalid']
                ? 'question.refresh.media_shape'
                : null;
            $normalised =
                \mod_quizgeist\local\editor\question_schema::normalise([
                    'qtype' => (string)$question->qtype,
                    'questiontext' =>
                        (string)($question->questiontext ?? ''),
                    'options' => $options,
                    'timelimit' => (int)($question->timelimit ?? 20),
                    'pointmode' =>
                        (string)($question->pointmode ?? 'standard'),
                    'explanation' =>
                        (string)($question->explanation ?? ''),
                ]);
            $manifest =
                \mod_quizgeist\local\editor\media_service::manifest(
                    $context,
                    'questionmedia',
                    (int)$question->id
                );
            $refreshedoptions =
                \mod_quizgeist\local\editor\media_service::
                    synchronise_question_options(
                        $normalised['question']['options'],
                        $manifest
                    );
            $errors =
                \mod_quizgeist\local\editor\question_schema::validate_media(
                    [
                        'qtype' => (string)$question->qtype,
                        'options' => $refreshedoptions,
                    ],
                    $normalised['validationErrors'],
                    $manifest
                );
            $refreshedjson =
                \mod_quizgeist\local\editor\question_schema::encode_options(
                    $refreshedoptions
                );
        } catch (\JsonException) {
            return [
                'diagnostic' => 'question.refresh.options_json',
                'degradation' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'diagnostic' => 'question.refresh.exception',
                'degradation' => null,
            ];
        }

        if (!hash_equals((string)$optionsjson, $refreshedjson)) {
            $diagnostic ??= 'question.refresh.options_bytes';
        }
        if ($errors) {
            $diagnostic ??= 'question.refresh.status';
        }
        if (!self::media_references_are_present(
                $referenceinfo['references'],
                $manifest
            )) {
            $diagnostic ??= 'question.refresh.media_missing';
            return [
                'diagnostic' => $diagnostic,
                'degradation' => 'question.refresh.media_missing',
            ];
        }
        return [
            'diagnostic' => $diagnostic,
            'degradation' => null,
        ];
    }

    /**
     * Extract only server-owned question media paths from decoded options.
     *
     * @param array $options Decoded options.
     * @param string $qtype Persisted question type.
     * @return array{references: string[], shapevalid: bool}
     */
    private static function option_media_references(
        array $options,
        string $qtype
    ): array {
        if (!in_array(
                $qtype,
                \mod_quizgeist\local\editor\question_schema::QTYPES,
                true
            )) {
            return ['references' => [], 'shapevalid' => false];
        }
        $references = [];
        $shapevalid = true;
        if (array_key_exists('media', $options)) {
            if ($options['media'] !== null
                    && !is_string($options['media'])) {
                $shapevalid = false;
            } else if (is_string($options['media'])
                    && $options['media'] !== '') {
                if (!self::is_canonical_question_media_path(
                        $options['media'],
                        null
                    )) {
                    $shapevalid = false;
                } else {
                    $references[] = $options['media'];
                }
            }
        }
        $collections = match ($qtype) {
            'quiz', 'poll' => ['answers'],
            'puzzle' => ['items'],
            default => [],
        };
        foreach ($collections as $collection) {
            if (!array_key_exists($collection, $options)) {
                continue;
            }
            if (!is_array($options[$collection])
                    || !array_is_list($options[$collection])) {
                $shapevalid = false;
                continue;
            }
            foreach ($options[$collection] as $item) {
                if (!is_array($item)) {
                    $shapevalid = false;
                    continue;
                }
                $media = $item['media'] ?? null;
                if ($media !== null && !is_string($media)) {
                    $shapevalid = false;
                    continue;
                }
                if (is_string($media) && $media !== '') {
                    $itemid = $item['id'] ?? null;
                    if (!is_string($itemid)
                            || !preg_match(
                                '/^[a-z][a-z0-9_-]{0,31}$/D',
                                $itemid
                            )) {
                        $shapevalid = false;
                        continue;
                    }
                    if (!self::is_canonical_question_media_path(
                            $media,
                            $itemid
                        )) {
                        $shapevalid = false;
                        continue;
                    }
                    $references[] = $media;
                }
            }
        }
        return [
            'references' => $references,
            'shapevalid' => $shapevalid,
        ];
    }

    /**
     * Whether an option value can authoritatively name questionmedia content.
     *
     * @param string $path Persisted option path.
     * @param string|null $itemid Answer/item ID, or null for top-level media.
     * @return bool
     */
    private static function is_canonical_question_media_path(
        string $path,
        ?string $itemid
    ): bool {
        if (str_contains($path, '..')
                || str_contains($path, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }
        $prefix = $itemid === null
            ? '/question/'
            : '/answers/' . $itemid . '/';
        return str_starts_with($path, $prefix)
            && substr_count(substr($path, strlen($prefix)), '/') === 0
            && strlen($path) > strlen($prefix);
    }

    /**
     * Check backed-up media references against the safe restored manifest.
     *
     * @param string[] $references Backed-up option paths.
     * @param array $manifest Destination stored-file manifest.
     * @return bool
     */
    private static function media_references_are_present(
        array $references,
        array $manifest
    ): bool {
        $paths = [];
        foreach ($manifest as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $paths[$file['path']] = true;
            }
        }
        foreach ($references as $reference) {
            if (!isset($paths[$reference])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Record the first failed consistency subcondition.
     *
     * @param string|null $failure First stable diagnostic code.
     * @param string $code Stable diagnostic code.
     * @return false
     */
    private static function fail(?string &$failure, string $code): bool {
        $failure ??= $code;
        return false;
    }

    /**
     * Whether a diagnostic proves destination content loss.
     *
     * @param string $reason Stable diagnostic code.
     * @return bool
     */
    private static function requires_degradation(string $reason): bool {
        return in_array($reason, self::DEGRADATION_REASONS, true);
    }

    /**
     * Log one stable code without source data, paths, names or token material.
     *
     * @param callable $warninglogger Restore warning logger.
     * @param string|null $code Stable diagnostic code.
     * @return void
     */
    private static function log_diagnostic(
        callable $warninglogger,
        ?string $code
    ): void {
        if ($code === null
                || !preg_match('/^[a-z][a-z0-9_.]{0,63}$/D', $code)) {
            $code = 'consistency.unknown';
        }
        $warninglogger(
            'Quizgeist restored import diagnostic: ' . $code . '.'
        );
    }

    /**
     * Turn an incomplete restored import into an exact retryable ledger.
     *
     * @param \stdClass $import Restored marker.
     * @param \stdClass[] $questions Marker-owned questions.
     * @param \context_module $context Restored module context.
     * @param int $cmid Restored course-module ID.
     * @param callable $warninglogger Restore warning logger.
     * @param string $reason Content-loss diagnostic authorizing quarantine.
     * @return void
     */
    private static function degrade_restored_import(
        \stdClass $import,
        array $questions,
        \context_module $context,
        int $cmid,
        callable $warninglogger,
        string $reason
    ): void {
        global $DB;

        if (!self::requires_degradation($reason)) {
            throw new \coding_exception(
                'Quizgeist restore quarantine requires content-loss evidence.'
            );
        }

        $questionids = array_map('intval', array_keys($questions));
        $assignmentids = [];
        $activesessionids = [];
        if ($questionids) {
            [$questionsql, $questionparams] = $DB->get_in_or_equal(
                $questionids,
                SQL_PARAMS_NAMED,
                'restorequestion'
            );
            $assignmentids = array_values(array_unique(array_map(
                'intval',
                $DB->get_fieldset_select(
                    'quizgeist_assignment_questions',
                    'assignmentid',
                    "questionid {$questionsql}",
                    $questionparams
                )
            )));
            $sessionids = array_values(array_unique(array_map(
                'intval',
                $DB->get_fieldset_select(
                    'quizgeist_session_questions',
                    'sessionid',
                    "questionid {$questionsql}",
                    $questionparams
                )
            )));
            if ($sessionids) {
                [$sessionsql, $sessionparams] = $DB->get_in_or_equal(
                    $sessionids,
                    SQL_PARAMS_NAMED,
                    'restoresession'
                );
                $sessionparams['endedstatus'] = 'ended';
                $sessionparams['abortedstatus'] = 'aborted';
                $activesessionids = array_map(
                    'intval',
                    $DB->get_fieldset_select(
                        'quizgeist_sessions',
                        'id',
                        "id {$sessionsql}
                             AND status <> :endedstatus
                             AND status <> :abortedstatus",
                        $sessionparams
                    )
                );
            }
        }

        try {
            $oldreport = json_decode(
                (string)($import->reportjson ?? ''),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            $oldreport = null;
        }
        $oldrows = is_array($oldreport)
                && is_array($oldreport['questions'] ?? null)
                && array_is_list($oldreport['questions'])
            ? $oldreport['questions']
            : [];
        $questionrows = [];
        $usedsourceindices = [];
        $reportedtargetids = [];
        foreach ($oldrows as $oldrow) {
            if (!is_array($oldrow)) {
                continue;
            }
            $sourceindex = is_int($oldrow['sourceIndex'] ?? null)
                    && $oldrow['sourceIndex'] >= 0
                    && !isset($usedsourceindices[$oldrow['sourceIndex']])
                ? $oldrow['sourceIndex']
                : self::next_restore_source_index($usedsourceindices);
            $sourcetype = is_string($oldrow['sourceType'] ?? null)
                    && $oldrow['sourceType'] !== ''
                ? $oldrow['sourceType']
                : 'unknown';
            $oldtargetid = is_int($oldrow['targetQuestionId'] ?? null)
                ? $oldrow['targetQuestionId']
                : 0;
            if ($oldtargetid > 0) {
                $reportedtargetids[$oldtargetid] = true;
            }
            $usedsourceindices[$sourceindex] = true;
            $questionrows[] = [
                'sourceIndex' => $sourceindex,
                'sourceType' => $sourcetype,
                'targetType' => null,
                'outcome' => 'skipped',
                'reasons' => ['restore_incomplete'],
                'targetQuestionId' => null,
            ];
        }
        foreach ($questions as $question) {
            if (isset($reportedtargetids[(int)$question->id])) {
                continue;
            }
            $sourceindex = self::next_restore_source_index(
                $usedsourceindices
            );
            $usedsourceindices[$sourceindex] = true;
            $questionrows[] = [
                'sourceIndex' => $sourceindex,
                'sourceType' => (string)$question->qtype,
                'targetType' => null,
                'outcome' => 'skipped',
                'reasons' => ['restore_incomplete'],
                'targetQuestionId' => null,
            ];
        }
        usort(
            $questionrows,
            static fn(array $left, array $right): int =>
                $left['sourceIndex'] <=> $right['sourceIndex']
        );
        $quarantinedcount = count($questions);
        $skippedcount = count($questionrows);
        $report = [
            'schemaVersion' => 1,
            'status' => 'failed',
            'dryRun' => false,
            'source' => [
                'uuid' => (string)$import->sourceuuid,
                'title' => (string)$import->sourcename,
                'sha256' => (string)$import->sourcehash,
                'format' => (string)$import->sourceformat,
            ],
            'target' => [
                'courseId' => (int)$import->courseid,
                'cmid' => $cmid,
                'instanceId' => (int)$import->quizgeistid,
            ],
            'totals' => [
                'source' => $skippedcount,
                'retained' => 0,
                'imported' => 0,
                'adjusted' => 0,
                'skipped' => $skippedcount,
                'mediaExpected' => 0,
                'mediaImported' => 0,
            ],
            'questions' => $questionrows,
            'media' => [],
            'failure' => [
                'code' => 'restore_incomplete',
                'retryable' => true,
                'quarantinedQuestions' => $quarantinedcount,
            ],
        ];

        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            if ($assignmentids) {
                [$assignmentsql, $assignmentparams] = $DB->get_in_or_equal(
                    $assignmentids,
                    SQL_PARAMS_NAMED,
                    'restoreassignment'
                );
                $DB->set_field_select(
                    'quizgeist_assignments',
                    'status',
                    'closed',
                    "id {$assignmentsql}",
                    $assignmentparams
                );
                $DB->set_field_select(
                    'quizgeist_attempts',
                    'status',
                    'abandoned',
                    "assignmentid {$assignmentsql}",
                    $assignmentparams
                );
            }
            if ($activesessionids) {
                [$activesql, $activeparams] = $DB->get_in_or_equal(
                    $activesessionids,
                    SQL_PARAMS_NAMED,
                    'restoreactivesession'
                );
                foreach ([
                    'status' => 'aborted',
                    'joincode' => null,
                    'currentquestionid' => null,
                    'timeended' => time(),
                    'timemodified' => time(),
                ] as $field => $value) {
                    $DB->set_field_select(
                        'quizgeist_sessions',
                        $field,
                        $value,
                        "id {$activesql}",
                        $activeparams
                    );
                }
            }
            foreach ($questions as $question) {
                // Keep immutable session/assignment references valid, but
                // detach incomplete rows from the retry ledger and make them
                // permanently non-playable.
                $DB->update_record('quizgeist_questions', (object)[
                    'id' => (int)$question->id,
                    'importid' => null,
                    'status' => 'archived',
                    'timemodified' => time(),
                ]);
            }
            $DB->update_record('quizgeist_imports', (object)[
                'id' => (int)$import->id,
                'status' => 'failed',
                'questioncount' => 0,
                'adaptedcount' => 0,
                'skippedcount' => $skippedcount,
                'mediacount' => 0,
                'reportjson' => json_encode(
                    $report,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                ),
                'timemodified' => time(),
            ]);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        // The terminal failed transition is recorded before any source file is
        // removed, so a partial file cleanup never leaves status "complete".
        $fs = get_file_storage();
        foreach ($fs->get_area_files(
            $context->id,
            'mod_quizgeist',
            'importmedia',
            (int)$import->id,
            'id ASC',
            false
        ) as $file) {
            $file->delete();
        }
        foreach ($activesessionids as $sessionid) {
            \mod_quizgeist\local\live\visit_ledger::
                neutralise_unresolved_visits($sessionid);
        }
        $warninglogger(
            'Marked an incomplete restored Quizgeist import as retryable failed.'
        );
    }

    /**
     * Return the first non-negative source index not already in use.
     *
     * @param array<int,bool> $used Existing indexes.
     * @return int
     */
    private static function next_restore_source_index(array $used): int {
        $candidate = 0;
        while (isset($used[$candidate])) {
            $candidate++;
        }
        return $candidate;
    }
}
