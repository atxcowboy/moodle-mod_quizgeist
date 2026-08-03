<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for server-side question-workshop rules.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\workshop\peer_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Submission, curation and peer-rating invariants are enforced on the server.
 *
 * @covers \mod_quizgeist\local\workshop\peer_service
 */
final class peer_service_test extends \advanced_testcase {

    /**
     * Create an activity and its module context.
     *
     * @return array{quizgeist:\stdClass,context:\context_module}
     */
    private function activity(): array {
        $course = $this->getDataGenerator()->create_course();
        $quizgeist = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        return [
            'quizgeist' => $quizgeist,
            'context' => \context_module::instance($quizgeist->cmid),
        ];
    }

    /**
     * A complete, media-free question accepted by workshop_schema.
     *
     * @param string $explanation Explanation text.
     * @return array
     */
    private function question(string $explanation): array {
        return [
            'qtype' => 'truefalse',
            'questiontext' => 'Ist Wasser nass?',
            'options' => ['media' => null, 'correct' => true],
            'timelimit' => 20,
            'pointmode' => 'standard',
            'explanation' => $explanation,
        ];
    }

    public function test_submission_and_rating_guards_are_server_side(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->activity();
        $author = $this->getDataGenerator()->create_user();
        $rater = $this->getDataGenerator()->create_user();

        $missing = peer_service::submit(
            $fixture['quizgeist'],
            $fixture['context'],
            $author,
            ['question' => $this->question('')]
        );
        $this->assertSame(
            [['field' => 'explanation', 'code' => 'required']],
            $missing['validationErrors']
        );
        $this->assertNull($missing['submission']);

        $submitted = peer_service::submit(
            $fixture['quizgeist'],
            $fixture['context'],
            $author,
            ['question' => $this->question('Weil Wasser Oberflächen benetzt.')]
        );
        $this->assertSame([], $submitted['validationErrors']);
        $workshopid = (int)$submitted['submission']['id'];
        $questionid = (int)$submitted['submission']['questionId'];
        $this->assertSame(
            'draft',
            (string)$DB->get_field(
                'quizgeist_questions',
                'status',
                ['id' => $questionid],
                MUST_EXIST
            )
        );
        $this->assertNotSame('ready', (string)$DB->get_field(
            'quizgeist_questions',
            'status',
            ['id' => $questionid],
            MUST_EXIST
        ));

        $this->setUser($author);
        $unmanaged = peer_service::curate(
            $fixture['quizgeist'],
            $fixture['context'],
            $author,
            ['workshopId' => $workshopid, 'state' => 'approved', 'note' => '']
        );
        $this->assertSame(
            [['field' => 'state', 'code' => 'requires_manage']],
            $unmanaged['validationErrors']
        );

        $self = peer_service::rate(
            $fixture['quizgeist'],
            $author,
            [
                'workshopId' => $workshopid,
                'quality' => 4,
                'difficulty' => 3,
                'comment' => 'Eigene Frage',
            ]
        );
        $this->assertSame(
            [['field' => 'workshopId', 'code' => 'self_rating']],
            $self['validationErrors']
        );

        $first = peer_service::rate(
            $fixture['quizgeist'],
            $rater,
            [
                'workshopId' => $workshopid,
                'quality' => 4,
                'difficulty' => 3,
                'comment' => 'Klar formuliert',
            ]
        );
        $second = peer_service::rate(
            $fixture['quizgeist'],
            $rater,
            [
                'workshopId' => $workshopid,
                'quality' => 5,
                'difficulty' => 2,
                'comment' => 'Nachtrag',
            ]
        );
        $this->assertSame([], $first['validationErrors']);
        $this->assertSame([], $second['validationErrors']);
        $this->assertSame(1, $DB->count_records('quizgeist_workshop_ratings', [
            'workshopid' => $workshopid,
            'userid' => (int)$rater->id,
        ]));
    }
}
