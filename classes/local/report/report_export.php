<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\report;

defined('MOODLE_INTERNAL') || die();

/**
 * Stable, visit-token-free report export projection.
 *
 * The column list is a MACHINE FORMAT. School timetables, spreadsheets and
 * scripts read it by position ([P10-F16], "bewusst nicht geändert"). New
 * columns are therefore always APPENDED; an existing column is never renamed,
 * never moved and never removed. `checks/p6_fixture.php` nails this down from
 * the outside.
 */
final class report_export {

    public static function columns(): array {
        return array_map(
            static fn(string $key): string => get_string(
                'export:column:' . $key,
                'mod_quizgeist'
            ),
            [
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
                // --- ab P11/C2 angehängt, nie eingeschoben (F6) ---
                'competence',
                'competencepercent',
            ]
        );
    }

    /**
     * Stream pre-projected rows with localised headers as an Excel-friendly CSV.
     *
     * @param iterable<int,array> $rows Export rows.
     */
    public static function download_csv_rows(
        string $filename,
        iterable $rows
    ): void {
        if (!defined('BEHAT_SITE_RUNNING') && !PHPUNIT_TEST) {
            header('Cache-Control: private, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
            header('Content-Type: text/csv; charset=utf-8');
            header(
                'Content-Disposition: attachment; filename="'
                    . clean_filename($filename) . '.csv"'
            );
        }
        \core\session\manager::write_close();
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new \coding_exception('CSV output stream is unavailable.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        self::write_csv_row($output, self::columns());
        foreach ($rows as $row) {
            self::write_csv_row($output, $row);
        }
        fclose($output);
    }

    /**
     * @param callable(array):iterable<int,array>|null $distributionprovider
     * @return \Generator<int,array>
     */
    public static function rows(
        array $report,
        ?callable $distributionprovider = null
    ): \Generator {
        foreach ($report['students'] ?? [] as $student) {
            yield self::row(
                'student',
                (string)($student['displayName'] ?? ''),
                (string)($student['userIdentifier'] ?? ''),
                '',
                '',
                '',
                '',
                $student['points'] ?? '',
                $student['maxPoints'] ?? '',
                $student['correctCount'] ?? '',
                $student['gradableCount'] ?? '',
                $student['correctPercent'] ?? '',
                self::percent(
                    $student['points'] ?? 0,
                    $student['maxPoints'] ?? 0
                ),
                $student['averageResponseTimeMs'] ?? '',
                $student['responseCount'] ?? '',
                '',
                '',
                '',
                '',
                '',
                ''
            );
        }
        foreach ($report['questions'] ?? [] as $question) {
            $versions = implode(', ', array_map(
                static fn(array $version): string => 'v'
                    . (int)$version['version']
                    . ' (#' . (int)$version['questionId'] . ')',
                $question['versions'] ?? []
            ));
            yield self::row(
                'question',
                '',
                '',
                '',
                $question['rootId'] ?? '',
                $question['title'] ?? '',
                $versions,
                $question['points'] ?? '',
                $question['maxPoints'] ?? '',
                $question['correctCount'] ?? '',
                $question['gradableCount'] ?? '',
                $question['correctPercent'] ?? '',
                self::percent(
                    $question['points'] ?? 0,
                    $question['maxPoints'] ?? 0
                ),
                $question['averageResponseTimeMs'] ?? '',
                $question['responseCount'] ?? '',
                $question['missingCount'] ?? '',
                !empty($question['difficult']) ? 'difficult' : '',
                '',
                '',
                // F6: die führende Kompetenz dieser Frage und die Quote ihres
                // Kompetenzbereichs. Ohne reports-Addon bleiben beide leer.
                (string)($question['competence']['label'] ?? ''),
                $question['competence']['percent'] ?? ''
            );
            $distributions = $distributionprovider === null
                ? ($question['distributions'] ?? [])
                : $distributionprovider($question);
            foreach ($distributions as $distribution) {
                $projection = report_distribution_sanitizer::for_export([
                    'question' => $distribution['question'] ?? [],
                    'aggregate' => $distribution['aggregate'] ?? [],
                ]);
                $json = json_encode(
                    $projection,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                yield self::row(
                    'distribution',
                    '',
                    '',
                    $distribution['sourceLabel'] ?? '',
                    $question['rootId'] ?? '',
                    $projection['question']['questionText'] ?? '',
                    'v' . (int)($distribution['version'] ?? 0)
                        . ' (#' . (int)($distribution['questionId'] ?? 0) . ')',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    '',
                    $distribution['aggregate']['responseCount']
                        ?? $distribution['aggregate']['total']
                        ?? '',
                    '',
                    (!empty($distribution['authoritative'])
                        ? 'authoritative'
                        : 'unresolved')
                        . '; stage=' . (string)($distribution['stage'] ?? '')
                        . '; occurrences='
                        . (int)($distribution['occurrenceCount'] ?? 1),
                    is_string($json) ? $json : '',
                    '',
                    '',
                    ''
                );
            }
        }
        foreach ($report['timeline'] ?? [] as $entry) {
            yield self::row(
                'timeline',
                $entry['instanceName'] ?? '',
                '',
                $entry['sourceLabel'] ?? '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $entry['averageCorrectPercent'] ?? '',
                $entry['pointsPercent'] ?? '',
                '',
                $entry['participantCount'] ?? '',
                '',
                $entry['kind'] ?? '',
                $entry['sourceKey'] ?? '',
                $entry['timestamp'] ?? '',
                '',
                ''
            );
        }
        foreach ($report['openResponses'] ?? [] as $response) {
            yield self::row(
                'open_response',
                $response['displayName'] ?? '',
                $response['userIdentifier'] ?? '',
                $response['sourceLabel'] ?? '',
                $response['rootId'] ?? '',
                $response['questionTitle'] ?? '',
                'v' . (int)($response['version'] ?? 0),
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                1,
                '',
                $response['status'] ?? '',
                $response['text'] ?? '',
                $response['timeCreated'] ?? '',
                '',
                ''
            );
        }
        foreach ($report['moderationTrail'] ?? [] as $entry) {
            yield self::row(
                'moderation',
                $entry['actorName'] ?? '',
                '',
                $entry['sourceLabel'] ?? '',
                $entry['rootId'] ?? '',
                $entry['questionTitle'] ?? '',
                'v' . (int)($entry['version'] ?? 0),
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $entry['action'] ?? '',
                $entry['targetKey'] ?? '',
                $entry['timeCreated'] ?? '',
                '',
                ''
            );
        }
    }

    /**
     * Keep every yielded row aligned with columns().
     */
    private static function row(...$cells): array {
        return array_values($cells);
    }

    /**
     * @param resource $output
     */
    private static function write_csv_row($output, array $row): void {
        $row = array_map(static function($value): string {
            if (is_float($value)) {
                $value = rtrim(
                    rtrim(number_format($value, 6, ',', ''), '0'),
                    ','
                );
            } else if ($value === null) {
                $value = '';
            } else {
                $value = (string)$value;
            }
            return (string)\core\dataformat::escape_spreadsheet_formula(
                $value
            );
        }, $row);
        fputcsv($output, $row, ';', '"', '');
    }

    private static function percent($points, $maximum): float|string {
        $maximum = (float)$maximum;
        return $maximum > 0
            ? round((float)$points * 100 / $maximum, 1)
            : '';
    }
}
