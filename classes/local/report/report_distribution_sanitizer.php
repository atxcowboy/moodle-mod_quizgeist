<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Role- and boundary-aware sanitising for report distributions.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\report;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps report UI projections private and makes exports solution-free.
 */
final class report_distribution_sanitizer {

    /** Stable, non-secret fields shared by every current question DTO. */
    private const QUESTION_FIELDS = [
        'id' => 'int',
        'rootId' => 'int',
        'version' => 'int',
        'index' => 'int',
        'total' => 'int',
        'qtype' => 'string',
        'questionText' => 'string',
        'timeLimit' => 'int',
        'pointMode' => 'string',
        'multiple' => 'bool',
        'responseType' => 'string',
        'interactionStage' => 'string',
        'allowsMultipleSubmissions' => 'bool',
        'policyDescriptor' => ['object' => [
            'stages' => ['list' => ['object' => [
                'key' => 'string',
                'labelKey' => 'string',
            ]]],
            'currentStage' => 'string',
            'allowsMultipleSubmissions' => 'bool',
            'canAdvance' => 'bool',
            'nextStageKey' => '?string',
            'nextStageLabelKey' => '?string',
            'showsCorrectness' => 'bool',
        ]],
        'mediaMimeType' => 'string',
    ];

    /** Question-type-specific, solution-free typeData fields. */
    private const TYPE_DATA_FIELDS = [
        'quiz' => [],
        'truefalse' => [],
        'shortanswer' => [
            'maxChars' => 'int',
            'typoTolerance' => 'bool',
        ],
        'poll' => [],
        'wordcloud' => [
            'maxChars' => 'int',
            'moderation' => 'bool',
        ],
        'slide' => [
            'layout' => 'string',
            'title' => 'string',
            'body' => 'string',
            'bullets' => ['list' => 'string'],
            'quote' => 'string',
            'attribution' => 'string',
            'reactions' => 'bool',
            'reactionKeys' => ['list' => 'string'],
            'reactionOptions' => ['list' => ['object' => [
                'id' => 'string',
                'emoji' => 'string',
            ]]],
            'speakText' => 'string',
        ],
        'puzzle' => [
            'items' => ['list' => ['object' => [
                'id' => 'string',
                'text' => 'string',
                'mediaMimeType' => 'string',
            ]]],
        ],
        'scale' => [
            'steps' => 'int',
            'minLabel' => 'string',
            'maxLabel' => 'string',
        ],
        'slider' => [
            'min' => 'number',
            'max' => 'number',
            'step' => 'number',
        ],
        'pin' => [],
        'reveal' => [
            'grid' => 'int',
            'revealSeconds' => 'int',
            'tileOrder' => ['list' => 'int'],
            'maxChars' => 'int',
        ],
        'brainstorm' => [
            'stage' => 'string',
            'maxIdeaChars' => 'int',
            'collectSeconds' => 'int',
            'voteSeconds' => 'int',
            'grouping' => 'string',
            'moderation' => 'bool',
        ],
        'open' => [
            'maxChars' => 'int',
        ],
    ];

