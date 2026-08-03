<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for report metric accumulation and finalisation.
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
 * Covers the extracted report arithmetic without repository dependencies.
 */
final class report_metrics_test extends \advanced_testcase {

    /**
     * Graded observations and live non-responses update every accumulator.
     *
     * @return void
     */
    public function test_observations_update_all_metric_levels(): void {
        $root = [
            'points' => 0,
            'maxPoints' => 0,
            'correctCount' => 0,
            'gradableCount' => 0,
            'responseCount' => 0,
            'missingCount' => 0,
            '_timeTotal' => 0,
            '_timeSamples' => 0,
        ];
        $student = [
            'points' => 0,
            'maxPoints' => 0,
            'correctCount' => 0,
            'gradableCount' => 0,
            'responseCount' => 0,
            '_timeTotal' => 0,
            '_timeSamples' => 0,
        ];
        $source = ['_users' => []];
        $rows = [
            (object)[
                'answertype' => 'answer',
                'points' => 80,
                'maxpoints' => 100,
                'iscorrect' => 1,
                'responsetime' => 1200,
            ],
            (object)[
                'answertype' => 'scorevoid',
                'points' => 0,
                'maxpoints' => 100,
                'iscorrect' => 0,
                'responsetime' => 0,
            ],
        ];

        report_metrics::apply_observation(
            $root,
            $student,
            $source,
            42,
            $rows,
            ['answer' => true],
            true,
            true
        );
        report_metrics::apply_missing_observation(
            $root,
            $student,
            $source,
            42,
            true,
            120
        );

        $this->assertSame(80, $root['points']);
        $this->assertSame(320, $root['maxPoints']);
        $this->assertSame(1, $root['correctCount']);
        $this->assertSame(3, $root['gradableCount']);
        $this->assertSame(2, $root['missingCount']);
        $this->assertSame(1, $root['responseCount']);
        $this->assertSame(1200, $root['_timeTotal']);
        $this->assertSame(1, $root['_timeSamples']);

        $this->assertSame(80, $student['points']);
        $this->assertSame(320, $student['maxPoints']);
        $this->assertSame(1, $student['correctCount']);
        $this->assertSame(3, $student['gradableCount']);
        $this->assertSame(1, $student['responseCount']);
        $this->assertSame(1200, $student['_timeTotal']);
        $this->assertSame(1, $student['_timeSamples']);

        $this->assertSame([
            'points' => 80,
            'maxPoints' => 320,
            'correct' => 1,
            'gradable' => 3,
            'timeTotal' => 1200,
            'timeSamples' => 1,
        ], $source['_users'][42]);

        report_metrics::apply_missing_observation(
            $root,
            $student,
            $source,
            99,
            false,
            999
        );
        $this->assertSame(3, $root['missingCount']);
        $this->assertArrayNotHasKey(99, $source['_users']);
    }

    /**
     * Question finalisation preserves ordering, rounding and difficulty rules.
     *
     * @return void
     */
    public function test_finish_questions_preserves_projection_contract(): void {
        $questions = [
            '2:9' => [
                'rootKey' => '2:9',
                'title' => 'Zeta',
                'qtypes' => ['quiz' => true, 'poll' => true],
                'points' => 100,
                'maxPoints' => 300,
                'correctCount' => 1,
                'gradableCount' => 3,
                'responseCount' => 2,
                'missingCount' => 1,
                '_timeTotal' => 2501,
                '_timeSamples' => 2,
                '_latestVersion' => 2,
                '_versions' => [
                    20 => [
                        'questionId' => 20,
                        'version' => 2,
                        'qtype' => 'poll',
                        'questionText' => 'Zeta 2',
                    ],
                    10 => [
                        'questionId' => 10,
                        'version' => 1,
                        'qtype' => 'quiz',
                        'questionText' => 'Zeta 1',
                    ],
                ],
                'distributions' => [
                    ['sourceKey' => 'b', 'questionId' => 20],
                    ['sourceKey' => 'a', 'questionId' => 10],
                ],
            ],
            '2:8' => [
                'rootKey' => '2:8',
                'title' => 'Alpha',
                'qtypes' => ['open' => true],
                'points' => 0,
                'maxPoints' => 0,
                'correctCount' => 0,
                'gradableCount' => 0,
                'responseCount' => 0,
                'missingCount' => 0,
                '_timeTotal' => 0,
                '_timeSamples' => 0,
                '_latestVersion' => 1,
                '_versions' => [
                    8 => [
                        'questionId' => 8,
                        'version' => 1,
                        'qtype' => 'open',
                        'questionText' => 'Alpha',
                    ],
                ],
                'distributions' => [],
            ],
        ];

        $finished = report_metrics::finish_questions($questions, 3, 40.0);

        $this->assertSame(['2:8', '2:9'], array_column(
            $finished,
            'rootKey'
        ));
        $zeta = $finished[1];
        $this->assertSame(['quiz', 'poll'], $zeta['qtypes']);
        $this->assertSame([10, 20], array_column(
            $zeta['versions'],
            'questionId'
        ));
        $this->assertSame(['a', 'b'], array_column(
            $zeta['distributions'],
            'sourceKey'
        ));
        $this->assertSame(33.3, $zeta['correctPercent']);
        $this->assertSame(1251, $zeta['averageResponseTimeMs']);
        $this->assertTrue($zeta['difficult']);
        $this->assertArrayNotHasKey('_versions', $zeta);
        $this->assertNull($finished[0]['correctPercent']);
        $this->assertNull($finished[0]['averageResponseTimeMs']);
        $this->assertFalse($finished[0]['difficult']);
    }

