<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the stable report export column contract.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\report\report_export;

defined('MOODLE_INTERNAL') || die();

/**
 * Existing export columns stay in place while new columns are appended.
 */
final class report_export_columns_test extends \advanced_testcase {

    public function test_columns_keep_the_first_nineteen_positions(): void {
        $this->resetAfterTest(true);

        $keys = [
            'rowtype',
            'name',
            'useridentifier',
            'source',
            'rootid',
            'question',
            'playedversions',
            'points',
            'maxpoints',
            'correct',
            'gradable',
            'correctpercent',
            'pointspercent',
            'averageresponsetimems',
            'responses',
            'missing',
            'status',
            'content',
            'timestamp',
        ];
        $columns = report_export::columns();
        $expected = array_map(
            static fn(string $key): string => get_string(
                'export:column:' . $key,
                'mod_quizgeist'
            ),
            $keys
        );

        $this->assertCount(21, $columns);
        $this->assertSame($expected, array_slice($columns, 0, 19));
        $this->assertSame(
            [
                get_string('export:column:competence', 'mod_quizgeist'),
                get_string(
                    'export:column:competencepercent',
                    'mod_quizgeist'
                ),
            ],
            array_slice($columns, 19)
        );
    }

    public function test_each_report_row_matches_columns_and_appended_cells(
    ): void {
        $this->resetAfterTest(true);

        $report = [
            'students' => [[
                'displayName' => 'Student',
                'userIdentifier' => 'student@example.test',
                'points' => 4,
                'maxPoints' => 5,
                'correctCount' => 2,
                'gradableCount' => 2,
                'correctPercent' => 100,
                'averageResponseTimeMs' => 800,
                'responseCount' => 2,
            ]],
            'questions' => [[
                'rootId' => 7,
                'title' => 'Question',
                'versions' => [['version' => 1, 'questionId' => 7]],
                'points' => 4,
                'maxPoints' => 5,
                'correctCount' => 2,
                'gradableCount' => 2,
                'correctPercent' => 100,
                'averageResponseTimeMs' => 800,
                'responseCount' => 2,
                'missingCount' => 0,
                'competence' => ['label' => 'Analyse', 'percent' => 75],
            ]],
            'timeline' => [[
                'instanceName' => 'Timeline',
                'sourceLabel' => 'Source',
                'averageCorrectPercent' => 90,
                'pointsPercent' => 80,
                'participantCount' => 1,
                'kind' => 'session',
                'sourceKey' => 'session:1',
                'timestamp' => 1700000000,
            ]],
            'openResponses' => [[
                'displayName' => 'Student',
                'userIdentifier' => 'student@example.test',
                'sourceLabel' => 'Source',
                'rootId' => 7,
                'questionTitle' => 'Question',
                'version' => 1,
                'status' => 'submitted',
                'text' => 'An answer',
                'timeCreated' => 1700000001,
            ]],
            'moderationTrail' => [[
                'actorName' => 'Teacher',
                'sourceLabel' => 'Source',
                'rootId' => 7,
                'questionTitle' => 'Question',
                'version' => 1,
                'action' => 'approved',
                'targetKey' => 'response:1',
                'timeCreated' => 1700000002,
            ]],
        ];
        $columns = report_export::columns();
        $rows = iterator_to_array(report_export::rows($report), false);

        $this->assertCount(5, $rows);
        foreach ($rows as $row) {
            $this->assertCount(count($columns), $row);
        }
        $this->assertSame(
            ['student', 'question', 'timeline', 'open_response', 'moderation'],
            array_column($rows, 0)
        );

        $bytype = [];
        foreach ($rows as $row) {
            $bytype[$row[0]] = $row;
        }
        foreach (
            ['student', 'timeline', 'open_response', 'moderation'] as $type
        ) {
            $this->assertSame('', $bytype[$type][19]);
            $this->assertSame('', $bytype[$type][20]);
        }
        $this->assertSame('Analyse', $bytype['question'][19]);
        $this->assertSame(75, $bytype['question'][20]);
    }
}
