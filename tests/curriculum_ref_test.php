<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for curriculum anchors attached to question roots.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\editor\editor_service;
use mod_quizgeist\local\tagging\curriculum_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * A curriculum citation follows a question lineage, not one content version.
 *
 * @covers \mod_quizgeist\local\editor\editor_service::create_question
 * @covers \mod_quizgeist\local\editor\editor_service::ensure_editable_question
 * @covers \mod_quizgeist\local\editor\editor_service::create_question_version
 * @covers \mod_quizgeist\local\tagging\curriculum_repository
 */
final class curriculum_ref_test extends \advanced_testcase {

    /** @var \stdClass Activity record. */
    private \stdClass $quizgeist;

    /** @var \context_module Activity context. */
    private \context_module $context;

    /** @var \stdClass Test user. */
    private \stdClass $user;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $this->quizgeist = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $this->context = \context_module::instance($this->quizgeist->cmid);
        $this->user = $this->getDataGenerator()->create_and_enrol($course);
    }

    public function test_citation_is_stored_on_root_and_survives_new_question_version(): void {
        $firstquestion = editor_service::create_question(
            $this->quizgeist,
            $this->context,
            (int)$this->user->id,
            'truefalse'
        );
        $first = (int)$firstquestion['id'];
        curriculum_repository::store((int)$this->quizgeist->id, $first, [
            'subject' => 'deutsch',
            'grade' => 11,
            'learningarea' => 'Lyrik',
            'competency' => 'Gedichte analysieren',
            'citationurl' => 'https://www.lehrplanplus.bayern.de/anker#lyrik',
            'chunkid' => 42,
        ]);

        // A real answer makes the question immutable. The editor's public
        // ensure_editable_question() then takes the production
        // create_question_version() copy-on-write path.
        global $DB;
        $DB->insert_record('quizgeist_answers', (object)[
            'sessionid' => null,
            'playerid' => null,
            'attemptid' => null,
            'questionid' => $first,
            'userid' => (int)$this->user->id,
            'answertype' => 'answer',
            'visit' => null,
            'submissionkey' => null,
            'answerjson' => json_encode(['text' => 'Antwort']),
            'iscorrect' => null,
            'points' => 0,
            'maxpoints' => 0,
            'responsetime' => 0,
            'timecreated' => time(),
        ]);
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $version = editor_service::ensure_editable_question(
                $this->quizgeist,
                $this->context,
                $first,
                (int)$this->user->id
            );
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        $second = (int)$version['record']->id;

        $anchor = curriculum_repository::of_root(
            (int)$this->quizgeist->id,
            $first
        );
        $this->assertInstanceOf(\stdClass::class, $anchor);
        $this->assertNotSame($first, $second);
        $this->assertSame('archived', (string)$DB->get_field(
            'quizgeist_questions',
            'status',
            ['id' => $first]
        ));
        $this->assertSame($first, (int)$anchor->rootid);
        $this->assertSame('https://www.lehrplanplus.bayern.de/anker#lyrik', (string)$anchor->citationurl);
        $this->assertSame($first, (int)$DB->get_field('quizgeist_questions', 'rootid', ['id' => $second]));

        $same_anchor = curriculum_repository::of_root(
            (int)$this->quizgeist->id,
            (int)$DB->get_field('quizgeist_questions', 'rootid', ['id' => $second])
        );
        $this->assertInstanceOf(\stdClass::class, $same_anchor);
        $this->assertSame((string)$anchor->citationurl, (string)$same_anchor->citationurl);
    }

    public function test_non_http_citation_url_is_discarded(): void {
        $question = editor_service::create_question(
            $this->quizgeist,
            $this->context,
            (int)$this->user->id,
            'truefalse'
        );
        $rootid = (int)$question['id'];

        curriculum_repository::store((int)$this->quizgeist->id, $rootid, [
            'subject' => 'deutsch',
            'grade' => 11,
            'citationurl' => 'javascript:alert(1)',
        ]);

        $anchor = curriculum_repository::of_root((int)$this->quizgeist->id, $rootid);
        $this->assertInstanceOf(\stdClass::class, $anchor);
        $this->assertSame('', (string)($anchor->citationurl ?? ''));
    }

}
