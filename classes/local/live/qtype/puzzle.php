<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\live\qtype;

use mod_quizgeist\local\live\aggregation_context;
use mod_quizgeist\local\live\interaction_policy;
use mod_quizgeist\local\live\projection_context;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\submission_context;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact-order draggable puzzle.
 */
final class puzzle implements live_question_type {

    /** Domain separator for visit-bound puzzle item handles. */
    private const HANDLE_NAMESPACE = 'puzzle:items';

    public function type(): string {
        return 'puzzle';
    }

    public function policy(array $question): interaction_policy {
        return interaction_policy::standard(
            'order',
            reasonstage: strategy_support::reason_stage($question)
        );
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        $ids = $rawanswer['orderIds'] ?? ($rawanswer['itemIds'] ?? $rawanswer);
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new \invalid_parameter_exception('orderIds is invalid.');
        }
        $available = array_values(array_map(
            static fn(array $item): string => (string)$item['id'],
            $question['options']['items']
        ));
        if (count($ids) !== count($available)
                || count(array_unique($ids, SORT_STRING)) !== count($available)) {
            throw new \invalid_parameter_exception('Every puzzle item is required.');
        }
        $ids = $context !== null && $context->visit !== ''
            ? strategy_support::resolve_opaque_ids(
                $ids,
                $available,
                $context->visit,
                self::HANDLE_NAMESPACE
            )
            : $ids;
        foreach ($ids as $id) {
            if (!is_string($id) || !in_array($id, $available, true)) {
                throw new \invalid_parameter_exception('Unknown puzzle item.');
            }
        }
        return ['orderIds' => array_values($ids)];
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        $answer = $this->validate_answer($question, $rawanswer, $context);
        $correct = array_values(array_column($question['options']['items'], 'id'));
        return strategy_support::evaluated(
            $question,
            $answer,
            $answer['orderIds'] === $correct,
            $scoring
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $correctids = array_values(array_column($question['options']['items'], 'id'));
        $positioncounts = array_fill_keys($correctids, 0);
        $total = 0;
        $correct = 0;
        foreach (strategy_support::rows($answers) as $row) {
            $ids = $row['payload']['orderIds'] ?? null;
            if (!is_array($ids)) {
                continue;
            }
            $total++;
            $correct += $ids === $correctids ? 1 : 0;
            foreach ($correctids as $position => $id) {
                if (($ids[$position] ?? null) === $id) {
                    $positioncounts[$id]++;
                }
            }
        }
        $publicids = $context->visit === ''
            ? $correctids
            : strategy_support::opaque_ids(
                $correctids,
                $correctids,
                $context->visit,
                self::HANDLE_NAMESPACE
            );
        $positions = [];
        if ($context->includecorrect) {
            $positions = array_map(
                static fn(
                    string $publicid,
                    string $internalid,
                    int $position
                ): array => [
                    'itemId' => $publicid,
                    'position' => $position,
                    'correctPositionCount' => $positioncounts[$internalid],
                ],
                $publicids,
                $correctids,
                array_keys($correctids)
            );
        }
        return [
            'kind' => 'puzzle',
            'total' => $total,
            'correctCount' => $correct,
            // Position rows encode the exact solution order and are host-only.
            'positions' => $positions,
        ];
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $available = array_values(array_column(
            $question['options']['items'],
            'id'
        ));
        $map = strategy_support::opaque_id_map(
            $available,
            $context->visit,
            self::HANDLE_NAMESPACE
        );
        $items = [];
        foreach ($question['options']['items'] as $item) {
            $items[] = choice_support::project_choice(
                $item,
                $mediafiles,
                $map[(string)$item['id']],
                $context->visit,
                'item'
            );
        }
        if (!$context->includecorrect) {
            $items = strategy_support::shuffled(
                $items,
                $context->visit,
                'puzzle'
            );
        }
        $typedata = ['items' => $items];
        if ($context->includecorrect) {
            $typedata['correctOrderIds'] = strategy_support::opaque_ids(
                $available,
                $available,
                $context->visit,
                self::HANDLE_NAMESPACE
            );
        }
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'order',
            'typeData' => $typedata,
        ];
    }
}
