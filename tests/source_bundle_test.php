<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for the bounded Kahoot source reader.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\kahoot\import_service;
use mod_quizgeist\local\kahoot\source_bundle;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers directory, standalone JSON and ZIP layouts without network access.
 */
final class source_bundle_test extends \advanced_testcase {

    /** Stable fixture UUID. */
    private const UUID = '236494fe-85a7-4f86-8372-e8a78764a604';

    /**
     * A rescued export root and its data subdirectory expose identical data.
     *
     * @return void
     */
    public function test_opens_export_directory_and_resolves_local_media(): void {
        $root = self::fixture_root();
        $kahootdir = $root . '/data/kahoots';
        $mediadir = $root . '/data/media/' . self::UUID;
        mkdir($kahootdir, 0700, true);
        mkdir($mediadir, 0700, true);

        $document = self::fixture_document();
        $jsonpath = $kahootdir . '/' . self::UUID . '.json';
        self::write_json($jsonpath, $document);
        $mediabytes = "\xFF\xD8\xFFfixture-jpeg";
        $mediapath = $mediadir . '/question.jpg';
        file_put_contents($mediapath, $mediabytes);
        self::write_json($root . '/data/media_map.json', [
            'https://media.kahoot.test/question' =>
                'data/media/' . self::UUID . '/question.jpg',
        ]);

        foreach ([$root, $root . '/data', $root . '/data/kahoots'] as $sourcepath) {
            $bundle = source_bundle::open($sourcepath);
            $this->assertCount(1, $bundle->kahoots());
            $this->assertSame(self::UUID, $bundle->kahoots()[0]['document']['uuid']);
            $this->assertSame(hash_file('sha256', $jsonpath), $bundle->kahoots()[0]['sha256']);
            $this->assertSame(1, $bundle->media_count());
            $this->assertTrue($bundle->has_media('https://media.kahoot.test/question'));
            $this->assertFalse($bundle->has_media('https://remote.invalid/not-fetched'));
            $this->assertNull($bundle->read_media('https://remote.invalid/not-fetched'));
            $this->assertSame(
                $mediabytes,
                $bundle->read_media('https://media.kahoot.test/question')
            );
            $this->assertSame([
                'sourceName' => 'data/media/' . self::UUID . '/question.jpg',
                'filename' => 'question.jpg',
                'size' => strlen($mediabytes),
                'sha256' => hash('sha256', $mediabytes),
            ], $bundle->media_descriptor('https://media.kahoot.test/question'));
        }

        $standalone = source_bundle::open($jsonpath);
        $this->assertCount(1, $standalone->kahoots());
        $this->assertSame($mediabytes, $standalone->read_media(
            'https://media.kahoot.test/question'
        ));
    }

    /**
     * Opening the exact UUID JSON used by CLI --datei keeps its export media.
     *
     * The second document proves that the JSON path selects exactly one
     * Kahoot, while media_map.json and data/media remain anchored to the same
     * validated export bundle.
     *
     * @return void
     */
    public function test_single_export_json_keeps_bundle_media(): void {
        $root = self::fixture_root();
        $kahootdir = $root . '/data/kahoots';
        $mediadir = $root . '/data/media/' . self::UUID;
        mkdir($kahootdir, 0700, true);
        mkdir($mediadir, 0700, true);

        $mediaurl = 'https://media.kahoot.test/cli-single-file';
        $document = self::fixture_document();
        $document['questions'][0]['image'] = $mediaurl;
        $jsonpath = $kahootdir . '/' . self::UUID . '.json';
        self::write_json($jsonpath, $document);

        $otheruuid = '1576dcd2-8c5d-4d8a-8d1b-6a95a34804f5';
        $otherdocument = self::fixture_document();
        $otherdocument['uuid'] = $otheruuid;
        self::write_json($kahootdir . '/' . $otheruuid . '.json', $otherdocument);

        $mediabytes = "\x89PNG\r\n\x1A\ncli-single-file";
        $mediapath = $mediadir . '/question.png';
        file_put_contents($mediapath, $mediabytes);
        self::write_json($root . '/data/media_map.json', [
            $mediaurl => 'data/media/' . self::UUID . '/question.png',
        ]);

        $bundle = source_bundle::open($jsonpath);

        $this->assertCount(1, $bundle->kahoots());
        $this->assertSame(self::UUID, $bundle->kahoots()[0]['document']['uuid']);
        $this->assertSame(self::UUID . '.json', $bundle->kahoots()[0]['sourceName']);
        $this->assertSame(1, $bundle->media_count());
        $this->assertSame($mediabytes, $bundle->read_media($mediaurl));
        $this->assertSame([
            'sourceName' => 'data/media/' . self::UUID . '/question.png',
            'filename' => 'question.png',
            'size' => strlen($mediabytes),
            'sha256' => hash('sha256', $mediabytes),
        ], $bundle->media_descriptor($mediaurl));
        $this->assertSame(
            hash('sha256', $mediabytes),
            hash('sha256', (string)$bundle->read_media($mediaurl))
        );
    }

