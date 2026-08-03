<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the AI response-clustering boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use quizgeistaddon_ai\local\ai\response_clusterer;

defined('MOODLE_INTERNAL') || die();

/**
 * Model output is advisory: every element receives its own honest verdict.
 *
 * @covers \quizgeistaddon_ai\local\ai\response_clusterer::cluster
 */
final class clusterer_boundary_test extends \advanced_testcase {

    public function test_clean_model_suggestion_is_usable(): void {
        $this->resetAfterTest(true);

        $result = response_clusterer::cluster(
            ['Apfel', 'Birne'],
            static function (string $prompt): array {
                return [
                    'clusters' => [
                        ['label' => 'Apfel', 'memberIndexes' => [0]],
                        ['label' => 'Birne', 'memberIndexes' => [1]],
                    ],
                ];
            }
        );

        $this->assertSame('gateway', $result['origin']);
        $this->assertTrue($result['boundaryHonest']);
        $this->assertSame(2, $result['usableClusterCount']);
        $this->assertSame([], $result['unassigned']);
        foreach ($result['clusters'] as $cluster) {
            $this->assertTrue($cluster['valid']);
            $this->assertSame([], $cluster['validationErrors']);
        }
    }

    public function test_one_marked_element_is_valid_exactly_when_errors_are_empty(): void {
        $this->resetAfterTest(true);

        $result = response_clusterer::cluster(
            ['Apfel', 'Birne'],
            static function (string $prompt): array {
                return [
                    'clusters' => [
                        ['label' => 'Apfel', 'memberIndexes' => [0]],
                        ['label' => '', 'memberIndexes' => [1]],
                    ],
                ];
            }
        );

        $this->assertTrue($result['boundaryHonest']);
        $this->assertSame(1, $result['usableClusterCount']);
        foreach ($result['clusters'] as $cluster) {
            $this->assertSame(
                $cluster['validationErrors'] === [],
                $cluster['valid']
            );
        }
        $this->assertSame([1], $result['unassigned']);
    }

    public function test_fully_unusable_model_proposal_is_reported_honestly(): void {
        $this->resetAfterTest(true);

        $result = response_clusterer::cluster(
            ['Apfel', 'Birne'],
            static function (string $prompt): array {
                return [
                    'clusters' => [
                        ['label' => '', 'memberIndexes' => [0]],
                        ['label' => '', 'memberIndexes' => [1]],
                    ],
                ];
            }
        );

        $this->assertTrue($result['boundaryHonest']);
        $this->assertSame(0, $result['usableClusterCount']);
        $this->assertSame([0, 1], $result['unassigned']);
        foreach ($result['clusters'] as $cluster) {
            $this->assertFalse($cluster['valid']);
            $this->assertNotEmpty($cluster['validationErrors']);
        }
    }

    public function test_rule_fallback_is_deterministic(): void {
        $this->resetAfterTest(true);
        $texts = ['Äpfel', 'äpfel', 'Birnen', 'Birnen'];

        $first = response_clusterer::cluster($texts);
        $second = response_clusterer::cluster($texts);

        $this->assertSame($first, $second);
        $this->assertSame('fallback', $first['origin']);
        $this->assertTrue($first['boundaryHonest']);
        $this->assertGreaterThan(0, $first['usableClusterCount']);
    }
}
