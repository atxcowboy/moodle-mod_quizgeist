<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for idempotent card-scan confirmation.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\ajax\action_context;
use mod_quizgeist\local\cards\card_limits;
use mod_quizgeist\local\cards\card_scan_service;
use mod_quizgeist\local\cards\cardset_service;
use mod_quizgeist\local\live\session_service;
use mod_quizgeist\local\live\session_state;
use quizgeistaddon_ai\local\ajax\card_scan_confirm_handler;

defined('MOODLE_INTERNAL') || die();

/**
 * A card scan books each card once, and discard never books anything.
 *
 * @covers \quizgeistaddon_ai\local\ajax\card_scan_confirm_handler
 * @covers \mod_quizgeist\local\cards\card_scan_service
 */
final class card_confirm_idempotent_test extends \advanced_testcase {

    public function test_confirm_is_idempotent_and_discard_is_side_effect_free(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $handler = new card_scan_confirm_handler();
        $confirmrequest = static function (array $fixture, int $scanid, bool $discard = false): action_context {
            return new action_context(
                'card_scan_confirm',
                $fixture['cm'],
                $fixture['instance'],
                $fixture['context'],
                $fixture['teacher'],
                ['scanId' => $scanid, 'discard' => $discard]
            );
        };

        $scan = $this->create_scan($fixture);
        $first = $handler->execute($confirmrequest($fixture, (int)$scan->id));
        $this->assertSame(2, (int)$first['booked']);
        $fresh = $DB->get_record('quizgeist_card_scans', ['id' => $scan->id], '*', MUST_EXIST);
        $this->assertSame('confirmed', (string)$fresh->state);
        $this->assertGreaterThan(0, (int)$fresh->imagedeleted);
        $this->assertNull(card_scan_service::file($fixture['context'], $fresh));

        $answers = array_values($DB->get_records('quizgeist_answers', [
            'sessionid' => $fixture['sessionid'],
            'questionid' => $fixture['questionid'],
            'visit' => $fixture['visit'],
        ], 'id ASC'));
        $this->assertCount(2, $answers);
        $userids = array_map(static fn(\stdClass $answer): int => (int)$answer->userid, $answers);
        sort($userids, SORT_NUMERIC);
        $expecteduserids = [(int)$fixture['players'][0]->id, (int)$fixture['players'][1]->id];
        sort($expecteduserids, SORT_NUMERIC);
        $this->assertSame($expecteduserids, $userids);

        // The complete answer table is the idempotency witness, not only a count.
        $beforesecond = $this->answers_hash();
        $second = $handler->execute($confirmrequest($fixture, (int)$scan->id));
        $aftersecond = $this->answers_hash();
        $this->assertSame($beforesecond, $aftersecond);
        $this->assertSame(2, $DB->count_records('quizgeist_answers', [
            'sessionid' => $fixture['sessionid'],
            'questionid' => $fixture['questionid'],
            'visit' => $fixture['visit'],
        ]));
        $this->assertSame('confirmed', (string)$second['scan']['state']);

        $discard = $this->create_scan($fixture);
        $beforediscard = $this->answers_hash();
        $discarded = $handler->execute($confirmrequest($fixture, (int)$discard->id, true));
        $afterdiscard = $this->answers_hash();
        $this->assertSame($beforediscard, $afterdiscard);
        $this->assertSame(0, (int)$discarded['booked']);
        $freshdiscard = $DB->get_record('quizgeist_card_scans', ['id' => $discard->id], '*', MUST_EXIST);
        $this->assertSame('discarded', (string)$freshdiscard->state);
        $this->assertGreaterThan(0, (int)$freshdiscard->imagedeleted);
        $this->assertNull(card_scan_service::file($fixture['context'], $freshdiscard));
    }

