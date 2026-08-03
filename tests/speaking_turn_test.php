<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for one idempotent spoken live turn.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\ajax\action_context;
use mod_quizgeist\local\ajax\live_answer_handler;
use mod_quizgeist\local\live\session_service;
use mod_quizgeist\local\live\session_state;
use mod_quizgeist\local\media\clip_binding;

defined('MOODLE_INTERNAL') || die();

/**
 * A spoken repeatable interaction is one ledger row and one clip, even when
 * the POST retries with the same submission key.
 *
 * @covers \mod_quizgeist\local\live\live_submission_service::submit
 * @covers \mod_quizgeist\local\ajax\live_answer_handler::execute
 * @covers \mod_quizgeist\local\media\clip_binding::attach
 */
final class speaking_turn_test extends \advanced_testcase {

    public function test_one_spoken_turn_is_idempotent_for_submission_key(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $handler = new live_answer_handler();
        $request = static function (array $fixture): action_context {
            return new action_context(
                'live_answer',
                $fixture['cm'],
                $fixture['instance'],
                $fixture['context'],
                $fixture['user'],
                [
                    'sessionId' => $fixture['sessionid'],
                    'questionId' => $fixture['questionid'],
                    'questionToken' => $fixture['token'],
                    'answer' => ['clipId' => $fixture['clipid']],
                    'submissionKey' => 'turn-2026-08-01',
                ]
            );
        };
        $first = $handler->execute($request($fixture));
        $second = $handler->execute($request($fixture));

        $this->assertTrue($first['accepted']);
        $this->assertTrue($second['accepted']);
        $this->assertSame(
            1,
            $DB->count_records('quizgeist_answers', [
                'sessionid' => $fixture['sessionid'],
                'questionid' => $fixture['questionid'],
                'userid' => $fixture['user']->id,
            ])
        );
        $answer = $DB->get_record('quizgeist_answers', [
            'sessionid' => $fixture['sessionid'],
            'questionid' => $fixture['questionid'],
        ], '*', MUST_EXIST);
        $this->assertSame($answer->id, $DB->get_field(
            'quizgeist_clips', 'answerid', ['id' => $fixture['clipid']]
        ));
        $this->assertSame(
            $fixture['clipid'],
            (int)json_decode((string)$answer->answerjson, true)['clipId']
        );
        $this->assertSame(1, $DB->count_records('quizgeist_clips', [
            'id' => $fixture['clipid'],
        ]));
        $this->assertSame($first, $second);
    }

    /**
     * Build a real live session and put it into its question phase.
     *
     * @return array{cm:\stdClass,instance:\stdClass,context:\context_module,user:\stdClass,
     *   sessionid:int,questionid:int,token:string,clipid:int}
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $cm = get_coursemodule_from_id(
            'quizgeist',
            $module->cmid,
            $course->id,
            false,
            MUST_EXIST
        );
        $context = \context_module::instance($module->cmid);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $now = time();
        $questionid = (int)$DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$module->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'brainstorm',
            'questiontext' => 'Nenne eine Idee.',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode(['collectSeconds' => 60, 'voteSeconds' => 30]),
            'timelimit' => 20,
            'pointmode' => 'standard',
            'explanation' => null,
            'status' => 'ready',
            'createdby' => (int)$user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, ['id' => $questionid]);
        $instance = $DB->get_record('quizgeist', ['id' => (int)$module->id], '*', MUST_EXIST);
        $created = session_service::create_session_for_questions(
            $instance,
            $context,
            $user,
            'classic',
            'generated',
            [$questionid]
        );
        $sessionid = (int)$created['state']['sessionId'];
        $session = $DB->get_record('quizgeist_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $state = session_state::enter_question(
            session_state::decode((string)$session->statejson),
            0,
            $now * 1000 - 5000,
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
        $DB->update_record('quizgeist_session_questions', (object)[
            'id' => $DB->get_field('quizgeist_session_questions', 'id', [
                'sessionid' => $sessionid,
                'sortindex' => 0,
            ]),
            'visit' => $state['questionToken'],
            'visitstate' => 'active',
            'resolvedvisit' => null,
            'stage' => 'collect',
        ]);
        $playerid = (int)$DB->insert_record('quizgeist_players', (object)[
            'sessionid' => $sessionid,
            'userid' => (int)$user->id,
            'displayname' => 'Speaking learner',
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
        $clipid = (int)$DB->insert_record('quizgeist_clips', (object)[
            'quizgeistid' => (int)$module->id,
            'userid' => (int)$user->id,
            'answerid' => null,
            'purpose' => 'answer',
            'itemid' => 0,
            'durationms' => 1000,
            'bytes' => 128,
            'language' => 'de',
            'transcript' => null,
            'transcriptstate' => 'pending',
            'transcriptcode' => null,
            'audiodeleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_clips', 'itemid', $clipid, ['id' => $clipid]);
        // This is the persisted upload fixture used by the binding path. A
        // CLI test cannot create a real HTTP multipart upload; upload-origin
        // hardening is covered by clip_service_test's test seam.
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_quizgeist',
            'filearea' => 'clipaudio',
            'itemid' => $clipid,
            'filepath' => '/',
            'filename' => 'clip' . $clipid . '.wav',
            'userid' => (int)$user->id,
            'mimetype' => 'audio/wav',
        ], 'RIFF');
        $this->assertNotNull(
            get_file_storage()->get_file(
                $context->id,
                'mod_quizgeist',
                'clipaudio',
                $clipid,
                '/',
                'clip' . $clipid . '.wav'
            )
        );
        // Keep the player variable in the fixture construction visibly tied
        // to the session; the submission service resolves it from SQL.
        $this->addToAssertionCount($playerid > 0 ? 1 : 0);

        return [
            'cm' => $cm,
            'instance' => $instance,
            'context' => $context,
            'user' => $user,
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'token' => $state['questionToken'],
            'clipid' => $clipid,
        ];
    }
}
