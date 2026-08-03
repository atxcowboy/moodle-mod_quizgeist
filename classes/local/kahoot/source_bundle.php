<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Bounded, read-only access to rescued Kahoot source bundles.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\kahoot;

defined('MOODLE_INTERNAL') || die();

/**
 * Opens one Kahoot JSON file or a directory/ZIP export without network access.
 *
 * Supported directory and ZIP layouts are:
 *
 * - data/kahoots/*.json + data/media_map.json + data/media/*
 * - kahoots/*.json + media_map.json + media/*
 * - one standalone Kahoot JSON file (media are optional)
 *
 * Archive members are never extracted. Directory paths obtained from
 * media_map.json are resolved below the selected bundle root and symlinks are
 * rejected. URL-shaped map keys are identifiers only and are never fetched.
 */
final class source_bundle {

    /** Maximum number of Kahoot documents in one source. */
    public const MAX_KAHOOTS = 1000;

    /** Maximum number of questions in one Kahoot document. */
    public const MAX_QUESTIONS = 150;

    /** Maximum decoded source JSON size. */
    public const MAX_JSON_BYTES = 5 * 1024 * 1024;

    /** Maximum combined Kahoot JSON bytes retained by one bundle. */
    public const MAX_TOTAL_JSON_BYTES = 64 * 1024 * 1024;

    /** Maximum number of ZIP members. */
    public const MAX_ZIP_ENTRIES = 2048;

    /** Maximum compressed ZIP file size. */
    public const MAX_ZIP_BYTES = 128 * 1024 * 1024;

    /** Maximum total uncompressed ZIP size. */
    public const MAX_ZIP_UNCOMPRESSED_BYTES = 160 * 1024 * 1024;

    /** Maximum size of one non-JSON media member. */
    public const MAX_MEDIA_BYTES = 25 * 1024 * 1024;

    /** Maximum accepted expansion ratio for a sizeable ZIP member. */
    public const MAX_ZIP_EXPANSION_RATIO = 100;

    /** UUID shape used by rescued Kahoot records and their filenames. */
    private const UUID_PATTERN =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';

    /** Extensions the manual Quizgeist media pipeline can subsequently validate. */
    private const MEDIA_EXTENSIONS = [
        'png',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'svg',
        'mp4',
        'webm',
        'mp3',
        'ogg',
        'wav',
    ];

    /**
     * Valid documents and bounded per-document validation failures.
     *
     * @var array<int, array{
     *     sourceName:string,
     *     sha256:string,
     *     document?:array,
     *     failureCode?:string,
     *     sourceQuestionCount?:int
     * }>
     */
    private array $kahoots = [];

    /** Combined raw Kahoot JSON bytes accepted so far. */
    private int $totaljsonbytes = 0;

    /**
     * URL to internal source descriptor.
     *
     * @var array<string, array{sourceName:string,filename:string,size:int,kind:string,path?:string,index?:int}>
     */
    private array $media = [];

    /** @var array<string, string> Lazily calculated SHA-256 fingerprints. */
    private array $mediasha256 = [];

    /** @var \ZipArchive|null Open read-only archive. */
    private ?\ZipArchive $archive = null;

    /** @var bool Whether an opened archive has already been closed. */
    private bool $closed = false;

    /**
     * Construction is routed through open() so every source receives the same
     * lstat, realpath and format checks.
     */
    private function __construct() {
    }

