<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Pure mapping from supported Kahoot questions to Quizgeist schema input.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\kahoot;

defined('MOODLE_INTERNAL') || die();

/**
 * Maps supported Kahoot question and content types to native Quizgeist types.
 *
 * The mapper performs no file, database, network or Moodle-user operation.
 * Media remain exact source identifiers paired with semantic target slots.
 * The import service can therefore resolve them through source_bundle and
 * stage them through the authoritative Moodle media pipeline.
 */
final class question_mapper {

    /** Formatting was flattened to Quizgeist plain text. */
    public const REASON_HTML_FORMATTING_REMOVED = 'html_formatting_removed';

    /** Source text exceeded the Quizgeist field bound. */
    public const REASON_TEXT_TRUNCATED = 'text_truncated';

    /** A normal quiz contained more than one correct answer. */
    public const REASON_MULTIPLE_INFERRED = 'quiz_multiple_inferred';

    /** Survey/poll correctness flags have no meaning in Quizgeist. */
    public const REASON_POLL_CORRECTNESS_IGNORED = 'poll_correctness_ignored';

    /** Kahoot scale semantics were represented with explicit endpoint labels. */
    public const REASON_SCALE_LABELS_ADAPTED = 'scale_labels_adapted';

    /** A non-integral/out-of-range scale was fitted to Quizgeist bounds. */
    public const REASON_SCALE_RANGE_ADAPTED = 'scale_range_adapted';

    /** Kahoot drop-pin has no target and is imported as an ungraded heatmap. */
    public const REASON_DROP_PIN_UNGRADED = 'drop_pin_ungraded_heatmap';

    /** Source media attribution/crop/alt metadata has no canonical field. */
    public const REASON_MEDIA_METADATA_OMITTED = 'media_metadata_omitted';

    /** Choice language metadata has no canonical field. */
    public const REASON_CHOICE_METADATA_OMITTED = 'choice_metadata_omitted';

    /** Kahoot video references cannot be delivered from external hosts. */
    public const REASON_EXTERNAL_VIDEO_OMITTED = 'external_video_omitted';

    /** Only the first media object can be represented in one semantic slot. */
    public const REASON_ADDITIONAL_MEDIA_OMITTED = 'additional_media_omitted';

    /** More than six source choices were reduced to the schema maximum. */
    public const REASON_CHOICES_TRUNCATED = 'choices_truncated';

    /** A time value was rounded or clamped to Quizgeist bounds. */
    public const REASON_TIME_LIMIT_ADAPTED = 'time_limit_adapted';

    /** An unknown point multiplier was represented as standard points. */
    public const REASON_POINT_MODE_ADAPTED = 'point_mode_adapted';

    /** The source type has no supported Quizgeist mapping. */
    public const REASON_TYPE_UNSUPPORTED = 'source_type_unsupported';

    /** The source question or one of its required fields is invalid. */
    public const REASON_SOURCE_INVALID = 'source_question_invalid';

    /** A scored source question contains no reliable correct answer. */
    public const REASON_CORRECT_ANSWER_MISSING = 'correct_answer_missing';

    /** A source question has too few usable choices. */
    public const REASON_CHOICES_MISSING = 'choices_missing';

    /** A question type requiring an image has no media reference. */
    public const REASON_REQUIRED_MEDIA_MISSING = 'required_media_missing';

    /** A referenced local media object is absent from the supplied bundle. */
    public const REASON_MEDIA_MISSING = 'media_missing';

    /** TRUE/FALSE labels were not recognisable and source order was used. */
    public const REASON_TRUEFALSE_LABELS_ADAPTED = 'truefalse_labels_adapted';

    /** Kahoot's per-player idea limit has no configurable target field. */
    public const REASON_BRAINSTORM_ANSWER_LIMIT_ADAPTED =
        'brainstorm_answer_limit_adapted';

    /** Kahoot brainstorming points have no native target scoring semantics. */
    public const REASON_BRAINSTORM_POINTS_OMITTED =
        'brainstorm_points_omitted';

    /** Kahoot's brainstorming stages cannot be represented one-to-one. */
    public const REASON_BRAINSTORM_WORKFLOW_ADAPTED =
        'brainstorm_workflow_adapted';

    /** Kahoot's fractional slider margin became an absolute tolerance. */
    public const REASON_SLIDER_TOLERANCE_ADAPTED =
        'slider_tolerance_adapted';

    /** Kahoot's slider unit has no native target field. */
    public const REASON_SLIDER_UNIT_OMITTED = 'slider_unit_omitted';

    /** A Kahoot content layout was fitted to a native slide layout. */
    public const REASON_CONTENT_LAYOUT_ADAPTED = 'content_layout_adapted';

    /** Kahoot slide styling has no native target field. */
    public const REASON_CONTENT_STYLE_OMITTED = 'content_style_omitted';

    /** @var string[] Supported Kahoot source question types. */
    private const SOURCE_TYPES = [
        'quiz',
        'multiple_select_quiz',
        'survey',
        'multiple_select_poll',
        'scale',
        'jumble',
        'drop_pin',
        'open_ended',
        'word_cloud',
        'brainstorm',
        'brainstorming',
        'slider',
        'content',
    ];

    /** Domain separator for non-positional, canonical option identifiers. */
    private const OPTION_ID_KEY = 'mod_quizgeist/kahoot/opaque-option-id/v1';

