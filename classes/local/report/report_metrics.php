<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Metric accumulation and finalisation for Quizgeist reports.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\report;

defined('MOODLE_INTERNAL') || die();

/**
 * Applies learner observations and finalises stable report metric rows.
 */
final class report_metrics {

    /**
     * Apply one learner/source/question occurrence exactly once.
     *
     * @param array $root Question-root accumulator.
     * @param array $student Student accumulator.
     * @param array $sourcestats Source accumulator.
     * @param int $userid User ID.
     * @param \stdClass[] $rows Answer rows.
     * @param array<string,bool> $primarytypes Primary answer types.
     * @param bool $graded Whether correctness contributes to metrics.
     * @param bool $selfstudy Whether this is a self-study observation.
     * @return void
     */
    public static function apply_observation(
        array &$root,
        array &$student,
        array &$sourcestats,
        int $userid,
        array $rows,
        array $primarytypes,
        bool $graded,
        bool $selfstudy
    ): void {
        $points = 0;
        $maxpoints = 0;
        $correct = 0;
        $gradable = 0;
        $missing = 0;
        $responsetime = null;
        foreach ($rows as $row) {
            $type = (string)$row->answertype;
            $points += (int)$row->points;
            if ($graded && $row->iscorrect !== null
                    && ($type === 'scorevoid'
                        || isset($primarytypes[$type]))) {
                $gradable++;
                $maxpoints += max(0, (int)$row->maxpoints);
                if (!empty($row->iscorrect)) {
                    $correct++;
                }
            }
            if ($selfstudy && $graded && $type === 'scorevoid') {
                $missing++;
            }
            if ($type !== 'scorevoid' && isset($primarytypes[$type])) {
                $candidate = max(0, (int)$row->responsetime);
                $responsetime = $responsetime === null
                    ? $candidate
                    : min($responsetime, $candidate);
            }
        }
        $root['points'] += $points;
        $root['maxPoints'] += $maxpoints;
        $root['correctCount'] += $correct;
        $root['gradableCount'] += $gradable;
        $root['missingCount'] += $missing;
        $student['points'] += $points;
        $student['maxPoints'] += $maxpoints;
        $student['correctCount'] += $correct;
        $student['gradableCount'] += $gradable;
        $sourcestats['_users'][$userid] ??= [
            'points' => 0,
            'maxPoints' => 0,
            'correct' => 0,
            'gradable' => 0,
            'timeTotal' => 0,
            'timeSamples' => 0,
        ];
        $sourcestats['_users'][$userid]['points'] += $points;
        $sourcestats['_users'][$userid]['maxPoints'] += $maxpoints;
        $sourcestats['_users'][$userid]['correct'] += $correct;
        $sourcestats['_users'][$userid]['gradable'] += $gradable;
        if ($responsetime !== null) {
            $root['_timeTotal'] += $responsetime;
            $root['_timeSamples']++;
            $root['responseCount']++;
            $student['_timeTotal'] += $responsetime;
            $student['_timeSamples']++;
            $student['responseCount']++;
            $sourcestats['_users'][$userid]['timeTotal'] += $responsetime;
            $sourcestats['_users'][$userid]['timeSamples']++;
        }
    }

    /**
     * Count an eligible live non-response consistently as incorrect.
     *
     * @param array $root Question-root accumulator.
     * @param array $student Student accumulator.
     * @param array $sourcestats Source accumulator.
     * @param int $userid User ID.
     * @param bool $graded Whether correctness contributes to metrics.
     * @param int $maximum Maximum points for the missing observation.
     * @return void
     */
    public static function apply_missing_observation(
        array &$root,
        array &$student,
        array &$sourcestats,
        int $userid,
        bool $graded,
        int $maximum
    ): void {
        $root['missingCount']++;
        if (!$graded) {
            return;
        }
        $root['gradableCount']++;
        $root['maxPoints'] += max(0, $maximum);
        $student['gradableCount']++;
        $student['maxPoints'] += max(0, $maximum);
        $sourcestats['_users'][$userid] ??= [
            'points' => 0,
            'maxPoints' => 0,
            'correct' => 0,
            'gradable' => 0,
            'timeTotal' => 0,
            'timeSamples' => 0,
        ];
        $sourcestats['_users'][$userid]['maxPoints'] += max(0, $maximum);
        $sourcestats['_users'][$userid]['gradable']++;
    }

