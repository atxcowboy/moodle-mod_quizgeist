<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Integration tests for Kahoot import provenance across activity restore.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\kahoot\import_service;
use mod_quizgeist\local\kahoot\source_bundle;
use mod_quizgeist\local\live\live_domain_exception;
use mod_quizgeist\local\live\session_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Exercises the genuine Moodle backup/restore boundary after a fresh import.
 */
final class kahoot_restore_test extends \advanced_testcase {

    /** Stable source identity used by both isolated test databases. */
    private const SOURCE_UUID = '236494fe-85a7-4f86-8372-e8a78764a604';

    /** Exact local identifier bound to the required drop-pin image. */
    private const MEDIA_URL = 'https://media.kahoot.test/required-pin';

    /** A valid, fileinfo-detectable 1x1 PNG. */
    private const PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk'
            . '+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    /**
     * A complete same-course copy keeps the whole playable import ledger.
     *
     * @return void
     */
    public function test_complete_import_survives_same_course_userinfo_restore(): void {
        global $DB, $USER;

        $fixture = $this->fresh_required_media_import();
        $sourceimport = $fixture['import'];
        $sourcequestion = $fixture['question'];
        $sourcereportjson = (string)$sourceimport->reportjson;
        $sourcereport = self::decode_json($sourcereportjson);
        $sourcequestionfiles = self::area_fingerprints(
            $fixture['context'],
            'questionmedia',
            (int)$sourcequestion->id
        );
        $sourceimportfiles = self::area_fingerprints(
            $fixture['context'],
            'importmedia',
            (int)$sourceimport->id
        );

        $restoredcmid = $this->backup_and_restore(
            (int)$fixture['cm']->id,
            (int)$fixture['course']->id
        );
        $restoredcm = get_coursemodule_from_id(
            'quizgeist',
            $restoredcmid,
            (int)$fixture['course']->id,
            false,
            MUST_EXIST
        );
        $restoredcontext = \context_module::instance($restoredcmid, MUST_EXIST);
        $restoredquizgeist = $DB->get_record(
            'quizgeist',
            ['id' => (int)$restoredcm->instance],
            '*',
            MUST_EXIST
        );
        $restoredimports = array_values($DB->get_records(
            'quizgeist_imports',
            ['quizgeistid' => (int)$restoredquizgeist->id],
            'id ASC'
        ));
        $restoredquestions = array_values($DB->get_records(
            'quizgeist_questions',
            ['quizgeistid' => (int)$restoredquizgeist->id],
            'sortorder ASC, id ASC'
        ));

        $this->assertCount(1, $restoredimports);
        $this->assertCount(1, $restoredquestions);
        $restoredimport = $restoredimports[0];
        $restoredquestion = $restoredquestions[0];

        // Same-course collision handling may rewrite provenance, but no
        // teacher-visible import state or imported content.
        $this->assertSame('kahoot', (string)$sourceimport->sourceformat);
        $this->assertSame('external', (string)$restoredimport->sourceformat);
        $this->assertNotSame(
            (string)$sourceimport->sourceuuid,
            (string)$restoredimport->sourceuuid
        );
        $this->assertMatchesRegularExpression(
            '/^restore-[a-f0-9]{56}$/D',
            (string)$restoredimport->sourceuuid
        );
        foreach ([
            'sourcename',
            'sourcehash',
            'status',
            'questioncount',
            'adaptedcount',
            'skippedcount',
            'mediacount',
            'timecreated',
            'timemodified',
        ] as $field) {
            $this->assertSame(
                $sourceimport->{$field},
                $restoredimport->{$field},
                "Import field {$field} changed during restore."
            );
        }
        $this->assertSame('complete', (string)$restoredimport->status);
        $this->assertSame(
            (int)$fixture['course']->id,
            (int)$restoredimport->courseid
        );
        $this->assertSame(
            (int)$restoredquizgeist->id,
            (int)$restoredimport->quizgeistid
        );

        $this->assertSame(
            (int)$sourceimport->id,
            (int)$sourcequestion->importid
        );
        $this->assertSame(
            (int)$restoredimport->id,
            (int)$restoredquestion->importid
        );
        $this->assertSame((int)$sourcequestion->id, (int)$sourcequestion->rootid);
        $this->assertSame(
            (int)$restoredquestion->id,
            (int)$restoredquestion->rootid
        );
        foreach ([
            'version',
            'sortorder',
            'qtype',
            'questiontext',
            'questionformat',
            'optionsjson',
            'timelimit',
            'pointmode',
            'explanation',
            'status',
            'createdby',
            'timecreated',
            'timemodified',
        ] as $field) {
            $this->assertSame(
                $sourcequestion->{$field},
                $restoredquestion->{$field},
                "Question field {$field} changed during restore."
            );
        }
        $this->assertSame(
            (string)$sourcequestion->optionsjson,
            (string)$restoredquestion->optionsjson,
            'Canonical question options must remain byte-identical.'
        );

        $restoredreport = self::decode_json((string)$restoredimport->reportjson);
        $expectedreport = $sourcereport;
        $expectedreport['source']['uuid'] = (string)$restoredimport->sourceuuid;
        $expectedreport['source']['format'] = 'external';
        $expectedreport['source']['restoredFrom'] = [
            'format' => 'kahoot',
            'uuid' => self::SOURCE_UUID,
        ];
        $expectedreport['restoredCopy'] = true;
        $expectedreport['target'] = [
            'courseId' => (int)$fixture['course']->id,
            'cmid' => $restoredcmid,
            'instanceId' => (int)$restoredquizgeist->id,
        ];
        foreach ($expectedreport['questions'] as &$questionrow) {
            if (($questionrow['targetQuestionId'] ?? null)
                    === (int)$sourcequestion->id) {
                $questionrow['targetQuestionId'] =
                    (int)$restoredquestion->id;
            }
        }
        unset($questionrow);

        $this->assertSame(
            $sourcereport['totals'],
            $restoredreport['totals'],
            'Import totals changed during restore.'
        );
        $this->assertSame(
            $expectedreport['questions'],
            $restoredreport['questions'],
            'Question decisions or their restored binding changed.'
        );
        $this->assertSame($expectedreport, $restoredreport);
        $this->assertSame(
            self::encode_json($expectedreport),
            (string)$restoredimport->reportjson,
            'The restored report must be the byte-exact source report plus provenance/ID rewrites.'
        );
        $this->assertSame(
            $sourcereportjson,
            (string)$DB->get_field(
                'quizgeist_imports',
                'reportjson',
                ['id' => (int)$sourceimport->id],
                MUST_EXIST
            ),
            'Restoring the copy mutated the original import report.'
        );

        $this->assertSame(
            $sourcequestionfiles,
            self::area_fingerprints(
                $restoredcontext,
                'questionmedia',
                (int)$restoredquestion->id
            )
        );
        $this->assertSame(
            $sourceimportfiles,
            self::area_fingerprints(
                $restoredcontext,
                'importmedia',
                (int)$restoredimport->id
            )
        );

        $sessionresult = session_service::create_session_for_questions(
            $restoredquizgeist,
            $restoredcontext,
            $USER,
            'classic',
            'generated',
            [(int)$restoredquestion->id]
        );
        $this->assertSame('lobby', $sessionresult['state']['phase']);
        $this->assertSame(1, $sessionresult['state']['totalQuestions']);
        $sessionquestion = $DB->get_record(
            'quizgeist_session_questions',
            ['sessionid' => (int)$sessionresult['state']['sessionId']],
            '*',
            MUST_EXIST
        );
        $this->assertSame(
            (int)$restoredquestion->id,
            (int)$sessionquestion->questionid
        );
    }