    /** Positive schemas for every current named aggregate kind. */
    private const AGGREGATE_FIELDS = [
        'shortanswer' => [
            'kind' => 'string',
            'total' => 'int',
            'correctCount' => 'int',
            'responses' => ['list' => ['object' => [
                'text' => 'string',
                'count' => 'int',
            ]]],
        ],
        'wordcloud' => [
            'kind' => 'string',
            'total' => 'int',
            'responseCount' => 'int',
            'publishedCount' => 'int',
            'words' => ['list' => ['object' => [
                'key' => 'string',
                'text' => 'string',
                'count' => 'int',
                'percent' => 'number',
            ]]],
            'moderation' => ['object' => [
                'pendingCount' => 'int',
                'approvedCount' => 'int',
                'rejectedCount' => 'int',
                'items' => ['list' => ['object' => [
                    'key' => 'string',
                    'text' => 'string',
                    'count' => 'int',
                    'status' => 'string',
                ]]],
            ]],
        ],
        'reactions' => [
            'kind' => 'string',
            'counts' => ['list' => ['object' => [
                'reaction' => 'string',
                'count' => 'int',
            ]]],
            'total' => 'int',
        ],
        'puzzle' => [
            'kind' => 'string',
            'total' => 'int',
            'correctCount' => 'int',
        ],
        'scale' => [
            'kind' => 'string',
            'total' => 'int',
            'mean' => '?number',
            'median' => '?number',
            'histogram' => ['list' => ['object' => [
                'value' => 'int',
                'count' => 'int',
                'percent' => 'number',
            ]]],
        ],
        'slider' => [
            'kind' => 'string',
            'total' => 'int',
            'correctCount' => 'int',
            'mean' => '?number',
            'median' => '?number',
            'values' => ['list' => 'number'],
        ],
        'pin' => [
            'kind' => 'string',
            'total' => 'int',
            'correctCount' => 'int',
            'points' => ['list' => ['object' => [
                'x' => 'number',
                'y' => 'number',
            ]]],
        ],
        'reveal' => [
            'kind' => 'string',
            'total' => 'int',
            'correctCount' => 'int',
        ],
        'brainstorm' => [
            'kind' => 'string',
            'stage' => 'string',
            'groups' => ['list' => ['object' => [
                'key' => 'string',
                'label' => 'string',
                'ideas' => ['list' => ['object' => [
                    'id' => 'int',
                    'text' => 'string',
                ]]],
                'votes' => '?int',
            ]]],
            'groupingCurrent' => 'bool',
            'responseCount' => 'int',
            'totalIdeas' => 'int',
            'totalVotes' => '?int',
            'moderation' => ['object' => [
                'items' => ['list' => ['object' => [
                    'id' => 'int',
                    'text' => 'string',
                    'status' => 'string',
                ]]],
            ]],
        ],
        'open' => [
            'kind' => 'string',
            'total' => 'int',
            'responses' => ['list' => ['object' => [
                'id' => 'int',
                'text' => 'string',
            ]]],
        ],
    ];

    /**
     * Prepare one protected report UI projection for its viewer role.
     *
     * Teachers retain only boolean `correct` flags for choice markers.
     * Learners retain the previous fully scrubbed projection.
     */
    public static function for_report(array $value, bool $teacher): array {
        return self::project($value, $teacher);
    }

    /**
     * Prepare one solution-free export projection.
     */
    public static function for_export(array $value): array {
        return self::project($value, false);
    }

    /**
     * Project a known DTO shape, dropping everything not explicitly declared.
     */
    private static function project(
        array $value,
        bool $allowcorrectflags
    ): array {
        if (array_key_exists('question', $value)
                || array_key_exists('aggregate', $value)) {
            return self::project_export_pair($value);
        }
        if (array_is_list($value)) {
            return self::project_choice_aggregate(
                $value,
                $allowcorrectflags
            );
        }
        if (isset($value['qtype']) && is_string($value['qtype'])) {
            return self::project_question($value, $allowcorrectflags);
        }
        if (isset($value['kind']) && is_string($value['kind'])) {
            return self::project_named_aggregate($value);
        }
        return [];
    }

    /**
     * Project the exact question/aggregate wrapper used by exports.
     */
    private static function project_export_pair(array $value): array {
        $result = [];
        if (is_array($value['question'] ?? null)) {
            $result['question'] = self::project_question(
                $value['question'],
                false
            );
        }
        if (is_array($value['aggregate'] ?? null)) {
            $aggregate = $value['aggregate'];
            $result['aggregate'] = array_is_list($aggregate)
                ? self::project_choice_aggregate($aggregate, false)
                : self::project_named_aggregate($aggregate);
        }
        return $result;
    }