    /**
     * Finalise and sort question-root metric rows.
     *
     * @param array $questions Question-root accumulators.
     * @param int $difficultminsample Minimum gradable sample.
     * @param float $difficultthreshold Difficult correctness threshold.
     * @return array
     */
    public static function finish_questions(
        array $questions,
        int $difficultminsample,
        float $difficultthreshold
    ): array {
        foreach ($questions as &$question) {
            $question['qtypes'] = array_keys($question['qtypes']);
            $question['versions'] = array_values($question['_versions']);
            usort(
                $question['versions'],
                static fn(array $left, array $right): int =>
                    $left['version'] <=> $right['version']
                    ?: $left['questionId'] <=> $right['questionId']
            );
            usort(
                $question['distributions'],
                static fn(array $left, array $right): int =>
                    strcmp($left['sourceKey'], $right['sourceKey'])
                    ?: $left['questionId'] <=> $right['questionId']
            );
            // F2 Denk-Moment: stable order for the justifications the
            // projector already cut down to what this viewer may see.
            $question['reasons'] = array_values($question['reasons'] ?? []);
            usort(
                $question['reasons'],
                static fn(array $left, array $right): int =>
                    $left['timeCreated'] <=> $right['timeCreated']
                    ?: $left['userId'] <=> $right['userId']
            );
            $question['correctPercent'] = $question['gradableCount'] > 0
                ? round(
                    $question['correctCount'] * 100
                        / $question['gradableCount'],
                    1
                )
                : null;
            $question['averageResponseTimeMs'] =
                $question['_timeSamples'] > 0
                    ? (int)round(
                        $question['_timeTotal'] / $question['_timeSamples']
                    )
                    : null;
            $question['difficult'] = $question['correctPercent'] !== null
                && $question['gradableCount'] >= $difficultminsample
                && $question['correctPercent']
                    < $difficultthreshold;
            unset(
                $question['_versions'],
                $question['_latestVersion'],
                $question['_timeTotal'],
                $question['_timeSamples']
            );
        }
        unset($question);
        $questions = array_values($questions);
        usort(
            $questions,
            static fn(array $left, array $right): int =>
                strnatcasecmp($left['title'], $right['title'])
                ?: strcmp($left['rootKey'], $right['rootKey'])
        );
        return $questions;
    }

    /**
     * Aggregate finished question rows along the competence axis (F6).
     *
     * Pure arithmetic over data the projector already gathered. Every question
     * additionally learns its own leading competence, so the export can name it
     * without a second lookup and the panel can label a bar without guessing.
     *
     * A question may carry several competences; each one counts the question in
     * full. Competence coverage is a statement about a curriculum area, not a
     * division of one question into fractions.
     *
     * @param array $questions Finished question rows, modified in place.
     * @param array<int, \stdClass[]> $tagsbyroot Approved competence tags.
     * @return array<int, array{
     *     tagId:int, key:string, label:string, color:?string,
     *     correctPercent:?float, pointsPercent:?float,
     *     sample:int, questionCount:int
     * }>
     */
    public static function finish_competences(
        array &$questions,
        array $tagsbyroot
    ): array {
        $aggregates = [];
        foreach ($questions as &$question) {
            $rootid = (int)($question['rootId'] ?? 0);
            $tags = array_values($tagsbyroot[$rootid] ?? []);
            $question['competence'] = null;
            foreach ($tags as $index => $tag) {
                $key = (string)$tag->tagkey;
                $color = $tag->colorkey !== null && $tag->colorkey !== ''
                    ? (string)$tag->colorkey
                    : null;
                if (!isset($aggregates[$key])) {
                    $aggregates[$key] = [
                        'tagId' => (int)$tag->tagid,
                        'key' => $key,
                        'label' => (string)$tag->label,
                        'color' => $color,
                        'correctCount' => 0,
                        'gradableCount' => 0,
                        'points' => 0,
                        'maxPoints' => 0,
                        'questionCount' => 0,
                    ];
                }
                $aggregates[$key]['correctCount'] +=
                    (int)($question['correctCount'] ?? 0);
                $aggregates[$key]['gradableCount'] +=
                    (int)($question['gradableCount'] ?? 0);
                $aggregates[$key]['points'] += (int)($question['points'] ?? 0);
                $aggregates[$key]['maxPoints'] +=
                    (int)($question['maxPoints'] ?? 0);
                $aggregates[$key]['questionCount']++;
                if ($index === 0) {
                    // Tags arrive ordered by weight, so the first one is the
                    // leading competence of this question.
                    $question['competence'] = [
                        'key' => $key,
                        'label' => (string)$tag->label,
                        'color' => $color,
                        'percent' => null,
                    ];
                }
            }
        }
        unset($question);

        $result = [];
        foreach ($aggregates as $aggregate) {
            $result[] = [
                'tagId' => $aggregate['tagId'],
                'key' => $aggregate['key'],
                'label' => $aggregate['label'],
                'color' => $aggregate['color'],
                'correctPercent' => $aggregate['gradableCount'] > 0
                    ? round(
                        $aggregate['correctCount'] * 100
                            / $aggregate['gradableCount'],
                        1
                    )
                    : null,
                'pointsPercent' => $aggregate['maxPoints'] > 0
                    ? round(
                        $aggregate['points'] * 100 / $aggregate['maxPoints'],
                        1
                    )
                    : null,
                'sample' => $aggregate['gradableCount'],
                'questionCount' => $aggregate['questionCount'],
            ];
        }
        // Weakest area first: a report exists to show where help is needed. An
        // area without evidence sorts last instead of pretending to be perfect.
        usort(
            $result,
            static fn(array $left, array $right): int
                => [$left['correctPercent'] ?? 101.0, $left['label']]
                    <=> [$right['correctPercent'] ?? 101.0, $right['label']]
        );
        $quotas = [];
        foreach ($result as $row) {
            $quotas[$row['key']] = $row['correctPercent'];
        }
        foreach ($questions as &$stamped) {
            if (is_array($stamped['competence'] ?? null)) {
                $stamped['competence']['percent'] =
                    $quotas[$stamped['competence']['key']] ?? null;
            }
        }
        unset($stamped);
        return $result;
    }