    /**
     * Missing required question media still triggers the safe quarantine path.
     *
     * @return void
     */
    public function test_missing_required_questionmedia_degrades_restored_import(): void {
        global $DB, $USER;

        $fixture = $this->fresh_required_media_import();
        $sourceimport = $fixture['import'];
        $sourcequestion = $fixture['question'];
        $sourceoptions = (string)$sourcequestion->optionsjson;
        $sourceimportfiles = self::area_fingerprints(
            $fixture['context'],
            'importmedia',
            (int)$sourceimport->id
        );
        $this->assertCount(1, $sourceimportfiles);

        get_file_storage()->delete_area_files(
            (int)$fixture['context']->id,
            'mod_quizgeist',
            'questionmedia',
            (int)$sourcequestion->id
        );
        $this->assertSame(
            [],
            self::area_fingerprints(
                $fixture['context'],
                'questionmedia',
                (int)$sourcequestion->id
            )
        );
        $this->assertSame(
            $sourceimportfiles,
            self::area_fingerprints(
                $fixture['context'],
                'importmedia',
                (int)$sourceimport->id
            ),
            'The private provenance file must remain present before backup.'
        );
        $this->assertSame(
            $sourceoptions,
            (string)$DB->get_field(
                'quizgeist_questions',
                'optionsjson',
                ['id' => (int)$sourcequestion->id],
                MUST_EXIST
            )
        );
        $this->assertSame(
            'ready',
            (string)$DB->get_field(
                'quizgeist_questions',
                'status',
                ['id' => (int)$sourcequestion->id],
                MUST_EXIST
            )
        );

        $restoredcmid = $this->backup_and_restore(
            (int)$fixture['cm']->id,
            (int)$fixture['course']->id
        );
        $restoredcm = get_coursemodule_from_id(
            'quizgeist',
            $restoredcmid,
            (int)$fixture['course']->id,
            false,
            MUST_EXIST
        );
        $restoredcontext = \context_module::instance($restoredcmid, MUST_EXIST);
        $restoredquizgeist = $DB->get_record(
            'quizgeist',
            ['id' => (int)$restoredcm->instance],
            '*',
            MUST_EXIST
        );
        $restoredimport = $DB->get_record(
            'quizgeist_imports',
            ['quizgeistid' => (int)$restoredquizgeist->id],
            '*',
            MUST_EXIST
        );
        $restoredquestions = array_values($DB->get_records(
            'quizgeist_questions',
            ['quizgeistid' => (int)$restoredquizgeist->id],
            'id ASC'
        ));

        $this->assertCount(1, $restoredquestions);
        $restoredquestion = $restoredquestions[0];
        $this->assertSame('external', (string)$restoredimport->sourceformat);
        $this->assertNotSame(
            (string)$sourceimport->sourceuuid,
            (string)$restoredimport->sourceuuid
        );
        $this->assertSame('failed', (string)$restoredimport->status);
        $this->assertSame(0, (int)$restoredimport->questioncount);
        $this->assertSame(0, (int)$restoredimport->adaptedcount);
        $this->assertSame(1, (int)$restoredimport->skippedcount);
        $this->assertSame(0, (int)$restoredimport->mediacount);

        $failedreport = self::decode_json((string)$restoredimport->reportjson);
        $this->assertSame('failed', $failedreport['status']);
        $this->assertSame(
            'restore_incomplete',
            $failedreport['failure']['code']
        );
        $this->assertTrue($failedreport['failure']['retryable']);
        $this->assertSame(1, $failedreport['failure']['quarantinedQuestions']);
        $this->assertSame([
            'source' => 1,
            'retained' => 0,
            'imported' => 0,
            'adjusted' => 0,
            'skipped' => 1,
            'mediaExpected' => 0,
            'mediaImported' => 0,
        ], $failedreport['totals']);
        $this->assertCount(1, $failedreport['questions']);
        $this->assertSame(
            'skipped',
            $failedreport['questions'][0]['outcome']
        );
        $this->assertSame(
            ['restore_incomplete'],
            $failedreport['questions'][0]['reasons']
        );
        $this->assertNull(
            $failedreport['questions'][0]['targetQuestionId']
        );

        $this->assertNull($restoredquestion->importid);
        $this->assertSame('archived', (string)$restoredquestion->status);
        $this->assertSame(
            $sourceoptions,
            (string)$restoredquestion->optionsjson,
            'Quarantine must not rewrite the archived recovery content.'
        );
        $this->assertSame(
            [],
            self::area_fingerprints(
                $restoredcontext,
                'questionmedia',
                (int)$restoredquestion->id
            )
        );
        $this->assertSame(
            [],
            self::area_fingerprints(
                $restoredcontext,
                'importmedia',
                (int)$restoredimport->id
            )
        );
        $this->assertSame(
            $sourceimportfiles,
            self::area_fingerprints(
                $fixture['context'],
                'importmedia',
                (int)$sourceimport->id
            ),
            'Quarantining the copy must not remove source provenance.'
        );

        try {
            session_service::create_session_for_questions(
                $restoredquizgeist,
                $restoredcontext,
                $USER,
                'classic',
                'generated',
                [(int)$restoredquestion->id]
            );
            $this->fail('An archived incomplete import unexpectedly remained playable.');
        } catch (live_domain_exception $exception) {
            $this->assertSame(
                'no_playable_questions',
                $exception->get_error_code()
            );
        }
        $this->assertSame(0, $DB->count_records(
            'quizgeist_sessions',
            ['quizgeistid' => (int)$restoredquizgeist->id]
        ));
    }