    /**
     * An empty direct media map is valid and never triggers a remote fallback.
     *
     * @return void
     */
    public function test_accepts_standalone_json_with_empty_media_map(): void {
        $root = self::fixture_root();
        $jsonpath = $root . '/' . self::UUID . '.json';
        self::write_json($jsonpath, self::fixture_document());
        self::write_json($root . '/media_map.json', (object)[]);

        $bundle = source_bundle::open($jsonpath);

        $this->assertCount(1, $bundle->kahoots());
        $this->assertSame(0, $bundle->media_count());
        $this->assertNull($bundle->media_descriptor(
            'https://media.kahoot.test/question'
        ));
    }

    /**
     * Relative traversal in media_map.json is rejected before file access.
     *
     * @return void
     */
    public function test_rejects_directory_media_traversal(): void {
        $root = self::fixture_root();
        mkdir($root . '/kahoots', 0700, true);
        self::write_json(
            $root . '/kahoots/' . self::UUID . '.json',
            self::fixture_document()
        );
        self::write_json($root . '/media_map.json', [
            'https://media.kahoot.test/question' => '../outside.jpg',
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('unsafe_relative_path');
        source_bundle::open($root);
    }

    /**
     * A media-map target may not traverse a symlink, even within the bundle.
     *
     * @return void
     */
    public function test_rejects_directory_media_symlink(): void {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symbolic links are unavailable.');
        }
        $root = self::fixture_root();
        mkdir($root . '/kahoots', 0700, true);
        mkdir($root . '/media', 0700, true);
        self::write_json(
            $root . '/kahoots/' . self::UUID . '.json',
            self::fixture_document()
        );
        file_put_contents($root . '/media/source.jpg', 'jpeg');
        if (!symlink($root . '/media/source.jpg', $root . '/media/link.jpg')) {
            $this->markTestSkipped('The test environment denied symbolic links.');
        }
        self::write_json($root . '/media_map.json', [
            'https://media.kahoot.test/question' => 'media/link.jpg',
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('unsafe_media_symlink');
        source_bundle::open($root);
    }

    /**
     * A UUID filename mismatch remains a bounded source failure row.
     *
     * @return void
     */
    public function test_retains_uuid_filename_mismatch_as_source_failure(): void {
        $root = self::fixture_root();
        $otheruuid = '1576dcd2-8c5d-4d8a-8d1b-6a95a34804f5';
        $jsonpath = $root . '/' . $otheruuid . '.json';
        self::write_json($jsonpath, self::fixture_document());

        $bundle = source_bundle::open($root);

        $this->assertSame([[
            'sourceName' => $otheruuid . '.json',
            'sha256' => hash_file('sha256', $jsonpath),
            'failureCode' => 'kahoot_uuid_filename_mismatch',
            'sourceQuestionCount' => 1,
        ]], $bundle->kahoots());
    }

    /**
     * Invalid directory documents do not hide a later valid document.
     *
     * @return void
     */
    public function test_retains_directory_document_failures_and_continues(): void {
        $root = self::fixture_root();
        $kahootdir = $root . '/kahoots';
        mkdir($kahootdir, 0700, true);

        $invalidjson = '{"uuid":';
        file_put_contents($kahootdir . '/00-invalid-json.json', $invalidjson);

        $invaliduuid = self::fixture_document();
        $invaliduuid['uuid'] = 'not-a-uuid';
        self::write_json($kahootdir . '/01-invalid-uuid.json', $invaliduuid);

        $invalidquestions = self::fixture_document();
        $invalidquestions['uuid'] = '1576dcd2-8c5d-4d8a-8d1b-6a95a34804f5';
        $invalidquestions['questions'] = array_fill(
            0,
            source_bundle::MAX_QUESTIONS + 1,
            $invalidquestions['questions'][0]
        );
        self::write_json(
            $kahootdir . '/02-invalid-questions.json',
            $invalidquestions
        );

        $validpath = $kahootdir . '/99-valid.json';
        self::write_json($validpath, self::fixture_document());

        $rows = source_bundle::open($root)->kahoots();

        $this->assertCount(4, $rows);
        $this->assertSame('00-invalid-json.json', $rows[0]['sourceName']);
        $this->assertSame(hash('sha256', $invalidjson), $rows[0]['sha256']);
        $this->assertSame('kahoot_json_invalid', $rows[0]['failureCode']);
        $this->assertArrayNotHasKey('document', $rows[0]);
        $this->assertArrayNotHasKey('sourceQuestionCount', $rows[0]);

        $this->assertSame('01-invalid-uuid.json', $rows[1]['sourceName']);
        $this->assertSame('kahoot_uuid_invalid', $rows[1]['failureCode']);
        $this->assertSame(1, $rows[1]['sourceQuestionCount']);
        $this->assertArrayNotHasKey('document', $rows[1]);

        $this->assertSame('02-invalid-questions.json', $rows[2]['sourceName']);
        $this->assertSame('kahoot_questions_invalid', $rows[2]['failureCode']);
        $this->assertSame(
            source_bundle::MAX_QUESTIONS + 1,
            $rows[2]['sourceQuestionCount']
        );
        $this->assertArrayNotHasKey('document', $rows[2]);

        $this->assertSame('99-valid.json', $rows[3]['sourceName']);
        $this->assertSame(hash_file('sha256', $validpath), $rows[3]['sha256']);
        $this->assertSame(self::UUID, $rows[3]['document']['uuid']);
        $this->assertArrayNotHasKey('failureCode', $rows[3]);
    }

    /**
     * A retained source failure becomes a concrete per-Kahoot batch report.
     *
     * @return void
     */
    public function test_import_bundle_reports_retained_source_failure(): void {
        $root = self::fixture_root();
        $jsonpath = $root . '/broken.json';
        $invalidjson = '{"uuid":';
        file_put_contents($jsonpath, $invalidjson);
        $bundle = source_bundle::open($jsonpath);

        $report = import_service::import_bundle(
            $bundle,
            (object)['id' => 42],
            null,
            7,
            true
        );

        $this->assertSame('failed', $report['status']);
        $this->assertSame(1, $report['totals']['kahoots']);
        $this->assertSame(1, $report['totals']['failed']);
        $this->assertSame('broken.json', $report['reports'][0]['source']['title']);
        $this->assertSame(
            hash('sha256', $invalidjson),
            $report['reports'][0]['source']['sha256']
        );
        $this->assertSame(
            'kahoot_json_invalid',
            $report['reports'][0]['failure']['code']
        );
    }

    /**
     * A valid dry run still traverses mapping and report preparation only.
     *
     * @return void
     */
    public function test_import_bundle_dry_run_prepares_without_writes(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $root = self::fixture_root();
        $jsonpath = $root . '/' . self::UUID . '.json';
        self::write_json($jsonpath, self::fixture_document());

        $report = import_service::import_bundle(
            source_bundle::open($jsonpath),
            $course,
            null,
            (int)$USER->id,
            true
        );

        $this->assertSame('dry-run', $report['status']);
        $this->assertSame(1, $report['totals']['kahoots']);
        $this->assertSame(1, $report['totals']['succeeded']);
        $this->assertSame('dry-run', $report['reports'][0]['status']);
        $this->assertSame(1, $report['reports'][0]['totals']['source']);
        $this->assertFalse($DB->record_exists('quizgeist_imports', [
            'courseid' => (int)$course->id,
        ]));
    }

    /**
     * The first source-name-sorted UUID wins and every later copy is an error.
     *
     * @return void
     */
    public function test_retains_later_duplicate_uuid_as_source_failure(): void {
        $root = self::fixture_root();
        $kahootdir = $root . '/kahoots';
        mkdir($kahootdir, 0700, true);
        self::write_json($kahootdir . '/a.json', self::fixture_document());
        self::write_json($kahootdir . '/b.json', self::fixture_document());

        $rows = source_bundle::open($root)->kahoots();

        $this->assertCount(2, $rows);
        $this->assertSame('a.json', $rows[0]['sourceName']);
        $this->assertSame(self::UUID, $rows[0]['document']['uuid']);
        $this->assertSame('b.json', $rows[1]['sourceName']);
        $this->assertSame('kahoot_uuid_duplicate', $rows[1]['failureCode']);
        $this->assertSame(1, $rows[1]['sourceQuestionCount']);
        $this->assertArrayNotHasKey('document', $rows[1]);
    }

    /**
     * A mismatching first filename still reserves its decoded source UUID.
     *
     * @return void
     */
    public function test_mismatching_first_source_reserves_duplicate_uuid(): void {
        $root = self::fixture_root();
        $kahootdir = $root . '/kahoots';
        mkdir($kahootdir, 0700, true);
        $otheruuid = '1576dcd2-8c5d-4d8a-8d1b-6a95a34804f5';
        self::write_json(
            $kahootdir . '/' . $otheruuid . '.json',
            self::fixture_document()
        );
        self::write_json(
            $kahootdir . '/' . self::UUID . '.json',
            self::fixture_document()
        );

        $rows = source_bundle::open($root)->kahoots();

        $this->assertCount(2, $rows);
        $this->assertSame(
            'kahoot_uuid_filename_mismatch',
            $rows[0]['failureCode']
        );
        $this->assertSame(
            'kahoot_uuid_duplicate',
            $rows[1]['failureCode']
        );
        $this->assertArrayNotHasKey('document', $rows[1]);
    }

    /**
     * One oversized directory document is isolated from a later valid source.
     *
     * @return void
     */
    public function test_retains_directory_json_size_failure_and_continues(): void {
        $root = self::fixture_root();
        $kahootdir = $root . '/kahoots';
        mkdir($kahootdir, 0700, true);
        file_put_contents(
            $kahootdir . '/00-oversized.json',
            str_repeat('x', source_bundle::MAX_JSON_BYTES + 1)
        );
        self::write_json(
            $kahootdir . '/99-valid.json',
            self::fixture_document()
        );

        $rows = source_bundle::open($root)->kahoots();

        $this->assertCount(2, $rows);
        $this->assertSame('00-oversized.json', $rows[0]['sourceName']);
        $this->assertSame('', $rows[0]['sha256']);
        $this->assertSame('kahoot_json_too_large', $rows[0]['failureCode']);
        $this->assertSame(self::UUID, $rows[1]['document']['uuid']);
    }

    /**
     * A ZIP export is read in place and media bytes remain exact.
     *
     * @return void
     */
    public function test_opens_zip_export_without_extracting_members(): void {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP ZIP is unavailable.');
        }
        $root = self::fixture_root();
        $zippath = $root . '/bundle.zip';
        $mediabytes = "\x89PNG\r\n\x1A\nfixture";
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open(
            $zippath,
            \ZipArchive::CREATE | \ZipArchive::OVERWRITE
        ));
        $archive->addFromString(
            'rescued/data/kahoots/' . self::UUID . '.json',
            self::json(self::fixture_document())
        );
        $archive->addFromString(
            'rescued/data/media_map.json',
            self::json([
                'https://media.kahoot.test/question' =>
                    'data/media/' . self::UUID . '/question.png',
            ])
        );
        $archive->addFromString(
            'rescued/data/media/' . self::UUID . '/question.png',
            $mediabytes
        );
        $archive->close();

        $bundle = source_bundle::open($zippath);

        $this->assertCount(1, $bundle->kahoots());
        $this->assertSame(1, $bundle->media_count());
        $this->assertSame(
            $mediabytes,
            $bundle->read_media('https://media.kahoot.test/question')
        );
        $this->assertSame(
            'rescued/data/media/' . self::UUID . '/question.png',
            $bundle->media_descriptor(
                'https://media.kahoot.test/question'
            )['sourceName']
        );
    }

    /**
     * Invalid ZIP documents are isolated while later members remain available.
     *
     * @return void
     */
    public function test_retains_zip_document_failure_and_continues(): void {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP ZIP is unavailable.');
        }
        $root = self::fixture_root();
        $zippath = $root . '/mixed.zip';
        $invalidjson = '{"questions":';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open(
            $zippath,
            \ZipArchive::CREATE | \ZipArchive::OVERWRITE
        ));
        $archive->addFromString(
            'kahoots/00-invalid.json',
            $invalidjson
        );
        $archive->addFromString(
            'kahoots/99-valid.json',
            self::json(self::fixture_document())
        );
        $archive->close();

