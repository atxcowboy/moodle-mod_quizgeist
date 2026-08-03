<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the parent practice digest.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\licence\feature_gate;
use mod_quizgeist\local\parent\digest;

defined('MOODLE_INTERNAL') || die();

/**
 * The digest keeps one stable shape for empty and populated learners.
 */
final class parent_digest_test extends \advanced_testcase {

    public function test_missing_users_receive_the_complete_empty_form(): void {
        $this->resetAfterTest(true);

        $expectedkeys = [
            'hasData',
            'weeklyGoal',
            'weeklyDone',
            'weeklyPercent',
            'streakDays',
            'dueCount',
            'nextDue',
            'competences',
            'generatedAt',
        ];
        $empty = digest::empty_digest();
        $this->assertSame($expectedkeys, array_keys($empty));
        foreach ([0, 999999999] as $userid) {
            $result = digest::for_user($userid);

            $this->assertSame($expectedkeys, array_keys($result));
            $this->assertFalse($result['hasData']);
            $this->assertSame([], $result['competences']);
        }
    }

    public function test_empty_and_populated_forms_have_the_same_keys(): void {
        $this->resetAfterTest(true);

        $fixture = $this->populated_fixture();
        $empty = digest::empty_digest();
        $filled = digest::for_user($fixture['userid']);

        $this->assertSame(array_keys($empty), array_keys($filled));
        $this->assertTrue($filled['hasData']);
        $this->assertSame([], $filled['competences']);
    }

    public function test_invalid_ids_and_zero_course_never_throw(): void {
        $this->resetAfterTest(true);

        foreach ([-1, PHP_INT_MAX, 0] as $userid) {
            $result = digest::for_user($userid, 0);

            $this->assertIsArray($result);
        }
    }

    public function test_competences_are_empty_without_optional_addons(): void {
        $this->resetAfterTest(true);

        $fixture = $this->populated_fixture();
        foreach (['selfstudy', 'reports'] as $feature) {
            $component = feature_gate::component($feature);
            if ($component !== null
                    && feature_gate::allows(
                        $feature,
                        feature_gate::VIEW_EXISTING
                    )) {
                // Existing addon detection decides which config can be hidden.
                unset_config('version', $component);
            }
        }

        $result = digest::for_user($fixture['userid']);

        $this->assertSame([], $result['competences']);
    }

    /**
     * Create one real activity and answer so the digest follows its SQL path.
     *
     * @return array{userid:int,courseid:int}
     */
    private function populated_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $now = time();
        $questionid = (int)$DB->insert_record(
            'quizgeist_questions',
            (object)[
                'quizgeistid' => (int)$module->id,
                'rootid' => 0,
                'version' => 1,
                'sortorder' => 0,
                'qtype' => 'truefalse',
                'questiontext' => 'Is this true?',
                'questionformat' => FORMAT_PLAIN,
                'optionsjson' => json_encode(['correct' => true]),
                'timelimit' => 20,
                'pointmode' => 'standard',
                'status' => 'ready',
                'timecreated' => $now,
                'timemodified' => $now,
            ]
        );
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, [
            'id' => $questionid,
        ]);
        $DB->insert_record('quizgeist_answers', (object)[
            'questionid' => $questionid,
            'userid' => (int)$user->id,
            'answertype' => 'answer',
            'answerjson' => json_encode(['answer' => true]),
            'iscorrect' => 1,
            'points' => 1,
            'maxpoints' => 1,
            'responsetime' => 1000,
            'timecreated' => $now,
        ]);

        return [
            'userid' => (int)$user->id,
            'courseid' => (int)$course->id,
        ];
    }
}