    /**
     * The append-only answer ledger survives restore without score rewrites.
     *
     * Two persisted shapes look "inconsistent" but are deliberate: a
     * `scorevoid` compensation row carries negative points, and every live
     * answer stores `maxpoints = 0` because live scoring is speed-based and has
     * no fixed per-answer ceiling. A restore that clamps either value silently
     * rewrites report and CSV figures of the copy (P10-F2).
     *
     * @return void
     */
    public function test_live_answer_ledger_survives_restore_unchanged(): void {
        global $DB, $USER;

        $fixture = $this->fresh_required_media_import();
        $quizgeistid = (int)$fixture['quizgeist']->id;
        $questionid = (int)$fixture['question']->id;
        // Keep grading out of the way: this test is about the ledger bytes.
        $DB->set_field('quizgeist', 'grade', 0, ['id' => $quizgeistid]);

        $now = time() - 600;
        $voidedvisit = bin2hex(random_bytes(16));
        $countedvisit = bin2hex(random_bytes(16));
        $sessionid = (int)$DB->insert_record('quizgeist_sessions', (object)[
            'quizgeistid' => $quizgeistid,
            'hostuserid' => (int)$USER->id,
            'joincode' => null,
            'status' => 'ended',
            'mode' => 'classic',
            'currentquestionid' => null,
            'stateversion' => 7,
            'statejson' => null,
            'settingsjson' => null,
            'timestarted' => $now,
            'timeended' => $now + 300,
            'timecreated' => $now,
            'timemodified' => $now + 300,
        ]);
        // Both positions are terminal, so the restore's visit ledger has
        // nothing left to compensate and may not add or drop ledger rows.
        $DB->insert_record('quizgeist_session_questions', (object)[
            'sessionid' => $sessionid,
            'sortindex' => 0,
            'questionid' => $questionid,
            'visit' => $voidedvisit,
            'visitstate' => 'skipped',
            'resolvedvisit' => null,
            'stage' => 'answer',
        ]);
        $DB->insert_record('quizgeist_session_questions', (object)[
            'sessionid' => $sessionid,
            'sortindex' => 1,
            'questionid' => $questionid,
            'visit' => $countedvisit,
            'visitstate' => 'revealed',
            'resolvedvisit' => $countedvisit,
            'stage' => 'answer',
        ]);
        $playerid = (int)$DB->insert_record('quizgeist_players', (object)[
            'sessionid' => $sessionid,
            'userid' => (int)$USER->id,
            'displayname' => 'Ledger fixture',
            'groupid' => null,
            'teamname' => null,
            'avatarkey' => null,
            // The player total already reflects the voided visit.
            'score' => 500,
            'streak' => 1,
            'status' => 'joined',
            'timejoined' => $now,
            'lastseen' => $now + 300,
            'timemodified' => $now + 300,
        ]);

        $ledger = [
            [
                'answertype' => 'answer',
                'visit' => $voidedvisit,
                'answerjson' => self::encode_json([
                    'choiceIds' => ['a'],
                    'questionToken' => $voidedvisit,
                    'scoreBefore' => 0,
                    'streakBefore' => 0,
                ]),
                'iscorrect' => 1,
                'points' => 947,
                'maxpoints' => 0,
                'responsetime' => 1234,
            ],
            [
                'answertype' => 'scorevoid',
                'visit' => $voidedvisit,
                'answerjson' => self::encode_json([
                    'questionToken' => $voidedvisit,
                    'reason' => 'skipped',
                ]),
                'iscorrect' => null,
                // A compensation row is negative on purpose.
                'points' => -947,
                'maxpoints' => 0,
                'responsetime' => 0,
            ],
            [
                'answertype' => 'answer',
                'visit' => $countedvisit,
                'answerjson' => self::encode_json([
                    'choiceIds' => ['a'],
                    'questionToken' => $countedvisit,
                    'scoreBefore' => 0,
                    'streakBefore' => 0,
                ]),
                'iscorrect' => 1,
                'points' => 500,
                'maxpoints' => 0,
                'responsetime' => 2345,
            ],
        ];
        foreach ($ledger as $offset => $row) {
            $DB->insert_record('quizgeist_answers', (object)($row + [
                'sessionid' => $sessionid,
                'playerid' => $playerid,
                'attemptid' => null,
                'questionid' => $questionid,
                'userid' => (int)$USER->id,
                'submissionkey' => null,
                'timecreated' => $now + 10 + $offset,
            ]));
        }
        $sourceledger = self::ledger_fingerprints($sessionid);
        $this->assertCount(3, $sourceledger);

        $restoredcmid = $this->backup_and_restore(
            (int)$fixture['cm']->id,
            (int)$fixture['course']->id
        );
        $restoredcm = get_coursemodule_from_id(
            'quizgeist',
            $restoredcmid,
            (int)$fixture['course']->id,
            false,
            MUST_EXIST
        );
        $restoredsessions = array_values($DB->get_records(
            'quizgeist_sessions',
            ['quizgeistid' => (int)$restoredcm->instance],
            'id ASC'
        ));
        $this->assertCount(1, $restoredsessions);
        $restoredsessionid = (int)$restoredsessions[0]->id;
        $this->assertNotSame($sessionid, $restoredsessionid);

        $this->assertSame(
            $sourceledger,
            self::ledger_fingerprints($restoredsessionid),
            'Backup/restore rewrote the append-only answer ledger.'
        );
        $this->assertSame(
            $sourceledger,
            self::ledger_fingerprints($sessionid),
            'Restoring the copy mutated the original answer ledger.'
        );

        $restoredplayers = array_values($DB->get_records(
            'quizgeist_players',
            ['sessionid' => $restoredsessionid],
            'id ASC'
        ));
        $this->assertCount(1, $restoredplayers);
        $this->assertSame(500, (int)$restoredplayers[0]->score);
        // The compensated ledger and the player total must still agree.
        $this->assertSame(
            500,
            (int)$DB->get_field_sql(
                'SELECT COALESCE(SUM(points), 0)
                   FROM {quizgeist_answers}
                  WHERE sessionid = :sessionid',
                ['sessionid' => $restoredsessionid]
            )
        );
        $this->assertSame(
            0,
            (int)$DB->get_field_sql(
                'SELECT COALESCE(SUM(maxpoints), 0)
                   FROM {quizgeist_answers}
                  WHERE sessionid = :sessionid',
                ['sessionid' => $restoredsessionid]
            ),
            'Live answers have no fixed maximum and must stay at zero.'
        );
    }