    /**
     * Map one zero-based source question.
     *
     * Return shape:
     *
     * - question is schema input; status remains draft until media have passed
     *   Moodle validation and the complete question is normalised.
     * - media entries use target "question" or "answers/<opaque-id>".
     * - reasons are stable machine codes suitable for a persisted report.
     *
     * @param array $sourcequestion Decoded Kahoot question.
     * @param int $sourceindex Zero-based question index.
     * @return array{
     *     sourceIndex:int,
     *     sourceType:string,
     *     targetType:?string,
     *     outcome:string,
     *     question:?array,
     *     media:array,
     *     reasons:string[]
     * }
     */
    public static function map(array $sourcequestion, int $sourceindex = 0): array {
        if ($sourceindex < 0) {
            throw new \InvalidArgumentException('source_index_invalid');
        }
        $sourcetype = self::source_type($sourcequestion);
        if (!in_array($sourcetype, self::SOURCE_TYPES, true)) {
            return self::skipped(
                $sourceindex,
                $sourcetype,
                [self::REASON_TYPE_UNSUPPORTED]
            );
        }

        $reasons = [];
        $iscontent = $sourcetype === 'content';
        $textstate = self::plain_text(
            $iscontent ? '' : ($sourcequestion['question'] ?? ''),
            4000
        );
        if ($textstate['formatted']) {
            self::reason($reasons, self::REASON_HTML_FORMATTING_REMOVED);
        }
        if ($textstate['truncated']) {
            self::reason($reasons, self::REASON_TEXT_TRUNCATED);
        }
        if (!$iscontent && $textstate['text'] === '') {
            return self::skipped(
                $sourceindex,
                $sourcetype,
                [self::REASON_SOURCE_INVALID]
            );
        }

        $questionmedia = self::question_media($sourcequestion, $reasons);
        self::metadata_reasons($sourcequestion, $reasons);
        if (self::has_meaningful_video($sourcequestion['video'] ?? null)) {
            self::reason($reasons, self::REASON_EXTERNAL_VIDEO_OMITTED);
        }

        $usesstagedtime = in_array(
            $sourcetype,
            ['brainstorm', 'brainstorming'],
            true
        );
        $timelimit = ($iscontent || $usesstagedtime)
            ? 0
            : self::time_limit($sourcequestion['time'] ?? null, $reasons);
        $fingerprint = self::fingerprint($sourcequestion, $sourceindex);
        $mapping = match ($sourcetype) {
            'quiz' => self::map_quiz(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $fingerprint,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'multiple_select_quiz' => self::map_choice_question(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $fingerprint,
                $sourceindex,
                $questionmedia,
                true,
                true,
                'quiz',
                $reasons
            ),
            'survey' => self::map_choice_question(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $fingerprint,
                $sourceindex,
                $questionmedia,
                false,
                false,
                'poll',
                $reasons
            ),
            'multiple_select_poll' => self::map_choice_question(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $fingerprint,
                $sourceindex,
                $questionmedia,
                true,
                false,
                'poll',
                $reasons
            ),
            'scale' => self::map_scale(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'jumble' => self::map_puzzle(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $fingerprint,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'drop_pin' => self::map_drop_pin(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'open_ended' => self::map_open_ended(
                $textstate['text'],
                $timelimit,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'word_cloud' => self::map_word_cloud(
                $textstate['text'],
                $timelimit,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'brainstorm', 'brainstorming' => self::map_brainstorm(
                $sourcequestion,
                $textstate['text'],
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'slider' => self::map_slider(
                $sourcequestion,
                $textstate['text'],
                $timelimit,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
            'content' => self::map_content(
                $sourcequestion,
                $sourceindex,
                $questionmedia,
                $reasons
            ),
        };

        return $mapping;
    }

    /**
     * Map a complete decoded questions list.
     *
     * @param array $sourcequestions List of decoded questions.
     * @return array<int, array>
     */
    public static function map_all(array $sourcequestions): array {
        if (!array_is_list($sourcequestions)) {
            throw new \InvalidArgumentException('source_questions_invalid');
        }
        $mapped = [];
        foreach ($sourcequestions as $index => $sourcequestion) {
            if (!is_array($sourcequestion)) {
                $mapped[] = self::skipped(
                    $index,
                    '',
                    [self::REASON_SOURCE_INVALID]
                );
                continue;
            }
            $mapped[] = self::map($sourcequestion, $index);
        }
        return $mapped;
    }

    /**
     * Reconcile mapped media bindings with the files available in a bundle.
     *
     * A missing optional question image remains a documented adaptation. A
     * targetless pin or an image-only answer/item cannot remain schema-ready
     * without its image, so that one source question is reported as skipped
     * before any database write begins.
     *
     * @param array<int,array> $mapped Mapper rows.
     * @param array<string,mixed> $available Media map keyed by source URL.
     * @return array<int,array>
     */
    public static function reconcile_media(array $mapped, array $available): array {
        foreach ($mapped as &$row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException(
                    'mapped_question_invalid'
                );
            }
            if (!is_array($row['reasons'] ?? null)) {
                $row['reasons'] = [];
            }
            $requiredmissing = false;
            $anymissing = false;
            foreach ($row['media'] ?? [] as $binding) {
                if (!is_array($binding)
                        || isset($available[(string)($binding['sourceUrl'] ?? '')])) {
                    continue;
                }
                $anymissing = true;
                self::downgrade_missing_slide_media($row, $binding);
                if (self::binding_requires_media($row, $binding)) {
                    $requiredmissing = true;
                }
            }
            if (!$anymissing) {
                continue;
            }
            self::reason($row['reasons'], self::REASON_MEDIA_MISSING);
            if ($requiredmissing) {
                self::reason(
                    $row['reasons'],
                    self::REASON_REQUIRED_MEDIA_MISSING
                );
                $row['outcome'] = 'skipped';
                $row['targetType'] = null;
                $row['question'] = null;
                continue;
            }
            if (is_array($row['question'] ?? null)) {
                $row['outcome'] = 'adjusted';
            }
        }
        unset($row);
        return $mapped;
    }

    /**
     * Select true/false or classic quiz semantics.
     *
     * @param array $source Source question.
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param string $fingerprint Question fingerprint.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_quiz(
        array $source,
        string $questiontext,
        int $timelimit,
        string $fingerprint,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        $layout = is_string($source['layout'] ?? null)
            ? strtoupper(trim($source['layout']))
            : '';
        if ($layout !== 'TRUE_FALSE') {
            return self::map_choice_question(
                $source,
                $questiontext,
                $timelimit,
                $fingerprint,
                $sourceindex,
                $questionmedia,
                false,
                true,
                'quiz',
                $reasons
            );
        }

        $choices = self::choices($source, $reasons);
        if (count($choices) !== 2) {
            return self::skipped(
                $sourceindex,
                'quiz',
                [...$reasons, self::REASON_CHOICES_MISSING]
            );
        }
        $correctindices = [];
        foreach ($choices as $index => $choice) {
            if (self::boolean($choice['correct'] ?? false)) {
                $correctindices[] = $index;
            }
            if (self::choice_media($choice, $reasons) !== null) {
                self::reason($reasons, self::REASON_ADDITIONAL_MEDIA_OMITTED);
            }
            self::choice_metadata_reasons($choice, $reasons);
        }
        if (count($correctindices) !== 1) {
            return self::skipped(
                $sourceindex,
                'quiz',
                [...$reasons, self::REASON_CORRECT_ANSWER_MISSING]
            );
        }

        $trueindex = null;
        foreach ($choices as $index => $choice) {
            $label = self::comparison_text($choice['answer'] ?? '');
            if (in_array($label, ['true', 'wahr'], true)) {
                $trueindex = $index;
                break;
            }
        }
        if ($trueindex === null) {
            $trueindex = 0;
            self::reason($reasons, self::REASON_TRUEFALSE_LABELS_ADAPTED);
        }

        $question = self::base_question(
            'truefalse',
            $questiontext,
            $timelimit,
            self::point_mode($source, true, $reasons),
            [
                'media' => null,
                'correct' => $correctindices[0] === $trueindex,
            ]
        );
        return self::mapped(
            $sourceindex,
            'quiz',
            'truefalse',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Map quiz and poll choice families.
     *
     * @param array $source Source question.
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param string $fingerprint Question fingerprint.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param bool $multiple Declared multiple-choice behaviour.
     * @param bool $scored Whether correctness must be retained.
     * @param string $targettype quiz or poll.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_choice_question(
        array $source,
        string $questiontext,
        int $timelimit,
        string $fingerprint,
        int $sourceindex,
        ?string $questionmedia,
        bool $multiple,
        bool $scored,
        string $targettype,
        array $reasons
    ): array {
        $choices = self::choices($source, $reasons);
        if (count($choices) < 2) {
            return self::skipped(
                $sourceindex,
                self::source_type($source),
                [...$reasons, self::REASON_CHOICES_MISSING]
            );
        }

        $answers = [];
        $media = self::question_media_binding($questionmedia);
        $correctcount = 0;
        foreach ($choices as $choiceindex => $choice) {
            $textstate = self::plain_text($choice['answer'] ?? '', 1000);
            if ($textstate['formatted']) {
                self::reason($reasons, self::REASON_HTML_FORMATTING_REMOVED);
            }
            if ($textstate['truncated']) {
                self::reason($reasons, self::REASON_TEXT_TRUNCATED);
            }
            $choiceurl = self::choice_media($choice, $reasons);
            if ($textstate['text'] === '' && $choiceurl === null) {
                return self::skipped(
                    $sourceindex,
                    self::source_type($source),
                    [...$reasons, self::REASON_SOURCE_INVALID]
                );
            }
            self::choice_metadata_reasons($choice, $reasons);

            $id = self::opaque_option_id(
                $fingerprint,
                $choiceindex,
                $textstate['text']
            );
            $correct = self::boolean($choice['correct'] ?? false);
            if ($correct) {
                $correctcount++;
            }
            $answer = [
                'id' => $id,
                'text' => $textstate['text'],
                'media' => null,
            ];
            if ($scored) {
                $answer['correct'] = $correct;
            } else if ($correct) {
                self::reason($reasons, self::REASON_POLL_CORRECTNESS_IGNORED);
            }
            $answers[] = $answer;
            if ($choiceurl !== null) {
                $media[] = [
                    'sourceUrl' => $choiceurl,
                    'target' => 'answers/' . $id,
                    'optionId' => $id,
                ];
            }
        }

        if ($scored && $correctcount < 1) {
            return self::skipped(
                $sourceindex,
                self::source_type($source),
                [...$reasons, self::REASON_CORRECT_ANSWER_MISSING]
            );
        }
        if ($scored && !$multiple && $correctcount > 1) {
            $multiple = true;
            self::reason($reasons, self::REASON_MULTIPLE_INFERRED);
        }

        $question = self::base_question(
            $targettype,
            $questiontext,
            $timelimit,
            self::point_mode($source, $scored, $reasons),
            [
                'media' => null,
                'multiple' => $multiple,
                'answers' => $answers,
            ]
        );
        return self::mapped(
            $sourceindex,
            self::source_type($source),
            $targettype,
            $question,
            $media,
            $reasons
        );
    }

    /**
     * Map a Kahoot scale range to 3-10 Quizgeist steps.
     *
     * @param array $source Source question.
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_scale(
        array $source,
        string $questiontext,
        int $timelimit,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        $range = is_array($source['scaleRange'] ?? null)
            ? $source['scaleRange']
            : [];
        $start = self::finite_number($range['start'] ?? null);
        $end = self::finite_number($range['end'] ?? null);
        if ($start === null || $end === null || $end <= $start) {
            $start = 1.0;
            $end = 5.0;
            self::reason($reasons, self::REASON_SCALE_RANGE_ADAPTED);
        }
        $rawsteps = $end - $start + 1.0;
        $steps = (int)round($rawsteps);
        if (abs($rawsteps - $steps) > 0.000001 || $steps < 3 || $steps > 10) {
            $steps = max(3, min(10, $steps));
            self::reason($reasons, self::REASON_SCALE_RANGE_ADAPTED);
        }

        $labeltype = is_string($range['labelType'] ?? null)
            ? strtolower(trim($range['labelType']))
            : '';
        if ($labeltype === 'custom') {
            $minstate = self::plain_text($range['startLabelText'] ?? '', 120);
            $maxstate = self::plain_text($range['endLabelText'] ?? '', 120);
            if ($minstate['formatted'] || $maxstate['formatted']) {
                self::reason($reasons, self::REASON_HTML_FORMATTING_REMOVED);
            }
            if ($minstate['truncated'] || $maxstate['truncated']) {
                self::reason($reasons, self::REASON_TEXT_TRUNCATED);
            }
            $minlabel = $minstate['text'];
            $maxlabel = $maxstate['text'];
        } else if ($labeltype === 'agreement') {
            $minlabel = 'Stimme überhaupt nicht zu';
            $maxlabel = 'Stimme voll zu';
            self::reason($reasons, self::REASON_SCALE_LABELS_ADAPTED);
        } else {
            $minlabel = self::number_label($start);
            $maxlabel = self::number_label($end);
            self::reason($reasons, self::REASON_SCALE_LABELS_ADAPTED);
        }

        $question = self::base_question(
            'scale',
            $questiontext,
            $timelimit,
            'none',
            [
                'media' => null,
                'minLabel' => $minlabel,
                'maxLabel' => $maxlabel,
                'steps' => $steps,
            ]
        );
        return self::mapped(
            $sourceindex,
            'scale',
            'scale',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Map Kahoot open-ended input to native ungraded open text.
     *
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_open_ended(
        string $questiontext,
        int $timelimit,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        $question = self::base_question(
            'open',
            $questiontext,
            $timelimit,
            'none',
            [
                'media' => null,
                'sampleAnswer' => '',
            ]
        );
        return self::mapped(
            $sourceindex,
            'open_ended',
            'open',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Map a Kahoot word cloud to the native ungraded target.
     *
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_word_cloud(
        string $questiontext,
        int $timelimit,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        $question = self::base_question(
            'wordcloud',
            $questiontext,
            $timelimit,
            'none',
            [
                'media' => null,
                'maxChars' => 20,
                'moderation' => false,
            ]
        );
        return self::mapped(
            $sourceindex,
            'word_cloud',
            'wordcloud',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Map both observed Kahoot brainstorm type names to staged brainstorming.
     *
     * Current Kahoot payloads express brainstorming time in seconds, while
     * older exports may retain the millisecond convention used by quiz types.
     *
     * @param array $source Source question.
     * @param string $questiontext Plain text.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_brainstorm(
        array $source,
        string $questiontext,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        self::reason($reasons, self::REASON_BRAINSTORM_WORKFLOW_ADAPTED);
        $collectseconds = self::brainstorm_seconds(
            $source['time'] ?? null,
            $reasons
        );
        $timelimit = min(240, $collectseconds);
        if ($timelimit !== $collectseconds) {
            self::reason($reasons, self::REASON_TIME_LIMIT_ADAPTED);
        }

        if (array_key_exists('numberOfAnswersAllowed', $source)) {
            $answerlimit = self::finite_number(
                $source['numberOfAnswersAllowed']
            );
            if ($answerlimit === null
                    || abs($answerlimit - 5.0) > 0.000001) {
                self::reason(
                    $reasons,
                    self::REASON_BRAINSTORM_ANSWER_LIMIT_ADAPTED
                );
            }
        }
        if (array_key_exists('pointsMultiplier', $source)) {
            $points = self::finite_number($source['pointsMultiplier']);
            if ($points === null || abs($points) > 0.000001) {
                self::reason(
                    $reasons,
                    self::REASON_BRAINSTORM_POINTS_OMITTED
                );
            }
        }

        $question = self::base_question(
            'brainstorm',
            $questiontext,
            $timelimit,
            'none',
            [
                'media' => null,
                'collectSeconds' => $collectseconds,
                'grouping' => 'manual',
                'voteSeconds' => 45,
            ]
        );
        return self::mapped(
            $sourceindex,
            self::source_type($source),
            'brainstorm',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Read either seconds or legacy milliseconds for a brainstorm stage.
     *
     * @param mixed $rawtime Source duration.
     * @param string[] $reasons Mutable reasons.
     * @return int Duration in 10-600 seconds.
     */
    private static function brainstorm_seconds(
        $rawtime,
        array &$reasons
    ): int {
        $number = self::finite_number($rawtime);
        if ($number === null || $number <= 0) {
            self::reason($reasons, self::REASON_TIME_LIMIT_ADAPTED);
            return 60;
        }
        $seconds = $number <= 600.0 ? $number : $number / 1000.0;
        $rounded = (int)round($seconds);
        $bounded = max(10, min(600, $rounded));
        if (abs($seconds - $rounded) > 0.000001 || $bounded !== $rounded) {
            self::reason($reasons, self::REASON_TIME_LIMIT_ADAPTED);
        }
        return $bounded;
    }

    /**
     * Map a Kahoot numerical slider without inventing a target answer.
     *
     * Kahoot stores tolerance as a fraction of the complete range. Quizgeist
     * stores the same margin as an absolute distance from the target value.
     *
     * @param array $source Source question.
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_slider(
        array $source,
        string $questiontext,
        int $timelimit,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        $range = is_array($source['choiceRange'] ?? null)
            ? $source['choiceRange']
            : [];
        $min = self::finite_number($range['start'] ?? null);
        $max = self::finite_number($range['end'] ?? null);
        $step = self::finite_number($range['step'] ?? null);
        $target = self::finite_number($range['correct'] ?? null);
        $fraction = self::finite_number($range['tolerance'] ?? 0);
        if ($min === null
                || $max === null
                || $step === null
                || $target === null
                || $fraction === null
                || $min < -1000000
                || $max > 1000000
                || $max <= $min
                || $step < 0.000001
                || $step > ($max - $min)
                || $target < $min
                || $target > $max
                || $fraction < 0
                || $fraction > 1) {
            return self::skipped(
                $sourceindex,
                'slider',
                [...$reasons, self::REASON_SOURCE_INVALID]
            );
        }

        $tolerance = ($max - $min) * $fraction;
        $gridposition = ($target - $min) / $step;
        $nearesttarget = $min + round($gridposition) * $step;
        $targetdistance = abs($target - $nearesttarget);
        if ($targetdistance > $tolerance + 0.000000001) {
            return self::skipped(
                $sourceindex,
                'slider',
                [...$reasons, self::REASON_SOURCE_INVALID]
            );
        }
        if ($fraction > 0) {
            self::reason(
                $reasons,
                self::REASON_SLIDER_TOLERANCE_ADAPTED
            );
        }
        $unit = $range['unit'] ?? ($source['unit'] ?? null);
        if (self::has_meaningful_value($unit)) {
            self::reason($reasons, self::REASON_SLIDER_UNIT_OMITTED);
        }

        $question = self::base_question(
            'slider',
            $questiontext,
            $timelimit,
            self::point_mode($source, true, $reasons),
            [
                'media' => null,
                'min' => $min,
                'max' => $max,
                'step' => $step,
                'target' => $target,
                'tolerance' => $tolerance,
            ]
        );
        return self::mapped(
            $sourceindex,
            'slider',
            'slider',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Map a non-question Kahoot content item to a zero-time native slide.
     *
     * @param array $source Source content item.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_content(
        array $source,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        $titlestate = self::plain_text($source['title'] ?? '', 1000);
        $bodystate = self::plain_text($source['description'] ?? '', 8000);
        if ($titlestate['formatted'] || $bodystate['formatted']) {
            self::reason($reasons, self::REASON_HTML_FORMATTING_REMOVED);
        }
        if ($titlestate['truncated'] || $bodystate['truncated']) {
            self::reason($reasons, self::REASON_TEXT_TRUNCATED);
        }
        $title = $titlestate['text'];
        $body = $bodystate['text'];

        $sourcelayout = is_string($source['layout'] ?? null)
            ? trim($source['layout'])
            : '';
        $bullets = strtoupper($sourcelayout) === 'MEDIA_TITLE_BULLETS'
            ? self::content_bullets($body, $reasons)
            : [];
        $layout = self::content_layout(
            $sourcelayout,
            $questionmedia !== null,
            $title,
            $body,
            $bullets,
            $reasons
        );

        foreach (['backgroundColor', 'backgroundImage', 'theme'] as $key) {
            if (self::has_meaningful_value($source[$key] ?? null)) {
                self::reason($reasons, self::REASON_CONTENT_STYLE_OMITTED);
                break;
            }
        }
        if ($title === ''
                && $body === ''
                && !$bullets
                && $questionmedia === null) {
            return self::skipped(
                $sourceindex,
                'content',
                [...$reasons, self::REASON_SOURCE_INVALID]
            );
        }

        $question = self::base_question(
            'slide',
            '',
            0,
            'none',
            [
                'layout' => $layout,
                'title' => $title,
                'body' => $layout === 'bullets' ? '' : $body,
                'bullets' => $bullets,
                'quote' => '',
                'attribution' => '',
                'media' => null,
                'reactions' => array_key_exists('hasReactions', $source)
                    ? self::boolean($source['hasReactions'])
                    : true,
            ]
        );
        return self::mapped(
            $sourceindex,
            'content',
            'slide',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Convert one Kahoot content layout to the closest native slide layout.
     *
     * @param string $rawlayout Kahoot layout identifier.
     * @param bool $hasmedia Whether local source media is bound.
     * @param string $title Normalised title.
     * @param string $body Normalised body.
     * @param string[] $bullets Normalised bullets.
     * @param string[] $reasons Mutable reasons.
     * @return string Native slide layout.
     */
    private static function content_layout(
        string $rawlayout,
        bool $hasmedia,
        string $title,
        string $body,
        array $bullets,
        array &$reasons
    ): string {
        $lower = strtolower($rawlayout);
        $native = [
            'title',
            'text-image',
            'bullets',
            'fullscreen',
        ];
        if (in_array($lower, $native, true)) {
            if (in_array(
                $lower,
                ['text-image', 'fullscreen'],
                true
            )
                    && !$hasmedia) {
                self::reason($reasons, self::REASON_CONTENT_LAYOUT_ADAPTED);
                return 'title';
            }
            if ($lower === 'bullets' && !$bullets) {
                self::reason($reasons, self::REASON_CONTENT_LAYOUT_ADAPTED);
                return $hasmedia ? 'text-image' : 'title';
            }
            return $lower;
        }

        if ($rawlayout !== '') {
            self::reason($reasons, self::REASON_CONTENT_LAYOUT_ADAPTED);
        }
        return match (strtoupper($rawlayout)) {
            'MEDIA_TITLE_BULLETS' => $bullets
                ? 'bullets'
                : ($hasmedia ? 'text-image' : 'title'),
            'MEDIA_BIG', 'IMPORTED_SLIDE' => $hasmedia
                ? 'fullscreen'
                : 'title',
            'MEDIA_TITLE_TEXT', 'TOP_IMAGE' => $hasmedia
                ? 'text-image'
                : 'title',
            'TEXT_TITLE_BIG_TEXT' => 'title',
            default => $hasmedia && $title === '' && $body === ''
                ? 'fullscreen'
                : ($hasmedia ? 'text-image' : 'title'),
        };
    }

    /**
     * Split a content description into bounded native bullet rows.
     *
     * @param string $body Normalised source description.
     * @param string[] $reasons Mutable reasons.
     * @return string[]
     */
    private static function content_bullets(
        string $body,
        array &$reasons
    ): array {
        $lines = preg_split('/\n+/u', $body) ?: [];
        if (count($lines) > 20) {
            $lines = array_slice($lines, 0, 20);
            self::reason($reasons, self::REASON_TEXT_TRUNCATED);
        }
        $bullets = [];
        foreach ($lines as $line) {
            $line = preg_replace(
                '/^\s*(?:(?:[-*•‣▪]+)|(?:\d+[.)]))\s*/u',
                '',
                trim($line)
            ) ?? trim($line);
            if ($line === '') {
                continue;
            }
            if (self::text_length($line) > 1000) {
                $line = self::text_substring($line, 1000);
                self::reason($reasons, self::REASON_TEXT_TRUNCATED);
            }
            $bullets[] = $line;
        }
        return $bullets;
    }

    /**
     * Preserve the jumble source order as the server-side puzzle solution.
     *
     * IDs are opaque digests. They do not contain a/b/c or a numeric position,
     * and the live puzzle projector replaces them with visit-bound HMAC handles
     * before shuffling choices for clients.
     *
     * @param array $source Source question.
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param string $fingerprint Question fingerprint.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_puzzle(
        array $source,
        string $questiontext,
        int $timelimit,
        string $fingerprint,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        $choices = self::choices($source, $reasons);
        if (count($choices) < 2) {
            return self::skipped(
                $sourceindex,
                'jumble',
                [...$reasons, self::REASON_CHOICES_MISSING]
            );
        }
        $items = [];
        $media = self::question_media_binding($questionmedia);
        foreach ($choices as $choiceindex => $choice) {
            $textstate = self::plain_text($choice['answer'] ?? '', 1000);
            if ($textstate['formatted']) {
                self::reason($reasons, self::REASON_HTML_FORMATTING_REMOVED);
            }
            if ($textstate['truncated']) {
                self::reason($reasons, self::REASON_TEXT_TRUNCATED);
            }
            $choiceurl = self::choice_media($choice, $reasons);
            if ($textstate['text'] === '' && $choiceurl === null) {
                return self::skipped(
                    $sourceindex,
                    'jumble',
                    [...$reasons, self::REASON_SOURCE_INVALID]
                );
            }
            self::choice_metadata_reasons($choice, $reasons);
            $id = self::opaque_option_id(
                $fingerprint,
                $choiceindex,
                $textstate['text']
            );
            $items[] = [
                'id' => $id,
                'text' => $textstate['text'],
                'media' => null,
            ];
            if ($choiceurl !== null) {
                $media[] = [
                    'sourceUrl' => $choiceurl,
                    'target' => 'answers/' . $id,
                    'optionId' => $id,
                ];
            }
        }
        $question = self::base_question(
            'puzzle',
            $questiontext,
            $timelimit,
            self::point_mode($source, true, $reasons),
            [
                'media' => null,
                'items' => $items,
            ]
        );
        return self::mapped(
            $sourceindex,
            'jumble',
            'puzzle',
            $question,
            $media,
            $reasons
        );
    }

    /**
     * Map targetless Kahoot drop-pin to an ungraded Quizgeist heatmap.
     *
     * A neutral target/radius remains in the schema input for compatibility
     * with older normalisers, while hasTarget=false is the authoritative flag.
     * Runtime projection must not reveal or score the neutral placeholder.
     *
     * @param array $source Source question.
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param int $sourceindex Source index.
     * @param string|null $questionmedia Question media URL.
     * @param string[] $reasons Mutable reasons.
     * @return array Mapping result.
     */
    private static function map_drop_pin(
        array $source,
        string $questiontext,
        int $timelimit,
        int $sourceindex,
        ?string $questionmedia,
        array $reasons
    ): array {
        self::reason($reasons, self::REASON_DROP_PIN_UNGRADED);
        if ($questionmedia === null) {
            return self::skipped(
                $sourceindex,
                'drop_pin',
                [...$reasons, self::REASON_REQUIRED_MEDIA_MISSING]
            );
        }
        $question = self::base_question(
            'pin',
            $questiontext,
            $timelimit,
            'none',
            [
                'media' => null,
                'hasTarget' => false,
                'target' => ['x' => 50.0, 'y' => 50.0],
                'radius' => 10.0,
            ]
        );
        return self::mapped(
            $sourceindex,
            'drop_pin',
            'pin',
            $question,
            self::question_media_binding($questionmedia),
            $reasons
        );
    }

    /**
     * Return at most six list-shaped choices.
     *
     * @param array $source Source question.
     * @param string[] $reasons Mutable reasons.
     * @return array<int, array>
     */
    private static function choices(array $source, array &$reasons): array {
        $raw = $source['choices'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        if (count($raw) > 6) {
            self::reason($reasons, self::REASON_CHOICES_TRUNCATED);
            $raw = array_slice($raw, 0, 6);
        }
        $choices = [];
        foreach ($raw as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $choices[] = $choice;
        }
        return $choices;
    }

    /**
     * Get the one representable question-level media URL.
     *
     * @param array $source Source question.
     * @param string[] $reasons Mutable reasons.
     * @return string|null
     */
    private static function question_media(array $source, array &$reasons): ?string {
        $urls = [];
        self::collect_media_value($source['image'] ?? null, $urls);
        self::collect_media_value($source['media'] ?? null, $urls);
        $urls = array_values(array_unique($urls));
        if (count($urls) > 1) {
            self::reason($reasons, self::REASON_ADDITIONAL_MEDIA_OMITTED);
        }
        return $urls[0] ?? null;
    }

    /**
     * Get the one representable choice-level media URL.
     *
     * @param array $choice Source choice.
     * @param string[] $reasons Mutable reasons.
     * @return string|null
     */
    private static function choice_media(array $choice, array &$reasons): ?string {
        $urls = [];
        foreach (['image', 'imageUrl', 'media'] as $key) {
            self::collect_media_value($choice[$key] ?? null, $urls);
        }
        $urls = array_values(array_unique($urls));
        if (count($urls) > 1) {
            self::reason($reasons, self::REASON_ADDITIONAL_MEDIA_OMITTED);
        }
        return $urls[0] ?? null;
    }

    /**
     * Collect strings and common {url:...} media objects recursively one level.
     *
     * @param mixed $value Source value.
     * @param string[] $urls Mutable URL list.
     * @return void
     */
    private static function collect_media_value($value, array &$urls): void {
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '' && strlen($value) <= 8192) {
                $urls[] = $value;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (isset($value['url']) && is_string($value['url'])) {
            self::collect_media_value($value['url'], $urls);
            return;
        }
        if (!array_is_list($value)) {
            return;
        }
        foreach ($value as $item) {
            if (is_string($item)
                    || (is_array($item) && isset($item['url']))) {
                self::collect_media_value($item, $urls);
            }
        }
    }

    /**
     * Add a question media binding when present.
     *
     * @param string|null $sourceurl Source URL.
     * @return array<int, array{sourceUrl:string,target:string,optionId:null}>
     */
    private static function question_media_binding(?string $sourceurl): array {
        if ($sourceurl === null) {
            return [];
        }
        return [[
            'sourceUrl' => $sourceurl,
            'target' => 'question',
            'optionId' => null,
        ]];
    }

    /**
     * Keep textual content slides when their optional image is unavailable.
     *
     * @param array $row Mutable mapper row.
     * @param array $binding Missing media binding.
     * @return void
     */
    private static function downgrade_missing_slide_media(
        array &$row,
        array $binding
    ): void {
        if ((string)($binding['target'] ?? '') !== 'question'
                || !is_array($row['question'] ?? null)
                || (string)($row['question']['qtype'] ?? '') !== 'slide') {
            return;
        }
        $options = &$row['question']['options'];
        if (!is_array($options)) {
            return;
        }
        $layout = (string)($options['layout'] ?? '');
        if (!in_array($layout, ['text-image', 'fullscreen', 'video'], true)) {
            return;
        }
        $bullets = array_values(array_filter(
            $options['bullets'] ?? [],
            static fn($item): bool =>
                is_string($item) && trim($item) !== ''
        ));
        $hastext = trim((string)($options['title'] ?? '')) !== ''
            || trim((string)($options['body'] ?? '')) !== ''
            || trim((string)($options['quote'] ?? '')) !== ''
            || (bool)$bullets;
        if (!$hastext) {
            return;
        }
        $options['layout'] = $bullets ? 'bullets' : 'title';
        self::reason(
            $row['reasons'],
            self::REASON_CONTENT_LAYOUT_ADAPTED
        );
    }

    /**
     * Whether one mapped semantic slot becomes invalid without its file.
     */
    private static function binding_requires_media(
        array $row,
        array $binding
    ): bool {
        $question = $row['question'] ?? null;
        if (!is_array($question)) {
            return false;
        }
        $target = (string)($binding['target'] ?? '');
        if ($target === 'question') {
            if (($question['qtype'] ?? '') === 'pin') {
                return true;
            }
            if (($question['qtype'] ?? '') === 'slide') {
                $layout = (string)($question['options']['layout'] ?? '');
                return in_array(
                    $layout,
                    ['text-image', 'fullscreen', 'video'],
                    true
                );
            }
            return false;
        }
        if (!str_starts_with($target, 'answers/')) {
            return false;
        }
        $optionid = (string)($binding['optionId']
            ?? substr($target, strlen('answers/')));
        $options = is_array($question['options'] ?? null)
            ? $question['options']
            : [];
        foreach (['answers', 'items'] as $collection) {
            foreach ($options[$collection] ?? [] as $option) {
                if (is_array($option)
                        && (string)($option['id'] ?? '') === $optionid) {
                    return trim((string)($option['text'] ?? '')) === '';
                }
            }
        }
        // A binding to an unknown semantic option is never safe to discard.
        return true;
    }

    /**
     * Record omitted question-level image metadata.
     *
     * @param array $source Source question.
     * @param string[] $reasons Mutable reasons.
     * @return void
     */
    private static function metadata_reasons(array $source, array &$reasons): void {
        foreach (['imageMetadata', 'resources'] as $key) {
            $value = $source[$key] ?? null;
            if ((is_array($value) && $value)
                    || (is_string($value) && trim($value) !== '')) {
                self::reason($reasons, self::REASON_MEDIA_METADATA_OMITTED);
                return;
            }
        }
    }

    /**
     * Record omitted choice metadata.
     *
     * @param array $choice Source choice.
     * @param string[] $reasons Mutable reasons.
     * @return void
     */
    private static function choice_metadata_reasons(array $choice, array &$reasons): void {
        foreach (['languageInfo', 'imageMetadata', 'resources'] as $key) {
            $value = $choice[$key] ?? null;
            if ((is_array($value) && $value)
                    || (is_string($value) && trim($value) !== '')) {
                self::reason($reasons, self::REASON_CHOICE_METADATA_OMITTED);
                return;
            }
        }
    }

    /**
     * Ignore empty Kahoot YouTube placeholders, but flag real remote videos.
     *
     * @param mixed $video Source video.
     * @return bool
     */
    private static function has_meaningful_video($video): bool {
        if (is_string($video)) {
            return trim($video) !== '';
        }
        if (!is_array($video)) {
            return false;
        }
        foreach (['id', 'videoId', 'url', 'fullUrl'] as $key) {
            if (isset($video[$key])
                    && is_scalar($video[$key])
                    && trim((string)$video[$key]) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether an omitted scalar or structured source field carries information.
     *
     * @param mixed $value Source value.
     * @return bool
     */
    private static function has_meaningful_value($value): bool {
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_scalar($value)) {
            return trim((string)$value) !== '';
        }
        return false;
    }

    /**
     * Convert milliseconds to bounded whole seconds.
     *
     * @param mixed $rawtime Source milliseconds.
     * @param string[] $reasons Mutable reasons.
     * @return int
     */
    private static function time_limit($rawtime, array &$reasons): int {
        if (!is_int($rawtime) && !is_float($rawtime) && !is_string($rawtime)) {
            self::reason($reasons, self::REASON_TIME_LIMIT_ADAPTED);
            return 20;
        }
        $milliseconds = filter_var($rawtime, FILTER_VALIDATE_FLOAT);
        if ($milliseconds === false || !is_finite((float)$milliseconds)) {
            self::reason($reasons, self::REASON_TIME_LIMIT_ADAPTED);
            return 20;
        }
        $seconds = (int)round((float)$milliseconds / 1000);
        $bounded = max(5, min(240, $seconds));
        if (abs((float)$milliseconds - ($bounded * 1000)) > 0.000001
                || $seconds !== $bounded) {
            self::reason($reasons, self::REASON_TIME_LIMIT_ADAPTED);
        }
        return $bounded;
    }

    /**
     * Translate Kahoot's pointsMultiplier.
     *
     * @param array $source Source question.
     * @param bool $scored Whether the target can be scored.
     * @param string[] $reasons Mutable reasons.
     * @return string
     */
    private static function point_mode(
        array $source,
        bool $scored,
        array &$reasons
    ): string {
        if (!$scored) {
            return 'none';
        }
        if (!array_key_exists('pointsMultiplier', $source)) {
            return 'standard';
        }
        $raw = $source['pointsMultiplier'];
        if ($raw === 1 || $raw === 1.0 || $raw === '1') {
            return 'standard';
        }
        if ($raw === 2 || $raw === 2.0 || $raw === '2') {
            return 'double';
        }
        if ($raw === 0 || $raw === 0.0 || $raw === '0') {
            return 'none';
        }
        self::reason($reasons, self::REASON_POINT_MODE_ADAPTED);
        return 'standard';
    }

    /**
     * Build source-independent canonical schema input.
     *
     * @param string $qtype Target question type.
     * @param string $questiontext Plain text.
     * @param int $timelimit Seconds.
     * @param string $pointmode Target point mode.
     * @param array $options Type-specific options.
     * @return array
     */
    private static function base_question(
        string $qtype,
        string $questiontext,
        int $timelimit,
        string $pointmode,
        array $options
    ): array {
        return [
            'qtype' => $qtype,
            'questiontext' => $questiontext,
            'questionformat' => defined('FORMAT_PLAIN') ? FORMAT_PLAIN : 2,
            'options' => $options,
            'timelimit' => $timelimit,
            'pointmode' => $pointmode,
            'explanation' => '',
            'status' => 'draft',
        ];
    }

    /**
     * Build a successful mapping result.
     *
     * @param int $sourceindex Source index.
     * @param string $sourcetype Source type.
     * @param string $targettype Target type.
     * @param array $question Canonical schema input.
     * @param array $media Media bindings.
     * @param string[] $reasons Stable reasons.
     * @return array
     */
    private static function mapped(
        int $sourceindex,
        string $sourcetype,
        string $targettype,
        array $question,
        array $media,
        array $reasons
    ): array {
        $reasons = array_values(array_unique($reasons));
        return [
            'sourceIndex' => $sourceindex,
            'sourceType' => $sourcetype,
            'targetType' => $targettype,
            'outcome' => $reasons ? 'adjusted' : 'imported',
            'question' => $question,
            'media' => $media,
            'reasons' => $reasons,
        ];
    }

    /**
     * Build an omitted mapping result.
     *
     * @param int $sourceindex Source index.
     * @param string $sourcetype Source type.
     * @param string[] $reasons Stable reasons.
     * @return array
     */
    private static function skipped(
        int $sourceindex,
        string $sourcetype,
        array $reasons
    ): array {
        return [
            'sourceIndex' => $sourceindex,
            'sourceType' => $sourcetype,
            'targetType' => null,
            'outcome' => 'skipped',
            'question' => null,
            'media' => [],
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * Return a supported source type or a bounded diagnostic string.
     *
     * @param array $source Source question.
     * @return string
     */
    private static function source_type(array $source): string {
        if (!is_string($source['type'] ?? null)) {
            return '';
        }
        return substr(strtolower(trim($source['type'])), 0, 80);
    }

    /**
     * Create an opaque, deterministic and schema-safe option identifier.
     *
     * The position is an HMAC input, never an encoded/plain output. This avoids
     * IDs such as a/b/c or item-1/item-2 that reveal the canonical puzzle order.
     *
     * @param string $fingerprint Question fingerprint.
     * @param int $choiceindex Choice index.
     * @param string $text Plain choice text.
     * @return string
     */
    private static function opaque_option_id(
        string $fingerprint,
        int $choiceindex,
        string $text
    ): string {
        $message = $fingerprint . "\0" . $choiceindex . "\0" . $text;
        return 'k' . substr(
            hash_hmac('sha256', $message, self::OPTION_ID_KEY),
            0,
            23
        );
    }

    /**
     * Fingerprint a source question for domain-separated option IDs.
     *
     * @param array $source Source question.
     * @param int $sourceindex Source index.
     * @return string
     */
    private static function fingerprint(array $source, int $sourceindex): string {
        try {
            $json = json_encode(
                $source,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            $json = serialize($source);
        }
        return hash('sha256', $sourceindex . "\0" . $json);
    }

    /**
     * Flatten HTML and entities while preserving explicit line breaks.
     *
     * @param mixed $raw Raw source text.
     * @param int $maxlength Maximum Unicode characters.
     * @return array{text:string,formatted:bool,truncated:bool}
     */
    private static function plain_text($raw, int $maxlength): array {
        if (!is_scalar($raw) && $raw !== null) {
            return ['text' => '', 'formatted' => false, 'truncated' => false];
        }
        $value = (string)($raw ?? '');
        $formatted = preg_match('/<[^>]+>/u', $value) === 1
            || preg_match('/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]+);/iu', $value) === 1;
        $value = preg_replace('/<br\s*\/?>/iu', "\n", $value) ?? $value;
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        $value = preg_replace('/[ \t]+\n/u', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        $value = trim($value);

        $truncated = self::text_length($value) > $maxlength;
        if ($truncated) {
            $value = self::text_substring($value, $maxlength);
        }
        return [
            'text' => $value,
            'formatted' => $formatted,
            'truncated' => $truncated,
        ];
    }

    /**
     * Produce a comparison-only lowercase plain label.
     *
     * @param mixed $raw Source label.
     * @return string
     */
    private static function comparison_text($raw): string {
        $plain = self::plain_text($raw, 100)['text'];
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($plain, 'UTF-8');
        }
        return strtolower($plain);
    }

    /**
     * Unicode-aware string length with a safe fallback.
     *
     * @param string $value Text.
     * @return int
     */
    private static function text_length(string $value): int {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        return strlen($value);
    }

    /**
     * Unicode-aware prefix with a safe fallback.
     *
     * @param string $value Text.
     * @param int $length Maximum characters.
     * @return string
     */
    private static function text_substring(string $value, int $length): string {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }

    /**
     * Normalise JSON boolean-like values.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function boolean($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, [1, 1.0, '1', 'true', 'on'], true);
    }

    /**
     * Convert one finite numeric scalar.
     *
     * @param mixed $value Value.
     * @return float|null
     */
    private static function finite_number($value): ?float {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || !is_finite((float)$number)) {
            return null;
        }
        return (float)$number;
    }

    /**
     * Format integral scale endpoints without a decimal suffix.
     *
     * @param float $number Number.
     * @return string
     */
    private static function number_label(float $number): string {
        if (abs($number - round($number)) < 0.000001) {
            return (string)(int)round($number);
        }
        return rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
    }

    /**
     * Append a reason only once while retaining discovery order.
     *
     * @param string[] $reasons Mutable reasons.
     * @param string $reason Stable code.
     * @return void
     */
    private static function reason(array &$reasons, string $reason): void {
        if (!in_array($reason, $reasons, true)) {
            $reasons[] = $reason;
        }
    }
}
