<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for reason visibility in the session report (F2).
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\report\report_access;
use mod_quizgeist\local\report\report_metrics;

defined('MOODLE_INTERNAL') || die();

/**
 * A learner sees only their own justification — proof in the payload.
 */
final class report_reason_visibility_test extends \advanced_testcase {

    public function test_a_learner_boundary_admits_only_themselves(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $context = \context_module::instance($module->cmid);
        $cm = get_coursemodule_from_id('quizgeist', $module->cmid, 0, false, MUST_EXIST);

        $learneraccess = report_access::for_module($cm, $context, $learner, 0);
        $this->assertFalse($learneraccess->is_teacher());
        $this->assertTrue($learneraccess->can_view_user((int)$learner->id));
        // The whole promise of F2 rests on this one boundary.
        $this->assertFalse($learneraccess->can_view_user((int)$other->id));
        $this->assertSame((int)$learner->id, $learneraccess->viewer_id());

        $teacheraccess = report_access::for_module($cm, $context, $teacher, 0);
        $this->assertTrue($teacheraccess->is_teacher());
        $this->assertTrue($teacheraccess->can_view_user((int)$learner->id));
        $this->assertTrue($teacheraccess->can_view_user((int)$other->id));
    }

    public function test_reasons_are_ordered_deterministically(): void {
        $this->resetAfterTest(true);

        $questions = [
            'q:1' => [
                'rootKey' => 'q:1',
                'quizgeistId' => 1,
                'rootId' => 1,
                'title' => 'Frage',
                'qtypes' => ['quiz' => true],
                'points' => 0,
                'maxPoints' => 0,
                'correctCount' => 0,
                'gradableCount' => 0,
                'responseCount' => 0,
                'missingCount' => 0,
                '_timeTotal' => 0,
                '_timeSamples' => 0,
                '_latestVersion' => 1,
                '_versions' => [
                    1 => [
                        'questionId' => 1,
                        'version' => 1,
                        'qtype' => 'quiz',
                        'questionText' => 'Frage',
                    ],
                ],
                'distributions' => [],
                'reasons' => [
                    ['questionId' => 1, 'userId' => 9, 'own' => false,
                        'text' => 'spät', 'timeCreated' => 200],
                    ['questionId' => 1, 'userId' => 4, 'own' => true,
                        'text' => 'früh', 'timeCreated' => 100],
                ],
            ],
        ];

        $finished = report_metrics::finish_questions($questions, 5, 50.0);

        $this->assertCount(1, $finished);
        $this->assertSame(
            ['früh', 'spät'],
            array_column($finished[0]['reasons'], 'text')
        );
    }

    public function test_a_question_without_reasons_keeps_an_empty_list(): void {
        $this->resetAfterTest(true);

        $questions = [
            'q:2' => [
                'rootKey' => 'q:2',
                'quizgeistId' => 1,
                'rootId' => 2,
                'title' => 'Ohne',
                'qtypes' => ['quiz' => true],
                'points' => 0,
                'maxPoints' => 0,
                'correctCount' => 0,
                'gradableCount' => 0,
                'responseCount' => 0,
                'missingCount' => 0,
                '_timeTotal' => 0,
                '_timeSamples' => 0,
                '_latestVersion' => 1,
                '_versions' => [],
                'distributions' => [],
                'reasons' => [],
            ],
        ];

        $finished = report_metrics::finish_questions($questions, 5, 50.0);
        $this->assertSame([], $finished[0]['reasons']);
    }
}
