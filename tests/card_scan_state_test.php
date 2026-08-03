<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the persisted card-scan state and safe projection.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\cards\card_scan_service;
use mod_quizgeist\local\cards\cardset_service;

defined('MOODLE_INTERNAL') || die();

/**
 * A scan has a visible lifecycle and never exposes its machine diagnosis.
 *
 * @covers \mod_quizgeist\local\cards\card_scan_service::store_result
 * @covers \mod_quizgeist\local\cards\card_scan_service::mark_failed
 * @covers \mod_quizgeist\local\cards\card_scan_service::project
 */
final class card_scan_state_test extends \advanced_testcase {

    public function test_scan_lifecycle_and_reason_family_projection(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $scan = $fixture['scan'];
        $this->assertSame('pending', (string)$scan->state);

        $stored = card_scan_service::store_result(
            $fixture['quizgeist'],
            $scan,
            [[
                'cardcode' => (string)$fixture['card']->cardcode,
                'answerKey' => 'A',
                'confidence' => 0.95,
            ]]
        );
        $this->assertSame('recognised', (string)$stored->state);
        $this->assertSame(1, (int)$stored->recognised);
        $this->assertLessThanOrEqual((int)$stored->expected, (int)$stored->recognised);
        $this->assertSame(
            'recognised',
            (string)$DB->get_field('quizgeist_card_scans', 'state', ['id' => $stored->id])
        );

        $failed = card_scan_service::mark_failed(
            $stored,
            str_repeat('Vision-Failure.', 8)
        );
        $this->assertSame('failed', (string)$failed->state);
        $this->assertLessThanOrEqual(32, strlen((string)$failed->reasoncode));
        $this->assertSame(
            'failed',
            (string)$DB->get_field('quizgeist_card_scans', 'state', ['id' => $failed->id])
        );
        $persistedreason = (string)$DB->get_field(
            'quizgeist_card_scans',
            'reasoncode',
            ['id' => $failed->id]
        );
        $this->assertLessThanOrEqual(32, strlen($persistedreason));

        $projection = card_scan_service::project($failed);
        $this->assertArrayNotHasKey('reasoncode', $projection);
        $this->assertSame('failed', $projection['reasonFamily']);
    }

    /**
     * Build the smallest persisted activity, question, session, player, set
     * and scan needed by store_result().
     *
     * @return array{quizgeist:\stdClass,scan:\stdClass,card:\stdClass}
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $quizgeist = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $now = time();

        $questionid = (int)$DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$quizgeist->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'truefalse',
            'questiontext' => 'Ist Wasser nass?',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode(['media' => null, 'correct' => true]),
            'timelimit' => 20,
            'pointmode' => 'standard',
            'explanation' => '',
            'status' => 'ready',
            'createdby' => (int)$user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, ['id' => $questionid]);

        $sessionid = (int)$DB->insert_record('quizgeist_sessions', (object)[
            'quizgeistid' => (int)$quizgeist->id,
            'hostuserid' => (int)$user->id,
            'joincode' => 'CS' . substr(md5((string)$quizgeist->id), 0, 4),
            'status' => 'question',
            'mode' => 'classic',
            'currentquestionid' => $questionid,
            'stateversion' => 1,
            'statejson' => '{}',
            'settingsjson' => '{}',
            'timestarted' => $now,
            'timeended' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('quizgeist_players', (object)[
            'sessionid' => $sessionid,
            'userid' => (int)$user->id,
            'displayname' => 'Card learner',
            'groupid' => null,
            'teamname' => null,
            'avatarkey' => null,
            'score' => 0,
            'streak' => 0,
            'status' => 'joined',
            'timejoined' => $now,
            'lastseen' => $now,
            'timemodified' => $now,
        ]);

        $set = cardset_service::create(
            $quizgeist,
            (int)$user->id,
            'Boundary set',
            'truefalse',
            [(int)$user->id],
            0
        );
        $card = $set->cards[0];

        $scanid = (int)$DB->insert_record('quizgeist_card_scans', (object)[
            'quizgeistid' => (int)$quizgeist->id,
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'visit' => str_repeat('a', 32),
            'cardsetid' => (int)$set->id,
            'scannedby' => (int)$user->id,
            'itemid' => 0,
            'state' => 'pending',
            'reasoncode' => null,
            'resultjson' => null,
            'recognised' => 0,
            'expected' => 1,
            'imagedeleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_card_scans', 'itemid', $scanid, ['id' => $scanid]);

        return [
            'quizgeist' => $quizgeist,
            'scan' => $DB->get_record('quizgeist_card_scans', ['id' => $scanid], '*', MUST_EXIST),
            'card' => $card,
        ];
    }
}