    /**
     * Return the complete, ID-independent answer ledger of one live session.
     *
     * @param int $sessionid Live session ID.
     * @return array<int,array<string,int|string|null>>
     */
    private static function ledger_fingerprints(int $sessionid): array {
        global $DB;

        return array_values(array_map(
            static fn(\stdClass $row): array => [
                'answertype' => (string)$row->answertype,
                'visit' => (string)$row->visit,
                'submissionkey' => $row->submissionkey === null
                    ? null
                    : (string)$row->submissionkey,
                'answerjson' => (string)$row->answerjson,
                'iscorrect' => $row->iscorrect === null
                    ? null
                    : (int)$row->iscorrect,
                'points' => (int)$row->points,
                'maxpoints' => (int)$row->maxpoints,
                'responsetime' => (int)$row->responsetime,
                'timecreated' => (int)$row->timecreated,
            ],
            $DB->get_records(
                'quizgeist_answers',
                ['sessionid' => $sessionid],
                'timecreated ASC, id ASC'
            )
        ));
    }

    /**
     * Import one drop-pin whose image is both required and locally available.
     *
     * @return array{
     *     course:\stdClass,
     *     cm:\stdClass,
     *     context:\context_module,
     *     quizgeist:\stdClass,
     *     import:\stdClass,
     *     question:\stdClass
     * }
     */
    private function fresh_required_media_import(): array {
        global $DB, $USER;

        // import_service deliberately refuses an ambient transaction. Moodle's
        // PHPUnit layer uses rollback resets on PostgreSQL and SQL Server, so
        // force the portable full-reset path before starting the fresh import.
        $this->preventResetByRollback();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course([
            'fullname' => 'Kahoot restore integration',
            'shortname' => 'kahoot-restore-' . bin2hex(random_bytes(4)),
        ]);
        $batchreport = import_service::import_bundle(
            source_bundle::open(self::create_source_bundle()),
            $course,
            null,
            (int)$USER->id,
            false
        );

        $this->assertSame('complete', $batchreport['status']);
        $this->assertCount(1, $batchreport['reports']);
        $importreport = $batchreport['reports'][0];
        $this->assertSame('complete', $importreport['status']);
        $this->assertSame(1, $importreport['totals']['retained']);
        $this->assertSame(1, $importreport['totals']['mediaExpected']);
        $this->assertSame(1, $importreport['totals']['mediaImported']);

        $cmid = (int)$importreport['target']['cmid'];
        $cm = get_coursemodule_from_id(
            'quizgeist',
            $cmid,
            (int)$course->id,
            false,
            MUST_EXIST
        );
        $context = \context_module::instance($cmid, MUST_EXIST);
        $quizgeist = $DB->get_record(
            'quizgeist',
            ['id' => (int)$cm->instance],
            '*',
            MUST_EXIST
        );
        $import = $DB->get_record(
            'quizgeist_imports',
            ['quizgeistid' => (int)$quizgeist->id],
            '*',
            MUST_EXIST
        );
        $question = $DB->get_record(
            'quizgeist_questions',
            [
                'quizgeistid' => (int)$quizgeist->id,
                'importid' => (int)$import->id,
            ],
            '*',
            MUST_EXIST
        );
        $options = self::decode_json((string)$question->optionsjson);

        $this->assertSame('complete', (string)$import->status);
        $this->assertSame('pin', (string)$question->qtype);
        $this->assertSame('ready', (string)$question->status);
        $this->assertIsString($options['media']);
        $this->assertNotSame('', $options['media']);
        $this->assertCount(
            1,
            self::area_fingerprints(
                $context,
                'questionmedia',
                (int)$question->id
            )
        );
        $this->assertCount(
            1,
            self::area_fingerprints(
                $context,
                'importmedia',
                (int)$import->id
            )
        );

        return [
            'course' => $course,
            'cm' => $cm,
            'context' => $context,
            'quizgeist' => $quizgeist,
            'import' => $import,
            'question' => $question,
        ];
    }

