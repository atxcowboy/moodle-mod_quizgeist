<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Storage, boundary and booking of one camera scan (F11a).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

use mod_quizgeist\local\live\answer_evaluator;
use mod_quizgeist\local\live\live_submission_service;
use mod_quizgeist\local\live\qtype\strategy_support;
use mod_quizgeist\local\live\session_repository;
use mod_quizgeist\local\transaction_scope;

defined('MOODLE_INTERNAL') || die();

/**
 * Everything a class photograph is allowed to become, and nothing else.
 *
 * The upload half is the U3 hardening applied to pictures: origin, size,
 * container BYTES, declared type and rate, in the order in which they get
 * cheaper to violate. Two rules are added because an image is not audio —
 * the MIME type is read from the CONTENT (`getimagesizefromstring`) and the
 * pixel count is bounded, because a small file can still decode to gigabytes.
 *
 * The recognition half is the boundary of [P10-F14] applied to card codes:
 * *everything that reaches the teacher is either valid or unmistakably marked
 * as uncertain*. This class decides that, not the model. A code the model
 * invented is discarded; a code it read twice is discarded; a letter the
 * question does not have is discarded; and every card that was NOT recognised
 * is listed by name, because a silently swallowed learner is the one failure
 * mode a teacher cannot notice.
 */
final class card_scan_service {

    /** Component owning the file area. */
    public const COMPONENT = 'mod_quizgeist';

    /**
     * Accept one uploaded scan picture.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $quizgeist Owning activity.
     * @param int $userid Scanning teacher.
     * @param array{sessionId:int,questionId:int,visit:string,cardSetId:int} $target Scan target.
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $upload One $_FILES entry.
     * @return \stdClass The stored scan row.
     */
    public static function accept_upload(
        \context_module $context,
        \stdClass $quizgeist,
        int $userid,
        array $target,
        array $upload
    ): \stdClass {
        global $DB;

        $quizgeistid = (int)$quizgeist->id;
        $sessionid = (int)($target['sessionId'] ?? 0);
        $questionid = (int)($target['questionId'] ?? 0);
        $visit = (string)($target['visit'] ?? '');
        $cardsetid = (int)($target['cardSetId'] ?? 0);
        if ($userid <= 0 || $sessionid <= 0 || $questionid <= 0) {
            throw new card_exception('card_scan_not_allowed');
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $visit)) {
            throw new card_exception('card_scan_question_invalid');
        }
        $set = card_repository::cardset($quizgeistid, $cardsetid);
        if ($set === null) {
            throw new card_exception('cardset_not_found');
        }
        // The scan must belong to the question the session is actually on.
        // Reading it here rather than trusting the request means a stale tab
        // cannot book last question's photograph onto this question.
        self::assert_live_target($quizgeist, $sessionid, $questionid, $visit);

        self::assert_upload_ok($upload);
        $tmpname = (string)($upload['tmp_name'] ?? '');
        self::assert_uploaded_file($tmpname);
        $bytes = self::assert_size($tmpname);
        $image = self::assert_image($tmpname);
        self::assert_declared_type((string)($upload['type'] ?? ''), $image['mimetype']);
        self::assert_rate($quizgeistid, $userid);

        $now = time();
        $record = (object)[
            'quizgeistid' => $quizgeistid,
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'visit' => $visit,
            'cardsetid' => $cardsetid,
            'scannedby' => $userid,
            'itemid' => 0,
            'state' => 'pending',
            'reasoncode' => null,
            'resultjson' => null,
            'recognised' => 0,
            'expected' => self::expected_cards($set, $sessionid),
            'imagedeleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $transaction = transaction_scope::begin();
        try {
            $record->id = (int)$DB->insert_record('quizgeist_card_scans', $record);
            // The item ID is the scan ID: a picture can then never outlive or
            // precede its bookkeeping row, which is what makes the deletion
            // provable afterwards.
            $record->itemid = $record->id;
            $DB->set_field('quizgeist_card_scans', 'itemid', $record->itemid, ['id' => $record->id]);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        try {
            get_file_storage()->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => self::COMPONENT,
                'filearea' => card_limits::FILE_AREA,
                'itemid' => $record->itemid,
                'filepath' => '/',
                // The stored name is generated, never the client's.
                'filename' => 'scan_' . $record->id . '.' . $image['extension'],
                'userid' => $userid,
                'mimetype' => $image['mimetype'],
            ], $tmpname);
        } catch (\Throwable $exception) {
            $DB->delete_records('quizgeist_card_scans', ['id' => $record->id]);
            throw new card_exception('card_scan_storage_failed');
        }

