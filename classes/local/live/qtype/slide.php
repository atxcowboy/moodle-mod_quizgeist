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
 * Content slide with optional append-only live reactions.
 */
final class slide implements live_question_type {

    /** Stable identity keys; themes may render different glyphs. */
    public const REACTIONS = ['heart', 'clap', 'idea', 'laugh', 'wow'];

    public function type(): string {
        return 'slide';
    }

    public function policy(array $question): interaction_policy {
        $stages = [[
            'key' => 'react',
            'labelKey' => 'live:stage:react',
            'duration' => 'none',
            'submissions' => [
                'reaction' => [
                    'answerType' => 'reaction',
                    'role' => 'player',
                    'cardinality' => 'multiple',
                    'maxPerActor' => 20,
                ],
            ],
        ]];
        // F13 Buehnen-Check. `slide` ist ein BASIS-Fragetyp — genau deshalb
        // steht der Untermodus auch dort zur Verfuegung, wo das Addon `qtypes`
        // fehlt und `open` deshalb nicht anlegbar ist. Eine Folie traegt keine
        // Antwort, die Buehne verweist also auf nichts.
        if (strategy_support::stage_check($question)) {
            $stages[0]['advanceLabelKey'] = 'live:stage:stage';
            $stages[] = interaction_policy::stage_stage();
        }
        return new interaction_policy('reaction', $stages, true, ['react'], true, false);
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        $reaction = $rawanswer['reaction'] ?? null;
        if (empty($question['options']['reactions'])
                || !is_string($reaction)
                || !in_array($reaction, self::REACTIONS, true)) {
            throw new \invalid_parameter_exception('Slide reaction is invalid.');
        }
        return ['reaction' => $reaction];
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        return strategy_support::evaluated(
            $question,
            $this->validate_answer($question, $rawanswer, $context),
            null,
            $scoring,
            1.0,
            'reaction'
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $counts = array_fill_keys(self::REACTIONS, 0);
        $latest = [];
        foreach (strategy_support::rows($answers) as $row) {
            $reaction = $row['payload']['reaction'] ?? null;
            if ($row['answerType'] === 'reaction'
                    && is_string($reaction)
                    && isset($counts[$reaction])
                    && $row['playerId'] > 0) {
                $latest[$row['playerId']] = $reaction;
            }
        }
        foreach ($latest as $reaction) {
            $counts[$reaction]++;
        }
        return [
            'kind' => 'reactions',
            'counts' => array_map(
                static fn(string $reaction): array => [
                    'reaction' => $reaction,
                    'count' => $counts[$reaction],
                ],
                self::REACTIONS
            ),
            'total' => array_sum($counts),
        ];
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        $options = $question['options'];
        return [
            'multiple' => true,
            'choices' => [],
            'responseType' => 'reaction',
            'typeData' => [
                'layout' => (string)$options['layout'],
                'title' => (string)$options['title'],
                'body' => (string)$options['body'],
                'bullets' => array_values($options['bullets']),
                'quote' => (string)$options['quote'],
                'attribution' => (string)$options['attribution'],
                'reactions' => !empty($options['reactions']),
                'reactionKeys' => self::REACTIONS,
                'reactionOptions' => [
                    ['id' => 'heart', 'emoji' => '❤️'],
                    ['id' => 'clap', 'emoji' => '👏'],
                    ['id' => 'idea', 'emoji' => '💡'],
                    ['id' => 'laugh', 'emoji' => '😄'],
                    ['id' => 'wow', 'emoji' => '🤩'],
                ],
                'speakText' => trim(implode(' ', array_filter([
                    (string)$options['title'],
                    (string)$options['body'],
                    implode('. ', array_values($options['bullets'])),
                    (string)$options['quote'],
                    (string)$options['attribution'],
                ]))),
            ],
        ];
    }
}