    /**
     * Run a genuine TYPE_1ACTIVITY Moodle backup and same-course restore.
     *
     * Controllers, mutable backup configuration and the retained backup
     * workspace are cleaned on both success and failure.
     *
     * @param int $sourcecmid Source course-module ID.
     * @param int $courseid Destination course ID.
     * @return int Restored course-module ID.
     */
    private function backup_and_restore(int $sourcecmid, int $courseid): int {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->libdir . '/filelib.php');

        $sourcecontextid = (int)\context_module::instance(
            $sourcecmid,
            MUST_EXIST
        )->id;
        $moduleid = (int)$DB->get_field(
            'modules',
            'id',
            ['name' => 'quizgeist'],
            MUST_EXIST
        );
        $preexistingcmids = array_map('intval', array_keys($DB->get_records(
            'course_modules',
            ['course' => $courseid, 'module' => $moduleid],
            '',
            'id'
        )));

        $backupcontroller = null;
        $restorecontroller = null;
        $backupid = '';
        $backupbasepath = '';
        $restoredcmid = 0;
        $failure = null;
        $cleanupfailures = [];
        $hadkeeptemp = property_exists($CFG, 'keeptempdirectoriesonbackup');
        $oldkeeptemp = $CFG->keeptempdirectoriesonbackup ?? null;
        $hadlogger = property_exists($CFG, 'backup_file_logger_level');
        $oldlogger = $CFG->backup_file_logger_level ?? null;

