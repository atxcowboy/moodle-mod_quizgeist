<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for competence report metric aggregation.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\report\report_metrics;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers the competence axis of the report metrics.
 */
final class competence_report_test extends \advanced_testcase {

    /**
     * Questions sharing a competence contribute to one combined sample.
     *
     * @return void
     */
    public function test_same_competence_accumulates_sample_and_correct_percent(
    ): void {
        $this->resetAfterTest(true);

        $questions = [
            101 => [
                'rootId' => 101,
                'correctCount' => 1,
                'gradableCount' => 3,
                'points' => 4,
                'maxPoints' => 10,
            ],
            102 => [
                'rootId' => 102,
                'correctCount' => 2,
                'gradableCount' => 5,
                'points' => 7,
                'maxPoints' => 10,
            ],
        ];
        $tagsbyroot = [
            101 => [
                (object)[
                    'tagid' => 7,
                    'tagkey' => 'logic',
                    'label' => 'Logik',
                    'colorkey' => 'blue',
                ],
            ],
            102 => [
                (object)[
                    'tagid' => 7,
                    'tagkey' => 'logic',
                    'label' => 'Logik',
                    'colorkey' => 'blue',
                ],
            ],
        ];

        $competences = report_metrics::finish_competences(
            $questions,
            $tagsbyroot
        );

        $this->assertCount(1, $competences);
        $this->assertSame('logic', $competences[0]['key']);
        $this->assertSame(8, $competences[0]['sample']);
        $this->assertSame(37.5, $competences[0]['correctPercent']);
        $this->assertSame(2, $competences[0]['questionCount']);
        $this->assertSame(
            [
                'key' => 'logic',
                'label' => 'Logik',
                'color' => 'blue',
                'percent' => 37.5,
            ],
            $questions[101]['competence']
        );
        $this->assertSame(37.5, $questions[102]['competence']['percent']);
    }

    /**
     * A question carrying two competences is counted fully in each area.
     *
     * @return void
     */
    public function test_multiple_competences_count_one_question_in_full(
    ): void {
        $this->resetAfterTest(true);

        $questions = [
            201 => [
                'rootId' => 201,
                'correctCount' => 3,
                'gradableCount' => 4,
                'points' => 15,
                'maxPoints' => 20,
            ],
        ];
        $tagsbyroot = [
            201 => [
                (object)[
                    'tagid' => 11,
                    'tagkey' => 'leading',
                    'label' => 'Zentrale Kompetenz',
                    'colorkey' => 'green',
                ],
                (object)[
                    'tagid' => 12,
                    'tagkey' => 'secondary',
                    'label' => 'Weitere Kompetenz',
                    'colorkey' => 'yellow',
                ],
            ],
        ];

        $competences = report_metrics::finish_competences(
            $questions,
            $tagsbyroot
        );
        $bykey = [];
        foreach ($competences as $competence) {
            $bykey[$competence['key']] = $competence;
        }

        // Full counting keeps coverage percentages faithful for both areas.
        $this->assertCount(2, $bykey);
        foreach (['leading', 'secondary'] as $key) {
            $this->assertSame(4, $bykey[$key]['sample']);
            $this->assertSame(75.0, $bykey[$key]['correctPercent']);
            $this->assertSame(75.0, $bykey[$key]['pointsPercent']);
            $this->assertSame(1, $bykey[$key]['questionCount']);
        }
        $this->assertSame(
            [
                'key' => 'leading',
                'label' => 'Zentrale Kompetenz',
                'color' => 'green',
                'percent' => 75.0,
            ],
            $questions[201]['competence']
        );
    }

    /**
     * Weak areas sort first, while evidence-free areas sort last.
     *
     * @return void
     */
    public function test_sorting_and_tagless_questions(): void {
        $this->resetAfterTest(true);

        $questions = [
            301 => [
                'rootId' => 301,
                'correctCount' => 1,
                'gradableCount' => 4,
                'points' => 5,
                'maxPoints' => 10,
            ],
            302 => [
                'rootId' => 302,
                'correctCount' => 3,
                'gradableCount' => 4,
                'points' => 8,
                'maxPoints' => 10,
            ],
            303 => [
                'rootId' => 303,
                'correctCount' => 0,
                'gradableCount' => 0,
                'points' => 0,
                'maxPoints' => 0,
            ],
            304 => [
                'rootId' => 304,
                'correctCount' => 1,
                'gradableCount' => 1,
                'points' => 2,
                'maxPoints' => 2,
            ],
        ];
        $tagsbyroot = [
            301 => [
                (object)[
                    'tagid' => 21,
                    'tagkey' => 'hard',
                    'label' => 'Schwierig',
                    'colorkey' => 'red',
                ],
            ],
            302 => [
                (object)[
                    'tagid' => 22,
                    'tagkey' => 'easy',
                    'label' => 'Sicher',
                    'colorkey' => 'green',
                ],
            ],
            303 => [
                (object)[
                    'tagid' => 23,
                    'tagkey' => 'unseen',
                    'label' => 'Ohne Nachweis',
                    'colorkey' => 'gray',
                ],
            ],
        ];

        $competences = report_metrics::finish_competences(
            $questions,
            $tagsbyroot
        );

        // Null correctness is not evidence of a perfect competence.
        $this->assertSame(
            ['hard', 'easy', 'unseen'],
            array_column($competences, 'key')
        );
        $this->assertSame(25.0, $competences[0]['correctPercent']);
        $this->assertSame(75.0, $competences[1]['correctPercent']);
        $this->assertNull($competences[2]['correctPercent']);
        $this->assertSame(0, $competences[2]['sample']);
        $this->assertNull($questions[304]['competence']);
    }
}
