<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests unconditional dictation recording cleanup.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\ajax\action_context;
use mod_quizgeist\local\media\clip_limits;
use mod_quizgeist\local\media\clip_service;
use quizgeistaddon_ai\local\ajax\dictation_handler;

defined('MOODLE_INTERNAL') || die();

/**
 * Dictation clips disappear with their bookkeeping row even on ASR failure.
 *
 * @covers \quizgeistaddon_ai\local\ajax\dictation_handler
 * @covers \mod_quizgeist\local\media\clip_service
 */
final class dictation_cleanup_test extends \advanced_testcase {

    public function test_failed_transcription_removes_file_and_row(): void {
        global $DB;

        $this->resetAfterTest(true);
        // The client rejects this before cURL, giving the handler a deterministic
        // transcription failure without contacting an external service.
        set_config('gpuq_base_url', 'https://invalid.example/', 'mod_quizgeist');
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $now = time();
        $clipid = $DB->insert_record('quizgeist_clips', (object)[
            'quizgeistid' => $module->id,
            'userid' => $user->id,
            'answerid' => null,
            'purpose' => 'dictation',
            'itemid' => 0,
            'durationms' => 1000,
            'bytes' => 4,
            'language' => 'de',
            'transcript' => null,
            'transcriptstate' => 'none',
            'transcriptcode' => null,
            'audiodeleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_clips', 'itemid', $clipid, ['id' => $clipid]);
        $context = \context_module::instance($module->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => clip_service::COMPONENT,
            'filearea' => clip_limits::FILE_AREA,
            'itemid' => $clipid,
            'filepath' => '/',
            'filename' => 'clip' . $clipid . '.wav',
            'userid' => $user->id,
            'mimetype' => 'audio/wav',
        ], 'RIFF');

        $cm = get_coursemodule_from_instance('quizgeist', $module->id, $course->id, false, MUST_EXIST);
        $instance = $DB->get_record('quizgeist', ['id' => $module->id], '*', MUST_EXIST);
        $request = new action_context(
            'dictation',
            (object)$cm,
            $instance,
            $context,
            $user,
            ['clipId' => $clipid]
        );

        try {
            (new dictation_handler())->execute($request);
            $this->fail('A rejected transcription must be reported as an exception.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('dictation:error:failed', $exception->errorcode);
        }

        $this->assertFalse($DB->record_exists('quizgeist_clips', ['id' => $clipid]));
        $this->assertCount(0, get_file_storage()->get_area_files(
            $context->id,
            clip_service::COMPONENT,
            clip_limits::FILE_AREA,
            $clipid,
            'id ASC',
            false
        ));
    }
}
