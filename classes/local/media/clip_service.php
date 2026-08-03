<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Acceptance, storage, delivery and deletion of short audio clips (U3).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\media;

use mod_quizgeist\local\transaction_scope;

defined('MOODLE_INTERNAL') || die();

/**
 * The one audited place where recorded audio enters and leaves this plugin.
 *
 * Structured like classes/local/editor/media_service: every rule is a separate,
 * separately testable method, and the MIME type is derived from the bytes
 * rather than read from the request. The rules are applied in the order in
 * which they get cheaper to violate — an oversized upload is refused before
 * anything parses it.
 */
final class clip_service {

    /** Component owning the file area. */
    public const COMPONENT = 'mod_quizgeist';

    /**
     * Accept one uploaded clip.
     *
     * @param \context_module $context Module context.
     * @param int $quizgeistid Owning activity.
     * @param int $userid Recording user.
     * @param string $purpose One of clip_limits::PURPOSES.
     * @param string $language Requested recognition language.
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $upload One $_FILES entry.
     * @return \stdClass The stored clip row.
     */
    public static function accept_upload(
        \context_module $context,
        int $quizgeistid,
        int $userid,
        string $purpose,
        string $language,
        array $upload
    ): \stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $userid <= 0) {
            throw new clip_exception('clip_owner_invalid');
        }
        if (!clip_limits::is_purpose($purpose)) {
            throw new clip_exception('clip_purpose_invalid');
        }

        self::assert_upload_ok($upload);
        $tmpname = (string)($upload['tmp_name'] ?? '');
        self::assert_uploaded_file($tmpname);
        $bytes = self::assert_size($tmpname);
        $probe = self::assert_container($tmpname);
        self::assert_declared_type((string)($upload['type'] ?? ''), $probe['family']);
        self::assert_duration($probe['durationms']);
        self::assert_rate($quizgeistid, $userid);

        $now = time();
        $record = (object)[
            'quizgeistid' => $quizgeistid,
            'userid' => $userid,
            'answerid' => null,
            'purpose' => $purpose,
            'itemid' => 0,
            'durationms' => $probe['durationms'],
            'bytes' => $bytes,
            'language' => self::normalise_language($language),
            'transcript' => null,
            'transcriptstate' => 'none',
            'transcriptcode' => null,
            'audiodeleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $transaction = transaction_scope::begin();
        try {
            $record->id = (int)$DB->insert_record('quizgeist_clips', $record);
            // The item ID is the clip ID: a file area entry can then never
            // outlive or precede its bookkeeping row.
            $record->itemid = $record->id;
            $DB->set_field('quizgeist_clips', 'itemid', $record->itemid, ['id' => $record->id]);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        try {
            get_file_storage()->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => self::COMPONENT,
                'filearea' => clip_limits::FILE_AREA,
                'itemid' => $record->itemid,
                'filepath' => '/',
                // The stored name is generated, never the client's: an
                // uploaded name is attacker-controlled text.
                'filename' => self::filename($record->id, $probe['family']),
                'userid' => $userid,
                'mimetype' => $probe['mimetype'],
            ], $tmpname);
        } catch (\Throwable $exception) {
            // A row without audio would be a phantom clip. Remove it rather
            // than leave one behind.
            $DB->delete_records('quizgeist_clips', ['id' => $record->id]);
            throw new clip_exception('clip_storage_failed');
        }

        self::record_rate($quizgeistid, $userid);
        return $record;
    }

    /**
     * Bind one clip to exactly one answer.
     *
     * The unique index on `answerid` is the real guarantee; this method only
     * produces the readable diagnosis before the database says no.
     *
     * @param int $clipid Clip ID.
     * @param int $answerid Answer ID.
     * @param int $userid Acting user; must own the clip.
     * @return void
     */
    public static function bind_to_answer(int $clipid, int $answerid, int $userid): void {
        global $DB;

        if ($clipid <= 0 || $answerid <= 0) {
            throw new clip_exception('clip_binding_invalid');
        }
        $clip = $DB->get_record('quizgeist_clips', ['id' => $clipid]);
        if (!$clip || (int)$clip->userid !== $userid) {
            // Foreign clip: same diagnosis as a missing one, so the endpoint
            // cannot be used to probe which clip IDs exist.
            throw new clip_exception('clip_not_found');
        }
        if ($clip->answerid !== null && (int)$clip->answerid !== $answerid) {
            throw new clip_exception('clip_already_bound');
        }
        $DB->set_field_select(
            'quizgeist_clips',
            'answerid',
            $answerid,
            'id = :id AND (answerid IS NULL OR answerid = :answerid)',
            ['id' => $clipid, 'answerid' => $answerid]
        );
        $DB->set_field('quizgeist_clips', 'timemodified', time(), ['id' => $clipid]);
    }

    /**
     * Load one clip owned by the given activity.
     *
     * @param int $quizgeistid Activity.
     * @param int $clipid Clip ID.
     * @return \stdClass|null
     */
    public static function get(int $quizgeistid, int $clipid): ?\stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $clipid <= 0) {
            return null;
        }
        $clip = $DB->get_record('quizgeist_clips', [
            'id' => $clipid,
            'quizgeistid' => $quizgeistid,
        ]);
        return $clip ?: null;
    }

    /**
     * Return the stored audio file of one clip, when it still exists.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $clip Clip row.
     * @return \stored_file|null
     */
    public static function file(\context_module $context, \stdClass $clip): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            $context->id,
            self::COMPONENT,
            clip_limits::FILE_AREA,
            (int)$clip->itemid,
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
     * Mark one clip as queued for transcription.
     *
     * @param int $clipid Clip ID.
     * @return void
     */
    public static function mark_pending(int $clipid): void {
        self::set_state($clipid, 'pending', null, null);
    }

    /**
     * Store a finished transcript.
     *
     * @param int $clipid Clip ID.
     * @param string $transcript Plain text; may legitimately be empty.
     * @return void
     */
    public static function store_transcript(int $clipid, string $transcript): void {
        $transcript = clean_param($transcript, PARAM_TEXT);
        if (\core_text::strlen($transcript) > clip_limits::MAX_TRANSCRIPT_CHARS) {
            $transcript = \core_text::substr($transcript, 0, clip_limits::MAX_TRANSCRIPT_CHARS);
        }
        self::set_state($clipid, 'done', $transcript, null);
    }

    /**
     * Record a failed transcription with its machine code.
     *
     * @param int $clipid Clip ID.
     * @param string $code Stable machine code; never rendered as such (F16).
     * @return void
     */
    public static function mark_failed(int $clipid, string $code): void {
        $code = substr(preg_replace('/[^a-z0-9_]/', '', strtolower($code)) ?? '', 0, 32);
        self::set_state($clipid, 'failed', null, $code !== '' ? $code : 'unknown');
    }

    /**
     * Delete the AUDIO of one clip and keep its transcript (E-10).
     *
     * @param \context_module $context Module context.
     * @param \stdClass $clip Clip row.
     * @return bool Whether audio was actually removed.
     */
    public static function delete_audio(\context_module $context, \stdClass $clip): bool {
        global $DB;

        get_file_storage()->delete_area_files(
            $context->id,
            self::COMPONENT,
            clip_limits::FILE_AREA,
            (int)$clip->itemid
        );
        if ((int)$clip->audiodeleted > 0) {
            return false;
        }
        $now = time();
        $DB->set_field('quizgeist_clips', 'audiodeleted', $now, ['id' => (int)$clip->id]);
        $DB->set_field('quizgeist_clips', 'timemodified', $now, ['id' => (int)$clip->id]);
        return true;
    }

    /**
     * Delete a clip completely, audio and row.
     *
     * Used by the dictation path, where the recording exists only to become
     * text and has no reason to survive that moment.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $clip Clip row.
     * @return void
     */
    public static function delete(\context_module $context, \stdClass $clip): void {
        global $DB;

        get_file_storage()->delete_area_files(
            $context->id,
            self::COMPONENT,
            clip_limits::FILE_AREA,
            (int)$clip->itemid
        );
        $DB->delete_records('quizgeist_clips', ['id' => (int)$clip->id]);
    }

    /**
     * Public projection of one clip for a client.
     *
     * Deliberately excludes `transcriptcode`: a machine code belongs in the
     * server log and in the browser console, never in a payload a page may
     * render (F16).
     *
     * @param \stdClass $clip Clip row.
     * @param bool $includetranscript Whether the viewer may read the transcript.
     * @return array<string,mixed>
     */
    public static function project(\stdClass $clip, bool $includetranscript = true): array {
        $projection = [
            'id' => (int)$clip->id,
            'purpose' => (string)$clip->purpose,
            'durationMs' => (int)$clip->durationms,
            'language' => (string)$clip->language,
            'transcriptState' => (string)$clip->transcriptstate,
            'audioAvailable' => (int)$clip->audiodeleted === 0,
        ];
        if ($includetranscript) {
            $projection['transcript'] = $clip->transcript === null
                ? null
                : (string)$clip->transcript;
        }
        return $projection;
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
            throw new clip_exception('clip_too_large');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new clip_exception('clip_upload_failed');
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
            throw new clip_exception('clip_upload_failed');
        }
    }

    /**
     * Refuse an oversized clip.
     *
     * @param string $tmpname Temporary path.
     * @return int Size in bytes.
     */
    private static function assert_size(string $tmpname): int {
        $bytes = (int)@filesize($tmpname);
        if ($bytes <= 0) {
            throw new clip_exception('clip_empty');
        }
        if ($bytes > clip_limits::max_bytes()) {
            throw new clip_exception('clip_too_large');
        }
        return $bytes;
    }

    /**
     * Refuse anything whose BYTES are not one of the accepted containers.
     *
     * @param string $tmpname Temporary path.
     * @return array{family:string,mimetype:string,durationms:int}
     */
    private static function assert_container(string $tmpname): array {
        $probe = clip_probe::inspect($tmpname);
        if ($probe['code'] !== '' || $probe['mimetype'] === '') {
            throw new clip_exception($probe['code'] !== '' ? $probe['code'] : 'clip_signature_unknown');
        }
        if (!in_array($probe['mimetype'], clip_limits::MIME_TYPES, true)) {
            throw new clip_exception('clip_type_invalid');
        }
        $head = (string)@file_get_contents($tmpname, false, null, 0, 4096);
        $family = clip_probe::family($head);
        if ($family === '' || clip_probe::family_mimetype($family) !== $probe['mimetype']) {
            throw new clip_exception('clip_signature_unknown');
        }
        return [
            'family' => $family,
            'mimetype' => $probe['mimetype'],
            'durationms' => (int)$probe['durationms'],
        ];
    }

    /**
     * Compare the CLIENT's declaration against the detected family.
     *
     * The declaration can never grant acceptance — the container already did
     * that. It can only reveal a mismatch, and a mismatch is refused.
     *
     * @param string $declared Raw Content-Type of the upload part.
     * @param string $family Detected container family.
     * @return void
     */
    private static function assert_declared_type(string $declared, string $family): void {
        $declared = strtolower(trim(explode(';', $declared, 2)[0]));
        if ($declared === '') {
            // An absent declaration says nothing and is therefore not a lie.
            return;
        }
        if (!in_array($declared, clip_limits::MIME_TYPES, true)) {
            throw new clip_exception('clip_type_invalid');
        }
        if (!in_array($declared, clip_probe::family_mimetypes($family), true)) {
            throw new clip_exception('clip_type_mismatch');
        }
    }

    /**
     * Refuse a clip longer than the configured ceiling.
     *
     * The value comes from the container, not from a form field, so a client
     * cannot buy itself more GPU time by claiming a shorter recording.
     *
     * @param int $durationms Measured duration.
     * @return void
     */
    private static function assert_duration(int $durationms): void {
        if ($durationms <= 0) {
            throw new clip_exception('clip_duration_unreadable');
        }
        if ($durationms > clip_limits::max_seconds() * 1000) {
            throw new clip_exception('clip_too_long');
        }
    }

    /**
     * Refuse an upload flood from one user in one activity.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return void
     */
    private static function assert_rate(int $quizgeistid, int $userid): void {
        $entry = self::rate_cache()->get(self::rate_key($quizgeistid, $userid));
        if (is_array($entry)
                && (int)($entry['started'] ?? 0) >= time() - clip_limits::RATE_WINDOW
                && (int)($entry['uploads'] ?? 0) >= clip_limits::RATE_LIMIT) {
            throw new clip_exception('clip_rate_limited');
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
        $key = self::rate_key($quizgeistid, $userid);
        $entry = $cache->get($key);
        if (!is_array($entry) || (int)($entry['started'] ?? 0) < time() - clip_limits::RATE_WINDOW) {
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
        return \cache::make('mod_quizgeist', 'cliprate');
    }

    /**
     * Build the rate-limit key.
     *
     * @param int $quizgeistid Activity.
     * @param int $userid User.
     * @return string
     */
    private static function rate_key(int $quizgeistid, int $userid): string {
        return $quizgeistid . '_' . $userid;
    }

    /**
     * Write one transcription state transition.
     *
     * @param int $clipid Clip ID.
     * @param string $state Target state.
     * @param string|null $transcript Transcript, when the state carries one.
     * @param string|null $code Machine code, when the state carries one.
     * @return void
     */
    private static function set_state(int $clipid, string $state, ?string $transcript, ?string $code): void {
        global $DB;

        if (!in_array($state, clip_limits::STATES, true)) {
            throw new \coding_exception('An unknown clip transcription state was requested.');
        }
        $update = (object)[
            'id' => $clipid,
            'transcriptstate' => $state,
            'transcriptcode' => $code,
            'timemodified' => time(),
        ];
        if ($transcript !== null) {
            $update->transcript = $transcript;
        }
        $DB->update_record('quizgeist_clips', $update);
    }

    /**
     * Generated, non-guessable-free storage name of one clip.
     *
     * @param int $clipid Clip ID.
     * @param string $family Container family.
     * @return string
     */
    private static function filename(int $clipid, string $family): string {
        $extension = match ($family) {
            'webm' => 'webm',
            'ogg' => 'ogg',
            'mp4' => 'm4a',
            'wav' => 'wav',
            default => 'bin',
        };
        return 'clip' . $clipid . '.' . $extension;
    }

    /**
     * Normalise a language hint without depending on the AI addon.
     *
     * The clip channel is base functionality; it may not reference
     * quizgeistaddon_ai. The rule is deliberately the same one the gateway
     * client applies, and a static test keeps the two in step.
     *
     * @param string $language Raw language hint.
     * @return string
     */
    public static function normalise_language(string $language): string {
        $language = strtolower(trim($language));
        $language = (string)preg_replace('/[^a-z].*$/', '', $language);
        return preg_match('/^[a-z]{2}$/D', $language) ? $language : 'de';
    }
}
