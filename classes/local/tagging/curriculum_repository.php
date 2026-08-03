<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Storage of LehrplanPLUS anchors per question root (F10).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\tagging;

defined('MOODLE_INTERNAL') || die();

/**
 * Plain SQL around quizgeist_curriculum_refs.
 *
 * Lives in the BASE plugin next to the tag repository, not in the AI addon,
 * for the same reason the clip channel does: an anchor that a teacher has
 * accepted is course content. Removing the AI addon must not make a question
 * forget which competency it belongs to.
 *
 * The anchor hangs on `rootid`, never on `id` (P11_PLAN.md 2.3): editing a
 * question produces a new version row, and an anchor that did not survive that
 * would quietly disappear on the first typo correction.
 */
final class curriculum_repository {

    /** Maximum stored competency text. */
    public const MAX_COMPETENCY = 1000;

    /**
     * Store or replace the anchor of one question root.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $rootid Question root.
     * @param array{
     *     subject?:string, grade?:int, variant?:string, learningarea?:string,
     *     competency?:string, citationurl?:string, chunkid?:int
     * } $anchor Anchor fields.
     * @return int Stored record ID.
     */
    public static function store(int $quizgeistid, int $rootid, array $anchor): int {
        global $DB;

        if ($quizgeistid <= 0 || $rootid <= 0) {
            throw new \invalid_parameter_exception('A curriculum anchor needs an activity and a root.');
        }
        $record = (object)[
            'quizgeistid' => $quizgeistid,
            'rootid' => $rootid,
            'subject' => self::bounded((string)($anchor['subject'] ?? ''), 100),
            'grade' => max(0, min(13, (int)($anchor['grade'] ?? 0))),
            'variant' => self::nullable((string)($anchor['variant'] ?? ''), 100),
            'learningarea' => self::nullable((string)($anchor['learningarea'] ?? ''), 255),
            'competency' => self::nullable(
                (string)($anchor['competency'] ?? ''),
                self::MAX_COMPETENCY
            ),
            'citationurl' => self::nullable(self::url((string)($anchor['citationurl'] ?? '')), 255),
            'chunkid' => max(0, (int)($anchor['chunkid'] ?? 0)),
            'timecreated' => time(),
        ];

        $existing = $DB->get_record('quizgeist_curriculum_refs', ['rootid' => $rootid], 'id');
        if ($existing) {
            $record->id = (int)$existing->id;
            // Keep the original creation time: the anchor of a root has one
            // history, not one per correction.
            unset($record->timecreated);
            $DB->update_record('quizgeist_curriculum_refs', $record);
            return (int)$existing->id;
        }
        return (int)$DB->insert_record('quizgeist_curriculum_refs', $record);
    }

    /**
     * Return the anchor of one question root.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $rootid Question root.
     * @return \stdClass|null
     */
    public static function of_root(int $quizgeistid, int $rootid): ?\stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $rootid <= 0) {
            return null;
        }
        $record = $DB->get_record('quizgeist_curriculum_refs', [
            'quizgeistid' => $quizgeistid,
            'rootid' => $rootid,
        ]);
        return $record ?: null;
    }

    /**
     * Return the anchors of several roots, keyed by root ID.
     *
     * @param int $quizgeistid Owning activity.
     * @param int[] $rootids Question roots.
     * @return array<int,\stdClass>
     */
    public static function of_roots(int $quizgeistid, array $rootids): array {
        global $DB;

        $rootids = array_values(array_unique(array_filter(
            array_map('intval', $rootids),
            static fn(int $id): bool => $id > 0
        )));
        if ($quizgeistid <= 0 || !$rootids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($rootids, SQL_PARAMS_NAMED, 'r');
        $rows = $DB->get_records_select(
            'quizgeist_curriculum_refs',
            "quizgeistid = :quizgeistid AND rootid {$insql}",
            $params + ['quizgeistid' => $quizgeistid]
        );
        $byroot = [];
        foreach ($rows as $row) {
            $byroot[(int)$row->rootid] = $row;
        }
        return $byroot;
    }

    /**
     * Client projection of one anchor.
     *
     * @param \stdClass $record Anchor row.
     * @return array<string,mixed>
     */
    public static function project(\stdClass $record): array {
        return [
            'subject' => (string)$record->subject,
            'grade' => (int)$record->grade,
            'variant' => $record->variant === null ? '' : (string)$record->variant,
            'learningArea' => $record->learningarea === null ? '' : (string)$record->learningarea,
            'competency' => $record->competency === null ? '' : (string)$record->competency,
            'citationUrl' => $record->citationurl === null ? '' : (string)$record->citationurl,
        ];
    }

    /**
     * Accept only an absolute http(s) URL.
     *
     * A citation is rendered as an outbound link, so anything that is not a
     * plain web address is refused rather than escaped and hoped for.
     *
     * @param string $url Raw URL.
     * @return string
     */
    private static function url(string $url): string {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return (string)clean_param($url, PARAM_URL);
    }

    /**
     * Bound a plain string.
     *
     * @param string $value Raw value.
     * @param int $maxlength Maximum length.
     * @return string
     */
    private static function bounded(string $value, int $maxlength): string {
        $value = trim((string)clean_param($value, PARAM_TEXT));
        return \core_text::strlen($value) > $maxlength
            ? \core_text::substr($value, 0, $maxlength)
            : $value;
    }

    /**
     * Bound a plain string and turn the empty result into null.
     *
     * @param string $value Raw value.
     * @param int $maxlength Maximum length.
     * @return string|null
     */
    private static function nullable(string $value, int $maxlength): ?string {
        $value = self::bounded($value, $maxlength);
        return $value === '' ? null : $value;
    }
}