    /**
     * Site-wide evidence floor for the pedagogical "difficult" marker.
     *
     * @return int
     */
    public static function difficult_min_sample(): int {
        $configured = get_config('mod_quizgeist', 'report_difficult_min_sample');
        if ($configured === false || !is_numeric($configured)) {
            return 5;
        }
        return max(1, min(1000, (int)$configured));
    }

    /**
     * Site-wide correctness threshold for the pedagogical marker.
     *
     * @return float
     */
    public static function difficult_threshold(): float {
        $configured = get_config('mod_quizgeist', 'report_difficult_threshold');
        if ($configured === false || !is_numeric($configured)) {
            return 40.0;
        }
        return max(0.0, min(100.0, (float)$configured));
    }

    /**
     * Finalise identifiers, percentages and response times for students.
     *
     * @param array $students Student accumulators keyed by user ID.
     * @return array
     */
    public static function finish_students(array $students): array {
        ksort($students, SORT_NUMERIC);
        $identifiercounts = [];
        foreach ($students as $student) {
            $identifier = (string)$student['userIdentifier'];
            $identifiercounts[$identifier] =
                ($identifiercounts[$identifier] ?? 0) + 1;
        }
        $usedidentifiers = [];
        foreach ($identifiercounts as $identifier => $count) {
            if ($count === 1) {
                $usedidentifiers[$identifier] = true;
            }
        }
        foreach ($students as &$student) {
            if ($identifiercounts[$student['userIdentifier']] > 1) {
                $baseidentifier = (string)$student['userIdentifier'];
                $candidate = $baseidentifier . ' (#'
                    . (int)$student['userId']
                    . ')';
                $suffix = 2;
                while (array_key_exists($candidate, $usedidentifiers)) {
                    $candidate = $baseidentifier . ' (#'
                        . (int)$student['userId']
                        . ':' . $suffix
                        . ')';
                    $suffix++;
                }
                $student['userIdentifier'] = $candidate;
                $usedidentifiers[$candidate] = true;
            }
            $student['correctPercent'] = $student['gradableCount'] > 0
                ? round(
                    $student['correctCount'] * 100
                        / $student['gradableCount'],
                    1
                )
                : null;
            $student['averageResponseTimeMs'] =
                $student['_timeSamples'] > 0
                    ? (int)round(
                        $student['_timeTotal'] / $student['_timeSamples']
                    )
                    : null;
            unset($student['_timeTotal'], $student['_timeSamples']);
        }
        unset($student);
        $students = array_values($students);
        usort(
            $students,
            static fn(array $left, array $right): int =>
                strnatcasecmp($left['displayName'], $right['displayName'])
                ?: strnatcasecmp(
                    $left['userIdentifier'],
                    $right['userIdentifier']
                )
                ?: $left['userId'] <=> $right['userId']
        );
        return $students;
    }

    /**
     * Keep response and moderation audit rows stable across source loop order.
     *
     * @param array $rows Response or moderation rows.
     * @return array
     */
    public static function chronological_rows(array $rows): array {
        $rows = array_values($rows);
        usort(
            $rows,
            static fn(array $left, array $right): int =>
                ((int)($left['timeCreated'] ?? 0)
                    <=> (int)($right['timeCreated'] ?? 0))
                ?: ((int)($left['id'] ?? 0)
                    <=> (int)($right['id'] ?? 0))
        );
        return $rows;
    }

    /**
     * Finalise source statistics as chronological timeline rows.
     *
     * @param array $stats Source accumulators.
     * @return array
     */
    public static function finish_timeline(array $stats): array {
        $timeline = [];
        foreach ($stats as $sourcekey => $source) {
            $users = $source['_users'];
            $participants = count($source['_participants']);
            if ($participants === 0) {
                continue;
            }
            $points = array_sum(array_column($users, 'points'));
            $maximum = array_sum(array_column($users, 'maxPoints'));
            $correct = array_sum(array_column($users, 'correct'));
            $gradable = array_sum(array_column($users, 'gradable'));
            $timeline[] = [
                'sourceKey' => $sourcekey,
                'sourceLabel' => $source['name'],
                'kind' => $source['kind'],
                'quizgeistId' => $source['quizgeistId'],
                'instanceName' => $source['instanceName'],
                'timestamp' => $source['timestamp'],
                'pointsPercent' => $maximum > 0
                    ? round($points * 100 / $maximum, 1)
                    : null,
                'averageCorrectPercent' => $gradable > 0
                    ? round($correct * 100 / $gradable, 1)
                    : null,
                'participantCount' => $participants,
            ];
        }
        usort(
            $timeline,
            static fn(array $left, array $right): int =>
                $left['timestamp'] <=> $right['timestamp']
                ?: strcmp($left['sourceKey'], $right['sourceKey'])
        );
        return $timeline;
    }
}
