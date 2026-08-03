<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for the report projection boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\qtype\registry;
use mod_quizgeist\local\report\report_export;
use mod_quizgeist\local\report\report_distribution_sanitizer;
use mod_quizgeist\local\report\report_projector;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers report-only projection rules without repository dependencies.
 */
final class report_projector_test extends \advanced_testcase {

    /**
     * Teacher distributions retain boolean markers but discard reveal data.
     *
     * @return void
     */
    public function test_teacher_distribution_keeps_boolean_correct_flags(): void {
        $distribution = $this->distribution(true);

        $this->assertSame([
            'qtype' => 'quiz',
            'questionText' => 'Choose the answer.',
            'choices' => [
                [
                    'id' => 'a',
                    'text' => 'Correct display label',
                    'correct' => true,
                ],
                [
                    'id' => 'b',
                    'text' => 'Incorrect display label',
                    'correct' => false,
                ],
                [
                    'id' => 'c',
                    'text' => 'Invalid marker',
                ],
            ],
            'typeData' => [],
        ], $distribution['question']);
        $this->assertSame([
            [
                'choiceId' => 'a',
                'count' => 1,
                'percent' => 50.0,
                'correct' => true,
            ],
            [
                'choiceId' => 'b',
                'count' => 1,
                'percent' => 50.0,
                'correct' => false,
            ],
        ], $distribution['aggregate']);
    }

    /**
     * Learner distributions retain the pre-existing solution-free contract.
     *
     * @return void
     */
    public function test_learner_distribution_removes_all_correct_flags(): void {
        $distribution = $this->distribution(false);

        $this->assertSame([
            [
                'id' => 'a',
                'text' => 'Correct display label',
            ],
            [
                'id' => 'b',
                'text' => 'Incorrect display label',
            ],
            [
                'id' => 'c',
                'text' => 'Invalid marker',
            ],
        ], $distribution['question']['choices']);
        $this->assertSame([
            [
                'choiceId' => 'a',
                'count' => 1,
                'percent' => 50.0,
            ],
            [
                'choiceId' => 'b',
                'count' => 1,
                'percent' => 50.0,
            ],
        ], $distribution['aggregate']);
    }