        try {
            $CFG->keeptempdirectoriesonbackup = true;
            $CFG->backup_file_logger_level = \backup::LOG_NONE;
            $backupcontroller = new \backup_controller(
                \backup::TYPE_1ACTIVITY,
                $sourcecmid,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                (int)$USER->id
            );
            $backupid = (string)$backupcontroller->get_backupid();
            $backupbasepath =
                (string)$backupcontroller->get_plan()->get_basepath();
            $this->force_userinfo(
                $backupcontroller,
                $sourcecmid,
                'backup'
            );
            $backupcontroller->execute_plan();
            $backupcontroller->destroy();
            $backupcontroller = null;

            $restorecontroller = new \restore_controller(
                $backupid,
                $courseid,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                (int)$USER->id,
                \backup::TARGET_CURRENT_ADDING
            );
            $this->force_userinfo(
                $restorecontroller,
                $sourcecmid,
                'restore'
            );
            if (!$restorecontroller->execute_precheck()) {
                throw new \RuntimeException(
                    'Moodle restore precheck failed: ' . self::encode_json(
                        $restorecontroller->get_precheck_results()
                    )
                );
            }
            $restorecontroller->execute_plan();
            foreach ($restorecontroller->get_plan()->get_tasks() as $task) {
                if ($task instanceof \restore_activity_task
                        && (int)$task->get_old_contextid()
                            === $sourcecontextid) {
                    $restoredcmid = (int)$task->get_moduleid();
                    break;
                }
            }
            if ($restoredcmid <= 0) {
                $currentcmids = array_map(
                    'intval',
                    array_keys($DB->get_records(
                        'course_modules',
                        ['course' => $courseid, 'module' => $moduleid],
                        '',
                        'id'
                    ))
                );
                $createdcmids = array_values(array_diff(
                    $currentcmids,
                    $preexistingcmids
                ));
                if (count($createdcmids) === 1) {
                    $restoredcmid = $createdcmids[0];
                }
            }
            if ($restoredcmid <= 0
                    || $restoredcmid === $sourcecmid
                    || in_array($restoredcmid, $preexistingcmids, true)
                    || !$DB->record_exists('course_modules', [
                        'id' => $restoredcmid,
                        'course' => $courseid,
                        'module' => $moduleid,
                    ])) {
                throw new \RuntimeException(
                    'The restore did not create one identifiable activity copy.'
                );
            }
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            if ($restorecontroller instanceof \restore_controller) {
                try {
                    $restorecontroller->destroy();
                } catch (\Throwable $exception) {
                    $cleanupfailures[] =
                        'restore controller: ' . $exception->getMessage();
                }
            }
            if ($backupcontroller instanceof \backup_controller) {
                try {
                    $backupcontroller->destroy();
                } catch (\Throwable $exception) {
                    $cleanupfailures[] =
                        'backup controller: ' . $exception->getMessage();
                }
            }
            try {
                self::delete_backup_workspace(
                    $backupbasepath,
                    $backupid
                );
            } catch (\Throwable $exception) {
                $cleanupfailures[] =
                    'backup workspace: ' . $exception->getMessage();
            }

            if ($hadkeeptemp) {
                $CFG->keeptempdirectoriesonbackup = $oldkeeptemp;
            } else {
                unset($CFG->keeptempdirectoriesonbackup);
            }
            if ($hadlogger) {
                $CFG->backup_file_logger_level = $oldlogger;
            } else {
                unset($CFG->backup_file_logger_level);
            }
        }

