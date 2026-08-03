<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Closing screen collecting every worked solution of a finished run.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

use mod_quizgeist\local\live\explanation_policy;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the "Alle Lösungswege" screen for a completed run.
 *
 * A performance run withholds the worked solution question by question
 * (explanationpolicy = atend). The learner is not left without one: after the
 * run this screen collects every solution in the played order. With `never`
 * the screen stays unavailable; with `immediate` the solutions were already
 * visible and are gathered here once more for revision.
 */
final class review_summary {

    /** Maximum characters of one worked solution in the closing screen. */
    public const MAX_EXPLANATION = 4000;

    /**
     * Project the closing screen for one attempt.
     *
     * @param \stdClass $attempt Joined attempt record.
     * @param \stdClass[] $rows Frozen attempt questions indexed by sort index.
     * @return array{
     *     available:bool,
     *     policy:string,
     *     deferred:bool,
     *     total:int,
     *     withExplanation:int,
     *     entries:array<int,array{index:int,questionText:string,explanation:string}>
     * }
     */
    public static function for_attempt(\stdClass $attempt, array $rows): array {
        $policy = explanation_policy::normalise(
            $attempt->activityexplanationpolicy ?? null
        );
        $completed = (string)($attempt->status ?? '') === 'completed';
        $summary = [
            // The screen exists only after the run and only when the policy
            // allows a disclosure at all.
            'available' => $completed
                && explanation_policy::discloses_at_end($policy),
            'policy' => $policy,
            // True exactly when the learner was deliberately kept waiting
            // during the run. The client explains the wait instead of
            // presenting a bare list.
            'deferred' => $policy === explanation_policy::ATEND,
            'total' => count($rows),
            'withExplanation' => 0,
            'entries' => [],
        ];
        if (!$summary['available']) {
            return $summary;
        }
        foreach ($rows as $row) {
            $explanation = trim((string)($row->explanation ?? ''));
            if ($explanation === '') {
                continue;
            }
            $summary['entries'][] = [
                'index' => (int)($row->sortindex ?? 0),
                'questionText' => (string)($row->questiontext ?? ''),
                'explanation' => \core_text::substr(
                    $explanation,
                    0,
                    self::MAX_EXPLANATION
                ),
            ];
        }
        $summary['withExplanation'] = count($summary['entries']);
        return $summary;
    }
}
