<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for clip-backed spoken answer binding.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\qtype\open;
use mod_quizgeist\local\media\clip_binding;
use mod_quizgeist\local\media\clip_exception;
use mod_quizgeist\local\media\clip_service;

defined('MOODLE_INTERNAL') || die();

/**
 * A spoken answer is valid before ASR finishes, but ownership stays server-side.
 *
 * @covers \mod_quizgeist\local\live\qtype\open
 * @covers \mod_quizgeist\local\media\clip_binding
 * @covers \mod_quizgeist\local\media\clip_service
 */
final class spoken_answer_test extends \advanced_testcase {

    /**
     * Create a minimal activity, user and answer row.
     *
     * @return array{module:\stdClass,user:\stdClass,answerid:int}
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $now = time();
        $questionid = $DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => $module->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'open',
            'questiontext' => 'Was hast du gesagt?',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode(['sampleAnswer' => '', 'reasonStep' => false]),
            'timelimit' => 30,
            'pointmode' => 'standard',
            'explanation' => '',
            'status' => 'ready',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, ['id' => $questionid]);
        $answerid = $DB->insert_record('quizgeist_answers', (object)[
            'sessionid' => null,
            'playerid' => null,
            'attemptid' => null,
            'questionid' => $questionid,
            'userid' => $user->id,
            'answertype' => 'answer',
            'visit' => null,
            'submissionkey' => null,
            'answerjson' => json_encode(['text' => '', 'clipId' => 0]),
            'iscorrect' => null,
            'points' => 0,
            'maxpoints' => 0,
            'responsetime' => 0,
            'timecreated' => $now,
        ]);
        return ['module' => $module, 'user' => $user, 'answerid' => $answerid];
    }

    /**
     * Insert an unbound clip owned by one user.
     *
     * @param \stdClass $module Activity.
     * @param \stdClass $user Owner.
     * @param string $purpose Purpose.
     * @return int Clip ID.
     */
    private function clip(\stdClass $module, \stdClass $user, string $purpose = 'answer'): int {
        global $DB;

        $now = time();
        return (int)$DB->insert_record('quizgeist_clips', (object)[
            'quizgeistid' => $module->id,
            'userid' => $user->id,
            'answerid' => null,
            'purpose' => $purpose,
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
    }

    public function test_clip_id_is_accepted_before_transcript_and_published_later(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $clipid = $this->clip($fixture['module'], $fixture['user']);
        $canonical = (new open())->validate_answer([], ['clipId' => $clipid]);
        $this->assertSame('', $canonical['text']);
        $this->assertSame($clipid, $canonical['clipId']);

        clip_binding::attach(
            (int)$fixture['module']->id,
            $clipid,
            $fixture['answerid'],
            (int)$fixture['user']->id,
            'answer'
        );
        clip_service::store_transcript($clipid, 'gesprochener Text');
        $this->assertTrue(clip_binding::publish_transcript($clipid));
        $answer = $DB->get_record('quizgeist_answers', ['id' => $fixture['answerid']], '*', MUST_EXIST);
        $this->assertSame('gesprochener Text', json_decode($answer->answerjson, true)['text']);
    }

    public function test_foreign_clip_is_rejected_without_resolving_its_owner(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $foreign = $this->getDataGenerator()->create_and_enrol(
            $this->getDataGenerator()->create_course()
        );
        $clipid = $this->clip($fixture['module'], $foreign);

        try {
            clip_binding::attach(
                (int)$fixture['module']->id,
                $clipid,
                $fixture['answerid'],
                (int)$fixture['user']->id,
                'answer'
            );
            $this->fail('A foreign clip must not bind to an answer.');
        } catch (clip_exception $exception) {
            $this->assertSame('clip_not_found', $exception->get_error_code());
        }
    }

    public function test_a_second_clip_cannot_bind_to_the_same_answer(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $first = $this->clip($fixture['module'], $fixture['user']);
        $second = $this->clip($fixture['module'], $fixture['user']);
        clip_binding::attach(
            (int)$fixture['module']->id,
            $first,
            $fixture['answerid'],
            (int)$fixture['user']->id,
            'answer'
        );

        try {
            clip_binding::attach(
                (int)$fixture['module']->id,
                $second,
                $fixture['answerid'],
                (int)$fixture['user']->id,
                'answer'
            );
            $this->fail('The answer-to-clip relation must be one-to-one.');
        } catch (\dml_exception $exception) {
            // The unique answer index is the authoritative machine-level
            // diagnosis; no localized exception message is asserted here.
            $this->assertInstanceOf(\dml_exception::class, $exception);
        }
    }
}