    /**
     * Build a real live session with one quiz question and two players.
     *
     * @return array<string,mixed>
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $playerone = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $playertwo = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $cm = get_coursemodule_from_instance(
            'quizgeist',
            $module->id,
            $course->id,
            false,
            MUST_EXIST
        );
        $context = \context_module::instance($cm->id);
        $instance = $DB->get_record('quizgeist', ['id' => $module->id], '*', MUST_EXIST);
        $now = time();
        $questionid = (int)$DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$module->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'quiz',
            'questiontext' => 'Welche Antwort ist richtig?',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode([
                'media' => null,
                'multiple' => false,
                'answers' => [
                    ['id' => 'a', 'text' => 'Richtig', 'media' => null, 'correct' => true],
                    ['id' => 'b', 'text' => 'Falsch', 'media' => null, 'correct' => false],
                ],
            ], JSON_THROW_ON_ERROR),
            'timelimit' => 20,
            'pointmode' => 'standard',
            'explanation' => null,
            'status' => 'ready',
            'createdby' => (int)$teacher->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, ['id' => $questionid]);

        $this->setUser($teacher);
        $created = session_service::create_session_for_questions(
            $instance,
            $context,
            $teacher,
            'classic',
            'generated',
            [$questionid]
        );
        $sessionid = (int)$created['state']['sessionId'];
        $session = $DB->get_record('quizgeist_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $state = session_state::enter_question(
            session_state::decode((string)$session->statejson),
            0,
            ($now * 1000) - 10000,
            20
        );
        $DB->update_record('quizgeist_sessions', (object)[
            'id' => $sessionid,
            'status' => 'question',
            'currentquestionid' => $questionid,
            'stateversion' => (int)$session->stateversion + 1,
            'statejson' => session_state::encode($state),
            'timemodified' => $now,
        ]);
        $sessionquestionid = (int)$DB->get_field('quizgeist_session_questions', 'id', [
            'sessionid' => $sessionid,
            'sortindex' => 0,
        ], MUST_EXIST);
        $DB->update_record('quizgeist_session_questions', (object)[
            'id' => $sessionquestionid,
            'visit' => $state['questionToken'],
            'visitstate' => 'active',
            'resolvedvisit' => null,
            'stage' => 'answer',
        ]);

        foreach ([$playerone, $playertwo] as $player) {
            $DB->insert_record('quizgeist_players', (object)[
                'sessionid' => $sessionid,
                'userid' => (int)$player->id,
                'displayname' => fullname($player),
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
        }
        $cardset = cardset_service::create(
            $instance,
            (int)$teacher->id,
            'Idempotency cards',
            'abcd',
            [(int)$playerone->id, (int)$playertwo->id],
            0
        );
        return [
            'course' => $course,
            'cm' => (object)$cm,
            'context' => $context,
            'instance' => $instance,
            'teacher' => $teacher,
            'players' => [$playerone, $playertwo],
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'visit' => $state['questionToken'],
            'cardset' => $cardset,
        ];
    }

    /**
     * Insert one recognised scan and its private cardscan file.
     *
     * @param array<string,mixed> $fixture
     * @return \stdClass
     */
    private function create_scan(array $fixture): \stdClass {
        global $DB;

        $cards = $fixture['cardset']->cards;
        $now = time();
        $id = (int)$DB->insert_record('quizgeist_card_scans', (object)[
            'quizgeistid' => (int)$fixture['instance']->id,
            'sessionid' => (int)$fixture['sessionid'],
            'questionid' => (int)$fixture['questionid'],
            'visit' => (string)$fixture['visit'],
            'cardsetid' => (int)$fixture['cardset']->id,
            'scannedby' => (int)$fixture['teacher']->id,
            'itemid' => 0,
            'state' => 'recognised',
            'reasoncode' => null,
            'resultjson' => json_encode([
                'schemaVersion' => 1,
                'accepted' => [
                    [
                        'cardCode' => (string)$cards[0]->cardcode,
                        'userId' => (int)$cards[0]->userid,
                        'answerKey' => 'A',
                        'confidence' => 0.99,
                        'box' => null,
                        'bookable' => true,
                        'source' => 'model',
                    ],
                    [
                        'cardCode' => (string)$cards[1]->cardcode,
                        'userId' => (int)$cards[1]->userid,
                        'answerKey' => 'B',
                        'confidence' => 0.99,
                        'box' => null,
                        'bookable' => true,
                        'source' => 'model',
                    ],
                ],
                'rejected' => [],
            ], JSON_THROW_ON_ERROR),
            'recognised' => 2,
            'expected' => 2,
            'imagedeleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_card_scans', 'itemid', $id, ['id' => $id]);
        get_file_storage()->create_file_from_string([
            'contextid' => $fixture['context']->id,
            'component' => card_scan_service::COMPONENT,
            'filearea' => card_limits::FILE_AREA,
            'itemid' => $id,
            'filepath' => '/',
            'filename' => 'scan' . $id . '.jpg',
            'userid' => (int)$fixture['teacher']->id,
            'mimetype' => 'image/jpeg',
        ], 'scan bytes');
        return $DB->get_record('quizgeist_card_scans', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Hash every column of every answer row in stable order.
     *
     * @return string SHA-256 digest.
     */
    private function answers_hash(): string {
        global $DB;

        $rows = [];
        foreach ($DB->get_records('quizgeist_answers', [], 'id ASC') as $row) {
            $values = (array)$row;
            ksort($values);
            $rows[] = $values;
        }
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
