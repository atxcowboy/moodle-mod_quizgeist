<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for tag inheritance across question versions (U1).
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\tagging\tag_repository;
use mod_quizgeist\local\tagging\tag_service;

defined('MOODLE_INTERNAL') || die();

/**
 * A tag hangs on the root, so it survives every edit of its question.
 */
final class tag_inheritance_test extends \advanced_testcase {

    /** @var \stdClass Activity record. */
    private \stdClass $quizgeist;

    /**
     * Insert one question lineage member.
     *
     * @param int $rootid Root, or zero for a fresh lineage.
     * @param int $version One-based content version.
     * @return int Inserted question ID.
     */
    private function insert_question(int $rootid, int $version): int {
        global $DB;

        $now = time();
        $id = (int)$DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$this->quizgeist->id,
            'rootid' => $rootid,
            'version' => $version,
            'sortorder' => 0,
            'qtype' => 'truefalse',
            'questiontext' => "Version {$version}",
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode(['media' => null, 'correct' => true]),
            'timelimit' => 20,
            'pointmode' => 'standard',
            'status' => $version === 1 ? 'ready' : 'ready',
            'timecreated' => $now,
            'timemodified' => $now + $version,
        ]);
        if ($rootid === 0) {
            $DB->set_field('quizgeist_questions', 'rootid', $id, ['id' => $id]);
        }
        return $id;
    }

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $this->quizgeist = $module;
        $this->quizgeist->course = (int)$course->id;
    }

    public function test_a_new_version_inherits_every_tag(): void {
        global $DB;

        $first = $this->insert_question(0, 1);
        $saved = tag_service::save_tag($this->quizgeist, [
            'scope' => 'activity',
            'kind' => 'topic',
            'tagkey' => 'bruchrechnung',
            'label' => 'Bruchrechnung',
        ]);
        tag_service::save_question_tags(
            $this->quizgeist,
            $first,
            [['tagId' => $saved['tag']['id'], 'weight' => 100]],
            2
        );

        // A content edit creates a NEW row that keeps the root.
        $second = $this->insert_question($first, 2);
        $DB->set_field('quizgeist_questions', 'status', 'archived', [
            'id' => $first,
        ]);

        $inherited = tag_repository::assignments_for_root($first);
        $this->assertCount(1, $inherited);
        $this->assertSame(
            'bruchrechnung',
            (string)$inherited[0]->tagkey
        );
        // The decisive assertion: resolving from the NEW version finds it.
        $this->assertSame(
            $first,
            tag_repository::root_of_question(
                (int)$this->quizgeist->id,
                $second
            )
        );
    }

    public function test_the_reserved_new_tag_is_created_once(): void {
        $firstid = tag_service::ensure_reserved_new_tag($this->quizgeist);
        $secondid = tag_service::ensure_reserved_new_tag($this->quizgeist);

        $this->assertSame($firstid, $secondid);
    }

    public function test_the_error_friendly_framing_follows_the_root(): void {
        $first = $this->insert_question(0, 1);
        $tagid = tag_service::ensure_reserved_new_tag($this->quizgeist);
        tag_service::save_question_tags(
            $this->quizgeist,
            $first,
            [['tagId' => $tagid, 'weight' => 100]],
            2
        );

        $this->assertTrue(
            tag_service::is_friendly_new($this->quizgeist, $first)
        );
        // The activity switch can turn the whole framing off.
        $off = clone $this->quizgeist;
        $off->friendlynew = 0;
        $this->assertFalse(tag_service::is_friendly_new($off, $first));
    }

    public function test_saving_replaces_the_complete_assignment_list(): void {
        $question = $this->insert_question(0, 1);
        $first = tag_service::save_tag($this->quizgeist, [
            'scope' => 'activity', 'kind' => 'topic',
            'tagkey' => 'eins', 'label' => 'Eins',
        ])['tag']['id'];
        $second = tag_service::save_tag($this->quizgeist, [
            'scope' => 'activity', 'kind' => 'topic',
            'tagkey' => 'zwei', 'label' => 'Zwei',
        ])['tag']['id'];

        tag_service::save_question_tags(
            $this->quizgeist,
            $question,
            [['tagId' => $first], ['tagId' => $second]],
            2
        );
        $this->assertCount(
            2,
            tag_repository::assignments_for_root($question)
        );

        tag_service::save_question_tags(
            $this->quizgeist,
            $question,
            [['tagId' => $second]],
            2
        );
        $remaining = tag_repository::assignments_for_root($question);
        $this->assertCount(1, $remaining);
        $this->assertSame($second, (int)$remaining[0]->tagid);
    }

    public function test_saving_an_identical_tag_merges_instead_of_duplicating(): void {
        global $DB;

        $first = tag_service::save_tag($this->quizgeist, [
            'scope' => 'activity', 'kind' => 'topic',
            'tagkey' => 'bruch', 'label' => 'Brüche',
        ]);
        $second = tag_service::save_tag($this->quizgeist, [
            'scope' => 'activity', 'kind' => 'topic',
            'tagkey' => 'bruch', 'label' => 'Bruchrechnung',
        ]);

        $this->assertSame($first['tag']['id'], $second['tag']['id']);
        $this->assertSame(1, $DB->count_records('quizgeist_tags', [
            'scopeid' => (int)$this->quizgeist->id,
            'tagkey' => 'bruch',
        ]));
        $this->assertSame('Bruchrechnung', $DB->get_field(
            'quizgeist_tags',
            'label',
            ['id' => $first['tag']['id']]
        ));
    }

    public function test_a_foreign_tag_is_refused(): void {
        $question = $this->insert_question(0, 1);
        $othercourse = $this->getDataGenerator()->create_course();
        $othermodule = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $othercourse->id,
        ]);
        $othermodule->course = (int)$othercourse->id;
        $foreign = tag_service::save_tag($othermodule, [
            'scope' => 'activity', 'kind' => 'topic',
            'tagkey' => 'fremd', 'label' => 'Fremd',
        ])['tag']['id'];

        $result = tag_service::save_question_tags(
            $this->quizgeist,
            $question,
            [['tagId' => $foreign]],
            2
        );

        $this->assertNotSame([], $result['validationErrors']);
        $this->assertSame([], tag_repository::assignments_for_root($question));
    }

    public function test_orphaned_assignments_are_pruned(): void {
        global $DB;

        $question = $this->insert_question(0, 1);
        $tagid = tag_service::save_tag($this->quizgeist, [
            'scope' => 'activity', 'kind' => 'topic',
            'tagkey' => 'weg', 'label' => 'Weg',
        ])['tag']['id'];
        tag_service::save_question_tags(
            $this->quizgeist,
            $question,
            [['tagId' => $tagid]],
            2
        );

        $DB->delete_records('quizgeist_questions', ['id' => $question]);
        $removed = tag_service::prune_orphaned_assignments(
            (int)$this->quizgeist->id
        );

        $this->assertSame(1, $removed);
        $this->assertSame([], tag_repository::assignments_for_root($question));
    }
}