        if ($cleanupfailures !== []) {
            throw new \RuntimeException(
                'Backup/restore cleanup failed: '
                    . implode('; ', $cleanupfailures),
                0,
                $failure
            );
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $restoredcmid;
    }

    /**
     * Explicitly enable and prove userinfo on one backup or restore plan.
     *
     * @param \backup_controller|\restore_controller $controller Controller.
     * @param int $sourcecmid Original course-module ID.
     * @param string $phase Human-readable phase.
     * @return void
     */
    private function force_userinfo(
        $controller,
        int $sourcecmid,
        string $phase
    ): void {
        $plan = $controller->get_plan();
        $activitysetting = "quizgeist_{$sourcecmid}_userinfo";
        $this->assertTrue(
            $plan->setting_exists('users'),
            "The {$phase} plan has no users setting."
        );
        $this->assertTrue(
            $plan->setting_exists($activitysetting),
            "The {$phase} plan has no Quizgeist userinfo setting."
        );
        foreach (['users', $activitysetting] as $settingname) {
            $setting = $plan->get_setting($settingname);
            $setting->set_status(\backup_setting::NOT_LOCKED);
            $setting->set_value(true);
            $this->assertSame(
                \backup_setting::NOT_LOCKED,
                $setting->get_status()
            );
            $this->assertTrue((bool)$setting->get_value());
        }
    }

