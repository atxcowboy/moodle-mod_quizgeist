<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for deterministic interleaving of topic groups.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\schedule\due_selector;

defined('MOODLE_INTERNAL') || die();

/**
 * Round-robin groups, fallback behaviour and untagged roots.
 */
final class interleaving_test extends \advanced_testcase {

    public function test_three_topics_avoid_triples_while_topics_remain(): void {
        $this->resetAfterTest(true);

        $candidates = $this->candidates();
        $groupbyroot = $this->groups();
        $ordered = due_selector::interleaved(
            $candidates,
            $groupbyroot,
            271828
        );
        $topics = array_map(
            fn(\stdClass $candidate): string => (string)(
                $groupbyroot[(int)$candidate->rootid]
                ?? due_selector::UNTAGGED_GROUP
            ),
            $ordered
        );
        $remaining = array_count_values($topics);

        for ($index = 0, $count = count($topics); $index < $count; $index++) {
            $active = count(array_filter(
                $remaining,
                static fn(int $number): bool => $number > 0
            ));
            if ($index >= 2 && $active >= 2
                && $topics[$index] === $topics[$index - 1]
                && $topics[$index] === $topics[$index - 2]) {
                $this->fail('Three adjacent questions share a topic.');
            }
            $remaining[$topics[$index]]--;
        }

        $expectedroots = array_column($candidates, 'rootid');
        $actualroots = array_column($ordered, 'rootid');
        sort($expectedroots, SORT_NUMERIC);
        sort($actualroots, SORT_NUMERIC);
        $this->assertSame($expectedroots, $actualroots);
        $this->assertCount(9, $ordered);
    }

    public function test_same_seed_repeats_the_interleaved_root_order(): void {
        $this->resetAfterTest(true);

        $first = due_selector::interleaved(
            $this->candidates(),
            $this->groups(),
            161803
        );
        $second = due_selector::interleaved(
            $this->candidates(),
            $this->groups(),
            161803
        );

        $this->assertSame(
            array_column($first, 'rootid'),
            array_column($second, 'rootid')
        );
    }

    public function test_one_topic_falls_back_to_shuffled_order(): void {
        $this->resetAfterTest(true);

        $candidates = [
            (object)['rootid' => 41, 'duetime' => 200],
            (object)['rootid' => 12, 'duetime' => 0],
            (object)['rootid' => 33, 'duetime' => 100],
            (object)['rootid' => 27, 'duetime' => 0],
        ];
        $groupbyroot = [
            41 => 'single',
            12 => 'single',
            33 => 'single',
            27 => 'single',
        ];
        $interleaved = due_selector::interleaved($candidates, $groupbyroot, 42);
        $shuffled = due_selector::shuffled($candidates, 42);

        $this->assertCount(count($candidates), $interleaved);
        $this->assertSame(
            array_column($shuffled, 'rootid'),
            array_column($interleaved, 'rootid')
        );
        $expected = array_column($candidates, 'rootid');
        $actual = array_column($interleaved, 'rootid');
        sort($expected, SORT_NUMERIC);
        sort($actual, SORT_NUMERIC);
        $this->assertSame($expected, $actual);
    }

    public function test_untagged_roots_form_a_group_without_loss(): void {
        $this->resetAfterTest(true);

        $candidates = [
            (object)['rootid' => 11, 'duetime' => 0],
            (object)['rootid' => 12, 'duetime' => 100],
            (object)['rootid' => 21, 'duetime' => 0],
            (object)['rootid' => 22, 'duetime' => 100],
            (object)['rootid' => 31, 'duetime' => 0],
            (object)['rootid' => 32, 'duetime' => 100],
        ];
        $groupbyroot = [
            11 => 'topic-a',
            12 => 'topic-a',
            21 => 'topic-b',
            22 => 'topic-b',
            // Missing entries intentionally represent untagged roots.
        ];
        $ordered = due_selector::interleaved($candidates, $groupbyroot, 7);

        $this->assertCount(count($candidates), $ordered);
        $expected = array_column($candidates, 'rootid');
        $actual = array_column($ordered, 'rootid');
        sort($expected, SORT_NUMERIC);
        sort($actual, SORT_NUMERIC);
        $this->assertSame($expected, $actual);

        $firstround = array_map(
            static fn(\stdClass $candidate): string => (string)(
                $groupbyroot[(int)$candidate->rootid]
                ?? due_selector::UNTAGGED_GROUP
            ),
            array_slice($ordered, 0, 3)
        );
        sort($firstround, SORT_STRING);
        $this->assertSame(['', 'topic-a', 'topic-b'], $firstround);
    }

    /**
     * Nine questions across three topics, with one intentionally longer group.
     *
     * @return \stdClass[]
     */
    private function candidates(): array {
        return [
            (object)['rootid' => 1, 'duetime' => 0],
            (object)['rootid' => 2, 'duetime' => 0],
            (object)['rootid' => 3, 'duetime' => 0],
            (object)['rootid' => 4, 'duetime' => 0],
            (object)['rootid' => 5, 'duetime' => 0],
            (object)['rootid' => 6, 'duetime' => 0],
            (object)['rootid' => 7, 'duetime' => 0],
            (object)['rootid' => 8, 'duetime' => 0],
            (object)['rootid' => 9, 'duetime' => 0],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function groups(): array {
        return [
            1 => 'topic-a',
            2 => 'topic-a',
            3 => 'topic-a',
            4 => 'topic-a',
            5 => 'topic-a',
            6 => 'topic-b',
            7 => 'topic-b',
            8 => 'topic-c',
            9 => 'topic-c',
        ];
    }
}
