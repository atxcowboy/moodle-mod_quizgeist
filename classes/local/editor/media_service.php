<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Moodle file-area integration for the editor and site templates.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\editor;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps draft, module and system-context media handling in one audited place.
 */
final class media_service {

    /** @var string[] Module-context areas editable in P2. */
    public const MODULE_AREAS = ['questionmedia', 'background', 'logo'];

    /** Private provenance area used by the audited P8 importer. */
    public const IMPORT_AREA = 'importmedia';

    /** @var string[] Local formats accepted by the Moodle picker. */
    private const QUESTION_TYPES = [
        '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg',
        '.mp4', '.webm', '.mp3', '.ogg', '.wav',
    ];

    /** @var string[] Appearance images. */
    private const IMAGE_TYPES = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg'];

    /** @var array<string, string[]> MIME types accepted for each local-media filename extension. */
    private const DELIVERY_TYPES = [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml'],
        'mp4' => ['video/mp4', 'audio/mp4'],
        'webm' => ['video/webm', 'audio/webm'],
        'mp3' => ['audio/mp3', 'audio/mpeg'],
        'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
        'wav' => ['audio/wav', 'audio/x-wav'],
    ];

    /** SVG-sanitizer cache namespace, bumped whenever its rules change. */
    private const SVG_CACHE_VERSION = 'v1_';