    /**
     * Create the small rescued Kahoot directory consumed by source_bundle.
     *
     * @return string Bundle root.
     */
    private static function create_source_bundle(): string {
        $root = make_request_directory()
            . '/kahoot-restore-' . bin2hex(random_bytes(6));
        $kahootdir = $root . '/data/kahoots';
        $mediadir = $root . '/data/media/' . self::SOURCE_UUID;
        if (!mkdir($kahootdir, 0700, true)
                || !mkdir($mediadir, 0700, true)) {
            throw new \RuntimeException('Could not create the Kahoot test bundle.');
        }

        $document = [
            'uuid' => self::SOURCE_UUID,
            'title' => 'Required media restore fixture',
            'questions' => [[
                'type' => 'drop_pin',
                'question' => 'Place the pin on the image.',
                'image' => self::MEDIA_URL,
                'time' => 20000,
                'pointsMultiplier' => 1,
            ]],
        ];
        $jsonpath = $kahootdir . '/' . self::SOURCE_UUID . '.json';
        $mediapath = $mediadir . '/required-pin.png';
        $mediabytes = base64_decode(self::PNG_BASE64, true);
        if (!is_string($mediabytes)
                || file_put_contents(
                    $jsonpath,
                    self::encode_json($document)
                ) === false
                || file_put_contents($mediapath, $mediabytes) === false
                || file_put_contents(
                    $root . '/data/media_map.json',
                    self::encode_json([
                        self::MEDIA_URL =>
                            'data/media/' . self::SOURCE_UUID
                                . '/required-pin.png',
                    ])
                ) === false) {
            throw new \RuntimeException('Could not write the Kahoot test bundle.');
        }
        return $root;
    }

    /**
     * Return a context/owner-independent, byte-sensitive file-area manifest.
     *
     * @param \context $context File context.
     * @param string $filearea Plugin file area.
     * @param int $itemid File-area item ID.
     * @return array<int,array<string,int|string>>
     */
    private static function area_fingerprints(
        \context $context,
        string $filearea,
        int $itemid
    ): array {
        $files = get_file_storage()->get_area_files(
            (int)$context->id,
            'mod_quizgeist',
            $filearea,
            $itemid,
            'filepath ASC, filename ASC',
            false
        );
        return array_values(array_map(
            static fn(\stored_file $file): array => [
                'filepath' => $file->get_filepath(),
                'filename' => $file->get_filename(),
                'mimetype' => (string)$file->get_mimetype(),
                'filesize' => (int)$file->get_filesize(),
                'sha256' => hash('sha256', $file->get_content()),
            ],
            $files
        ));
    }

    /**
     * Delete only the exact backup-id workspace retained by this test.
     *
     * @param string $basepath Backup plan base path.
     * @param string $backupid Backup controller ID.
     * @return void
     */
    private static function delete_backup_workspace(
        string $basepath,
        string $backupid
    ): void {
        global $CFG;

        if ($basepath === '' || !file_exists($basepath)) {
            return;
        }
        $backuproot = realpath((string)$CFG->tempdir . '/backup');
        $candidate = realpath($basepath);
        if (!is_string($backuproot)
                || !is_string($candidate)
                || $candidate === $backuproot
                || !str_starts_with(
                    $candidate,
                    $backuproot . DIRECTORY_SEPARATOR
                )
                || basename($candidate) !== $backupid) {
            throw new \RuntimeException(
                'Refused to delete an unsafe backup workspace.'
            );
        }
        if (!fulldelete($candidate) || file_exists($candidate)) {
            throw new \RuntimeException(
                'The retained backup workspace could not be removed.'
            );
        }
    }

    /**
     * Decode one exact associative JSON document.
     *
     * @param string $json JSON bytes.
     * @return array
     */
    private static function decode_json(string $json): array {
        $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Expected a JSON object.');
        }
        return $decoded;
    }

    /**
     * Encode fixture and report JSON in the plugin's canonical byte format.
     *
     * @param mixed $value JSON value.
     * @return string
     */
    private static function encode_json($value): string {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );
    }
}