    /**
     * Open a directory, standalone JSON file or ZIP archive.
     *
     * @param string $path Local source path.
     * @return self
     */
    public static function open(string $path): self {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        if ($path === '' || str_contains($path, "\0") || is_link($path)) {
            self::fail('unsafe_source_path');
        }
        $realpath = realpath($path);
        if ($realpath === false) {
            self::fail('source_not_found');
        }

        $bundle = new self();
        if (is_dir($realpath)) {
            $bundle->load_directory($realpath);
            return $bundle;
        }
        if (!is_file($realpath) || !is_readable($realpath)) {
            self::fail('source_not_readable');
        }

        $extension = strtolower((string)pathinfo($realpath, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            $bundle->load_standalone_json($realpath);
            return $bundle;
        }
        if ($extension === 'zip') {
            $bundle->load_zip($realpath);
            return $bundle;
        }
        self::fail('source_type_unsupported');
    }

    /**
     * Close a ZIP promptly instead of waiting for request shutdown.
     */
    public function __destruct() {
        if ($this->archive !== null && !$this->closed) {
            $this->archive->close();
            $this->closed = true;
        }
    }

    /**
     * Return decoded Kahoot documents and isolated failures in source-name order.
     *
     * @return array<int, array{
     *     sourceName:string,
     *     sha256:string,
     *     document?:array,
     *     failureCode?:string,
     *     sourceQuestionCount?:int
     * }>
     */
    public function kahoots(): array {
        return $this->kahoots;
    }

    /**
     * Return the number of locally mapped media URLs.
     *
     * @return int
     */
    public function media_count(): int {
        return count($this->media);
    }

    /**
     * Test whether one exact source URL has a local bundle member.
     *
     * @param string $sourceurl Kahoot media URL used as map key.
     * @return bool
     */
    public function has_media(string $sourceurl): bool {
        return isset($this->media[$sourceurl]);
    }

    /**
     * Describe one mapped media file without exposing an unsafe filesystem path.
     *
     * SHA-256 is calculated lazily over the exact bytes returned by read_media().
     *
     * @param string $sourceurl Kahoot media URL used as map key.
     * @return array{sourceName:string,filename:string,size:int,sha256:string}|null
     */
    public function media_descriptor(string $sourceurl): ?array {
        if (!isset($this->media[$sourceurl])) {
            return null;
        }
        if (!isset($this->mediasha256[$sourceurl])) {
            $content = $this->read_media($sourceurl);
            if (!is_string($content)) {
                self::fail('media_read_failed');
            }
            $this->mediasha256[$sourceurl] = hash('sha256', $content);
        }
        $descriptor = $this->media[$sourceurl];
        return [
            'sourceName' => $descriptor['sourceName'],
            'filename' => $descriptor['filename'],
            'size' => $descriptor['size'],
            'sha256' => $this->mediasha256[$sourceurl],
        ];
    }

    /**
     * Read one exact local/ZIP media object.
     *
     * This method deliberately has no URL fallback: a source URL absent from
     * media_map.json always returns null.
     *
     * @param string $sourceurl Kahoot media URL used as map key.
     * @return string|null
     */
    public function read_media(string $sourceurl): ?string {
        if (!isset($this->media[$sourceurl])) {
            return null;
        }
        $descriptor = $this->media[$sourceurl];
        if ($descriptor['kind'] === 'directory') {
            $path = $descriptor['path'] ?? '';
            if ($path === ''
                    || is_link($path)
                    || !is_file($path)
                    || filesize($path) !== $descriptor['size']) {
                self::fail('media_changed_after_validation');
            }
            $content = file_get_contents($path);
        } else {
            if ($this->archive === null || $this->closed || !isset($descriptor['index'])) {
                self::fail('archive_closed');
            }
            $content = $this->archive->getFromIndex(
                $descriptor['index'],
                $descriptor['size'] + 1,
                \ZipArchive::FL_UNCHANGED
            );
        }
        if (!is_string($content) || strlen($content) !== $descriptor['size']) {
            self::fail('media_read_failed');
        }
        return $content;
    }

    /**
     * Load a supported directory layout.
     *
     * @param string $root Canonical input directory.
     * @return void
     */
    private function load_directory(string $root): void {
        [$kahootdir, $mediamappath, $anchors] = self::directory_layout($root);
        self::require_directory($kahootdir);

        $filenames = scandir($kahootdir);
        if (!is_array($filenames)) {
            self::fail('kahoot_directory_unreadable');
        }
        $jsonpaths = [];
        foreach ($filenames as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }
            $path = $kahootdir . DIRECTORY_SEPARATOR . $filename;
            if (strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)) !== 'json') {
                continue;
            }
            if (is_link($path) || !is_file($path) || !is_readable($path)) {
                self::fail('unsafe_kahoot_file');
            }
            $realpath = realpath($path);
            if ($realpath === false || !self::is_below($realpath, $kahootdir)) {
                self::fail('unsafe_kahoot_file');
            }
            $jsonpaths[$filename] = $realpath;
        }
        ksort($jsonpaths, SORT_STRING);
        if (!$jsonpaths || count($jsonpaths) > self::MAX_KAHOOTS) {
            self::fail('kahoot_count_invalid');
        }

        $seen = [];
        foreach ($jsonpaths as $filename => $path) {
            try {
                $bytes = self::read_bounded_file(
                    $path,
                    self::MAX_JSON_BYTES,
                    'kahoot_json_too_large'
                );
            } catch (\UnexpectedValueException $exception) {
                if ($exception->getMessage() !== 'kahoot_json_too_large') {
                    throw $exception;
                }
                $this->add_kahoot_failure(
                    $filename,
                    '',
                    'kahoot_json_too_large'
                );
                continue;
            }
            $this->add_kahoot($filename, $bytes, $seen);
        }

        if ($mediamappath !== null) {
            if (is_link($mediamappath)) {
                self::fail('unsafe_media_map');
            }
            $maprealpath = realpath($mediamappath);
            if ($maprealpath === false || !is_file($maprealpath) || !is_readable($maprealpath)) {
                self::fail('media_map_unreadable');
            }
            $bytes = self::read_bounded_file(
                $maprealpath,
                self::MAX_JSON_BYTES,
                'media_map_too_large'
            );
            $map = self::decode_media_map($bytes);
            $this->load_directory_media($map, $anchors);
        }
    }

    /**
     * Load one Kahoot JSON and, when present, its adjacent export media map.
     *
     * @param string $path Canonical JSON path.
     * @return void
     */
    private function load_standalone_json(string $path): void {
        $seen = [];
        try {
            $bytes = self::read_bounded_file(
                $path,
                self::MAX_JSON_BYTES,
                'kahoot_json_too_large'
            );
            $this->add_kahoot(basename($path), $bytes, $seen);
        } catch (\UnexpectedValueException $exception) {
            if ($exception->getMessage() !== 'kahoot_json_too_large') {
                throw $exception;
            }
            $this->add_kahoot_failure(
                basename($path),
                '',
                'kahoot_json_too_large'
            );
        }

        [$mediamappath, $anchors] = self::standalone_media_layout($path);
        if ($mediamappath === null) {
            return;
        }
        if (is_link($mediamappath)) {
            self::fail('unsafe_media_map');
        }
        $maprealpath = realpath($mediamappath);
        if ($maprealpath === false || !is_file($maprealpath) || !is_readable($maprealpath)) {
            self::fail('media_map_unreadable');
        }
        $mapbytes = self::read_bounded_file(
            $maprealpath,
            self::MAX_JSON_BYTES,
            'media_map_too_large'
        );
        $this->load_directory_media(self::decode_media_map($mapbytes), $anchors);
    }

    /**
     * Load and validate a ZIP without extracting members.
     *
     * @param string $path Canonical ZIP path.
     * @return void
     */
    private function load_zip(string $path): void {
        if (!class_exists(\ZipArchive::class)) {
            self::fail('zip_unavailable');
        }
        $archivesize = filesize($path);
        if (!is_int($archivesize) || $archivesize < 1 || $archivesize > self::MAX_ZIP_BYTES) {
            self::fail('zip_size_limit');
        }
        $this->archive = new \ZipArchive();
        $opened = $this->archive->open($path, \ZipArchive::RDONLY);
        if ($opened !== true) {
            self::fail('zip_invalid');
        }
        try {
            [$indices, $stats] = $this->validate_zip_members();
            [$kahootnames, $mediamapname, $anchorprefixes] =
                self::zip_layout(array_keys($indices));

            $seen = [];
            foreach ($kahootnames as $name) {
                $stat = $stats[$name];
                if ((int)$stat['size'] > self::MAX_JSON_BYTES) {
                    $this->add_kahoot_failure(
                        $name,
                        '',
                        'kahoot_json_too_large'
                    );
                    continue;
                }
                $bytes = $this->read_zip_member($indices[$name], (int)$stat['size']);
                $this->add_kahoot($name, $bytes, $seen);
            }

            if ($mediamapname !== null) {
                $stat = $stats[$mediamapname];
                if ((int)$stat['size'] > self::MAX_JSON_BYTES) {
                    self::fail('media_map_too_large');
                }
                $mapbytes = $this->read_zip_member(
                    $indices[$mediamapname],
                    (int)$stat['size']
                );
                $this->load_zip_media(
                    self::decode_media_map($mapbytes),
                    $anchorprefixes,
                    $indices,
                    $stats
                );
            }
        } catch (\Throwable $exception) {
            $this->archive->close();
            $this->closed = true;
            throw $exception;
        }
    }

    /**
     * Validate all ZIP members before any document is exposed.
     *
     * @return array{0:array<string,int>,1:array<string,array>}
     */
    private function validate_zip_members(): array {
        if ($this->archive === null
                || $this->archive->numFiles < 1
                || $this->archive->numFiles > self::MAX_ZIP_ENTRIES) {
            self::fail('zip_entry_limit');
        }

        $indices = [];
        $stats = [];
        $casefolded = [];
        $total = 0;
        for ($index = 0; $index < $this->archive->numFiles; $index++) {
            $stat = $this->archive->statIndex($index, \ZipArchive::FL_UNCHANGED);
            if (!is_array($stat)
                    || !isset($stat['name'], $stat['size'], $stat['comp_size'])) {
                self::fail('zip_member_invalid');
            }
            $name = (string)$stat['name'];
            $isdirectory = str_ends_with($name, '/');
            self::require_safe_relative_name(rtrim($name, '/'));
            if ($isdirectory && (int)$stat['size'] !== 0) {
                self::fail('zip_directory_has_content');
            }

            $lookup = strtolower($name);
            if (isset($indices[$name]) || isset($casefolded[$lookup])) {
                self::fail('zip_member_duplicate');
            }
            $casefolded[$lookup] = true;

            $size = (int)$stat['size'];
            $compressedsize = (int)$stat['comp_size'];
            if ($size < 0
                    || $compressedsize < 0
                    || $size > self::MAX_MEDIA_BYTES) {
                self::fail('zip_member_size_limit');
            }
            $total += $size;
            if ($total > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                self::fail('zip_size_limit');
            }
            if ($size > 1024 * 1024
                    && ($compressedsize === 0
                        || ($size / max(1, $compressedsize))
                            > self::MAX_ZIP_EXPANSION_RATIO)) {
                self::fail('zip_ratio_limit');
            }

            $method = isset($stat['comp_method'])
                ? (int)$stat['comp_method']
                : \ZipArchive::CM_DEFAULT;
            if (!$isdirectory
                    && !in_array(
                        $method,
                        [\ZipArchive::CM_STORE, \ZipArchive::CM_DEFLATE],
                        true
                    )) {
                self::fail('zip_method_unsupported');
            }
            $this->require_regular_zip_member($index, $isdirectory);

            if (!$isdirectory) {
                $indices[$name] = $index;
                $stats[$name] = $stat;
            }
        }
        return [$indices, $stats];
    }

    /**
     * Reject archive links and special files when Unix mode data is available.
     *
     * @param int $index Member index.
     * @param bool $isdirectory Directory marker.
     * @return void
     */
    private function require_regular_zip_member(int $index, bool $isdirectory): void {
        if ($this->archive === null
                || !method_exists($this->archive, 'getExternalAttributesIndex')) {
            return;
        }
        $operationsystem = 0;
        $attributes = 0;
        if (!$this->archive->getExternalAttributesIndex(
            $index,
            $operationsystem,
            $attributes,
            \ZipArchive::FL_UNCHANGED
        ) || $operationsystem !== \ZipArchive::OPSYS_UNIX) {
            return;
        }
        $filetype = ($attributes >> 16) & 0170000;
        $allowedtypes = $isdirectory ? [0, 0040000] : [0, 0100000];
        if (!in_array($filetype, $allowedtypes, true)) {
            self::fail('zip_member_unsafe_type');
        }
    }

    /**
     * Insert one validated Kahoot document or one bounded validation failure.
     *
     * @param string $sourcename Stable local/member name.
     * @param string $bytes Exact JSON bytes.
     * @param array<string, bool> $seen Mutable UUID set.
     * @return void
     */
    private function add_kahoot(string $sourcename, string $bytes, array &$seen): void {
        $bytesize = strlen($bytes);
        if ($bytesize > self::MAX_TOTAL_JSON_BYTES - $this->totaljsonbytes) {
            self::fail('kahoot_json_total_too_large');
        }
        $this->totaljsonbytes += $bytesize;
        $sha256 = hash('sha256', $bytes);
        try {
            $document = self::decode_json_object($bytes, 'kahoot_json_invalid');
        } catch (\UnexpectedValueException $exception) {
            if ($exception->getMessage() !== 'kahoot_json_invalid') {
                throw $exception;
            }
            $this->add_kahoot_failure(
                $sourcename,
                $sha256,
                'kahoot_json_invalid'
            );
            return;
        }

        $questions = $document['questions'] ?? null;
        $sourcequestioncount = is_array($questions) && array_is_list($questions)
            ? count($questions)
            : null;
        $uuid = $document['uuid'] ?? null;
        if (!is_string($uuid) || preg_match(self::UUID_PATTERN, $uuid) !== 1) {
            $this->add_kahoot_failure(
                $sourcename,
                $sha256,
                'kahoot_uuid_invalid',
                $sourcequestioncount
            );
            return;
        }
        $uuid = strtolower($uuid);
        if (isset($seen[$uuid])) {
            $this->add_kahoot_failure(
                $sourcename,
                $sha256,
                'kahoot_uuid_duplicate',
                $sourcequestioncount
            );
            return;
        }
        $seen[$uuid] = true;

        $basename = (string)pathinfo(basename($sourcename), PATHINFO_FILENAME);
        if (preg_match(self::UUID_PATTERN, $basename) === 1
                && strtolower($basename) !== $uuid) {
            $this->add_kahoot_failure(
                $sourcename,
                $sha256,
                'kahoot_uuid_filename_mismatch',
                $sourcequestioncount
            );
            return;
        }
        if (!is_array($questions)
                || !array_is_list($questions)
                || count($questions) > self::MAX_QUESTIONS) {
            $this->add_kahoot_failure(
                $sourcename,
                $sha256,
                'kahoot_questions_invalid',
                $sourcequestioncount
            );
            return;
        }
        $document['uuid'] = $uuid;
        $this->kahoots[] = [
            'sourceName' => $sourcename,
            'sha256' => $sha256,
            'document' => $document,
        ];
    }

    /**
     * Retain one fixed, bounded source failure without retaining malformed JSON.
     *
     * @param string $sourcename Stable local/member name.
     * @param string $sha256 Exact source fingerprint, or empty when not read.
     * @param string $failurecode Stable machine-readable validation code.
     * @param int|null $sourcequestioncount Known list length, if available.
     * @return void
     */
    private function add_kahoot_failure(
        string $sourcename,
        string $sha256,
        string $failurecode,
        ?int $sourcequestioncount = null
    ): void {
        $row = [
            'sourceName' => $sourcename,
            'sha256' => $sha256,
            'failureCode' => $failurecode,
        ];
        if ($sourcequestioncount !== null) {
            $row['sourceQuestionCount'] = $sourcequestioncount;
        }
        $this->kahoots[] = $row;
    }

    /**
     * Register directory media after resolving each path below an anchor.
     *
     * @param array<string, string> $map URL-to-relative-path map.
     * @param string[] $anchors Canonical roots accepted by the selected layout.
     * @return void
     */
    private function load_directory_media(array $map, array $anchors): void {
        foreach ($map as $sourceurl => $relative) {
            self::require_media_reference($sourceurl, $relative);
            $resolved = null;
            foreach ($anchors as $anchor) {
                $candidate = self::resolve_directory_member($anchor, $relative);
                if ($candidate !== null) {
                    $resolved = $candidate;
                    break;
                }
            }
            if ($resolved === null) {
                self::fail('media_map_target_missing');
            }
            $size = filesize($resolved);
            if (!is_int($size) || $size < 0 || $size > self::MAX_MEDIA_BYTES) {
                self::fail('media_size_limit');
            }
            $this->media[$sourceurl] = [
                'sourceName' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
                'filename' => basename($resolved),
                'size' => $size,
                'kind' => 'directory',
                'path' => $resolved,
            ];
        }
    }

    /**
     * Register ZIP media through exact validated member names.
     *
     * @param array<string, string> $map URL-to-relative-path map.
     * @param string[] $anchorprefixes ZIP layout roots.
     * @param array<string, int> $indices Member indices.
     * @param array<string, array> $stats Member stats.
     * @return void
     */
    private function load_zip_media(
        array $map,
        array $anchorprefixes,
        array $indices,
        array $stats
    ): void {
        foreach ($map as $sourceurl => $relative) {
            self::require_media_reference($sourceurl, $relative);
            $membername = null;
            foreach ($anchorprefixes as $prefix) {
                $candidate = $prefix . $relative;
                if (isset($indices[$candidate])) {
                    $membername = $candidate;
                    break;
                }
            }
            if ($membername === null) {
                self::fail('media_map_target_missing');
            }
            $stat = $stats[$membername];
            $size = (int)$stat['size'];
            if ($size < 0 || $size > self::MAX_MEDIA_BYTES) {
                self::fail('media_size_limit');
            }
            $this->media[$sourceurl] = [
                'sourceName' => $membername,
                'filename' => basename($membername),
                'size' => $size,
                'kind' => 'zip',
                'index' => $indices[$membername],
            ];
        }
    }

    /**
     * Read one ZIP member with an exact declared-length check.
     *
     * @param int $index Member index.
     * @param int $size Declared uncompressed size.
     * @return string
     */
    private function read_zip_member(int $index, int $size): string {
        if ($this->archive === null || $this->closed) {
            self::fail('archive_closed');
        }
        $content = $this->archive->getFromIndex(
            $index,
            $size + 1,
            \ZipArchive::FL_UNCHANGED
        );
        if (!is_string($content) || strlen($content) !== $size) {
            self::fail('zip_member_read_failed');
        }
        return $content;
    }

    /**
     * Resolve directory locations and media anchors for a supported layout.
     *
     * @param string $root Canonical input directory.
     * @return array{0:string,1:string|null,2:string[]}
     */
    private static function directory_layout(string $root): array {
        $nestedkahoots = $root . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'kahoots';
        if (is_dir($nestedkahoots)) {
            if (is_link($root . DIRECTORY_SEPARATOR . 'data')
                    || is_link($nestedkahoots)) {
                self::fail('unsafe_kahoot_directory');
            }
            $dataroot = $root . DIRECTORY_SEPARATOR . 'data';
            return [
                realpath($dataroot . DIRECTORY_SEPARATOR . 'kahoots') ?: '',
                is_file($dataroot . DIRECTORY_SEPARATOR . 'media_map.json')
                    ? $dataroot . DIRECTORY_SEPARATOR . 'media_map.json'
                    : null,
                [$root, $dataroot],
            ];
        }
        $childkahoots = $root . DIRECTORY_SEPARATOR . 'kahoots';
        if (is_dir($childkahoots)) {
            if (is_link($childkahoots)) {
                self::fail('unsafe_kahoot_directory');
            }
            $anchors = basename($root) === 'data'
                ? [dirname($root), $root]
                : [$root];
            return [
                realpath($root . DIRECTORY_SEPARATOR . 'kahoots') ?: '',
                is_file($root . DIRECTORY_SEPARATOR . 'media_map.json')
                    ? $root . DIRECTORY_SEPARATOR . 'media_map.json'
                    : null,
                $anchors,
            ];
        }
        if (basename($root) === 'kahoots') {
            $dataroot = dirname($root);
            $anchors = basename($dataroot) === 'data'
                ? [dirname($dataroot), $dataroot]
                : [$dataroot];
            return [
                $root,
                is_file($dataroot . DIRECTORY_SEPARATOR . 'media_map.json')
                    ? $dataroot . DIRECTORY_SEPARATOR . 'media_map.json'
                    : null,
                $anchors,
            ];
        }
        return [
            $root,
            is_file($root . DIRECTORY_SEPARATOR . 'media_map.json')
                ? $root . DIRECTORY_SEPARATOR . 'media_map.json'
                : null,
            [$root],
        ];
    }

    /**
     * Find the media map adjacent to one standalone document.
     *
     * @param string $path Canonical JSON path.
     * @return array{0:string|null,1:string[]}
     */
    private static function standalone_media_layout(string $path): array {
        $directory = dirname($path);
        if (basename($directory) === 'kahoots') {
            $dataroot = dirname($directory);
            $anchors = basename($dataroot) === 'data'
                ? [dirname($dataroot), $dataroot]
                : [$dataroot];
            $map = $dataroot . DIRECTORY_SEPARATOR . 'media_map.json';
            return [is_file($map) ? $map : null, $anchors];
        }
        $map = $directory . DIRECTORY_SEPARATOR . 'media_map.json';
        return [is_file($map) ? $map : null, [$directory]];
    }

    /**
     * Select exactly one supported layout inside a ZIP.
     *
     * @param string[] $names Validated member names.
     * @return array{0:string[],1:string|null,2:string[]}
     */
    private static function zip_layout(array $names): array {
        $nameindex = array_fill_keys($names, true);
        $candidates = [];
        foreach ($names as $name) {
            if (preg_match('~^(.*)data/media_map\.json$~D', $name, $matches)) {
                $prefix = $matches[1];
                $documents = self::zip_documents_below($names, $prefix . 'data/kahoots/');
                if ($documents) {
                    $candidates[] = [$documents, $name, [$prefix, $prefix . 'data/']];
                }
            } else if (preg_match('~^(.*)media_map\.json$~D', $name, $matches)) {
                $prefix = $matches[1];
                $documents = self::zip_documents_below($names, $prefix . 'kahoots/');
                if ($documents) {
                    $candidates[] = [$documents, $name, [$prefix]];
                }
            }
        }
        if (count($candidates) > 1) {
            self::fail('zip_layout_ambiguous');
        }
        if ($candidates) {
            return $candidates[0];
        }

        $groups = [];
        foreach ($names as $name) {
            if (preg_match('~^(.*data/kahoots/)[^/]+\.json$~Di', $name, $matches)
                    || preg_match('~^(.*kahoots/)[^/]+\.json$~Di', $name, $matches)) {
                $groups[$matches[1]][] = $name;
            }
        }
        if (count($groups) > 1) {
            self::fail('zip_layout_ambiguous');
        }
        if ($groups) {
            $documents = reset($groups);
            sort($documents, SORT_STRING);
            return [$documents, null, ['']];
        }

        $standalone = [];
        foreach (array_keys($nameindex) as $name) {
            if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) === 'json'
                    && basename($name) !== 'media_map.json') {
                $standalone[] = $name;
            }
        }
        if (count($standalone) !== 1) {
            self::fail('zip_layout_unsupported');
        }
        return [$standalone, null, ['']];
    }

    /**
     * Find UUID-named Kahoot JSON documents under one ZIP prefix.
     *
     * @param string[] $names Member names.
     * @param string $prefix Exact member prefix.
     * @return string[]
     */
    private static function zip_documents_below(array $names, string $prefix): array {
        $documents = [];
        foreach ($names as $name) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $tail = substr($name, strlen($prefix));
            if ($tail !== ''
                    && !str_contains($tail, '/')
                    && strtolower((string)pathinfo($tail, PATHINFO_EXTENSION)) === 'json') {
                $documents[] = $name;
            }
        }
        sort($documents, SORT_STRING);
        if (count($documents) > self::MAX_KAHOOTS) {
            self::fail('kahoot_count_invalid');
        }
        return $documents;
    }

    /**
     * Decode one JSON object with a bounded nesting depth.
     *
     * @param string $bytes JSON bytes.
     * @param string $errorcode Stable failure message.
     * @return array
     */
    private static function decode_json_object(string $bytes, string $errorcode): array {
        $trimmed = ltrim($bytes);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            self::fail($errorcode);
        }
        try {
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            self::fail($errorcode);
        }
        if (!is_array($decoded)) {
            self::fail($errorcode);
        }
        return $decoded;
    }

    /**
     * Decode a direct URL-to-path media map.
     *
     * @param string $bytes JSON bytes.
     * @return array<string, string>
     */
    private static function decode_media_map(string $bytes): array {
        $decoded = self::decode_json_object($bytes, 'media_map_invalid');
        $map = [];
        foreach ($decoded as $sourceurl => $relative) {
            if (!is_string($sourceurl)
                    || $sourceurl === ''
                    || strlen($sourceurl) > 8192
                    || !is_string($relative)) {
                self::fail('media_map_invalid');
            }
            $map[$sourceurl] = $relative;
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * Validate one media-map pair without interpreting the key as a URL.
     *
     * @param string $sourceurl Exact source identifier.
     * @param string $relative Relative local/member name.
     * @return void
     */
    private static function require_media_reference(string $sourceurl, string $relative): void {
        if ($sourceurl === ''
                || strlen($sourceurl) > 8192
                || preg_match('//u', $sourceurl) !== 1) {
            self::fail('media_map_invalid');
        }
        self::require_safe_relative_name($relative);
        $extension = strtolower((string)pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, self::MEDIA_EXTENSIONS, true)) {
            self::fail('media_extension_unsupported');
        }
    }

    /**
     * Resolve one safe map value below a directory anchor.
     *
     * @param string $anchor Canonical bundle root.
     * @param string $relative Validated slash-separated relative path.
     * @return string|null Canonical regular file, or null when absent.
     */
    private static function resolve_directory_member(string $anchor, string $relative): ?string {
        $anchorreal = realpath($anchor);
        if ($anchorreal === false || !is_dir($anchorreal) || is_link($anchor)) {
            self::fail('unsafe_media_anchor');
        }
        $segments = explode('/', $relative);
        $candidate = $anchorreal;
        foreach ($segments as $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                self::fail('unsafe_media_symlink');
            }
        }
        $realpath = realpath($candidate);
        if ($realpath === false) {
            return null;
        }
        if (!self::is_below($realpath, $anchorreal)
                || !is_file($realpath)
                || !is_readable($realpath)
                || is_link($realpath)) {
            self::fail('unsafe_media_path');
        }
        return $realpath;
    }

    /**
     * Reject ambiguous relative names shared by directory and ZIP sources.
     *
     * @param string $name Relative slash-separated path.
     * @return void
     */
    private static function require_safe_relative_name(string $name): void {
        if ($name === ''
                || strlen($name) > 1024
                || str_starts_with($name, '/')
                || str_contains($name, '\\')
                || str_contains($name, "\0")
                || str_contains($name, '//')
                || str_contains($name, ':')
                || preg_match('/%(?:2e|2f|5c)/i', $name)
                || preg_match('/[\x00-\x1F\x7F]/', $name)
                || preg_match('//u', $name) !== 1) {
            self::fail('unsafe_relative_path');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                self::fail('unsafe_relative_path');
            }
        }
    }

    /**
     * Require a regular non-symlink directory.
     *
     * @param string $path Canonical directory.
     * @return void
     */
    private static function require_directory(string $path): void {
        if ($path === '' || is_link($path) || !is_dir($path) || !is_readable($path)) {
            self::fail('unsafe_kahoot_directory');
        }
    }

    /**
     * Test whether a canonical file remains below a canonical directory.
     *
     * @param string $path Canonical candidate.
     * @param string $root Canonical root.
     * @return bool
     */
    private static function is_below(string $path, string $root): bool {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($path, $prefix);
    }

    /**
     * Read a regular file only after a reliable bounded-size check.
     *
     * @param string $path Canonical file.
     * @param int $maxbytes Consumer limit.
     * @param string $errorcode Limit failure.
     * @return string
     */
    private static function read_bounded_file(
        string $path,
        int $maxbytes,
        string $errorcode
    ): string {
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            self::fail('source_not_readable');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $maxbytes) {
            self::fail($errorcode);
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            self::fail('source_read_failed');
        }
        return $bytes;
    }

    /**
     * Throw one stable machine-readable reader error.
     *
     * @param string $code Stable code.
     * @return never
     */
    private static function fail(string $code): never {
        throw new \UnexpectedValueException($code);
    }
}