    /**
     * File-manager options shared by media.php and programmatic finalisation.
     *
     * @param string $area File area.
     * @param \context $context Target context.
     * @param bool $targetslot Whether the picker represents one semantic slot.
     * @return array
     */
    public static function file_options(string $area, \context $context, bool $targetslot = false): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/filelib.php');
        // FILE_INTERNAL lebt in repository/lib.php und wird sonst nirgends geladen.
        require_once($CFG->dirroot . '/repository/lib.php');
        self::require_area($area);
        $coursemaxbytes = 0;
        if ($coursecontext = $context->get_course_context(false)) {
            $coursemaxbytes = (int)$DB->get_field(
                'course',
                'maxbytes',
                ['id' => (int)$coursecontext->instanceid],
                MUST_EXIST
            );
        }
        $maxbytes = get_user_max_upload_file_size($context, $CFG->maxbytes, $coursemaxbytes, 0);
        return [
            'subdirs' => true,
            'maxfiles' => $targetslot ? 1 : ($area === 'questionmedia' ? 64 : 1),
            'maxbytes' => $maxbytes,
            'areamaxbytes' => $maxbytes > 0 ? max($maxbytes, $maxbytes * 64) : FILE_AREA_MAX_BYTES_UNLIMITED,
            'accepted_types' => $area === 'questionmedia' ? self::QUESTION_TYPES : self::IMAGE_TYPES,
            'return_types' => FILE_INTERNAL,
        ];
    }

    /**
     * Validate an area/item pair against the current activity.
     *
     * @param int $quizgeistid Activity ID.
     * @param string $area Area.
     * @param int $itemid Item ID.
     * @param string|null $target Optional semantic question-media target.
     * @return void
     */
    public static function require_module_target(
        int $quizgeistid,
        string $area,
        int $itemid,
        ?string $target = null
    ): void {
        global $DB;

        self::require_area($area);
        if ($area === 'background' || $area === 'logo') {
            if ($itemid !== 0) {
                throw new \invalid_parameter_exception('Appearance media itemid must be zero.');
            }
            return;
        }
        $question = $itemid > 0 ? $DB->get_record_select(
            'quizgeist_questions',
            'id = :id AND quizgeistid = :quizgeistid AND status <> :archived',
            [
                'id' => $itemid,
                'quizgeistid' => $quizgeistid,
                'archived' => 'archived',
            ],
            'id,optionsjson'
        ) : false;
        if (!$question) {
            throw new \invalid_parameter_exception('Question media does not belong to this activity.');
        }
        if ($target === null) {
            return;
        }

        $targetpath = self::target_path($area, $target);
        if ($targetpath === '/question/') {
            return;
        }
        if (!preg_match('/^\/answers\/([a-z][a-z0-9_-]{0,31})\/$/D', $targetpath, $matches)) {
            throw new \invalid_parameter_exception('Invalid question media target.');
        }
        $options = question_schema::decode_options($question->optionsjson);
        foreach (['answers', 'items'] as $collection) {
            foreach ($options[$collection] ?? [] as $row) {
                if (is_array($row) && ($row['id'] ?? null) === $matches[1]) {
                    return;
                }
            }
        }
        throw new \invalid_parameter_exception('Question media target does not exist.');
    }

    /**
     * Prepare only one semantic slot in a fresh user draft area.
     *
     * Existing files outside the target slot are deliberately hidden. They are
     * merged back immediately before committing so editing one answer image can
     * never erase another answer's media.
     *
     * @param \context_module $context Module context.
     * @param string $area File area.
     * @param int $itemid Item ID.
     * @param string $target Semantic target.
     * @return int Draft item ID.
     */
    public static function prepare_target_draft(
        \context_module $context,
        string $area,
        int $itemid,
        string $target
    ): int {
        global $SESSION, $USER;

        $targetpath = self::target_path($area, $target);
        $options = self::file_options($area, $context, true);
        $draftitemid = 0;
        file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'mod_quizgeist',
            $area,
            $itemid,
            $options
        );

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        foreach ($fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id ASC',
            false
        ) as $file) {
            if ($file->get_filepath() !== $targetpath) {
                $file->delete();
            }
        }
        if (!isset($SESSION->quizgeistmediadrafts) || !is_array($SESSION->quizgeistmediadrafts)) {
            $SESSION->quizgeistmediadrafts = [];
        }
        $SESSION->quizgeistmediadrafts[$draftitemid] = true;
        return $draftitemid;
    }

    /**
     * Commit a target-only picker draft without disturbing sibling slots.
     *
     * @param \context_module $context Module context.
     * @param string $area Area.
     * @param int $itemid Item ID.
     * @param string $target Semantic target.
     * @param int $draftitemid User draft ID.
     * @return array Updated manifest.
     */
    public static function save_target_draft(
        \context_module $context,
        string $area,
        int $itemid,
        string $target,
        int $draftitemid
    ): array {
        global $SESSION, $USER;

        $targetpath = self::target_path($area, $target);
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $draftfiles = self::validated_draft_files(
            $draftitemid,
            $area,
            $context,
            1
        );

        // A freshly uploaded file normally lands in /. Move every target-draft
        // file into its canonical semantic folder before the full-area merge.
        foreach ($draftfiles as $file) {
            if ($file->get_filepath() !== $targetpath) {
                $existing = $fs->get_file(
                    $usercontext->id,
                    'user',
                    'draft',
                    $draftitemid,
                    $targetpath,
                    $file->get_filename()
                );
                if ($existing && (int)$existing->get_id() !== (int)$file->get_id()) {
                    $existing->delete();
                }
                $file->rename($targetpath, $file->get_filename());
            }
        }

        // Re-add untouched final files outside the edited target. The standard
        // file_save_draft_area_files() merge can now safely replace the area.
        $draftpaths = [];
        foreach ($fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id ASC',
            false
        ) as $draftfile) {
            $draftpaths[$draftfile->get_filepath() . $draftfile->get_filename()] = true;
        }
        foreach ($fs->get_area_files(
            $context->id,
            'mod_quizgeist',
            $area,
            $itemid,
            'id ASC',
            false
        ) as $storedfile) {
            if ($storedfile->get_filepath() === $targetpath) {
                continue;
            }
            $key = $storedfile->get_filepath() . $storedfile->get_filename();
            if (isset($draftpaths[$key])) {
                continue;
            }
            $fs->create_file_from_storedfile([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => $storedfile->get_filepath(),
                'filename' => $storedfile->get_filename(),
            ], $storedfile);
        }

        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_quizgeist',
            $area,
            $itemid,
            self::file_options($area, $context, false)
        );
        if (isset($SESSION->quizgeistmediadrafts[$draftitemid])) {
            unset($SESSION->quizgeistmediadrafts[$draftitemid]);
        }
        return self::manifest($context, $area, $itemid);
    }

    /**
     * Preflight a target-only draft without mutating its target question.
     *
     * @param \context_module $context Module context.
     * @param string $area Area.
     * @param string $target Semantic target.
     * @param int $draftitemid User draft ID.
     * @return void
     */
    public static function validate_target_draft(
        \context_module $context,
        string $area,
        string $target,
        int $draftitemid
    ): void {
        self::target_path($area, $target);
        self::validated_draft_files($draftitemid, $area, $context, 1);
    }

    /**
     * Whether a target-only draft would change its authoritative final slot.
     *
     * @param \context_module $context Module context.
     * @param string $area Area.
     * @param int $itemid Final item ID.
     * @param string $target Semantic target.
     * @param int $draftitemid User-owned draft ID.
     * @return bool
     */
    public static function target_draft_differs(
        \context_module $context,
        string $area,
        int $itemid,
        string $target,
        int $draftitemid
    ): bool {
        $targetpath = self::target_path($area, $target);
        $draftfiles = self::validated_draft_files($draftitemid, $area, $context, 1);
        $draftfingerprints = [];
        foreach ($draftfiles as $file) {
            $draftfingerprints[$targetpath . $file->get_filename()] = $file->get_contenthash();
        }
        ksort($draftfingerprints, SORT_STRING);

        $finalfiles = array_filter(
            get_file_storage()->get_area_files(
                $context->id,
                'mod_quizgeist',
                $area,
                $itemid,
                'filepath ASC, filename ASC',
                false
            ),
            static fn(\stored_file $file): bool => $file->get_filepath() === $targetpath
        );
        return $draftfingerprints !== self::file_fingerprints($finalfiles);
    }

    /**
     * Finalise a complete draft area supplied by the P2 gate/import tooling.
     *
     * @param \context_module $context Module context.
     * @param string $area Area.
     * @param int $itemid Item ID.
     * @param int $draftitemid User-owned draft ID.
     * @return array Updated manifest.
     */
    public static function save_complete_draft(
        \context_module $context,
        string $area,
        int $itemid,
        int $draftitemid
    ): array {
        $maxfiles = $area === 'questionmedia' ? 64 : 1;
        $files = self::validated_draft_files($draftitemid, $area, $context, $maxfiles);
        self::assert_canonical_paths($files, $area);
        file_save_draft_area_files(
            $draftitemid,
            $context->id,
            'mod_quizgeist',
            $area,
            $itemid,
            self::file_options($area, $context, false)
        );
        return self::manifest($context, $area, $itemid);
    }

    /**
     * Preflight a complete draft without mutating its target question.
     *
     * @param \context_module $context Module context.
     * @param string $area Area.
     * @param int $draftitemid User-owned draft ID.
     * @return void
     */
    public static function validate_complete_draft(
        \context_module $context,
        string $area,
        int $draftitemid
    ): void {
        $maxfiles = $area === 'questionmedia' ? 64 : 1;
        $files = self::validated_draft_files($draftitemid, $area, $context, $maxfiles);
        self::assert_canonical_paths($files, $area);
    }

    /**
     * Whether a complete draft would change a final media area.
     *
     * @param \context_module $context Module context.
     * @param string $area Area.
     * @param int $itemid Final item ID.
     * @param int $draftitemid User-owned draft ID.
     * @return bool
     */
    public static function complete_draft_differs(
        \context_module $context,
        string $area,
        int $itemid,
        int $draftitemid
    ): bool {
        $maxfiles = $area === 'questionmedia' ? 64 : 1;
        $draftfiles = self::validated_draft_files($draftitemid, $area, $context, $maxfiles);
        self::assert_canonical_paths($draftfiles, $area);
        $finalfiles = get_file_storage()->get_area_files(
            $context->id,
            'mod_quizgeist',
            $area,
            $itemid,
            'filepath ASC, filename ASC',
            false
        );
        return self::file_fingerprints($draftfiles) !== self::file_fingerprints($finalfiles);
    }

    /**
     * Return safe stored-file metadata and pluginfile URLs.
     *
     * @param \context $context File context.
     * @param string $area File area.
     * @param int $itemid Item ID.
     * @param int $accesscmid Course-module ID used to authorise system template media.
     * @return array
     */
    public static function manifest(
        \context $context,
        string $area,
        int $itemid,
        int $accesscmid = 0
    ): array {
        self::require_area_or_template($area);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_quizgeist',
            $area,
            $itemid,
            'filepath ASC, filename ASC',
            false
        );
        $manifest = [];
        foreach ($files as $file) {
            if (!self::is_safe_for_delivery($file)) {
                continue;
            }
            $filepath = $file->get_filepath();
            $filename = $file->get_filename();
            $url = \moodle_url::make_pluginfile_url(
                $context->id,
                'mod_quizgeist',
                $area,
                $itemid,
                $filepath,
                $filename,
                false
            );
            if ($area === 'templatemedia' && $accesscmid > 0) {
                $url->param('cmid', $accesscmid);
            }
            $manifest[] = [
                'path' => $filepath . $filename,
                'filepath' => $filepath,
                'filename' => $filename,
                'mimetype' => (string)$file->get_mimetype(),
                'filesize' => (int)$file->get_filesize(),
                'url' => $url->out(false),
            ];
        }
        return $manifest;
    }

    /**
     * Derive canonical media option paths from authoritative stored files.
     *
     * @param array $options Canonical options.
     * @param array $manifest Question media manifest.
     * @return array
     */
    public static function synchronise_question_options(array $options, array $manifest): array {
        $paths = array_column($manifest, 'path');
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
        $options['media'] = self::first_path_with_prefix($paths, '/question/');

        foreach (['answers', 'items'] as $collection) {
            if (!isset($options[$collection]) || !is_array($options[$collection])) {
                continue;
            }
            foreach ($options[$collection] as &$item) {
                $item['media'] = self::first_path_with_prefix(
                    $paths,
                    '/answers/' . $item['id'] . '/'
                );
            }
            unset($item);
        }
        return $options;
    }

    /**
     * Copy a complete Moodle file area, optionally adding/removing a prefix.
     *
     * Existing target paths are never deleted here. Identical content is skipped;
     * a conflicting target must be replaced explicitly by a post-commit caller.
     *
     * @param \context $sourcecontext Source context.
     * @param string $sourcearea Source area.
     * @param int $sourceitemid Source item.
     * @param \context $targetcontext Target context.
     * @param string $targetarea Target area.
     * @param int $targetitemid Target item.
     * @param string $addprefix Prefix to add below target root.
     * @param string $stripprefix Prefix required and removed from source.
     * @return void
     */
    public static function copy_area(
        \context $sourcecontext,
        string $sourcearea,
        int $sourceitemid,
        \context $targetcontext,
        string $targetarea,
        int $targetitemid,
        string $addprefix = '/',
        string $stripprefix = '/'
    ): void {
        self::require_area_or_template($targetarea);
        $addprefix = self::correct_prefix($addprefix);
        $fs = get_file_storage();
        $stripprefix = self::correct_prefix($stripprefix);
        $sourcefiles = self::copyable_files(
            $sourcecontext,
            $sourcearea,
            $sourceitemid,
            $stripprefix
        );
        foreach ($sourcefiles as $file) {
            $suffix = substr($file->get_filepath(), strlen($stripprefix));
            $targetpath = file_correct_filepath($addprefix . $suffix);
            $existing = $fs->get_file(
                $targetcontext->id,
                'mod_quizgeist',
                $targetarea,
                $targetitemid,
                $targetpath,
                $file->get_filename()
            );
            if ($existing) {
                if (hash_equals($existing->get_contenthash(), $file->get_contenthash())) {
                    continue;
                }
                throw new \invalid_parameter_exception(
                    'Target media already exists; replace it only after the DB commit.'
                );
            }
            $fs->create_file_from_storedfile([
                'contextid' => $targetcontext->id,
                'component' => 'mod_quizgeist',
                'filearea' => $targetarea,
                'itemid' => $targetitemid,
                'filepath' => $targetpath,
                'filename' => $file->get_filename(),
            ], $file);
        }
    }

    /**
     * Validate every file that would be selected by copy_area(), without mutation.
     *
     * @param \context $sourcecontext Source context.
     * @param string $sourcearea Source area.
     * @param int $sourceitemid Source item.
     * @param string $stripprefix Prefix required and removed from source.
     * @return void
     */
    public static function validate_copy_area(
        \context $sourcecontext,
        string $sourcearea,
        int $sourceitemid,
        string $stripprefix = '/'
    ): void {
        self::copyable_files(
            $sourcecontext,
            $sourcearea,
            $sourceitemid,
            self::correct_prefix($stripprefix)
        );
    }

    /**
     * Delete one complete stored-file area.
     *
     * Final referenced areas must be deleted only after the owning DB transaction
     * committed. Moodle DB rollback cannot restore stored-file contents.
     *
     * @param \context $context Context.
     * @param string $area Area.
     * @param int $itemid Item ID.
     * @return void
     */
    public static function delete_area(\context $context, string $area, int $itemid): void {
        self::require_area_or_template($area);
        get_file_storage()->delete_area_files($context->id, 'mod_quizgeist', $area, $itemid);
    }

    /**
     * Store one importer-supplied byte stream behind the same delivery gate as
     * manually uploaded editor media.
     *
     * The importer never trusts an archive MIME declaration. MIME is detected
     * from the bytes, checked against the filename extension allowlist, and SVG
     * content is parsed by is_safe_for_delivery() before the file survives.
     *
     * @param \context_module $context Target activity context.
     * @param string $area importmedia, questionmedia, background or logo.
     * @param int $itemid Owning import/question item ID.
     * @param string $filepath Canonical Moodle filepath.
     * @param string $filename Basename.
     * @param string $content Complete bounded file bytes.
     * @return \stored_file
     */
    public static function store_verified_import_content(
        \context_module $context,
        string $area,
        int $itemid,
        string $filepath,
        string $filename,
        string $content
    ): \stored_file {
        global $CFG;

        if (!in_array($area, [
            self::IMPORT_AREA,
            'questionmedia',
            'background',
            'logo',
        ], true)) {
            throw new \invalid_parameter_exception('Unsupported import media area.');
        }
        if ($itemid < 0
                || str_contains($filepath, '..')
                || str_contains($filepath, '\\')
                || str_contains($filepath, "\0")) {
            throw new \invalid_parameter_exception('Unsafe import media path.');
        }
        $filepath = file_correct_filepath('/' . trim($filepath, '/') . '/');
        $cleanfilename = clean_param($filename, PARAM_FILE);
        if ($cleanfilename === ''
                || $cleanfilename === '.'
                || $cleanfilename === '..'
                || $cleanfilename !== basename($cleanfilename)) {
            throw new \invalid_parameter_exception('Unsafe import media filename.');
        }

        require_once($CFG->libdir . '/filelib.php');
        $mimetype = self::preflight_import_content($context, $cleanfilename, $content);

        $fs = get_file_storage();
        $existing = $fs->get_file(
            $context->id,
            'mod_quizgeist',
            $area,
            $itemid,
            $filepath,
            $cleanfilename
        );
        $contenthash = sha1($content);
        if ($existing) {
            if (hash_equals($existing->get_contenthash(), $contenthash)
                    && self::is_safe_for_delivery($existing)) {
                return $existing;
            }
            throw new \invalid_parameter_exception('Conflicting import media file already exists.');
        }

        $file = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_quizgeist',
            'filearea' => $area,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $cleanfilename,
            'mimetype' => $mimetype,
        ], $content);
        if (!self::is_safe_for_delivery($file)) {
            $file->delete();
            throw new \invalid_parameter_exception('Import media content failed the safety gate.');
        }
        return $file;
    }

    /**
     * Run the byte-, size-, extension- and SVG-gate without retaining a file.
     *
     * Dry-run imports use the same implementation as the mutating path. A
     * short-lived stored file is necessary for the central SVG DOM parser; it
     * lives only in a fresh draft item and is deleted in all cases.
     *
     * @param \context $context Course-module or course context.
     * @param string $filename Basename.
     * @param string $content Complete bounded bytes.
     * @return string Detected, accepted MIME type.
     */
    public static function preflight_import_content(
        \context $context,
        string $filename,
        string $content
    ): string {
        global $USER;

        $cleanfilename = clean_param($filename, PARAM_FILE);
        if ($cleanfilename === '' || $cleanfilename !== basename($cleanfilename)) {
            throw new \invalid_parameter_exception('Unsafe import media filename.');
        }
        $options = self::file_options('questionmedia', $context, false);
        $filesize = strlen($content);
        if ($filesize < 1
                || ($options['maxbytes'] > 0 && $filesize > $options['maxbytes'])) {
            throw new \invalid_parameter_exception('Import media file exceeds the upload limit.');
        }
        $mimetype = self::detect_content_mimetype($content);
        if (!self::is_allowed_delivery_type($cleanfilename, $mimetype)) {
            throw new \invalid_parameter_exception('Import media MIME type is not permitted.');
        }
        if ($mimetype !== 'image/svg+xml') {
            return $mimetype;
        }

        if (empty($USER->id)) {
            throw new \coding_exception('SVG import preflight requires an authenticated Moodle user.');
        }
        $fs = get_file_storage();
        $usercontext = \context_user::instance((int)$USER->id);
        $draftitemid = file_get_unused_draft_itemid();
        $temp = $fs->create_file_from_string([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $cleanfilename,
            'mimetype' => $mimetype,
        ], $content);
        try {
            if (!self::is_safe_for_delivery($temp)) {
                throw new \invalid_parameter_exception('Import SVG content failed the safety gate.');
            }
        } finally {
            // The item ID was proven unused in this exact user context. Remove
            // both the temporary SVG and Moodle's directory placeholder.
            $fs->delete_area_files(
                $usercontext->id,
                'user',
                'draft',
                $draftitemid
            );
        }
        return $mimetype;
    }

    /**
     * Copy one already verified provenance file into a semantic editor slot.
     *
     * @param \stored_file $source Private importmedia file.
     * @param \context_module $context Target context.
     * @param string $area questionmedia or background.
     * @param int $itemid Target question/activity item.
     * @param string $filepath Target semantic path.
     * @return \stored_file
     */
    public static function copy_verified_import_file(
        \stored_file $source,
        \context_module $context,
        string $area,
        int $itemid,
        string $filepath
    ): \stored_file {
        if ($source->get_contextid() !== $context->id
                || $source->get_component() !== 'mod_quizgeist'
                || $source->get_filearea() !== self::IMPORT_AREA
                || !in_array($area, ['questionmedia', 'background'], true)
                || !self::is_safe_for_delivery($source)) {
            throw new \invalid_parameter_exception('Unverified import media source.');
        }
        return self::store_verified_import_content(
            $context,
            $area,
            $itemid,
            $filepath,
            $source->get_filename(),
            $source->get_content()
        );
    }

    /**
     * Validate draft ownership, type, references and count.
     *
     * @param int $draftitemid Draft ID.
     * @param string $area Target area.
     * @param \context $context Target context.
     * @param int $maxfiles Maximum files.
     * @return \stored_file[]
     */
    private static function validated_draft_files(
        int $draftitemid,
        string $area,
        \context $context,
        int $maxfiles
    ): array {
        global $SESSION, $USER;

        if ($draftitemid <= 0) {
            throw new \invalid_parameter_exception('Invalid draft item ID.');
        }
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $allfiles = array_values($fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            $draftitemid,
            'id ASC',
            true
        ));
        if (!$allfiles && empty($SESSION->quizgeistmediadrafts[$draftitemid])) {
            throw new \invalid_parameter_exception('Draft media does not belong to the current user.');
        }
        $files = array_values(array_filter(
            $allfiles,
            static fn(\stored_file $file): bool => !$file->is_directory()
        ));
        if (count($files) > $maxfiles) {
            throw new \invalid_parameter_exception('Too many media files in draft area.');
        }
        $options = self::file_options($area, $context, false);
        $types = new \core_form\filetypes_util();
        $allowlist = $types->normalize_file_types($options['accepted_types']);
        $totalsize = 0;
        foreach ($files as $file) {
            $totalsize += (int)$file->get_filesize();
            if ($file->is_external_file()
                    || !$types->is_allowed_file_type($file->get_filename(), $allowlist)
                    || ($options['maxbytes'] > 0 && $file->get_filesize() > $options['maxbytes'])
                    || !self::is_safe_for_delivery($file)) {
                throw new \invalid_parameter_exception('Draft media file is not permitted.');
            }
        }
        if ($options['areamaxbytes'] > 0 && $totalsize > $options['areamaxbytes']) {
            throw new \invalid_parameter_exception('Draft media area is too large.');
        }
        return $files;
    }

    /**
     * Reject orphan paths and more than one file per semantic slot.
     *
     * @param \stored_file[] $files Files.
     * @param string $area Target area.
     * @return void
     */
    private static function assert_canonical_paths(array $files, string $area): void {
        $slots = [];
        foreach ($files as $file) {
            $filepath = $file->get_filepath();
            if ($area === 'questionmedia') {
                $canonical = $filepath === '/question/'
                    || (bool)preg_match('/^\/answers\/[a-z][a-z0-9_-]{0,31}\/$/D', $filepath);
            } else {
                $canonical = $filepath === '/';
            }
            if (!$canonical || isset($slots[$filepath])) {
                throw new \invalid_parameter_exception('Draft media path is not canonical.');
            }
            $slots[$filepath] = true;
        }
    }

    /**
     * Check a stored file before it is copied or served from the Moodle origin.
     *
     * This also covers files introduced by restore or older plugin versions,
     * which did not necessarily pass through the current draft validator.
     *
     * @param \stored_file $file Stored file.
     * @return bool
     */
    public static function is_safe_for_delivery(\stored_file $file): bool {
        if (!self::is_allowed_delivery_type(
            $file->get_filename(),
            (string)$file->get_mimetype()
        )) {
            return false;
        }
        return self::safe_svg($file);
    }

    /**
     * Check the shared filename-extension and MIME allowlist.
     *
     * Restore code can use this before retaining related files; delivery performs
     * the additional content-based SVG check through is_safe_for_delivery().
     *
     * @param string $filename Filename.
     * @param string $mimetype Stored MIME type.
     * @return bool
     */
    public static function is_allowed_delivery_type(
        string $filename,
        string $mimetype
    ): bool {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimetype = strtolower(trim($mimetype));
        return isset(self::DELIVERY_TYPES[$extension])
            && in_array($mimetype, self::DELIVERY_TYPES[$extension], true);
    }

    /**
     * Apply a small defence-in-depth screen to uploaded SVG documents.
     *
     * SVGs are required by the P2 fixtures and editor, but are served from the
     * Moodle origin. Reject executable or external-resource constructs before
     * they enter a final file area.
     *
     * @param \stored_file $file File.
     * @return bool
     */
    private static function safe_svg(\stored_file $file): bool {
        $extensionissvg = strtolower(
            pathinfo($file->get_filename(), PATHINFO_EXTENSION)
        ) === 'svg';
        $mimetypeissvg = strtolower(trim((string)$file->get_mimetype())) === 'image/svg+xml';
        if (!$extensionissvg && !$mimetypeissvg) {
            return true;
        }

        $cache = null;
        $cachekey = self::SVG_CACHE_VERSION . $file->get_contenthash();
        try {
            $cache = \cache::make('mod_quizgeist', 'svgsafety');
            $cached = $cache->get($cachekey);
            if ($cached !== false) {
                return (int)$cached === 1;
            }
        } catch (\Throwable) {
            // During an upgrade the new cache definition may not be available yet.
            $cache = null;
        }

        $safe = self::safe_svg_uncached($file);
        if ($cache !== null) {
            try {
                $cache->set($cachekey, $safe ? 1 : 0);
            } catch (\Throwable) {
                // Cache availability must never turn a valid upload into an error.
            }
        }
        return $safe;
    }

    /**
     * Parse and inspect one SVG whose contenthash was not cached yet.
     *
     * @param \stored_file $file SVG file.
     * @return bool
     */
    private static function safe_svg_uncached(\stored_file $file): bool {
        if ($file->get_filesize() > 2 * 1024 * 1024) {
            return false;
        }
        $content = $file->get_content();
        if (!is_string($content)
                || trim($content) === ''
                || !class_exists(\DOMDocument::class)
                || preg_match('/<!(?:DOCTYPE|ENTITY)\b|<\?xml-stylesheet\b/i', $content) === 1
                || str_contains($content, '\\')) {
            return false;
        }

        $previouserrors = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;
        try {
            $loaded = $document->loadXML(
                $content,
                LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT
            );
        } catch (\Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previouserrors);
        }
        if (!$loaded || $document->doctype !== null || !$document->documentElement) {
            return false;
        }
        $root = $document->documentElement;
        if (strtolower($root->localName) !== 'svg'
                || !self::safe_svg_element_namespace($root)) {
            return false;
        }

        $xpath = new \DOMXPath($document);
        $processinginstructions = $xpath->query('//processing-instruction()');
        if ($processinginstructions === false || $processinginstructions->length > 0) {
            return false;
        }

        $forbiddenelements = [
            'script', 'foreignobject', 'iframe', 'object', 'embed',
            'style', 'link', 'handler', 'listener',
            'audio', 'video', 'source', 'meta',
            'animate', 'animatecolor', 'animatemotion', 'animatetransform', 'set', 'discard',
        ];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!$element instanceof \DOMElement || !self::safe_svg_element_namespace($element)) {
                return false;
            }
            if (in_array(strtolower($element->localName), $forbiddenelements, true)) {
                return false;
            }
            foreach ($element->attributes as $attribute) {
                if (!$attribute instanceof \DOMAttr) {
                    return false;
                }
                if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                    if (!in_array($attribute->value, [
                        'http://www.w3.org/2000/svg',
                        'http://www.w3.org/1999/xlink',
                    ], true)) {
                        return false;
                    }
                    continue;
                }
                $localname = strtolower($attribute->localName);
                if (str_starts_with($localname, 'on')
                        || in_array($localname, ['src', 'base'], true)) {
                    return false;
                }
                if ($localname === 'href'
                        && !preg_match(
                            '/^#[A-Za-z_][A-Za-z0-9_.:-]*$/D',
                            trim($attribute->value)
                        )) {
                    return false;
                }
                if (!self::safe_svg_attribute_value($attribute->value)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Allow only SVG elements in the SVG namespace (or an unqualified SVG tree).
     *
     * @param \DOMElement $element SVG element.
     * @return bool
     */
    private static function safe_svg_element_namespace(\DOMElement $element): bool {
        return $element->namespaceURI === null
            || $element->namespaceURI === ''
            || $element->namespaceURI === 'http://www.w3.org/2000/svg';
    }

    /**
     * Reject executable CSS and every non-fragment resource reference.
     *
     * Attribute values are supplied by DOM and therefore already have XML
     * character references decoded. CSS backslash escapes are rejected before
     * parsing the document.
     *
     * @param string $value Decoded attribute value.
     * @return bool
     */
    private static function safe_svg_attribute_value(string $value): bool {
        $normalised = preg_replace('/\/\*.*?\*\//su', '', $value);
        if (!is_string($normalised)
                || preg_match(
                    '/@(?:import|font-face|namespace|supports|document)\b'
                    . '|\bexpression\s*\('
                    . '|\bbehavior\s*:'
                    . '|-moz-binding\b/iu',
                    $normalised
                )) {
            return false;
        }
        if (preg_match_all('/\burl\s*\(\s*([^)]+?)\s*\)/isu', $normalised, $urlmatches)) {
            foreach ($urlmatches[1] as $url) {
                $url = trim($url, " \t\n\r\0\x0B\"'");
                if (!preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/D', $url)) {
                    return false;
                }
            }
        }
        $resources = preg_replace(
            '/[\x00-\x20\x7F]+/u',
            '',
            $normalised
        );
        return is_string($resources) && !preg_match(
            '/\b(?:https?|data|javascript|file)\s*:|(?<!:)\/\//iu',
            $resources
        );
    }

    /**
     * Resolve and validate files selected for one area copy.
     *
     * @param \context $sourcecontext Source context.
     * @param string $sourcearea Source area.
     * @param int $sourceitemid Source item.
     * @param string $stripprefix Canonical source prefix.
     * @return \stored_file[]
     */
    private static function copyable_files(
        \context $sourcecontext,
        string $sourcearea,
        int $sourceitemid,
        string $stripprefix
    ): array {
        self::require_area_or_template($sourcearea);
        $sourcefiles = array_values(array_filter(
            get_file_storage()->get_area_files(
                $sourcecontext->id,
                'mod_quizgeist',
                $sourcearea,
                $sourceitemid,
                'id ASC',
                false
            ),
            static fn(\stored_file $file): bool => str_starts_with(
                $file->get_filepath(),
                $stripprefix
            )
        ));
        foreach ($sourcefiles as $file) {
            if (!self::is_safe_for_delivery($file)) {
                throw new \invalid_parameter_exception('Source media file is not permitted.');
            }
        }
        return $sourcefiles;
    }

    /**
     * Build a deterministic path/name/content fingerprint map.
     *
     * @param iterable<\stored_file> $files Stored files.
     * @return array<string, string>
     */
    private static function file_fingerprints(iterable $files): array {
        $fingerprints = [];
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $fingerprints[$file->get_filepath() . $file->get_filename()]
                = $file->get_contenthash();
        }
        ksort($fingerprints, SORT_STRING);
        return $fingerprints;
    }

    /**
     * Resolve a target to a final filepath.
     *
     * @param string $area Area.
     * @param string $target Target.
     * @return string
     */
    private static function target_path(string $area, string $target): string {
        self::require_area($area);
        if ($area !== 'questionmedia') {
            if ($target !== '' && $target !== 'appearance') {
                throw new \invalid_parameter_exception('Invalid appearance media target.');
            }
            return '/';
        }
        if ($target === '' || $target === 'question') {
            return '/question/';
        }
        if (preg_match('/^answers\/([a-z][a-z0-9_-]{0,31})$/D', $target, $matches)) {
            return '/answers/' . $matches[1] . '/';
        }
        throw new \invalid_parameter_exception('Invalid question media target.');
    }

    /**
     * Find first media path below a semantic prefix.
     *
     * @param string[] $paths Paths.
     * @param string $prefix Prefix.
     * @return string|null
     */
    private static function first_path_with_prefix(array $paths, string $prefix): ?string {
        foreach ($paths as $path) {
            if (str_starts_with($path, $prefix)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Require a module file area.
     *
     * @param string $area Area.
     * @return void
     */
    private static function require_area(string $area): void {
        if (!in_array($area, self::MODULE_AREAS, true)) {
            throw new \invalid_parameter_exception('Unsupported Quizgeist media area.');
        }
    }

    /**
     * Require a known module or site-template area.
     *
     * @param string $area Area.
     * @return void
     */
    private static function require_area_or_template(string $area): void {
        if ($area !== 'templatemedia' && $area !== self::IMPORT_AREA) {
            self::require_area($area);
        }
    }

    /**
     * Detect a media MIME from bytes without trusting an upload/archive header.
     *
     * @param string $content File bytes.
     * @return string
     */
    private static function detect_content_mimetype(string $content): string {
        if (!class_exists(\finfo::class)) {
            throw new \coding_exception('The PHP fileinfo extension is required for media imports.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower(trim((string)$finfo->buffer($content)));
        return match ($detected) {
            'image/svg', 'application/svg+xml', 'text/xml' => 'image/svg+xml',
            'audio/x-wav', 'audio/wave' => 'audio/x-wav',
            default => $detected,
        };
    }

    /**
     * Canonicalise a folder prefix.
     *
     * @param string $prefix Prefix.
     * @return string
     */
    private static function correct_prefix(string $prefix): string {
        if ($prefix === '' || $prefix === '/') {
            return '/';
        }
        if (str_contains($prefix, '..') || str_contains($prefix, '\\')) {
            throw new \invalid_parameter_exception('Unsafe media path prefix.');
        }
        return file_correct_filepath('/' . trim($prefix, '/') . '/');
    }
}
