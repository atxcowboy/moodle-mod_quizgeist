<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for deterministic due-question selection.
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
 * Due ordering, strategy normalisation and deterministic shuffling.
 */
final class due_selector_test extends \advanced_testcase {

    public function test_due_orders_by_time_then_root_and_puts_new_first(): void {
        $this->resetAfterTest(true);

        $ordered = due_selector::due([
            (object)['rootid' => 9, 'duetime' => 500],
            (object)['rootid' => 5, 'duetime' => 0],
            (object)['rootid' => 3, 'duetime' => 300],
            (object)['rootid' => 1, 'duetime' => 300],
            (object)['rootid' => 2, 'duetime' => 0],
        ]);

        $this->assertSame(
            [2, 5, 1, 3, 9],
            array_column($ordered, 'rootid')
        );
    }

    public function test_due_limit_cuts_the_ordered_candidates(): void {
        $this->resetAfterTest(true);

        $ordered = due_selector::due($this->candidates(), 3);

        $this->assertSame(
            [2, 5, 1],
            array_column($ordered, 'rootid')
        );
    }

    public function test_unknown_strategy_normalises_to_sequential(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            due_selector::STRATEGY_SEQUENTIAL,
            due_selector::normalise_strategy('unsinn')
        );
    }

    public function test_strategy_constants_and_normalisation(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            [
                due_selector::STRATEGY_SEQUENTIAL,
                due_selector::STRATEGY_SHUFFLED,
                due_selector::STRATEGY_INTERLEAVED,
            ],
            due_selector::STRATEGIES
        );
        foreach (due_selector::STRATEGIES as $strategy) {
            $this->assertSame(
                $strategy,
                due_selector::normalise_strategy($strategy)
            );
        }
        $this->assertSame('', due_selector::UNTAGGED_GROUP);
    }

    public function test_seed_is_deterministic_and_non_negative(): void {
        $this->resetAfterTest(true);

        $first = due_selector::seed(23, 20260801, 17);
        $second = due_selector::seed(23, 20260801, 17);
        $changeduserid = due_selector::seed(24, 20260801, 17);
        $changeddaykey = due_selector::seed(23, 20260802, 17);
        $changedassignmentid = due_selector::seed(23, 20260801, 18);

        $this->assertSame($first, $second);
        $this->assertGreaterThanOrEqual(0, $first);
        $this->assertNotSame($first, $changeduserid);
        $this->assertNotSame($first, $changeddaykey);
        $this->assertNotSame($first, $changedassignmentid);
    }

    public function test_shuffled_repeats_order_and_root_set_for_same_seed(): void {
        $this->resetAfterTest(true);

        $candidates = [
            (object)['rootid' => 9, 'duetime' => 500],
            (object)['rootid' => 2, 'duetime' => 0],
            (object)['rootid' => 7, 'duetime' => 100],
            (object)['rootid' => 4, 'duetime' => 300],
        ];
        $first = due_selector::shuffled($candidates, 314159);
        $second = due_selector::shuffled($candidates, 314159);

        $this->assertSame(
            array_column($first, 'rootid'),
            array_column($second, 'rootid')
        );
        $expected = array_column($candidates, 'rootid');
        $actual = array_column($first, 'rootid');
        sort($expected, SORT_NUMERIC);
        sort($actual, SORT_NUMERIC);
        $this->assertSame($expected, $actual);
    }

    /**
     * @return \stdClass[]
     */
    private function candidates(): array {
        return [
            (object)['rootid' => 9, 'duetime' => 500],
            (object)['rootid' => 5, 'duetime' => 0],
            (object)['rootid' => 3, 'duetime' => 300],
            (object)['rootid' => 1, 'duetime' => 300],
            (object)['rootid' => 2, 'duetime' => 0],
        ];
    }
}