    /**
     * CSV and XLSX share a solution-free distribution content projection.
     *
     * @return void
     */
    public function test_export_content_scrubs_complete_private_key_list(): void {
        $projection = $this->projection();
        $report = [
            'questions' => [[
                'rootId' => 11,
                'title' => 'Explain the result.',
                'versions' => [],
                'distributions' => [[
                    'sourceLabel' => 'Live session',
                    'questionId' => 11,
                    'version' => 2,
                    'stage' => 'answer',
                    'authoritative' => true,
                    'occurrenceCount' => 1,
                    'question' => $projection['question'],
                    'aggregate' => $projection['aggregate'],
                ]],
            ]],
        ];

        $rows = iterator_to_array(report_export::rows($report), false);
        $this->assertCount(2, $rows);
        $content = json_decode(
            (string)$rows[1][17],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame([
            'question' => [
                'qtype' => 'quiz',
                'questionText' => 'Choose the answer.',
                'choices' => [
                    [
                        'id' => 'a',
                        'text' => 'Correct display label',
                    ],
                    [
                        'id' => 'b',
                        'text' => 'Incorrect display label',
                    ],
                    [
                        'id' => 'c',
                        'text' => 'Invalid marker',
                    ],
                ],
                'typeData' => [],
            ],
            'aggregate' => [
                [
                    'choiceId' => 'a',
                    'count' => 1,
                    'percent' => 50,
                ],
                [
                    'choiceId' => 'b',
                    'count' => 1,
                    'percent' => 50,
                ],
            ],
        ], $content);
    }

    /**
     * Unknown keys and object-valued branches fail closed at every depth.
     *
     * @return void
     */
    public function test_positive_projection_drops_unknown_keys_and_objects(): void {
        $question = report_distribution_sanitizer::for_report([
            'qtype' => 'wordcloud',
            'questionText' => 'Collect terms.',
            'futureSolution' => 'must not leave the boundary',
            'futureObject' => (object)['answer' => 'private'],
            'policyDescriptor' => [
                'stages' => [
                    [
                        'key' => 'answer',
                        'labelKey' => 'live:stage:answer',
                        'futureKey' => 'private',
                    ],
                    (object)['key' => 'reveal'],
                ],
                'currentStage' => 'answer',
                'futureObject' => (object)['answer' => 'private'],
            ],
            'typeData' => [
                'maxChars' => 50,
                'moderation' => true,
                'futureSolution' => 'private',
                'futureObject' => (object)['answer' => 'private'],
            ],
        ], true);

        $this->assertSame([
            'qtype' => 'wordcloud',
            'questionText' => 'Collect terms.',
            'policyDescriptor' => [
                'stages' => [[
                    'key' => 'answer',
                    'labelKey' => 'live:stage:answer',
                ]],
                'currentStage' => 'answer',
            ],
            'choices' => [],
            'typeData' => [
                'maxChars' => 50,
                'moderation' => true,
            ],
        ], $question);

        $aggregate = report_distribution_sanitizer::for_report([
            'kind' => 'wordcloud',
            'total' => 1,
            'responseCount' => 1,
            'publishedCount' => 1,
            'words' => [
                [
                    'key' => 'term',
                    'text' => 'Term',
                    'count' => 1,
                    'percent' => 100.0,
                    'futureSolution' => 'private',
                ],
                (object)['key' => 'private'],
            ],
            'moderation' => (object)['items' => []],
            'futureObject' => (object)['answer' => 'private'],
        ], true);

        $this->assertSame([
            'kind' => 'wordcloud',
            'total' => 1,
            'responseCount' => 1,
            'publishedCount' => 1,
            'words' => [[
                'key' => 'term',
                'text' => 'Term',
                'count' => 1,
                'percent' => 100.0,
            ]],
        ], $aggregate);
        $this->assert_projection_has_no_objects($question);
        $this->assert_projection_has_no_objects($aggregate);
    }

    /**
     * Every registry qtype has an explicit question and aggregate projection.
     *
     * @return void
     */
    public function test_all_current_qtype_projections_are_positive(): void {
        $fixtures = $this->current_type_fixtures();
        $types = registry::types();
        $this->assertNotEmpty($types, 'The qtype registry announces no types at all.');
        foreach ($types as $qtype) {
            $this->assertArrayHasKey(
                $qtype,
                $fixtures,
                "Registry type {$qtype} has no report projection fixture. "
                    . 'Every new question type needs one here, otherwise its report '
                    . 'projection silently collapses to an empty array.'
            );
            $fixture = $fixtures[$qtype];
            $rawtypedata = $fixture['typeData'];
            $rawtypedata['futureSolution'] = 'private-' . $qtype;
            $rawtypedata['futureObject'] = (object)['answer' => 'private'];
            $question = [
                'qtype' => $qtype,
                'questionText' => 'Question for ' . $qtype,
                'typeData' => $rawtypedata,
                'choices' => $fixture['choices'] ?? [],
                'futureSolution' => 'private-' . $qtype,
                'futureObject' => (object)['answer' => 'private'],
            ];

            $reportquestion = report_distribution_sanitizer::for_report(
                $question,
                true
            );
            $this->assertSame(
                $fixture['expectedTypeData'],
                $reportquestion['typeData'],
                'Unexpected typeData projection for ' . $qtype
            );
            $this->assert_projection_has_no_objects($reportquestion);
            $this->assertStringNotContainsString(
                'private-',
                json_encode($reportquestion, JSON_THROW_ON_ERROR)
            );

            $aggregate = $fixture['aggregate'];
            if (array_is_list($aggregate)) {
                $aggregate[] = (object)['solution' => 'private'];
            } else {
                $aggregate['futureSolution'] = 'private-' . $qtype;
                $aggregate['futureObject'] =
                    (object)['answer' => 'private'];
            }
            $reportaggregate = report_distribution_sanitizer::for_report(
                $aggregate,
                true
            );
            $this->assertSame(
                $fixture['expectedAggregate'],
                $reportaggregate,
                'Unexpected aggregate projection for ' . $qtype
            );
            $this->assert_projection_has_no_objects($reportaggregate);

            $export = report_distribution_sanitizer::for_export([
                'question' => $question,
                'aggregate' => $aggregate,
                'futureSolution' => 'private-' . $qtype,
            ]);
            $encoded = json_encode($export, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('private-', $encoded);
            $this->assertStringNotContainsString('"correct":', $encoded);
            $this->assert_projection_has_no_objects($export);
        }
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($fixtures), $types)),
            'A report projection fixture exists for a type the registry does not know.'
        );
    }

    /**
     * An unregistered qtype and aggregate kind disclose no future fields.
     *
     * @return void
     */
    public function test_unknown_projection_kinds_fail_closed(): void {
        $this->assertSame([], report_distribution_sanitizer::for_report([
            'qtype' => 'future',
            'questionText' => 'Safe-looking text',
            'typeData' => ['newAnswerKey' => 'private'],
        ], true));
        $this->assertSame([], report_distribution_sanitizer::for_report([
            'kind' => 'future',
            'newAnswerKey' => 'private',
        ], true));
    }

    /**
     * Build one distribution through the private projector boundary.
     */
    private function distribution(bool $teacher): array {
        $root = [
            'rootKey' => '7:11',
            'distributions' => [],
        ];
        $source = [
            'key' => 'session:23',
            'name' => 'Live session',
        ];
        $question = (object)[
            'id' => 11,
            'version' => 2,
        ];
        $projection = $this->projection();

        $method = new \ReflectionMethod(
            report_projector::class,
            'add_distribution'
        );
        $arguments = [
            &$root,
            $source,
            $question,
            $projection,
            'authoritative-visit',
            $teacher,
        ];
        $method->invokeArgs(null, $arguments);

        return $root['distributions'][0];
    }

    /**
     * Current qtype fixtures with their exact solution-free projections.
     *
     * @return array<string,array<string,mixed>>
     */
    private function current_type_fixtures(): array {
        $choiceaggregate = [[
            'choiceId' => 'a',
            'count' => 2,
            'percent' => 100.0,
            'correct' => true,
            'answerKey' => 'private',
        ]];
        $expectedchoiceaggregate = [[
            'choiceId' => 'a',
            'count' => 2,
            'percent' => 100.0,
            'correct' => true,
        ]];
        return [
            'quiz' => [
                'typeData' => [],
                'expectedTypeData' => [],
                'choices' => [[
                    'id' => 'a',
                    'text' => 'Choice',
                    'correct' => true,
                ]],
                'aggregate' => $choiceaggregate,
                'expectedAggregate' => $expectedchoiceaggregate,
            ],
            'truefalse' => [
                'typeData' => [],
                'expectedTypeData' => [],
                'choices' => [[
                    'id' => 'true',
                    'text' => 'True',
                    'correct' => true,
                ]],
                'aggregate' => [[
                    'choiceId' => 'true',
                    'count' => 1,
                    'percent' => 50.0,
                    'correct' => true,
                ]],
                'expectedAggregate' => [[
                    'choiceId' => 'true',
                    'count' => 1,
                    'percent' => 50.0,
                    'correct' => true,
                ]],
            ],
            'shortanswer' => [
                'typeData' => [
                    'maxChars' => 255,
                    'typoTolerance' => true,
                    'acceptedAnswers' => ['private'],
                ],
                'expectedTypeData' => [
                    'maxChars' => 255,
                    'typoTolerance' => true,
                ],
                'aggregate' => [
                    'kind' => 'shortanswer',
                    'total' => 2,
                    'correctCount' => 1,
                    'responses' => [[
                        'text' => 'A response',
                        'count' => 2,
                        'isCorrect' => true,
                    ]],
                ],
                'expectedAggregate' => [
                    'kind' => 'shortanswer',
                    'total' => 2,
                    'correctCount' => 1,
                    'responses' => [[
                        'text' => 'A response',
                        'count' => 2,
                    ]],
                ],
            ],
            'poll' => [
                'typeData' => [],
                'expectedTypeData' => [],
                'choices' => [[
                    'id' => 'a',
                    'text' => 'Opinion',
                ]],
                'aggregate' => [[
                    'choiceId' => 'a',
                    'count' => 3,
                    'percent' => 75.0,
                ]],
                'expectedAggregate' => [[
                    'choiceId' => 'a',
                    'count' => 3,
                    'percent' => 75.0,
                ]],
            ],
            'wordcloud' => [
                'typeData' => [
                    'maxChars' => 50,
                    'moderation' => true,
                ],
                'expectedTypeData' => [
                    'maxChars' => 50,
                    'moderation' => true,
                ],
                'aggregate' => [
                    'kind' => 'wordcloud',
                    'total' => 2,
                    'responseCount' => 2,
                    'publishedCount' => 1,
                    'words' => [[
                        'key' => 'term',
                        'text' => 'Term',
                        'count' => 1,
                        'percent' => 100.0,
                    ]],
                    'moderation' => [
                        'pendingCount' => 1,
                        'approvedCount' => 1,
                        'rejectedCount' => 0,
                        'items' => [[
                            'key' => 'term',
                            'text' => 'Term',
                            'count' => 1,
                            'status' => 'approved',
                        ]],
                    ],
                ],
                'expectedAggregate' => [
                    'kind' => 'wordcloud',
                    'total' => 2,
                    'responseCount' => 2,
                    'publishedCount' => 1,
                    'words' => [[
                        'key' => 'term',
                        'text' => 'Term',
                        'count' => 1,
                        'percent' => 100.0,
                    ]],
                    'moderation' => [
                        'pendingCount' => 1,
                        'approvedCount' => 1,
                        'rejectedCount' => 0,
                        'items' => [[
                            'key' => 'term',
                            'text' => 'Term',
                            'count' => 1,
                            'status' => 'approved',
                        ]],
                    ],
                ],
            ],
            'slide' => [
                'typeData' => [
                    'layout' => 'title-body',
                    'title' => 'Title',
                    'body' => 'Body',
                    'bullets' => ['One', 'Two'],
                    'quote' => 'Quote',
                    'attribution' => 'Author',
                    'reactions' => true,
                    'reactionKeys' => ['heart'],
                    'reactionOptions' => [[
                        'id' => 'heart',
                        'emoji' => '❤️',
                        'futureSolution' => 'private',
                    ]],
                    'speakText' => 'Title Body',
                ],
                'expectedTypeData' => [
                    'layout' => 'title-body',
                    'title' => 'Title',
                    'body' => 'Body',
                    'bullets' => ['One', 'Two'],
                    'quote' => 'Quote',
                    'attribution' => 'Author',
                    'reactions' => true,
                    'reactionKeys' => ['heart'],
                    'reactionOptions' => [[
                        'id' => 'heart',
                        'emoji' => '❤️',
                    ]],
                    'speakText' => 'Title Body',
                ],
                'aggregate' => [
                    'kind' => 'reactions',
                    'counts' => [[
                        'reaction' => 'heart',
                        'count' => 2,
                    ]],
                    'total' => 2,
                ],
                'expectedAggregate' => [
                    'kind' => 'reactions',
                    'counts' => [[
                        'reaction' => 'heart',
                        'count' => 2,
                    ]],
                    'total' => 2,
                ],
            ],
            'puzzle' => [
                'typeData' => [
                    'items' => [
                        [
                            'id' => 'b',
                            'text' => 'Second',
                            'mediaUrl' => '/private/b',
                        ],
                        [
                            'id' => 'a',
                            'text' => 'First',
                            'mediaMimeType' => 'image/png',
                        ],
                    ],
                    'correctOrderIds' => ['b', 'a'],
                ],
                'expectedTypeData' => [
                    'items' => [
                        [
                            'id' => 'a',
                            'text' => 'First',
                            'mediaMimeType' => 'image/png',
                        ],
                        [
                            'id' => 'b',
                            'text' => 'Second',
                        ],
                    ],
                ],
                'aggregate' => [
                    'kind' => 'puzzle',
                    'total' => 2,
                    'correctCount' => 1,
                    'positions' => [[
                        'itemId' => 'b',
                        'position' => 0,
                    ]],
                ],
                'expectedAggregate' => [
                    'kind' => 'puzzle',
                    'total' => 2,
                    'correctCount' => 1,
                ],
            ],
            'scale' => [
                'typeData' => [
                    'steps' => 5,
                    'minLabel' => 'Low',
                    'maxLabel' => 'High',
                ],
                'expectedTypeData' => [
                    'steps' => 5,
                    'minLabel' => 'Low',
                    'maxLabel' => 'High',
                ],
                'aggregate' => [
                    'kind' => 'scale',
                    'total' => 2,
                    'mean' => 3.5,
                    'median' => null,
                    'histogram' => [[
                        'value' => 3,
                        'count' => 1,
                        'percent' => 50.0,
                    ]],
                ],
                'expectedAggregate' => [
                    'kind' => 'scale',
                    'total' => 2,
                    'mean' => 3.5,
                    'median' => null,
                    'histogram' => [[
                        'value' => 3,
                        'count' => 1,
                        'percent' => 50.0,
                    ]],
                ],
            ],
            'slider' => [
                'typeData' => [
                    'min' => 0.0,
                    'max' => 10.0,
                    'step' => 0.5,
                    'target' => 7.0,
                    'tolerance' => 0.5,
                ],
                'expectedTypeData' => [
                    'min' => 0.0,
                    'max' => 10.0,
                    'step' => 0.5,
                ],
                'aggregate' => [
                    'kind' => 'slider',
                    'total' => 2,
                    'correctCount' => 1,
                    'mean' => 5.25,
                    'median' => 5.25,
                    'values' => [5.0, 5.5],
                    'target' => 7.0,
                    'tolerance' => 0.5,
                ],
                'expectedAggregate' => [
                    'kind' => 'slider',
                    'total' => 2,
                    'correctCount' => 1,
                    'mean' => 5.25,
                    'median' => 5.25,
                    'values' => [5.0, 5.5],
                ],
            ],
            'pin' => [
                'typeData' => [
                    'target' => ['x' => 20.0, 'y' => 30.0],
                    'radius' => 4.0,
                ],
                'expectedTypeData' => [],
                'aggregate' => [
                    'kind' => 'pin',
                    'total' => 1,
                    'correctCount' => 1,
                    'points' => [[
                        'x' => 20.0,
                        'y' => 30.0,
                    ]],
                    'target' => ['x' => 20.0, 'y' => 30.0],
                    'radius' => 4.0,
                ],
                'expectedAggregate' => [
                    'kind' => 'pin',
                    'total' => 1,
                    'correctCount' => 1,
                    'points' => [[
                        'x' => 20.0,
                        'y' => 30.0,
                    ]],
                ],
            ],
            'reveal' => [
                'typeData' => [
                    'grid' => 4,
                    'revealSeconds' => 3,
                    'tileOrder' => [2, 0, 1],
                    'maxChars' => 255,
                    'acceptedAnswers' => ['private'],
                ],
                'expectedTypeData' => [
                    'grid' => 4,
                    'revealSeconds' => 3,
                    'tileOrder' => [2, 0, 1],
                    'maxChars' => 255,
                ],
                'aggregate' => [
                    'kind' => 'reveal',
                    'total' => 2,
                    'correctCount' => 1,
                ],
                'expectedAggregate' => [
                    'kind' => 'reveal',
                    'total' => 2,
                    'correctCount' => 1,
                ],
            ],
            'brainstorm' => [
                'typeData' => [
                    'stage' => 'vote',
                    'maxIdeaChars' => 1000,
                    'collectSeconds' => 60,
                    'voteSeconds' => 30,
                    'grouping' => 'manual',
                    'moderation' => true,
                ],
                'expectedTypeData' => [
                    'stage' => 'vote',
                    'maxIdeaChars' => 1000,
                    'collectSeconds' => 60,
                    'voteSeconds' => 30,
                    'grouping' => 'manual',
                    'moderation' => true,
                ],
                'aggregate' => [
                    'kind' => 'brainstorm',
                    'stage' => 'done',
                    'groups' => [[
                        'key' => 'group-a',
                        'label' => 'Group A',
                        'ideas' => [[
                            'id' => 7,
                            'text' => 'An idea',
                        ]],
                        'votes' => null,
                    ]],
                    'groupingCurrent' => true,
                    'responseCount' => 1,
                    'totalIdeas' => 1,
                    'totalVotes' => null,
                    'moderation' => [
                        'items' => [[
                            'id' => 7,
                            'text' => 'An idea',
                            'status' => 'approved',
                        ]],
                    ],
                ],
                'expectedAggregate' => [
                    'kind' => 'brainstorm',
                    'stage' => 'done',
                    'groups' => [[
                        'key' => 'group-a',
                        'label' => 'Group A',
                        'ideas' => [[
                            'id' => 7,
                            'text' => 'An idea',
                        ]],
                        'votes' => null,
                    ]],
                    'groupingCurrent' => true,
                    'responseCount' => 1,
                    'totalIdeas' => 1,
                    'totalVotes' => null,
                    'moderation' => [
                        'items' => [[
                            'id' => 7,
                            'text' => 'An idea',
                            'status' => 'approved',
                        ]],
                    ],
                ],
            ],
            'open' => [
                'typeData' => [
                    'maxChars' => 6000,
                    'sampleAnswer' => 'private',
                ],
                'expectedTypeData' => [
                    'maxChars' => 6000,
                ],
                'aggregate' => [
                    'kind' => 'open',
                    'total' => 1,
                    'responses' => [[
                        'id' => 9,
                        'text' => 'An open response',
                        'modelAnswer' => 'private',
                    ]],
                ],
                'expectedAggregate' => [
                    'kind' => 'open',
                    'total' => 1,
                    'responses' => [[
                        'id' => 9,
                        'text' => 'An open response',
                    ]],
                ],
            ],
        ];
    }

    /**
     * Assert recursively that no object crossed the array-only boundary.
     */
    private function assert_projection_has_no_objects(mixed $value): void {
        $this->assertFalse(is_object($value));
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $entry) {
            $this->assert_projection_has_no_objects($entry);
        }
    }

    /**
     * Projection fixture containing display, solution and private fields.
     */
    private function projection(): array {
        return [
            'visit' => 'public-projection-key',
            'stage' => 'answer',
            'question' => [
                'qtype' => 'quiz',
                'questionText' => 'Choose the answer.',
                'questionToken' => 'private-token',
                'token' => 'private-token',
                'visitToken' => 'private-visit-token',
                'visit' => 'private-visit',
                'visits' => ['private-visit'],
                'bearer' => 'private-bearer',
                'solution' => 'Canonical solution',
                'solutionIds' => ['a'],
                'explanation' => 'Worked solution',
                'modelAnswer' => 'Expected answer',
                'optionsJson' => '{"answer":"Expected answer"}',
                'correctAnswer' => 'Expected answer',
                'correctAnswers' => ['Expected answer'],
                'correctChoiceIds' => ['a'],
                'correctOrderIds' => ['a'],
                'options' => [
                    'sampleAnswer' => 'Expected answer',
                    'inputLabel' => 'Your answer',
                ],
                'typeData' => [
                    'acceptedAnswers' => ['Expected answer'],
                    'targetZone' => ['x' => 4, 'y' => 5],
                    'responseType' => 'text',
                ],
                'choices' => [
                    [
                        'id' => 'a',
                        'text' => 'Correct display label',
                        'correct' => true,
                    ],
                    [
                        'id' => 'b',
                        'text' => 'Incorrect display label',
                        'correct' => false,
                    ],
                    [
                        'id' => 'c',
                        'text' => 'Invalid marker',
                        'correct' => 1,
                    ],
                ],
                'mediaUrl' => '/pluginfile.php/private-media',
            ],
            'aggregate' => [
                [
                    'choiceId' => 'a',
                    'count' => 1,
                    'percent' => 50.0,
                    'correct' => true,
                    'answerKey' => 'private-key',
                ],
                [
                    'choiceId' => 'b',
                    'count' => 1,
                    'percent' => 50.0,
                    'correct' => false,
                ],
            ],
        ];
    }
}
