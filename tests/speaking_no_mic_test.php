<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for microphone-independent speaking assessment.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\ajax\action_context;
use quizgeistaddon_ai\local\ajax\speaking_turn_handler;

defined('MOODLE_INTERNAL') || die();

/**
 * The speaking endpoint scores text, regardless of whether it came from typing or ASR.
 *
 * @covers \quizgeistaddon_ai\local\ajax\speaking_turn_handler
 */
final class speaking_no_mic_test extends \advanced_testcase {

    public function test_typed_text_and_same_transcript_receive_identical_assessment(): void {
        global $DB;

        $this->resetAfterTest(true);
        // The handler's injected model callback then fails at client
        // construction and speaking_coach falls back deterministically, before
        // any network request can be attempted.
        set_config('gpuq_base_url', 'https://invalid.example/', 'mod_quizgeist');

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $user = $this->getDataGenerator()->create_and_enrol($course);
        $cm = get_coursemodule_from_instance(
            'quizgeist',
            $module->id,
            $course->id,
            false,
            MUST_EXIST
        );
        $instance = $DB->get_record('quizgeist', ['id' => $module->id], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $text = 'Die Gewaltenteilung verteilt staatliche Macht auf mehrere Organe.';
        $accepted = [
            'Staatliche Macht wird auf mehrere Organe verteilt.',
            'Gewaltenteilung verhindert Machtkonzentration.',
        ];
        $task = 'Erkläre die Gewaltenteilung.';
        // speaking_turn is a discrete task endpoint; unlike live answer
        // submission it has no session identifier. The action context, task
        // payload and persisted speaking clip are therefore the real handler
        // fixtures used by this route.

        $now = time();
        $clipid = (int)$DB->insert_record('quizgeist_clips', (object)[
            'quizgeistid' => $module->id,
            'userid' => $user->id,
            'answerid' => null,
            'purpose' => 'speaking',
            'itemid' => 0,
            'durationms' => 1500,
            'bytes' => 0,
            'language' => 'de',
            'transcript' => $text,
            'transcriptstate' => 'done',
            'transcriptcode' => null,
            'audiodeleted' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_clips', 'itemid', $clipid, ['id' => $clipid]);

        $handler = new speaking_turn_handler();
        $typed = $handler->execute(new action_context(
            'speaking_turn',
            (object)$cm,
            $instance,
            $context,
            $user,
            ['text' => $text, 'accepted' => $accepted, 'task' => $task]
        ))['turn'];
        $spoken = $handler->execute(new action_context(
            'speaking_turn',
            (object)$cm,
            $instance,
            $context,
            $user,
            ['clipId' => $clipid, 'accepted' => $accepted, 'task' => $task]
        ))['turn'];

        foreach ([
            'origin', 'score', 'passed', 'feedback', 'nextPrompt', 'matched', 'missing',
            'valid', 'validationErrors', 'boundaryHonest', 'warnings', 'transcript',
        ] as $field) {
            $this->assertSame($typed[$field], $spoken[$field], "Mismatch in turn field {$field}.");
        }
        $this->assertFalse($typed['spoken']);
        $this->assertTrue($spoken['spoken']);
        $this->assertArrayHasKey('clip', $spoken);
    }
}