    /**
     * Project one question for a currently supported type.
     */
    private static function project_question(
        array $value,
        bool $allowcorrectflags
    ): array {
        $qtype = $value['qtype'] ?? null;
        if (!is_string($qtype)
                || !array_key_exists($qtype, self::TYPE_DATA_FIELDS)) {
            return [];
        }

        $result = self::project_object($value, self::QUESTION_FIELDS);
        $choiceqtypes = ['quiz', 'truefalse', 'poll'];
        $result['choices'] = in_array($qtype, $choiceqtypes, true)
            ? self::project_choices(
                $value['choices'] ?? null,
                $allowcorrectflags
            )
            : [];
        $result['typeData'] = is_array($value['typeData'] ?? null)
            ? self::project_object(
                $value['typeData'],
                self::TYPE_DATA_FIELDS[$qtype]
            )
            : [];

        if ($qtype === 'puzzle'
                && is_array($result['typeData']['items'] ?? null)) {
            usort(
                $result['typeData']['items'],
                static fn(array $left, array $right): int =>
                    strcmp(
                        (string)($left['id'] ?? ''),
                        (string)($right['id'] ?? '')
                    )
            );
        }
        return $result;
    }

    /**
     * Project choice labels while excluding tokenised media URLs.
     */
    private static function project_choices(
        mixed $value,
        bool $allowcorrectflags
    ): array {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $schema = [
            'id' => 'string',
            'text' => 'string',
            'mediaMimeType' => 'string',
        ];
        if ($allowcorrectflags) {
            $schema['correct'] = 'bool';
        }
        return self::project_list($value, ['object' => $schema]);
    }

    /**
     * Project quiz, true/false and poll aggregates.
     */
    private static function project_choice_aggregate(
        array $value,
        bool $allowcorrectflags
    ): array {
        $schema = [
            'choiceId' => 'string',
            'count' => 'int',
            'percent' => 'number',
        ];
        if ($allowcorrectflags) {
            $schema['correct'] = 'bool';
        }
        return self::project_list($value, ['object' => $schema]);
    }

    /**
     * Project one non-choice aggregate selected by its exact kind.
     */
    private static function project_named_aggregate(array $value): array {
        $kind = $value['kind'] ?? null;
        if (!is_string($kind)
                || !isset(self::AGGREGATE_FIELDS[$kind])) {
            return [];
        }
        return self::project_object(
            $value,
            self::AGGREGATE_FIELDS[$kind]
        );
    }

    /**
     * Apply one exact object schema.
     *
     * @param array<string,mixed> $value
     * @param array<string,mixed> $schema
     */
    private static function project_object(
        array $value,
        array $schema
    ): array {
        if ($value !== [] && array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach ($schema as $key => $descriptor) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            [$valid, $projected] = self::project_field(
                $value[$key],
                $descriptor
            );
            if ($valid) {
                $result[$key] = $projected;
            }
        }
        return $result;
    }

    /**
     * Project one list, silently dropping invalid or object-valued entries.
     */
    private static function project_list(
        array $value,
        mixed $descriptor
    ): array {
        if (!array_is_list($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $entry) {
            [$valid, $projected] = self::project_field(
                $entry,
                $descriptor
            );
            if ($valid) {
                $result[] = $projected;
            }
        }
        return $result;
    }

    /**
     * Validate and project one declared field.
     *
     * @return array{0:bool,1:mixed}
     */
    private static function project_field(
        mixed $value,
        mixed $descriptor
    ): array {
        if (is_string($descriptor)) {
            $nullable = str_starts_with($descriptor, '?');
            $type = $nullable ? substr($descriptor, 1) : $descriptor;
            if ($nullable && $value === null) {
                return [true, null];
            }
            $valid = match ($type) {
                'string' => is_string($value),
                'int' => is_int($value),
                'number' => (is_int($value) || is_float($value))
                    && is_finite((float)$value),
                'bool' => is_bool($value),
                default => false,
            };
            return [$valid, $value];
        }
        if (!is_array($descriptor) || !is_array($value)) {
            return [false, null];
        }
        if (array_key_exists('object', $descriptor)
                && is_array($descriptor['object'])
                && ($value === [] || !array_is_list($value))) {
            return [
                true,
                self::project_object($value, $descriptor['object']),
            ];
        }
        if (array_key_exists('list', $descriptor)
                && array_is_list($value)) {
            return [
                true,
                self::project_list($value, $descriptor['list']),
            ];
        }
        return [false, null];
    }
}
