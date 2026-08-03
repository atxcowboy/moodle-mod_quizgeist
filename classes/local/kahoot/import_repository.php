<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Persistence boundary for idempotent Kahoot imports.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\kahoot;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps source identity, status transitions and teacher reports auditable.
 */
final class import_repository {

    /**
     * Derive one opaque bounded provenance key for an activity restore copy.
     *
     * @param int $courseid Destination course.
     * @param string $sourceformat Original source format.
     * @param string $sourceuuid Original source identity.
     * @param int $quizgeistid Newly restored activity.
     * @param int $attempt Collision retry counter.
     * @return string
     */
    public static function restored_copy_uuid(
        int $courseid,
        string $sourceformat,
        string $sourceuuid,
        int $quizgeistid,
        int $attempt = 0
    ): string {
        if ($courseid <= 0 || $quizgeistid <= 0 || $attempt < 0) {
            throw new \coding_exception(
                'Restored import provenance requires positive destination IDs.'
            );
        }
        return 'restore-' . substr(hash(
            'sha256',
            implode(':', [
                $courseid,
                $sourceformat,
                $sourceuuid,
                $quizgeistid,
                $attempt,
            ])
        ), 0, 56);
    }

    /**
     * Find a completed import in a course, independent of its target CM.
     *
     * This read is used while holding the course/UUID import lock. It makes a
     * second CLI run without --ziel-cmid idempotent before a new module exists.
     *
     * @param int $courseid Course ID.
     * @param string $uuid Kahoot UUID.
     * @return \stdClass|null
     */
    public static function find_in_course(int $courseid, string $uuid): ?\stdClass {
        global $DB;

        $params = [
            'courseid' => $courseid,
            'sourceformat' => 'kahoot',
            'sourceuuid' => $uuid,
        ];
        // Older restores may intentionally contain the same origin in two
        // activities. Prefer the first complete marker deterministically.
        $complete = $DB->get_records(
            'quizgeist_imports',
            $params + ['status' => 'complete'],
            'id ASC',
            '*',
            0,
            1
        );
        if ($complete) {
            return reset($complete) ?: null;
        }
        $records = $DB->get_records(
            'quizgeist_imports',
            $params,
            'id ASC',
            '*',
            0,
            1
        );
        return $records ? (reset($records) ?: null) : null;
    }

    /**
     * Find one marker in an exact target activity.
     *
     * @param int $quizgeistid Activity instance ID.
     * @param string $uuid Kahoot UUID.
     * @return \stdClass|null
     */
    public static function find_in_target(int $quizgeistid, string $uuid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('quizgeist_imports', [
            'quizgeistid' => $quizgeistid,
            'sourceformat' => 'kahoot',
            'sourceuuid' => $uuid,
        ]);
        return $record ?: null;
    }

    /**
     * Create a pending marker.
     *
     * @param int $quizgeistid Activity instance ID.
     * @param int $courseid Course ID.
     * @param string $uuid Source UUID.
     * @param string $title Source title.
     * @param string $sourcehash SHA-256 source hash.
     * @return \stdClass
     */
    public static function create_pending(
        int $quizgeistid,
        int $courseid,
        string $uuid,
        string $title,
        string $sourcehash
    ): \stdClass {
        global $DB;

        $now = time();
        $record = (object)[
            'quizgeistid' => $quizgeistid,
            'courseid' => $courseid,
            'sourceformat' => 'kahoot',
            'sourceuuid' => $uuid,
            'sourcename' => clean_param($title, PARAM_TEXT),
            'sourcehash' => $sourcehash,
            'status' => 'pending',
            'questioncount' => 0,
            'adaptedcount' => 0,
            'skippedcount' => 0,
            'mediacount' => 0,
            'reportjson' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = (int)$DB->insert_record('quizgeist_imports', $record);
        return $record;
    }

    /**
     * Persist a terminal import report.
     *
     * @param int $importid Marker ID.
     * @param string $status complete or failed.
     * @param array $report Canonical teacher-facing report.
     * @return void
     */
    public static function finish(int $importid, string $status, array $report): void {
        global $DB;

        if (!in_array($status, ['complete', 'failed'], true)) {
            throw new \coding_exception('Invalid import terminal status.');
        }
        $totals = is_array($report['totals'] ?? null) ? $report['totals'] : [];
        $retained = array_key_exists('retained', $totals)
            ? (int)$totals['retained']
            : (int)($totals['imported'] ?? 0) + (int)($totals['adjusted'] ?? 0);
        $DB->update_record('quizgeist_imports', (object)[
            'id' => $importid,
            'status' => $status,
            'questioncount' => max(0, $retained),
            'adaptedcount' => max(0, (int)($totals['adjusted'] ?? 0)),
            'skippedcount' => max(0, (int)($totals['skipped'] ?? 0)),
            'mediacount' => max(0, (int)($totals['mediaImported'] ?? 0)),
            'reportjson' => json_encode(
                $report,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'timemodified' => time(),
        ]);
    }

    /**
     * Decode a stored report without trusting legacy/corrupt JSON.
     *
     * @param \stdClass $record Import marker.
     * @return array
     */
    public static function report(\stdClass $record): array {
        try {
            $report = json_decode((string)($record->reportjson ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $report = null;
        }
        if (is_array($report)) {
            if (!empty($report['_targetPublicationPending'])) {
                $report['publicationPending'] = true;
            }
            return $report;
        }
        return [
            'schemaVersion' => 1,
            'status' => (string)$record->status,
            'source' => [
                'uuid' => (string)$record->sourceuuid,
                'title' => (string)$record->sourcename,
                'sha256' => (string)$record->sourcehash,
            ],
            'totals' => [
                'retained' => (int)$record->questioncount,
                'imported' => max(
                    0,
                    (int)$record->questioncount - (int)$record->adaptedcount
                ),
                'adjusted' => (int)$record->adaptedcount,
                'skipped' => (int)$record->skippedcount,
                'mediaImported' => (int)$record->mediacount,
            ],
        ];
    }

    /**
     * List reports for the current target newest first.
     *
     * @param int $quizgeistid Activity instance ID.
     * @return \stdClass[]
     */
    public static function list_for_target(int $quizgeistid): array {
        global $DB;

        return array_values($DB->get_records(
            'quizgeist_imports',
            ['quizgeistid' => $quizgeistid],
            'timecreated DESC, id DESC'
        ));
    }
}
