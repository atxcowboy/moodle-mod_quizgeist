<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the server-side short-clip acceptance boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/*
 * PHPUnit cannot manufacture an HTTP multipart upload in CLI mode. The
 * production class resolves this unqualified call in its own namespace, so a
 * test-only shim lets the public end-to-end path run while defaulting false.
 */
namespace mod_quizgeist\local\media {
    function is_uploaded_file($filename): bool {
        global $QUIZGEIST_TEST_UPLOADS;
        return !empty($QUIZGEIST_TEST_UPLOADS[(string)$filename]);
    }
}

namespace mod_quizgeist {

use mod_quizgeist\local\media\clip_access;
use mod_quizgeist\local\media\clip_exception;
use mod_quizgeist\local\media\clip_limits;
use mod_quizgeist\local\media\clip_probe;
use mod_quizgeist\local\media\clip_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Exercises each inexpensive upload hardening rule through the public upload
 * API. The only test seam is the CLI upload-origin shim above.
 *
 * @covers \mod_quizgeist\local\media\clip_service
 * @covers \mod_quizgeist\local\media\clip_probe
 * @covers \mod_quizgeist\local\media\clip_access
 */
final class clip_service_test extends \advanced_testcase {

    /**
     * A small PCM WAVE container whose duration is measurable from its bytes.
     *
     * @param int $seconds Duration in seconds.
     * @return string
     */
    private function wav(int $seconds = 1): string {
        $samplerate = 8000;
        $channels = 1;
        $bits = 16;
        $byterate = $samplerate * $channels * intdiv($bits, 8);
        $datasize = $byterate * $seconds;
        return 'RIFF'
            . pack('V', 36 + $datasize)
            . 'WAVE'
            . 'fmt '
            . pack('V', 16)
            . pack('v', 1)
            . pack('v', $channels)
            . pack('V', $samplerate)
            . pack('V', $byterate)
            . pack('v', $channels * intdiv($bits, 8))
            . pack('v', $bits)
            . 'data'
            . pack('V', $datasize)
            . str_repeat("\0", $datasize);
    }

    /**
     * Write one disposable test file.
     *
     * @param string $contents Bytes.
     * @return string
     */
    private function file(string $contents): string {
        $path = tempnam(sys_get_temp_dir(), 'quizgeist-clip-');
        $this->assertNotFalse($path);
        $this->assertSame(strlen($contents), file_put_contents($path, $contents));
        return $path;
    }

    /** @return array{context:\context_module,quizgeistid:int,userid:int} */
    private function fixture(): array {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        return [
            'context' => \context_module::instance($module->cmid),
            'quizgeistid' => (int)$module->id,
            'userid' => (int)$user->id,
        ];
    }