        $rows = source_bundle::open($zippath)->kahoots();

        $this->assertCount(2, $rows);
        $this->assertSame('kahoots/00-invalid.json', $rows[0]['sourceName']);
        $this->assertSame(hash('sha256', $invalidjson), $rows[0]['sha256']);
        $this->assertSame('kahoot_json_invalid', $rows[0]['failureCode']);
        $this->assertArrayNotHasKey('document', $rows[0]);
        $this->assertSame('kahoots/99-valid.json', $rows[1]['sourceName']);
        $this->assertSame(self::UUID, $rows[1]['document']['uuid']);
    }

    /**
     * One oversized ZIP document is isolated without inflating it into memory.
     *
     * @return void
     */
    public function test_retains_zip_json_size_failure_and_continues(): void {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP ZIP is unavailable.');
        }
        $root = self::fixture_root();
        $zippath = $root . '/mixed-size.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open(
            $zippath,
            \ZipArchive::CREATE | \ZipArchive::OVERWRITE
        ));
        $oversizedname = 'kahoots/00-oversized.json';
        $archive->addFromString(
            $oversizedname,
            str_repeat('x', source_bundle::MAX_JSON_BYTES + 1)
        );
        $archive->setCompressionName($oversizedname, \ZipArchive::CM_STORE);
        $archive->addFromString(
            'kahoots/99-valid.json',
            self::json(self::fixture_document())
        );
        $archive->close();

        $rows = source_bundle::open($zippath)->kahoots();

        $this->assertCount(2, $rows);
        $this->assertSame($oversizedname, $rows[0]['sourceName']);
        $this->assertSame('', $rows[0]['sha256']);
        $this->assertSame('kahoot_json_too_large', $rows[0]['failureCode']);
        $this->assertSame(self::UUID, $rows[1]['document']['uuid']);
    }

    /**
     * ZIP traversal is rejected even though archive members are never extracted.
     *
     * @return void
     */
    public function test_rejects_zip_traversal_member(): void {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('PHP ZIP is unavailable.');
        }
        $root = self::fixture_root();
        $zippath = $root . '/unsafe.zip';
        $archive = new \ZipArchive();
        $this->assertTrue($archive->open(
            $zippath,
            \ZipArchive::CREATE | \ZipArchive::OVERWRITE
        ));
        $archive->addFromString('../outside.json', '{}');
        $archive->addFromString(
            'data/kahoots/' . self::UUID . '.json',
            self::json(self::fixture_document())
        );
        $archive->close();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('unsafe_relative_path');
        source_bundle::open($zippath);
    }

    /**
     * Build one minimal rescued Kahoot document.
     *
     * @return array
     */
    private static function fixture_document(): array {
        return [
            'uuid' => self::UUID,
            'title' => 'Fixture',
            'questions' => [[
                'type' => 'quiz',
                'layout' => 'CLASSIC',
                'question' => 'Fixture?',
                'time' => 20000,
                'pointsMultiplier' => 1,
                'choices' => [
                    ['answer' => 'Yes', 'correct' => true],
                    ['answer' => 'No', 'correct' => false],
                ],
            ]],
        ];
    }

    /**
     * Create a test-specific directory below Moodle's request temp root.
     *
     * @return string
     */
    private static function fixture_root(): string {
        $root = make_request_directory()
            . '/kahoot-source-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        return $root;
    }

    /**
     * Encode and write one JSON fixture.
     *
     * @param string $path Target path.
     * @param mixed $value JSON value.
     * @return void
     */
    private static function write_json(string $path, $value): void {
        file_put_contents($path, self::json($value));
    }

    /**
     * Encode fixture JSON deterministically.
     *
     * @param mixed $value JSON value.
     * @return string
     */
    private static function json($value): string {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
