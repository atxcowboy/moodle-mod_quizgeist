<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for privacy handling of question-workshop data.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_quizgeist\privacy\provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Workshop questions survive erasure, while authorship and ratings do not.
 *
 * @covers \mod_quizgeist\privacy\provider
 */
final class workshop_privacy_test extends \advanced_testcase {

    /**
     * Create one authored workshop question, its submission and a rating.
     *
     * @return array{module:\stdClass,context:\context_module,user:\stdClass,questionid:int,workshopid:int}
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
            'explanation' => 'Weil Wasser Oberflächen benetzt.',
            'status' => 'draft',
            'createdby' => (int)$user->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, [
            'id' => $questionid,
        ]);
        $workshopid = (int)$DB->insert_record('quizgeist_workshop', (object)[
            'quizgeistid' => (int)$module->id,
            'questionid' => $questionid,
            'rootid' => $questionid,
            'authorid' => (int)$user->id,
            'state' => 'submitted',
            'curatorid' => null,
            'curatornote' => null,
            'aicheckjson' => null,
            'timesubmitted' => $now,
            'timedecided' => 0,
            'timemodified' => $now,
        ]);
        $DB->insert_record('quizgeist_workshop_ratings', (object)[
            'workshopid' => $workshopid,
            'userid' => (int)$user->id,
            'quality' => 4,
            'difficulty' => 3,
            'comment' => 'Eigene Bewertung',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        return [
            'module' => $module,
            'context' => $context,
            'user' => $user,
            'questionid' => $questionid,
            'workshopid' => $workshopid,
        ];
    }

    /**
     * Create a foreign workshop submission curated by a distinct user.
     *
     * @return array{context:\context_module,curator:\stdClass,author:\stdClass,workshopid:int,curatornote:string}
     */
    private function curator_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $context = \context_module::instance($module->cmid);
        $author = $this->getDataGenerator()->create_user();
        $curator = $this->getDataGenerator()->create_user();
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
            'explanation' => 'Weil Wasser Oberflächen benetzt.',
            'status' => 'draft',
            'createdby' => (int)$author->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, [
            'id' => $questionid,
        ]);
        $curatornote = 'Kuratierte Rückmeldung';
        $workshopid = (int)$DB->insert_record('quizgeist_workshop', (object)[
            'quizgeistid' => (int)$module->id,
            'questionid' => $questionid,
            'rootid' => $questionid,
            'authorid' => (int)$author->id,
            'state' => 'approved',
            'curatorid' => (int)$curator->id,
            'curatornote' => $curatornote,
            'aicheckjson' => null,
            'timesubmitted' => $now,
            'timedecided' => $now,
            'timemodified' => $now,
        ]);

        return [
            'context' => $context,
            'curator' => $curator,
            'author' => $author,
            'workshopid' => $workshopid,
            'curatornote' => $curatornote,
        ];
    }

    public function test_export_then_anonymise_retains_the_question(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->fixture();
        $approved = new approved_contextlist(
            $fixture['user'],
            'mod_quizgeist',
            [(int)$fixture['context']->id]
        );
        provider::export_user_data($approved);

        $export = writer::with_context($fixture['context'])->get_data([
            get_string('pluginname', 'mod_quizgeist'),
        ]);
        $this->assertObjectHasProperty('workshop_submissions', $export);
        $this->assertNotEmpty($export->workshop_submissions);
        $this->assertStringContainsString(
            (string)$fixture['workshopid'],
            json_encode($export, JSON_THROW_ON_ERROR)
        );

        provider::delete_data_for_all_users_in_context($fixture['context']);

        $this->assertNull($DB->get_field(
            'quizgeist_workshop',
            'authorid',
            ['id' => $fixture['workshopid']],
            MUST_EXIST
        ));
        $this->assertFalse($DB->record_exists(
            'quizgeist_workshop_ratings',
            ['userid' => (int)$fixture['user']->id]
        ));
        $this->assertTrue($DB->record_exists(
            'quizgeist_questions',
            ['id' => $fixture['questionid']]
        ));
    }

    public function test_export_curator_data_omits_the_foreign_author(): void {
        global $DB;

        $this->resetAfterTest(true);
        $fixture = $this->curator_fixture();
        $this->assertNotSame(
            (int)$fixture['curator']->id,
            (int)$DB->get_field(
                'quizgeist_workshop',
                'authorid',
                ['id' => $fixture['workshopid']],
                MUST_EXIST
            )
        );
        $this->assertFalse($DB->record_exists(
            'quizgeist_workshop_ratings',
            ['userid' => (int)$fixture['curator']->id]
        ));

        $approved = new approved_contextlist(
            $fixture['curator'],
            'mod_quizgeist',
            [(int)$fixture['context']->id]
        );
        provider::export_user_data($approved);

        $export = writer::with_context($fixture['context'])->get_data([
            get_string('pluginname', 'mod_quizgeist'),
        ]);
        $this->assertObjectHasProperty('workshop_curations', $export);
        $this->assertCount(1, $export->workshop_curations);
        $curation = (array)$export->workshop_curations[0];
        $this->assertArrayHasKey('curatornote', $curation);
        $this->assertSame($fixture['curatornote'], $curation['curatornote']);
        $this->assertArrayNotHasKey('authorid', $curation);
    }
}