    /**
     * Student, audit and timeline finishing remains deterministic.
     *
     * @return void
     */
    public function test_finish_collections_is_deterministic(): void {
        $students = [
            10 => [
                'userId' => 10,
                'displayName' => 'Beta',
                'userIdentifier' => 'duplicate',
                'correctCount' => 1,
                'gradableCount' => 2,
                '_timeTotal' => 1000,
                '_timeSamples' => 2,
            ],
            20 => [
                'userId' => 20,
                'displayName' => 'Alpha',
                'userIdentifier' => 'duplicate',
                'correctCount' => 0,
                'gradableCount' => 0,
                '_timeTotal' => 0,
                '_timeSamples' => 0,
            ],
        ];
        $finishedstudents = report_metrics::finish_students($students);
        $this->assertSame([20, 10], array_column(
            $finishedstudents,
            'userId'
        ));
        $this->assertSame(
            'duplicate (#20)',
            $finishedstudents[0]['userIdentifier']
        );
        $this->assertNull($finishedstudents[0]['correctPercent']);
        $this->assertSame(50.0, $finishedstudents[1]['correctPercent']);
        $this->assertSame(
            500,
            $finishedstudents[1]['averageResponseTimeMs']
        );

        $ordered = report_metrics::chronological_rows([
            ['id' => 3, 'timeCreated' => 20],
            ['id' => 2, 'timeCreated' => 10],
            ['id' => 1, 'timeCreated' => 10],
        ]);
        $this->assertSame([1, 2, 3], array_column($ordered, 'id'));

        $timeline = report_metrics::finish_timeline([
            'late' => [
                'name' => 'Late',
                'kind' => 'session',
                'quizgeistId' => 7,
                'instanceName' => 'Instance',
                'timestamp' => 20,
                '_participants' => [10 => true, 20 => true],
                '_users' => [
                    10 => [
                        'points' => 50,
                        'maxPoints' => 100,
                        'correct' => 1,
                        'gradable' => 2,
                    ],
                    20 => [
                        'points' => 100,
                        'maxPoints' => 100,
                        'correct' => 2,
                        'gradable' => 2,
                    ],
                ],
            ],
            'early' => [
                'name' => 'Early',
                'kind' => 'assignment',
                'quizgeistId' => 8,
                'instanceName' => 'Instance',
                'timestamp' => 10,
                '_participants' => [10 => true],
                '_users' => [],
            ],
            'empty' => [
                'name' => 'Empty',
                'kind' => 'session',
                'quizgeistId' => 9,
                'instanceName' => 'Instance',
                'timestamp' => 1,
                '_participants' => [],
                '_users' => [],
            ],
        ]);

        $this->assertSame(['early', 'late'], array_column(
            $timeline,
            'sourceKey'
        ));
        $this->assertNull($timeline[0]['pointsPercent']);
        $this->assertSame(75.0, $timeline[1]['pointsPercent']);
        $this->assertSame(
            75.0,
            $timeline[1]['averageCorrectPercent']
        );
        $this->assertSame(2, $timeline[1]['participantCount']);
    }
}
