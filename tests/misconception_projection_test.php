<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the role-safe misconception radar projection.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\session_settings;
use mod_quizgeist\local\live\session_state;
use mod_quizgeist\local\live\state_projector;

defined('MOODLE_INTERNAL') || die();

/**
 * Misconception labels and the hinge light belong to the host payload only.
 *
 * @covers \mod_quizgeist\local\live\state_projector::poll_fields
 */
final class misconception_projection_test extends \advanced_testcase {

    /**
     * Build one revealed true/false visit with a teacher label on its root.
     *
     * @return array{session:\stdClass,player:\stdClass}
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $context = \context_module::instance($module->cmid);
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $questionid = (int)$DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$module->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'truefalse',
            'questiontext' => 'Ist Wasser nass?',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode(['media' => null, 'correct' => true]),
            'timelimit' => 20,
            'pointmode' => 'standard',
            'explanation' => 'Wasser benetzt andere Oberflächen.',
            'status' => 'ready',
            'createdby' => (int)$user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, [
            'id' => $questionid,
        ]);
        $DB->insert_record('quizgeist_misconceptions', (object)[
            'quizgeistid' => (int)$module->id,
            'rootid' => $questionid,
            'answerkey' => 'true',
            'label' => 'Verwechselt Aggregatzustände',
            'hint' => 'Begriff noch einmal klären.',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $state = session_state::create([$questionid], $now * 1000);
        $state = session_state::enter_question($state, 0, $now * 1000, 20);
        $state = session_state::mark_revealed($state, $now * 1000);
        $settings = session_settings::create(
            'classic',
            'generated',
            [],
            $context,
            null,
            $module
        );
        $sessionid = (int)$DB->insert_record('quizgeist_sessions', (object)[
            'quizgeistid' => (int)$module->id,
            'hostuserid' => (int)$user->id,
            'joincode' => null,
            'status' => 'reveal',
            'mode' => 'classic',
            'currentquestionid' => $questionid,
            'stateversion' => 1,
            'statejson' => session_state::encode($state),
            'settingsjson' => session_settings::encode($settings),
            'timestarted' => $now,
            'timeended' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('quizgeist_session_questions', (object)[
            'sessionid' => $sessionid,
            'sortindex' => 0,
            'questionid' => $questionid,
            'visit' => $state['questionToken'],
            'visitstate' => 'revealed',
            'resolvedvisit' => $state['questionToken'],
            'stage' => 'answer',
        ]);

        // The reports addon is present in the source tree; make its installed
        // marker explicit so the existing-content gate reaches the host radar.
        set_config('version', 1, 'quizgeistaddon_reports');

        return [
            'session' => $DB->get_record(
                'quizgeist_sessions',
                ['id' => $sessionid],
                '*',
                MUST_EXIST
            ),
            'player' => (object)[
                'id' => 1,
                'userid' => (int)$user->id,
            ],
        ];
    }

    public function test_host_gets_radar_fields_but_player_does_not(): void {
        $this->resetAfterTest(true);
        $fixture = $this->fixture();

        $host = state_projector::poll_fields($fixture['session'], 'host');
        $this->assertArrayHasKey('hingeStatus', $host);
        $this->assertNotEmpty($host['distribution']);
        $this->assertArrayHasKey(
            'misconceptionLabel',
            $host['distribution'][0]
        );
        $this->assertSame(
            'Verwechselt Aggregatzustände',
            $host['distribution'][0]['misconceptionLabel']
        );

        $player = state_projector::poll_fields(
            $fixture['session'],
            'player',
            $fixture['player']
        );
        $playerpayload = json_encode($player, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('misconceptionLabel', $playerpayload);
        $this->assertStringNotContainsString('hingeStatus', $playerpayload);
    }
}