        self::record_rate($quizgeistid, $userid);
        return $record;
    }

    /**
     * Return the stored picture of one scan, when it still exists.
     *
     * Deliberately NOT reachable from quizgeist_pluginfile(): this method
     * exists so the recogniser can hand the bytes to the gateway, not so a
     * URL can hand them to a browser.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $scan Scan row.
     * @return \stored_file|null
     */
    public static function file(\context_module $context, \stdClass $scan): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            $context->id,
            self::COMPONENT,
            card_limits::FILE_AREA,
            (int)$scan->itemid,
            'id ASC',
            false
        );
        foreach ($files as $file) {
            if ($file instanceof \stored_file && !$file->is_directory()) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Map the printable answer letters of one question to its canonical IDs.
     *
     * The MVP covers `quiz` and `truefalse`; every other type returns an empty
     * map, which makes every letter invalid and therefore makes the whole scan
     * refuse to book instead of guessing.
     *
     * @param array $question Canonical question.
     * @return array<string,string> letter => canonical answer ID
     */
    public static function answer_key_map(array $question): array {
        $qtype = (string)($question['qtype'] ?? '');
        if ($qtype === 'truefalse') {
            return ['A' => 'true', 'B' => 'false'];
        }
        if ($qtype !== 'quiz') {
            return [];
        }
        $letters = card_limits::LAYOUT_LETTERS['abcdef'];
        $map = [];
        foreach (array_values($question['options']['answers'] ?? []) as $position => $answer) {
            if (!isset($letters[$position]) || !isset($answer['id'])) {
                break;
            }
            $map[$letters[$position]] = (string)$answer['id'];
        }
        return $map;
    }

    /**
     * Apply the plugin's own boundary to a raw recognition result.
     *
     * Pure function on purpose: it takes the model's list, the code map of the
     * set and the letter map of the question, and returns what may be shown.
     * No database, no clock — so the boundary can be proved deterministically
     * from a fixture catalogue (P11_PLAN.md 2.4).
     *
     * @param array $entries Parsed model entries.
     * @param array<string,\stdClass> $codemap Codes of the set.
     * @param array<string,string> $lettermap Valid letters of the question.
     * @param array<int,\stdClass> $players Session players by user ID.
     * @return array{
     *     accepted:array<int,array<string,mixed>>,
     *     rejected:array<int,array{cardCode:string,code:string}>,
     *     recognised:int
     * }
     */
    public static function apply_boundary(
        array $entries,
        array $codemap,
        array $lettermap,
        array $players
    ): array {
        $accepted = [];
        $rejected = [];
        $seen = [];
        $recognised = 0;

        foreach (array_slice($entries, 0, card_limits::MAX_RESULT_ENTRIES) as $entry) {
            $rawcode = is_array($entry) ? (string)($entry['cardcode'] ?? '') : '';
            $code = card_code::normalise($rawcode);
            // 1. The check character must hold. A misread that survives the
            //    alphabet is caught here and never reaches the lookup.
            if ($code === '' || !card_code::is_valid($code)) {
                $rejected[] = ['cardCode' => $rawcode, 'code' => 'code_malformed'];
                continue;
            }
            // 2. The code must belong to THIS set.
            if (!isset($codemap[$code])) {
                $rejected[] = ['cardCode' => $code, 'code' => 'code_unknown'];
                continue;
            }
            // 3. No card twice. Two readings of one card mean one of them is
            //    wrong, and there is no way to tell which — so neither counts.
            if (isset($seen[$code])) {
                $rejected[] = ['cardCode' => $code, 'code' => 'code_duplicate'];
                continue;
            }
            $seen[$code] = true;
            $letter = strtoupper(trim((string)($entry['answerKey'] ?? '')));
            // 4. The letter must be one this question actually offers.
            if ($letter === '' || !isset($lettermap[$letter])) {
                $rejected[] = ['cardCode' => $code, 'code' => 'answer_unknown'];
                continue;
            }
            $card = $codemap[$code];
            $userid = $card->userid === null ? 0 : (int)$card->userid;
            $bookable = $userid > 0 && isset($players[$userid]);
            $accepted[] = [
                'cardCode' => $code,
                'userId' => $userid,
                'answerKey' => $letter,
                'confidence' => self::confidence($entry),
                'box' => self::box($entry),
                'bookable' => $bookable,
                'source' => 'model',
            ];
            if ($bookable) {
                $recognised++;
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'recognised' => $recognised,
        ];
    }

    /**
     * Persist a recognition result against the boundary.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \stdClass $scan Scan row.
     * @param array $entries Parsed model entries.
     * @return \stdClass Updated scan row.
     */
    public static function store_result(
        \stdClass $quizgeist,
        \stdClass $scan,
        array $entries
    ): \stdClass {
        global $DB;

        $question = answer_evaluator::canonical_question(
            session_repository::question((int)$quizgeist->id, (int)$scan->questionid)
        );
        $boundary = self::apply_boundary(
            $entries,
            card_repository::code_map((int)$scan->cardsetid),
            self::answer_key_map($question),
            card_repository::session_players((int)$scan->sessionid)
        );

        $expected = (int)$scan->expected;
        // The promise `recognised <= expected` is enforced, not assumed: a set
        // cannot yield more bookable cards than it holds, so a larger number
        // would mean the boundary above has a hole.
        $recognised = min($boundary['recognised'], $expected);
        $scan->state = 'recognised';
        $scan->reasoncode = $boundary['accepted'] === [] ? 'no_card_recognised' : null;
        $scan->resultjson = json_encode([
            'schemaVersion' => 1,
            'accepted' => $boundary['accepted'],
            'rejected' => $boundary['rejected'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $scan->recognised = $recognised;
        $scan->timemodified = time();
        $DB->update_record('quizgeist_card_scans', (object)[
            'id' => (int)$scan->id,
            'state' => $scan->state,
            'reasoncode' => $scan->reasoncode,
            'resultjson' => $scan->resultjson,
            'recognised' => $scan->recognised,
            'timemodified' => $scan->timemodified,
        ]);
        return $scan;
    }

    /**
     * Record that a scan could not be recognised at all.
     *
     * @param \stdClass $scan Scan row.
     * @param string $reasoncode Machine code; never rendered directly (F16).
     * @return \stdClass Updated scan row.
     */
    public static function mark_failed(\stdClass $scan, string $reasoncode): \stdClass {
        global $DB;

        $scan->state = 'failed';
        $scan->reasoncode = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($reasoncode)) ?: 'unknown', 0, 32);
        $scan->timemodified = time();
        $DB->update_record('quizgeist_card_scans', (object)[
            'id' => (int)$scan->id,
            'state' => $scan->state,
            'reasoncode' => $scan->reasoncode,
            'timemodified' => $scan->timemodified,
        ]);
        return $scan;
    }

    /**
     * Book the confirmed entries as live answers.
     *
     * Idempotent by construction: every entry goes through the ordinary
     * live_submission_service, whose unique submission index already refuses a
     * second answer for the same (session, player, question, visit). Pressing
     * "apply" twice therefore books once — the second run reports the same
     * lines and inserts nothing.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $scan Scan row.
     * @param array $corrections Client corrections: [{cardCode, answerKey|null}].
     * @return array{booked:int,skipped:array<int,array{cardCode:string,code:string}>}
     */
    public static function confirm(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $scan,
        array $corrections
    ): array {
        global $DB;

        if (!in_array((string)$scan->state, ['recognised', 'confirmed'], true)) {
            throw new card_exception('card_scan_state_invalid');
        }
        $questionrecord = session_repository::question(
            (int)$quizgeist->id,
            (int)$scan->questionid
        );
        $question = answer_evaluator::canonical_question($questionrecord);
        $lettermap = self::answer_key_map($question);
        $available = self::available_answer_ids($question);
        $players = card_repository::session_players((int)$scan->sessionid);
        $codemap = card_repository::code_map((int)$scan->cardsetid);

        $lines = self::merge_corrections($scan, $corrections, $codemap, $lettermap, $players);

        $booked = 0;
        $skipped = [];
        foreach ($lines as $line) {
            if (!$line['bookable']) {
                $skipped[] = ['cardCode' => $line['cardCode'], 'code' => 'card_without_player'];
                continue;
            }
            $answerid = $lettermap[$line['answerKey']] ?? '';
            if ($answerid === '') {
                $skipped[] = ['cardCode' => $line['cardCode'], 'code' => 'answer_unknown'];
                continue;
            }
            $user = $DB->get_record('user', ['id' => (int)$line['userId']], '*', IGNORE_MISSING);
            if (!$user) {
                $skipped[] = ['cardCode' => $line['cardCode'], 'code' => 'card_without_player'];
                continue;
            }
            // The card path enters the SAME pipeline as a learner's browser —
            // same validation, same scoring, same ledger — so it must speak
            // the same dialect. `quiz` resolves visit-bound opaque handles, so
            // the handle is built here; `truefalse` takes its two canonical
            // IDs verbatim and must NOT be handed a handle.
            $submitted = (string)$question['qtype'] === 'quiz'
                ? strategy_support::opaque_ids(
                    [$answerid],
                    $available,
                    (string)$scan->visit,
                    'quiz:choices'
                )[0]
                : $answerid;
            try {
                live_submission_service::submit(
                    $quizgeist,
                    $context,
                    $user,
                    (int)$scan->sessionid,
                    (int)$scan->questionid,
                    (string)$scan->visit,
                    ['choiceIds' => [$submitted]]
                );
                $booked++;
            } catch (\Throwable $exception) {
                // A refusal of the real pipeline (question already closed, the
                // learner answered from their own device) is REPORTED, never
                // swallowed and never retried into a duplicate.
                $skipped[] = [
                    'cardCode' => $line['cardCode'],
                    'code' => self::skip_code($exception),
                ];
            }
        }

        $DB->update_record('quizgeist_card_scans', (object)[
            'id' => (int)$scan->id,
            'state' => 'confirmed',
            'timemodified' => time(),
        ]);
        // E-10: the picture has served its purpose the moment the answers are
        // booked. It goes now, not at the next scheduled run.
        self::delete_image($context, $scan);

        return ['booked' => $booked, 'skipped' => $skipped];
    }

    /**
     * Abandon one scan without booking anything.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $scan Scan row.
     * @return void
     */
    public static function discard(\context_module $context, \stdClass $scan): void {
        global $DB;

        $DB->update_record('quizgeist_card_scans', (object)[
            'id' => (int)$scan->id,
            'state' => 'discarded',
            'resultjson' => null,
            'recognised' => 0,
            'timemodified' => time(),
        ]);
        self::delete_image($context, $scan);
    }

    /**
     * Remove the picture of one scan and record that it is gone.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $scan Scan row.
     * @return bool Whether this call performed the deletion.
     */
    public static function delete_image(\context_module $context, \stdClass $scan): bool {
        global $DB;

        if ((int)$scan->imagedeleted > 0) {
            return false;
        }
        get_file_storage()->delete_area_files(
            $context->id,
            self::COMPONENT,
            card_limits::FILE_AREA,
            (int)$scan->itemid
        );
        $now = time();
        $DB->update_record('quizgeist_card_scans', (object)[
            'id' => (int)$scan->id,
            'imagedeleted' => $now,
            'timemodified' => $now,
        ]);
        $scan->imagedeleted = $now;
        return true;
    }

    /**
     * Project one scan for the confirmation screen.
     *
     * Never carries `reasoncode` — the machine code stays server-side and the
     * client renders the sentence that belongs to the STATE (F16).
     *
     * @param \stdClass $scan Scan row.
     * @return array<string,mixed>
     */
    public static function project(\stdClass $scan): array {
        $result = self::decode_result($scan);
        $codemap = card_repository::code_map((int)$scan->cardsetid);
        $names = card_repository::holder_names((int)$scan->cardsetid);
        $players = card_repository::session_players((int)$scan->sessionid);

        $entries = [];
        $seen = [];
        foreach ($result['accepted'] as $entry) {
            $code = (string)$entry['cardCode'];
            $seen[$code] = true;
            $userid = (int)($entry['userId'] ?? 0);
            $entries[] = [
                'cardCode' => $code,
                'userId' => $userid,
                'displayName' => self::display_name($code, $userid, $codemap, $names),
                'answerKey' => (string)$entry['answerKey'],
                'confidence' => (float)($entry['confidence'] ?? 0.0),
                'bookable' => (bool)($entry['bookable'] ?? false),
                'source' => (string)($entry['source'] ?? 'model'),
            ];
        }

        // Every card of the set that was NOT recognised is listed by name and
        // sorted to the front by the client. This is the "kein stilles
        // Verschlucken" promise, and it is produced here rather than left to
        // the browser to notice.
        $missing = [];
        foreach ($codemap as $code => $card) {
            if (isset($seen[$code])) {
                continue;
            }
            $userid = $card->userid === null ? 0 : (int)$card->userid;
            $missing[] = [
                'cardCode' => $code,
                'userId' => $userid,
                'displayName' => self::display_name($code, $userid, $codemap, $names),
                'bookable' => $userid > 0 && isset($players[$userid]),
            ];
        }

        return [
            'id' => (int)$scan->id,
            'state' => (string)$scan->state,
            // F16: the machine code stays server-side. What travels is the
            // FAMILY it belongs to, and the client owns one sentence per
            // family — never a 1:1 translation of a diagnosis code.
            'reasonFamily' => self::reason_family($scan->reasoncode),
            'sessionId' => (int)$scan->sessionid,
            'questionId' => (int)$scan->questionid,
            'cardSetId' => (int)$scan->cardsetid,
            'recognised' => (int)$scan->recognised,
            'expected' => (int)$scan->expected,
            'imageDeleted' => (int)$scan->imagedeleted > 0,
            'entries' => $entries,
            'missing' => $missing,
            'rejectedCount' => count($result['rejected']),
            'timeModified' => (int)$scan->timemodified,
        ];
    }

    /**
     * Resolve one diagnosis code into its readable FAMILY.
     *
     * Closed by design: a code that nobody has thought about yet lands in the
     * generic family and still produces a sentence. That is the repaired
     * fallback of [P10-F16] — a raw key may appear in a log, never in the DOM.
     *
     * @param string|null $reasoncode Stored diagnosis code.
     * @return string One of: none, unavailable, unreadable, gone, unsupported, failed.
     */
    public static function reason_family(?string $reasoncode): string {
        $code = trim((string)$reasoncode);
        if ($code === '') {
            return 'none';
        }
        return match ($code) {
            'vision_not_configured', 'vision_unavailable' => 'unavailable',
            'image_gone' => 'gone',
            'qtype_unsupported' => 'unsupported',
            'no_card_recognised' => 'unreadable',
            default => str_starts_with($code, 'card_response_') ? 'unreadable' : 'failed',
        };
    }

    /**
     * Decode the stored result of one scan.
     *
     * @param \stdClass $scan Scan row.
     * @return array{accepted:array,rejected:array}
     */
    public static function decode_result(\stdClass $scan): array {
        $decoded = json_decode((string)($scan->resultjson ?? ''), true);
        if (!is_array($decoded)) {
            return ['accepted' => [], 'rejected' => []];
        }
        return [
            'accepted' => is_array($decoded['accepted'] ?? null) ? $decoded['accepted'] : [],
            'rejected' => is_array($decoded['rejected'] ?? null) ? $decoded['rejected'] : [],
        ];
    }

    /**
     * Merge the teacher's manual corrections into the recognised lines.
     *
     * A correction may change a letter or add a card the model missed, and it
     * may remove a line by sending a null letter. It can never introduce a
     * code that is not in the set — a manual entry passes the same boundary.
     *
     * @param \stdClass $scan Scan row.
     * @param array $corrections Client corrections.
     * @param array<string,\stdClass> $codemap Codes of the set.
     * @param array<string,string> $lettermap Valid letters.
     * @param array<int,\stdClass> $players Session players.
     * @return array<int,array<string,mixed>>
     */
    private static function merge_corrections(
        \stdClass $scan,
        array $corrections,
        array $codemap,
        array $lettermap,
        array $players
    ): array {
        $lines = [];
        foreach (self::decode_result($scan)['accepted'] as $entry) {
            $code = (string)($entry['cardCode'] ?? '');
            if ($code !== '') {
                $lines[$code] = [
                    'cardCode' => $code,
                    'userId' => (int)($entry['userId'] ?? 0),
                    'answerKey' => (string)($entry['answerKey'] ?? ''),
                    'bookable' => (bool)($entry['bookable'] ?? false),
                ];
            }
        }

        foreach (array_slice($corrections, 0, card_limits::MAX_RESULT_ENTRIES) as $correction) {
            if (!is_array($correction)) {
                continue;
            }
            $code = card_code::normalise((string)($correction['cardCode'] ?? ''));
            if ($code === '' || !card_code::is_valid($code) || !isset($codemap[$code])) {
                continue;
            }
            $rawletter = $correction['answerKey'] ?? null;
            if ($rawletter === null || $rawletter === '') {
                unset($lines[$code]);
                continue;
            }
            $letter = strtoupper(trim((string)$rawletter));
            if (!isset($lettermap[$letter])) {
                continue;
            }
            $card = $codemap[$code];
            $userid = $card->userid === null ? 0 : (int)$card->userid;
            $lines[$code] = [
                'cardCode' => $code,
                'userId' => $userid,
                'answerKey' => $letter,
                'bookable' => $userid > 0 && isset($players[$userid]),
            ];
        }
        return array_values($lines);
    }

    /**
     * Canonical answer IDs available for one question.
     *
     * @param array $question Canonical question.
     * @return string[]
     */
    private static function available_answer_ids(array $question): array {
        if ((string)$question['qtype'] === 'truefalse') {
            return ['true', 'false'];
        }
        return array_values(array_column($question['options']['answers'] ?? [], 'id'));
    }

    /**
     * Number of cards of one set that could be booked in this session.
     *
     * @param \stdClass $set Card set row.
     * @param int $sessionid Live session.
     * @return int
     */
    private static function expected_cards(\stdClass $set, int $sessionid): int {
        $players = card_repository::session_players($sessionid);
        $expected = 0;
        foreach (card_repository::cards((int)$set->id) as $card) {
            $userid = $card->userid === null ? 0 : (int)$card->userid;
            if ($userid > 0 && isset($players[$userid])) {
                $expected++;
            }
        }
        return $expected;
    }

    /**
     * Refuse a scan that does not belong to the session's current question.
     *
     * @param \stdClass $quizgeist Activity.
     * @param int $sessionid Session.
     * @param int $questionid Question.
     * @param string $visit Visit token.
     * @return void
     */
    private static function assert_live_target(
        \stdClass $quizgeist,
        int $sessionid,
        int $questionid,
        string $visit
    ): void {
        global $DB;

        $matches = $DB->record_exists_sql(
            'SELECT 1
               FROM {quizgeist_session_questions} sq
               JOIN {quizgeist_sessions} s ON s.id = sq.sessionid
              WHERE sq.sessionid = :sessionid
                AND sq.questionid = :questionid
                AND sq.visit = :visit
                AND s.quizgeistid = :quizgeistid',
            [
                'sessionid' => $sessionid,
                'questionid' => $questionid,
                'visit' => $visit,
                'quizgeistid' => (int)$quizgeist->id,
            ]
        );
        if (!$matches) {
            throw new card_exception('card_scan_question_invalid');
        }
    }

    /**
     * Refuse anything PHP itself already found wrong with the upload.
     *
     * @param array $upload One $_FILES entry.
     * @return void
     */
    private static function assert_upload_ok(array $upload): void {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new card_exception('card_scan_too_large');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new card_exception('card_scan_upload_failed');
        }
    }

    /**
     * Refuse anything that did not arrive through PHP's upload handling.
     *
     * @param string $tmpname Temporary path.
     * @return void
     */
    private static function assert_uploaded_file(string $tmpname): void {
        if ($tmpname === '' || !is_uploaded_file($tmpname)) {
            throw new card_exception('card_scan_upload_failed');
        }
    }

    /**
     * Refuse an oversized scan.
     *
     * @param string $tmpname Temporary path.
     * @return int Size in bytes.
     */
    private static function assert_size(string $tmpname): int {
        $bytes = (int)@filesize($tmpname);
        if ($bytes <= 0) {
            throw new card_exception('card_scan_empty');
        }
        if ($bytes > card_limits::max_bytes()) {
            throw new card_exception('card_scan_too_large');
        }
        return $bytes;
    }

    /**
     * Refuse anything whose BYTES are not one of the accepted raster images.
     *
     * The type comes from `getimagesizefromstring`, i.e. from the content, and
     * the magic bytes are checked separately so a crafted header alone cannot
     * pass. The pixel product is bounded against a decompression bomb: a small
     * file may still decode into gigabytes of memory.
     *
     * @param string $tmpname Temporary path.
     * @return array{mimetype:string,extension:string,width:int,height:int}
     */
    private static function assert_image(string $tmpname): array {
        $head = (string)@file_get_contents($tmpname, false, null, 0, 64);
        $family = self::magic_family($head);
        if ($family === '') {
            throw new card_exception('card_scan_type_invalid');
        }
        $content = (string)@file_get_contents($tmpname);
        if ($content === '') {
            throw new card_exception('card_scan_empty');
        }
        $size = @getimagesizefromstring($content);
        if (!is_array($size) || !isset($size[0], $size[1], $size['mime'])) {
            throw new card_exception('card_scan_type_invalid');
        }
        $mimetype = strtolower((string)$size['mime']);
        if (!in_array($mimetype, card_limits::MIME_TYPES, true)
                || $mimetype !== self::family_mimetype($family)) {
            throw new card_exception('card_scan_type_invalid');
        }
        $width = (int)$size[0];
        $height = (int)$size[1];
        if ($width < card_limits::MIN_EDGE_PIXELS || $height < card_limits::MIN_EDGE_PIXELS) {
            throw new card_exception('card_scan_pixels_invalid');
        }
        if ($width * $height > card_limits::MAX_PIXELS) {
            throw new card_exception('card_scan_pixels_invalid');
        }
        return [
            'mimetype' => $mimetype,
            'extension' => $family === 'jpeg' ? 'jpg' : $family,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Detect the raster family from the leading magic bytes.
     *
     * @param string $head First bytes of the file.
     * @return string png, jpeg, webp or ''.
     */
    public static function magic_family(string $head): string {
        if (str_starts_with($head, "\x89PNG\x0d\x0a\x1a\x0a")) {
            return 'png';
        }
        if (str_starts_with($head, "\xff\xd8\xff")) {
            return 'jpeg';
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return '';
    }

    /**
     * MIME type of one raster family.
     *
     * @param string $family Family key.
     * @return string
     */
    public static function family_mimetype(string $family): string {
        return match ($family) {
            'png' => 'image/png',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => '',
        };
    }

    /**
     * Compare the CLIENT's declaration against the detected type.
     *
     * @param string $declared Raw Content-Type of the upload part.
     * @param string $mimetype Detected type.
     * @return void
     */
    private static function assert_declared_type(string $declared, string $mimetype): void {
        $declared = strtolower(trim(explode(';', $declared, 2)[0]));
        if ($declared === '') {
            // An absent declaration says nothing and is therefore not a lie.
            return;
        }
        if (!in_array($declared, card_limits::MIME_TYPES, true) || $declared !== $mimetype) {
            throw new card_exception('card_scan_type_invalid');
        }
    }

    /**
     * Refuse an upload flood from one teacher in one activity.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return void
     */
    private static function assert_rate(int $quizgeistid, int $userid): void {
        $entry = self::rate_cache()->get($quizgeistid . '_' . $userid);
        if (is_array($entry)
                && (int)($entry['started'] ?? 0) >= time() - card_limits::RATE_WINDOW
                && (int)($entry['uploads'] ?? 0) >= card_limits::RATE_LIMIT) {
            throw new card_exception('card_scan_rate_limited');
        }
    }

    /**
     * Count one accepted upload against the window.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return void
     */
    private static function record_rate(int $quizgeistid, int $userid): void {
        $cache = self::rate_cache();
        $key = $quizgeistid . '_' . $userid;
        $entry = $cache->get($key);
        if (!is_array($entry) || (int)($entry['started'] ?? 0) < time() - card_limits::RATE_WINDOW) {
            $entry = ['started' => time(), 'uploads' => 0];
        }
        $entry['uploads'] = (int)$entry['uploads'] + 1;
        $cache->set($key, $entry);
    }

    /**
     * Obtain the rate cache.
     *
     * @return \cache
     */
    private static function rate_cache(): \cache {
        return \cache::make('mod_quizgeist', 'cardscanrate');
    }

    /**
     * Bounded confidence of one model entry.
     *
     * A missing or nonsensical value becomes 0.0, never 1.0: an unstated
     * confidence is the least certain case, not the most certain one.
     *
     * @param array $entry Model entry.
     * @return float
     */
    private static function confidence(array $entry): float {
        $raw = $entry['confidence'] ?? null;
        if (!is_int($raw) && !is_float($raw)) {
            return 0.0;
        }
        return max(0.0, min(1.0, round((float)$raw, 3)));
    }

    /**
     * Bounded bounding box of one model entry, or null.
     *
     * @param array $entry Model entry.
     * @return array<int,float>|null
     */
    private static function box(array $entry): ?array {
        $raw = $entry['box'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || count($raw) !== 4) {
            return null;
        }
        $box = [];
        foreach ($raw as $value) {
            if (!is_int($value) && !is_float($value)) {
                return null;
            }
            $box[] = max(0.0, min(1.0, round((float)$value, 4)));
        }
        return $box;
    }

    /**
     * Readable name of one card holder.
     *
     * @param string $code Card code.
     * @param int $userid Holder or zero.
     * @param array<string,\stdClass> $codemap Codes of the set.
     * @param array<int,string> $names Resolved holder names.
     * @return string
     */
    private static function display_name(
        string $code,
        int $userid,
        array $codemap,
        array $names
    ): string {
        if ($userid > 0 && isset($names[$userid])) {
            return $names[$userid];
        }
        if (isset($codemap[$code]) && $codemap[$code]->userid === null) {
            return get_string('cards:print:reservecard', 'mod_quizgeist');
        }
        return get_string('cards:print:unknownholder', 'mod_quizgeist');
    }

    /**
     * Stable machine code for a refusal of the live pipeline.
     *
     * @param \Throwable $exception Refusal.
     * @return string
     */
    private static function skip_code(\Throwable $exception): string {
        if ($exception instanceof \mod_quizgeist\local\live\live_conflict_exception) {
            return 'question_moved_on';
        }
        if ($exception instanceof \mod_quizgeist\local\live\live_domain_exception) {
            return (string)$exception->get_error_code();
        }
        return 'booking_refused';
    }
}
