<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests that card-scan pictures are never served by pluginfile.
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

defined('MOODLE_INTERNAL') || die();

/**
 * A cardscan file stays private even when the caller has the module capability.
 *
 * @covers ::quizgeist_pluginfile
 */
final class card_scan_no_pluginfile_test extends \advanced_testcase {

    public function test_cardscan_is_refused_for_teacher_host_and_learner(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $args = [(string)$fixture['scan']->itemid, 'scan' . $fixture['scan']->id . '.jpg'];
        foreach ([$fixture['teacher'], $fixture['host'], $fixture['learner']] as $user) {
            $this->setUser($user);
            $this->assertFalse(quizgeist_pluginfile(
                $fixture['course'],
                $fixture['cm'],
                $fixture['context'],
                card_limits::FILE_AREA,
                $args,
                false
            ));
            $this->assertNotNull(card_scan_service::file($fixture['context'], $fixture['scan']));
        }

        $source = file_get_contents(dirname(__DIR__) . '/lib.php');
        $this->assertNotFalse($source);
        $this->assertSame(
            1,
            preg_match('/\$allowedareas\s*=\s*\[(.*?)\];/s', (string)$source, $matches)
        );
        $this->assertStringNotContainsString("'cardscan'", $matches[1]);
        $this->assertStringNotContainsString('"cardscan"', $matches[1]);
        $this->assertSame(1, $DB->count_records('quizgeist_card_scans', [
            'id' => (int)$fixture['scan']->id,
        ]));
    }

    /**
     * Create one real scan row and a file in its cardscan area.
     *
     * @return array<string,mixed>
     */
    private function fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $host = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $learner = $this->getDataGenerator()->create_and_enrol($course, 'student');
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
            'questiontext' => 'Pluginfile fixture',
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
            'hostuserid' => (int)$host->id,
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
            'Pluginfile fixture cards',
            'abcd',
            [],
            1
        );
        $scanid = (int)$DB->insert_record('quizgeist_card_scans', (object)[
            'quizgeistid' => (int)$module->id,
            'sessionid' => $sessionid,
            'questionid' => $questionid,
            'visit' => str_repeat('b', 32),
            'cardsetid' => (int)$cardset->id,
            'scannedby' => (int)$teacher->id,
            'itemid' => 0,
            'state' => 'pending',
            'reasoncode' => null,
            'resultjson' => null,
            'recognised' => 0,
            'expected' => 0,
            'imagedeleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_card_scans', 'itemid', $scanid, ['id' => $scanid]);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => card_scan_service::COMPONENT,
            'filearea' => card_limits::FILE_AREA,
            'itemid' => $scanid,
            'filepath' => '/',
            'filename' => 'scan' . $scanid . '.jpg',
            'userid' => (int)$teacher->id,
            'mimetype' => 'image/jpeg',
        ], 'pluginfile bytes');
        return [
            'course' => $course,
            'cm' => (object)$cm,
            'context' => $context,
            'teacher' => $teacher,
            'host' => $host,
            'learner' => $learner,
            'scan' => $DB->get_record('quizgeist_card_scans', ['id' => $scanid], '*', MUST_EXIST),
        ];
    }
}