    private function upload(string $path, string $type = 'audio/wav'): array {
        return [
            'name' => 'voice.wav',
            'type' => $type,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    private function mark_uploaded(string $path): void {
        global $QUIZGEIST_TEST_UPLOADS;
        $QUIZGEIST_TEST_UPLOADS[$path] = true;
    }

    private function unmark_uploaded(string $path): void {
        global $QUIZGEIST_TEST_UPLOADS;
        unset($QUIZGEIST_TEST_UPLOADS[$path]);
    }

    public function test_valid_clip_is_probed_and_all_guards_accept_it(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        \cache::make('mod_quizgeist', 'cliprate')->set(
            $fixture['quizgeistid'] . '_' . $fixture['userid'],
            ['started' => time(), 'uploads' => 0]
        );
        $contents = $this->wav();
        $path = $this->file($contents);
        $this->mark_uploaded($path);
        try {
            $clip = clip_service::accept_upload(
                $fixture['context'],
                $fixture['quizgeistid'],
                $fixture['userid'],
                'answer',
                'de-DE',
                $this->upload($path, 'audio/x-wav')
            );
            $this->assertGreaterThan(0, (int)$clip->id);
            $this->assertSame($fixture['quizgeistid'], (int)$clip->quizgeistid);
            $this->assertSame(1000, (int)$clip->durationms);
            $this->assertSame(strlen($contents), (int)$clip->bytes);
            $this->assertSame('de', (string)$clip->language);
            $this->assertTrue($DB->record_exists('quizgeist_clips', ['id' => $clip->id]));
            $stored = clip_service::file($fixture['context'], $clip);
            $this->assertNotNull($stored);
            $this->assertSame('audio/wav', $stored->get_mimetype());

            $probe = clip_probe::inspect($path);
            $this->assertSame('', $probe['code']);
            $this->assertSame('audio/wav', $probe['mimetype']);
            $this->assertSame(1000, $probe['durationms']);
            $this->addToAssertionCount(1);
        } finally {
            $this->unmark_uploaded($path);
            unlink($path);
        }
    }

    public function test_a_non_uploaded_file_is_rejected_at_public_entry(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $path = $this->file($this->wav());
        try {
            try {
                clip_service::accept_upload(
                    $fixture['context'],
                    $fixture['quizgeistid'],
                    $fixture['userid'],
                    'answer',
                    'de',
                    $this->upload($path)
                );
                $this->fail('A filesystem path must not be treated as an HTTP upload.');
            } catch (clip_exception $exception) {
                $this->assertSame('clip_upload_failed', $exception->get_error_code());
            }
        } finally {
            unlink($path);
        }
    }

    public function test_size_ceiling_is_checked_before_container_parsing(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        set_config('clip_max_bytes', clip_limits::MIN_MAX_BYTES, 'mod_quizgeist');
        $contents = $this->wav() . str_repeat(
            'x',
            clip_limits::MIN_MAX_BYTES + 1 - strlen($this->wav())
        );
        $path = $this->file($contents);
        $this->mark_uploaded($path);
        try {
            try {
                clip_service::accept_upload(
                    $fixture['context'],
                    $fixture['quizgeistid'],
                    $fixture['userid'],
                    'answer',
                    'de',
                    $this->upload($path)
                );
                $this->fail('The byte ceiling must reject before parsing.');
            } catch (clip_exception $exception) {
                $this->assertSame('clip_too_large', $exception->get_error_code());
            }
        } finally {
            $this->unmark_uploaded($path);
            unlink($path);
        }
    }

    public function test_duration_is_measured_from_container_not_a_form_field(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $path = $this->file($this->wav(6));
        $this->mark_uploaded($path);
        try {
            $probe = clip_probe::inspect($path);
            $this->assertSame(6000, $probe['durationms']);
            set_config('clip_max_seconds', 5, 'mod_quizgeist');
            try {
                clip_service::accept_upload(
                    $fixture['context'],
                    $fixture['quizgeistid'],
                    $fixture['userid'],
                    'answer',
                    'de',
                    $this->upload($path) + ['durationms' => 1]
                );
                $this->fail('The server-measured duration must win over form data.');
            } catch (clip_exception $exception) {
                $this->assertSame('clip_too_long', $exception->get_error_code());
            }
        } finally {
            $this->unmark_uploaded($path);
            unlink($path);
        }
    }

    public function test_unknown_magic_bytes_are_rejected(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $path = $this->file('not an audio container');
        $this->mark_uploaded($path);
        try {
            try {
                clip_service::accept_upload(
                    $fixture['context'],
                    $fixture['quizgeistid'],
                    $fixture['userid'],
                    'answer',
                    'de',
                    $this->upload($path)
                );
                $this->fail('Unknown magic bytes must be rejected.');
            } catch (clip_exception $exception) {
                $this->assertSame('clip_signature_unknown', $exception->get_error_code());
            }
        } finally {
            $this->unmark_uploaded($path);
            unlink($path);
        }
    }

    public function test_allowed_mime_with_foreign_signature_is_rejected(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $path = $this->file($this->wav());
        $this->mark_uploaded($path);
        try {
            try {
                clip_service::accept_upload(
                    $fixture['context'],
                    $fixture['quizgeistid'],
                    $fixture['userid'],
                    'answer',
                    'de',
                    $this->upload($path, 'audio/ogg')
                );
                $this->fail('An allowed MIME with foreign signature must fail.');
            } catch (clip_exception $exception) {
                $this->assertSame('clip_type_mismatch', $exception->get_error_code());
            }
        } finally {
            $this->unmark_uploaded($path);
            unlink($path);
        }
    }

    public function test_rate_limit_allows_below_limit_and_rejects_at_limit(): void {
        global $QUIZGEIST_TEST_UPLOADS;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $path = $this->file($this->wav());
        $this->mark_uploaded($path);
        $cache = \cache::make('mod_quizgeist', 'cliprate');
        try {
            $cache->set($fixture['quizgeistid'] . '_' . $fixture['userid'], [
                'started' => time(),
                'uploads' => clip_limits::RATE_LIMIT - 1,
            ]);
            // One upload below the ceiling is accepted and persisted.
            $clip = clip_service::accept_upload(
                $fixture['context'],
                $fixture['quizgeistid'],
                $fixture['userid'],
                'answer',
                'de',
                $this->upload($path)
            );
            $this->assertGreaterThan(0, (int)$clip->id);
            $cache->set($fixture['quizgeistid'] . '_' . $fixture['userid'], [
                'started' => time(),
                'uploads' => clip_limits::RATE_LIMIT,
            ]);
            try {
                clip_service::accept_upload(
                    $fixture['context'],
                    $fixture['quizgeistid'],
                    $fixture['userid'],
                    'answer',
                    'de',
                    $this->upload($path)
                );
                $this->fail('The rate ceiling must reject the next upload.');
            } catch (clip_exception $exception) {
                $this->assertSame('clip_rate_limited', $exception->get_error_code());
            }
        } finally {
            $this->unmark_uploaded($path);
            unset($QUIZGEIST_TEST_UPLOADS[$path]);
            unlink($path);
        }
    }

    public function test_recording_requires_participation_and_own_attempt(): void {
        global $DB;

        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $owner = $this->getDataGenerator()->create_and_enrol($course);
        $foreign = $this->getDataGenerator()->create_and_enrol($course);

        $this->assertFalse(clip_access::may_record((int)$module->id, (int)$owner->id, 'answer'));

        $assignmentid = $DB->insert_record('quizgeist_assignments', (object)[
            'quizgeistid' => $module->id,
            'name' => 'Aufnahme-Test',
            'mode' => 'solo',
            'status' => 'open',
            'timeopen' => 0,
            'timedue' => 0,
            'createdby' => $owner->id,
            'selection' => 'fixed',
            'settingsjson' => null,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('quizgeist_attempts', (object)[
            'assignmentid' => $assignmentid,
            'userid' => $foreign->id,
            'attemptnumber' => 1,
            'mode' => 'solo',
            'status' => 'inprogress',
            'score' => 0,
            'maxscore' => 0,
            'statejson' => null,
            'flashcardsjson' => null,
            'stateversion' => 0,
            'timestarted' => time(),
            'timefinished' => 0,
            'remindedat' => 0,
            'timemodified' => time(),
        ]);
        $this->assertFalse(clip_access::may_record((int)$module->id, (int)$owner->id, 'answer'));

        $DB->insert_record('quizgeist_attempts', (object)[
            'assignmentid' => $assignmentid,
            'userid' => $owner->id,
            'attemptnumber' => 1,
            'mode' => 'solo',
            'status' => 'inprogress',
            'score' => 0,
            'maxscore' => 0,
            'statejson' => null,
            'flashcardsjson' => null,
            'stateversion' => 0,
            'timestarted' => time(),
            'timefinished' => 0,
            'remindedat' => 0,
            'timemodified' => time(),
        ]);
        $this->assertTrue(clip_access::may_record((int)$module->id, (int)$owner->id, 'answer'));
    }
}
}
