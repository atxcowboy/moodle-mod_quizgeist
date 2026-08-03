<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for evaluated-clip audio retention.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\media\clip_limits;
use mod_quizgeist\local\media\clip_service;
use mod_quizgeist\task\purge_clips;

defined('MOODLE_INTERNAL') || die();

/**
 * The task deletes only terminal audio and leaves the evidence row and text.
 *
 * @covers \mod_quizgeist\task\purge_clips
 * @covers \mod_quizgeist\local\media\clip_service
 */
final class clip_retention_test extends \advanced_testcase {

    /**
     * Insert a clip row and one file in its activity's file area.
     *
     * @param \stdClass $module Activity module.
     * @param \stdClass $user Owner.
     * @param string $state Transcript state.
     * @param int $modified Last modification time.
     * @param string $transcript Transcript.
     * @return array{clip:\stdClass,context:\context_module}
     */
    private function clip(
        \stdClass $module,
        \stdClass $user,
        string $state,
        int $modified,
        string $transcript
    ): array {
        global $DB;

        $now = time();
        $id = $DB->insert_record('quizgeist_clips', (object)[
            'quizgeistid' => $module->id,
            'userid' => $user->id,
            'answerid' => null,
            'purpose' => 'answer',
            'itemid' => 0,
            'durationms' => 1000,
            'bytes' => 4,
            'language' => 'de',
            'transcript' => $transcript,
            'transcriptstate' => $state,
            'transcriptcode' => $state === 'failed' ? 'gateway_error' : null,
            'audiodeleted' => 0,
            'timecreated' => $modified,
            'timemodified' => $modified,
        ]);
        $DB->set_field('quizgeist_clips', 'itemid', $id, ['id' => $id]);
        $context = \context_module::instance($module->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => clip_service::COMPONENT,
            'filearea' => clip_limits::FILE_AREA,
            'itemid' => $id,
            'filepath' => '/',
            'filename' => 'clip' . $id . '.wav',
            'userid' => $user->id,
            'mimetype' => 'audio/wav',
        ], 'RIFF');
        return [
            'clip' => $DB->get_record('quizgeist_clips', ['id' => $id], '*', MUST_EXIST),
            'context' => $context,
        ];
    }

    public function test_purge_removes_only_terminal_audio_and_keeps_transcript(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('clip_retention_days', 0, 'mod_quizgeist');
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $old = time() - (2 * DAYSECS);

        $done = $this->clip($module, $user, 'done', $old, 'fertiger Text');
        $failed = $this->clip($module, $user, 'failed', $old, 'Fehlertext');
        $declined = $this->clip($module, $user, 'declined', $old, 'abgelehnter Text');
        $pending = $this->clip($module, $user, 'pending', $old, '');

        (new purge_clips())->execute();

        foreach ([$done['clip'], $failed['clip'], $declined['clip']] as $terminal) {
            $fresh = $DB->get_record('quizgeist_clips', ['id' => $terminal->id], '*', MUST_EXIST);
            $this->assertGreaterThan(0, (int)$fresh->audiodeleted);
            $this->assertSame((string)$terminal->transcript, (string)$fresh->transcript);
            $this->assertNull(clip_service::file($done['context'], $fresh));
        }
        $freshpending = $DB->get_record('quizgeist_clips', ['id' => $pending['clip']->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$freshpending->audiodeleted);
        $this->assertNotNull(clip_service::file($pending['context'], $freshpending));
    }

    public function test_positive_retention_days_protects_fresh_terminal_audio(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('clip_retention_days', 7, 'mod_quizgeist');
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $oldclip = $this->clip(
            $module,
            $user,
            'declined',
            time() - (8 * DAYSECS),
            'alter Text'
        );
        $freshclip = $this->clip($module, $user, 'done', time(), 'frischer Text');

        (new purge_clips())->execute();

        $row = $DB->get_record('quizgeist_clips', ['id' => $freshclip['clip']->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$row->audiodeleted);
        $this->assertSame('frischer Text', (string)$row->transcript);
        $this->assertNotNull(clip_service::file($freshclip['context'], $row));
        $oldrow = $DB->get_record('quizgeist_clips', ['id' => $oldclip['clip']->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int)$oldrow->audiodeleted);
        $this->assertSame('alter Text', (string)$oldrow->transcript);
        $this->assertNull(clip_service::file($oldclip['context'], $oldrow));
    }
}
