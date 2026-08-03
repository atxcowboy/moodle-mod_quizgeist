<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for card-scan picture retention.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\cards\card_limits;
use mod_quizgeist\local\cards\card_scan_service;
use mod_quizgeist\local\cards\cardset_service;
use mod_quizgeist\task\purge_card_scans;

defined('MOODLE_INTERNAL') || die();

/**
 * The retention task always removes evaluated pictures and ages recognised ones.
 *
 * @covers \mod_quizgeist\task\purge_card_scans
 * @covers \mod_quizgeist\local\cards\card_scan_service
 */
final class card_scan_retention_test extends \advanced_testcase {

    public function test_confirmed_is_immediate_and_recognised_obeys_retention(): void {
        global $DB;

        $this->resetAfterTest(true);
        set_config('card_scan_retention_hours', 24, 'mod_quizgeist');
        $fixture = $this->fixture();
        $confirmed = $this->scan($fixture, 'confirmed', time());
        $recognised = $this->scan(
            $fixture,
            'recognised',
            time() - (24 * HOURSECS) + MINSECS
        );

        (new purge_card_scans())->execute();

        $confirmedrow = $DB->get_record(
            'quizgeist_card_scans',
            ['id' => $confirmed->id],
            '*',
            MUST_EXIST
        );
        $this->assertGreaterThan(0, (int)$confirmedrow->imagedeleted);
        $this->assertNull(card_scan_service::file($fixture['context'], $confirmedrow));
        $recognisedrow = $DB->get_record(
            'quizgeist_card_scans',
            ['id' => $recognised->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(0, (int)$recognisedrow->imagedeleted);
        $this->assertNotNull(card_scan_service::file($fixture['context'], $recognisedrow));

        // Once the configured ceiling is exceeded, the same recognised row is purged.
        $DB->set_field('quizgeist_card_scans', 'timemodified', time() - (25 * HOURSECS), [
            'id' => (int)$recognised->id,
        ]);
        (new purge_card_scans())->execute();

        $recognisedrow = $DB->get_record(
            'quizgeist_card_scans',
            ['id' => $recognised->id],
            '*',
            MUST_EXIST
        );
        $this->assertGreaterThan(0, (int)$recognisedrow->imagedeleted);
        $this->assertNull(card_scan_service::file($fixture['context'], $recognisedrow));
        $this->assertSame(1, $DB->count_records('quizgeist_card_scans', ['id' => $confirmed->id]));
        $this->assertSame(1, $DB->count_records('quizgeist_card_scans', ['id' => $recognised->id]));
    }

    /**
     * Build the foreign-key graph needed by one scan row.
     *
     * @return array<string,mixed>
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $context = \context_module::instance($module->cmid);
        $instance = $DB->get_record('quizgeist', ['id' => $module->id], '*', MUST_EXIST);
        $now = time();
        $questionid = (int)$DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$module->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'quiz',
            'questiontext' => 'Retention fixture',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode([
                'media' => null,
                'multiple' => false,
                'answers' => [
                    ['id' => 'a', 'text' => 'A', 'media' => null, 'correct' => true],
                    ['id' => 'b', 'text' => 'B', 'media' => null, 'correct' => false],
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
        $sessionid = (int)$DB->insert_record('quizgeist_sessions', (object)[
            'quizgeistid' => (int)$module->id,
            'hostuserid' => (int)$teacher->id,
            'joincode' => null,
            'status' => 'ended',
            'mode' => 'classic',
            'currentquestionid' => $questionid,
            'stateversion' => 1,
            'statejson' => null,
            'settingsjson' => null,
            'timestarted' => $now,
            'timeended' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $cardset = cardset_service::create(
            $instance,
            (int)$teacher->id,
            'Retention fixture cards',
            'abcd',
            [],
            1
        );
        return [
            'instance' => $instance,
            'context' => $context,
            'teacher' => $teacher,
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'cardset' => $cardset,
        ];
    }

    /**
     * Insert one scan and its file, leaving the row for the task to inspect.
     *
     * @param array<string,mixed> $fixture
     * @param string $state Scan state.
     * @param int $modified Last modification timestamp.
     * @return \stdClass
     */
    private function scan(array $fixture, string $state, int $modified): \stdClass {
        global $DB;

        $id = (int)$DB->insert_record('quizgeist_card_scans', (object)[
            'quizgeistid' => (int)$fixture['instance']->id,
            'sessionid' => (int)$fixture['sessionid'],
            'questionid' => (int)$fixture['questionid'],
            'visit' => str_repeat('a', 32),
            'cardsetid' => (int)$fixture['cardset']->id,
            'scannedby' => (int)$fixture['teacher']->id,
            'itemid' => 0,
            'state' => $state,
            'reasoncode' => null,
            'resultjson' => null,
            'recognised' => 0,
            'expected' => 0,
            'imagedeleted' => 0,
            'timecreated' => $modified,
            'timemodified' => $modified,
        ]);
        $DB->set_field('quizgeist_card_scans', 'itemid', $id, ['id' => $id]);
        get_file_storage()->create_file_from_string([
            'contextid' => $fixture['context']->id,
            'component' => card_scan_service::COMPONENT,
            'filearea' => card_limits::FILE_AREA,
            'itemid' => $id,
            'filepath' => '/',
            'filename' => 'retention' . $id . '.jpg',
            'userid' => (int)$fixture['teacher']->id,
            'mimetype' => 'image/jpeg',
        ], 'retention bytes');
        return $DB->get_record('quizgeist_card_scans', ['id' => $id], '*', MUST_EXIST);
    }
}
