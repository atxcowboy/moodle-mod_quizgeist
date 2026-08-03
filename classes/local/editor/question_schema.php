<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Canonical P2 question schemas and validation.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\editor;

defined('MOODLE_INTERNAL') || die();

/**
 * Normalises all editor input into the one stable representation consumed by
 * later live, self-learning, report and import phases.
 */
final class question_schema {

    /** @var string[] Persisted content types. SPEC item 14 is allowbacktrack. */
    public const QTYPES = [
        'quiz',
        'truefalse',
        'shortanswer',
        'puzzle',
        'poll',
        'wordcloud',
        'scale',
        'slider',
        'pin',
        'reveal',
        'brainstorm',
        'open',
        'slide',
    ];

    /** @var string[] Point modes understood by the scoring engine. */
    public const POINTMODES = ['standard', 'double', 'none'];

    /**
     * @var string[] Types that can carry the F2 reason stage.
     *
     * These are exactly the eleven strategies built on
     * interaction_policy::standard(). `slide` has no answer to justify, and
     * `brainstorm` already owns its own multi-stage flow.
     */
    public const REASON_STAGE_TYPES = [
        'quiz',
        'truefalse',
        'shortanswer',
        'puzzle',
        'poll',
        'wordcloud',
        'scale',
        'slider',
        'pin',
        'reveal',
        'open',
    ];

    /**
     * @var string[] Types whose distribution can carry the F5 hinge light.
     *
     * A hinge question needs exactly one notion of "correct" that a single
     * percentage can summarise. A poll has no right answer, and a
     * multiple-answer question has no single share worth a traffic light —
     * misconception_repository::correct_keys() enforces the second half.
     */
    public const HINGE_TYPES = ['quiz', 'truefalse'];

    /**
     * @var string[] Types that can carry the F13 stage-check sub-mode.
     *
     * Exactly two, and the pair is deliberate: `open` is a premium type (addon
     * `qtypes`), `slide` belongs to the basis. A school that licences the
     * stage check but not the premium types therefore still gets a working
     * sub-mode on slides — visible and usable, not a locked lure
     * (P11_PLAN.md 4/F13).
     */
    public const STAGE_CHECK_TYPES = ['open', 'slide'];

    /** @var string[] Types that can never be automatically scored. */
    private const UNGRADED_TYPES = ['poll', 'wordcloud', 'scale', 'brainstorm', 'open', 'slide'];

    /** @var array<string, string> Image MIME fallback by filename extension. */
    private const IMAGE_MIME_FALLBACKS = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
    ];

    /** @var array<string, string> Video MIME fallback by filename extension. */
    private const VIDEO_MIME_FALLBACKS = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
    ];

    /**
     * Return a complete, intentionally incomplete starter question.
     *
     * @param string $qtype Question type.
     * @return array
     */
    public static function defaults(string $qtype): array {
        self::require_qtype($qtype);

        $options = match ($qtype) {
            'quiz' => [
                'media' => null,
                'multiple' => false,
                'answers' => [
                    ['id' => 'a', 'text' => '', 'media' => null, 'correct' => true],
                    ['id' => 'b', 'text' => '', 'media' => null, 'correct' => false],
                ],
            ],
            'truefalse' => ['media' => null, 'correct' => true],
            'shortanswer' => [
                'media' => null,
                'acceptedAnswers' => [''],
                'typoTolerance' => true,
            ],
            'puzzle' => [
                'media' => null,
                'items' => [
                    ['id' => 'a', 'text' => '', 'media' => null],
                    ['id' => 'b', 'text' => '', 'media' => null],
                ],
            ],
            'poll' => [
                'media' => null,
                'multiple' => false,
                'answers' => [
                    ['id' => 'a', 'text' => '', 'media' => null],
                    ['id' => 'b', 'text' => '', 'media' => null],
                ],
            ],
            'wordcloud' => ['media' => null, 'maxChars' => 40, 'moderation' => false],
            'scale' => [
                'media' => null,
                'minLabel' => '',
                'maxLabel' => '',
                'steps' => 5,
            ],
            'slider' => [
                'media' => null,
                'min' => 0.0,
                'max' => 100.0,
                'step' => 1.0,
                'target' => 50.0,
                'tolerance' => 5.0,
            ],
            'pin' => [
                'media' => null,
                'hasTarget' => true,
                'target' => ['x' => 50.0, 'y' => 50.0],
                'radius' => 10.0,
            ],
            'reveal' => [
                'media' => null,
                'grid' => 4,
                'revealSeconds' => 3,
                'acceptedAnswers' => [''],
            ],
            'brainstorm' => [
                'media' => null,
                'collectSeconds' => 60,
                'grouping' => 'manual',
                'voteSeconds' => 45,
            ],
            'open' => ['media' => null, 'sampleAnswer' => ''],
            'slide' => [
                'layout' => 'title',
                'title' => '',
                'body' => '',
                'bullets' => [],
                'quote' => '',
                'attribution' => '',
                'media' => null,
                'reactions' => true,
            ],
        };

        return [
            'qtype' => $qtype,
            'questiontext' => '',
            'questionformat' => FORMAT_PLAIN,
            'options' => $options,
            'timelimit' => $qtype === 'slide' ? 0 : 20,
            'pointmode' => in_array($qtype, self::UNGRADED_TYPES, true) ? 'none' : 'standard',
            'explanation' => '',
            'status' => 'draft',
        ];
    }

    /**
     * Normalise shape and collect semantic validation errors.
     *
     * Malformed top-level shapes are rejected. Incomplete user content is kept
     * as a draft and returned with field-addressable errors for autosave.
     *
     * @param array $input Submitted question.
     * @return array{question: array, validationErrors: array}
     */
    public static function normalise(array $input): array {
        $qtype = self::scalar_string($input, 'qtype', '');
        self::require_qtype($qtype);
        $defaults = self::defaults($qtype);
        $errors = [];

        $questiontext = self::plain_text(
            self::scalar_string($input, 'questiontext', $defaults['questiontext']),
            4000
        );
        $explanation = self::plain_text(
            self::scalar_string($input, 'explanation', $defaults['explanation']),
            12000
        );
        $optionsinput = $input['options'] ?? $defaults['options'];
        if (!is_array($optionsinput)) {
            throw new \invalid_parameter_exception('Question options must be an object.');
        }
        $options = self::normalise_options($qtype, $optionsinput, $errors);

        $timelimit = self::normalise_int(
            $input['timelimit'] ?? $defaults['timelimit'],
            $defaults['timelimit'],
            $qtype === 'slide' ? 0 : 5,
            $qtype === 'slide' ? 0 : 240,
            'timelimit',
            $errors
        );
        $pointmode = self::scalar_string($input, 'pointmode', $defaults['pointmode']);
        if (!in_array($pointmode, self::POINTMODES, true)) {
            self::error($errors, 'pointmode', 'invalid');
            $pointmode = $defaults['pointmode'];
        }
        if (in_array($qtype, self::UNGRADED_TYPES, true)) {
            $pointmode = 'none';
        }

        if ($qtype !== 'slide' && $questiontext === '') {
            self::error($errors, 'questiontext', 'required');
        }
        self::validate_semantics($qtype, $options, $errors);

        return [
            'question' => [
                'qtype' => $qtype,
                'questiontext' => $questiontext,
                'questionformat' => FORMAT_PLAIN,
                'options' => $options,
                'timelimit' => $timelimit,
                'pointmode' => $pointmode,
                'explanation' => $explanation,
                'status' => $errors ? 'draft' : 'ready',
            ],
            'validationErrors' => $errors,
        ];
    }

    /**
     * Add validation that depends on Moodle stored files.
     *
     * @param array $question Canonical question.
     * @param array $errors Existing validation errors.
     * @param array $manifest Authoritative stored-file manifest.
     * @return array
     */
    public static function validate_media(array $question, array $errors, array $manifest): array {
        $qtype = $question['qtype'];
        $options = $question['options'];
        $mimetypes = self::manifest_mimetypes($manifest);

        if (in_array($qtype, ['pin', 'reveal'], true)
                && !self::manifest_path_has_type($options['media'] ?? null, $mimetypes, 'image/')) {
            self::error($errors, 'options.media', 'image_required');
        }
        if ($qtype === 'quiz' || $qtype === 'poll') {
            foreach ($options['answers'] as $index => $answer) {
                $mediapath = $answer['media'] ?? null;
                $hasimage = self::manifest_path_has_type($mediapath, $mimetypes, 'image/');
                if ($mediapath !== null && !$hasimage) {
                    self::error($errors, "options.answers.{$index}.media", 'image_required');
                }
                if ($answer['text'] === '' && !$hasimage) {
                    self::error($errors, "options.answers.{$index}", 'text_or_image_required');
                }
            }
        }
        if ($qtype === 'puzzle') {
            foreach ($options['items'] as $index => $item) {
                $mediapath = $item['media'] ?? null;
                $hasimage = self::manifest_path_has_type($mediapath, $mimetypes, 'image/');
                if ($mediapath !== null && !$hasimage) {
                    self::error($errors, "options.items.{$index}.media", 'image_required');
                }
                if ($item['text'] === '' && !$hasimage) {
                    self::error($errors, "options.items.{$index}", 'text_or_image_required');
                }
            }
        }
        if ($qtype === 'slide') {
            $layout = $options['layout'] ?? '';
            if (in_array($layout, ['text-image', 'fullscreen'], true)
                    && !self::manifest_path_has_type($options['media'] ?? null, $mimetypes, 'image/')) {
                self::error($errors, 'options.media', 'image_required');
            } else if ($layout === 'video'
                    && !self::manifest_path_has_type($options['media'] ?? null, $mimetypes, 'video/')) {
                self::error($errors, 'options.media', 'video_required');
            }
        }

        return self::unique_errors($errors);
    }

    /**
     * Encode canonical options deterministically.
     *
     * @param array $options Options object.
     * @return string
     */
    public static function encode_options(array $options): string {
        return json_encode(
            $options,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Decode persisted options, retaining compatibility with empty P1 records.
     *
     * @param string|null $json JSON text.
     * @return array
     */
    public static function decode_options(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalise the type-specific options object.
     *
     * @param string $qtype Question type.
     * @param array $input Raw options.
     * @param array $errors Mutable errors.
     * @return array
     */
    private static function normalise_options(string $qtype, array $input, array &$errors): array {
        $defaults = self::defaults($qtype)['options'];
        $media = self::media_path($input['media'] ?? null);

        $options = match ($qtype) {
            'quiz' => [
                'media' => $media,
                'multiple' => self::bool_value($input['multiple'] ?? false),
                'answers' => self::normalise_rows(
                    $input['answers'] ?? $defaults['answers'],
                    true,
                    'options.answers',
                    $errors
                ),
            ],
            'truefalse' => [
                'media' => $media,
                'correct' => self::bool_value($input['correct'] ?? true),
            ],
            'shortanswer' => [
                'media' => $media,
                'acceptedAnswers' => self::normalise_text_list(
                    $input['acceptedAnswers'] ?? [''],
                    12,
                    255,
                    'options.acceptedAnswers',
                    $errors,
                    true
                ),
                'typoTolerance' => self::bool_value($input['typoTolerance'] ?? true),
            ],
            'puzzle' => [
                'media' => $media,
                'items' => self::normalise_rows(
                    $input['items'] ?? $defaults['items'],
                    false,
                    'options.items',
                    $errors
                ),
            ],
            'poll' => [
                'media' => $media,
                'multiple' => self::bool_value($input['multiple'] ?? false),
                'answers' => self::normalise_rows(
                    $input['answers'] ?? $defaults['answers'],
                    false,
                    'options.answers',
                    $errors
                ),
            ],
            'wordcloud' => [
                'media' => $media,
                'maxChars' => self::normalise_int(
                    $input['maxChars'] ?? 40,
                    40,
                    10,
                    200,
                    'options.maxChars',
                    $errors
                ),
                'moderation' => self::bool_value($input['moderation'] ?? false),
            ],
            'scale' => [
                'media' => $media,
                'minLabel' => self::plain_text(self::array_string($input, 'minLabel', ''), 120),
                'maxLabel' => self::plain_text(self::array_string($input, 'maxLabel', ''), 120),
                'steps' => self::normalise_int(
                    $input['steps'] ?? 5,
                    5,
                    3,
                    10,
                    'options.steps',
                    $errors
                ),
            ],
            'slider' => self::normalise_slider($input, $media, $errors),
            'pin' => self::normalise_pin($input, $media, $errors),
            'reveal' => [
                'media' => $media,
                'grid' => self::normalise_int(
                    $input['grid'] ?? 4,
                    4,
                    3,
                    6,
                    'options.grid',
                    $errors
                ),
                'revealSeconds' => self::normalise_int(
                    $input['revealSeconds'] ?? 3,
                    3,
                    1,
                    30,
                    'options.revealSeconds',
                    $errors
                ),
                'acceptedAnswers' => self::normalise_text_list(
                    $input['acceptedAnswers'] ?? [''],
                    12,
                    255,
                    'options.acceptedAnswers',
                    $errors,
                    true
                ),
            ],
            'brainstorm' => [
                'media' => $media,
                'collectSeconds' => self::normalise_int(
                    $input['collectSeconds'] ?? 60,
                    60,
                    10,
                    600,
                    'options.collectSeconds',
                    $errors
                ),
                'grouping' => self::enum_value(
                    $input['grouping'] ?? 'manual',
                    ['manual', 'ai'],
                    'manual',
                    'options.grouping',
                    $errors
                ),
                'voteSeconds' => self::normalise_int(
                    $input['voteSeconds'] ?? 45,
                    45,
                    10,
                    300,
                    'options.voteSeconds',
                    $errors
                ),
            ],
            'open' => [
                'media' => $media,
                'sampleAnswer' => self::plain_text(self::array_string($input, 'sampleAnswer', ''), 6000),
            ],
            'slide' => self::normalise_slide($input, $errors),
        };

        // F2 Denk-Moment. One uniform switch instead of eleven near-identical
        // match arms. A slide carries no answer and therefore no reason; the
        // brainstorm type already owns a multi-stage flow of its own.
        if (in_array($qtype, self::REASON_STAGE_TYPES, true)) {
            $options['reasonStep'] = self::bool_value($input['reasonStep'] ?? false);
        }
        // F5 Fehlkonzept-Radar. Dieselbe Bauweise wie oben: ein Schalter an
        // einer Stelle statt zwei fast gleicher match-Arme. Der Wert 0 heisst
        // ausdruecklich "Standard aus den Einstellungen" und ist deshalb
        // gueltig, nicht ein Fehler.
        // F13 Buehnen-Check. Derselbe eine Schalter an einer Stelle. Ob er
        // GESETZT werden darf, entscheidet nicht das Schema, sondern das Tor
        // in editor_service — genauso wie beim Jahreszeiten-Thema: das Schema
        // normalisiert, die Absicht wird beim Speichern geprueft.
        if (in_array($qtype, self::STAGE_CHECK_TYPES, true)) {
            $options['stageCheck'] = self::bool_value($input['stageCheck'] ?? false);
        }
        if (in_array($qtype, self::HINGE_TYPES, true)) {
            $options['hingeThreshold'] = self::normalise_int(
                $input['hingeThreshold'] ?? 0,
                0,
                0,
                100,
                'options.hingeThreshold',
                $errors
            );
        }
        return $options;
    }

    /**
     * Normalise answer/puzzle rows.
     *
     * @param mixed $raw Raw list.
     * @param bool $withcorrect Include correctness.
     * @param string $field Field path.
     * @param array $errors Mutable errors.
     * @return array
     */
    private static function normalise_rows($raw, bool $withcorrect, string $field, array &$errors): array {
        if (!is_array($raw) || !array_is_list($raw)) {
            self::error($errors, $field, 'invalid');
            $raw = [];
        }
        $rows = [];
        $usedids = [];
        foreach (array_slice($raw, 0, 6) as $index => $row) {
            if (!is_array($row)) {
                self::error($errors, "{$field}.{$index}", 'invalid');
                continue;
            }
            $fallbackid = chr(ord('a') + $index);
            $rawid = $row['id'] ?? $fallbackid;
            $id = self::option_id($rawid, $fallbackid);
            if (!is_string($rawid) || $id !== $rawid) {
                self::error($errors, "{$field}.{$index}.id", 'invalid');
            }
            if (isset($usedids[$id])) {
                self::error($errors, "{$field}.{$index}.id", 'duplicate');
                foreach (range('a', 'z') as $candidate) {
                    if (!isset($usedids[$candidate])) {
                        $id = $candidate;
                        break;
                    }
                }
            }
            $usedids[$id] = true;
            $item = [
                'id' => $id,
                'text' => self::plain_text(self::array_string($row, 'text', ''), 1000),
                'media' => self::media_path($row['media'] ?? null),
            ];
            if ($withcorrect) {
                $item['correct'] = self::bool_value($row['correct'] ?? false);
            }
            $rows[] = $item;
        }
        if (count($raw) > 6) {
            self::error($errors, $field, 'max_six');
        }
        return $rows;
    }

    /**
     * Normalise numerical slider fields.
     *
     * @param array $input Raw options.
     * @param string|null $media Relative media path.
     * @param array $errors Mutable errors.
     * @return array
     */
    private static function normalise_slider(array $input, ?string $media, array &$errors): array {
        $min = self::normalise_float($input['min'] ?? 0, 0, -1000000, 1000000, 'options.min', $errors);
        $max = self::normalise_float($input['max'] ?? 100, 100, -1000000, 1000000, 'options.max', $errors);
        if ($max <= $min) {
            self::error($errors, 'options.max', 'greater_than_min');
            $max = $min + 100;
        }
        $step = self::normalise_float($input['step'] ?? 1, 1, 0.000001, $max - $min, 'options.step', $errors);
        $target = self::normalise_float($input['target'] ?? 50, 50, $min, $max, 'options.target', $errors);
        $tolerance = self::normalise_float(
            $input['tolerance'] ?? 5,
            5,
            0,
            $max - $min,
            'options.tolerance',
            $errors
        );
        return compact('media', 'min', 'max', 'step', 'target', 'tolerance');
    }

    /**
     * Normalise pin coordinates in percentages.
     *
     * @param array $input Raw options.
     * @param string|null $media Relative media path.
     * @param array $errors Mutable errors.
     * @return array
     */
    private static function normalise_pin(array $input, ?string $media, array &$errors): array {
        $hastarget = self::bool_value($input['hasTarget'] ?? true);
        $targetinput = $input['target'] ?? [];
        if (!is_array($targetinput)) {
            self::error($errors, 'options.target', 'invalid');
            $targetinput = [];
        }
        return [
            'media' => $media,
            'hasTarget' => $hastarget,
            'target' => [
                'x' => self::normalise_float(
                    $targetinput['x'] ?? 50,
                    50,
                    0,
                    100,
                    'options.target.x',
                    $errors
                ),
                'y' => self::normalise_float(
                    $targetinput['y'] ?? 50,
                    50,
                    0,
                    100,
                    'options.target.y',
                    $errors
                ),
            ],
            'radius' => self::normalise_float(
                $input['radius'] ?? 10,
                10,
                1,
                50,
                'options.radius',
                $errors
            ),
        ];
    }

    /**
     * Normalise slide layouts without accepting remote embeds.
     *
     * @param array $input Raw options.
     * @param array $errors Mutable errors.
     * @return array
     */
    private static function normalise_slide(array $input, array &$errors): array {
        if (isset($input['videoUrl']) && trim((string)$input['videoUrl']) !== '') {
            self::error($errors, 'options.videoUrl', 'external_media_forbidden');
        }
        return [
            'layout' => self::enum_value(
                $input['layout'] ?? 'title',
                ['title', 'text-image', 'bullets', 'quote', 'video', 'fullscreen'],
                'title',
                'options.layout',
                $errors
            ),
            'title' => self::plain_text(self::array_string($input, 'title', ''), 1000),
            'body' => self::plain_text(self::array_string($input, 'body', ''), 8000),
            'bullets' => self::normalise_text_list(
                $input['bullets'] ?? [],
                20,
                1000,
                'options.bullets',
                $errors,
                false
            ),
            'quote' => self::plain_text(self::array_string($input, 'quote', ''), 3000),
            'attribution' => self::plain_text(self::array_string($input, 'attribution', ''), 500),
            'media' => self::media_path($input['media'] ?? null),
            'reactions' => self::bool_value($input['reactions'] ?? true),
        ];
    }

    /**
     * Apply semantic rules after shape normalisation.
     *
     * @param string $qtype Type.
     * @param array $options Canonical options.
     * @param array $errors Mutable errors.
     * @return void
     */
    private static function validate_semantics(string $qtype, array $options, array &$errors): void {
        if ($qtype === 'quiz') {
            $count = count($options['answers']);
            if ($count < 2) {
                self::error($errors, 'options.answers', 'min_two');
            }
            $correct = count(array_filter($options['answers'], static fn(array $answer): bool => $answer['correct']));
            if ($correct < 1) {
                self::error($errors, 'options.answers', 'correct_required');
            }
            if (!$options['multiple'] && $correct !== 1) {
                self::error($errors, 'options.answers', 'exactly_one_correct');
            }
        } else if ($qtype === 'poll' && count($options['answers']) < 2) {
            self::error($errors, 'options.answers', 'min_two');
        } else if ($qtype === 'puzzle' && count($options['items']) < 2) {
            self::error($errors, 'options.items', 'min_two');
        } else if (in_array($qtype, ['shortanswer', 'reveal'], true)) {
            $nonempty = array_filter(
                $options['acceptedAnswers'],
                static fn(string $answer): bool => trim($answer) !== ''
            );
            if (!$nonempty) {
                self::error($errors, 'options.acceptedAnswers', 'required');
            }
        } else if ($qtype === 'slide') {
            $hascontent = $options['title'] !== ''
                || $options['body'] !== ''
                || $options['quote'] !== ''
                || !empty(array_filter($options['bullets']))
                || $options['media'] !== null;
            if (!$hascontent) {
                self::error($errors, 'options', 'slide_content_required');
            }
        }
    }

    /**
     * Require a supported qtype.
     *
     * @param string $qtype Type.
     * @return void
     */
    private static function require_qtype(string $qtype): void {
        if (!in_array($qtype, self::QTYPES, true)) {
            throw new \invalid_parameter_exception('Unsupported Quizgeist question type.');
        }
    }

    /**
     * Read a scalar string from the top-level payload.
     *
     * @param array $input Input.
     * @param string $key Key.
     * @param string $default Default.
     * @return string
     */
    private static function scalar_string(array $input, string $key, string $default): string {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        if (!is_scalar($input[$key]) && $input[$key] !== null) {
            throw new \invalid_parameter_exception("Question field {$key} must be scalar.");
        }
        return (string)($input[$key] ?? '');
    }

    /**
     * Read a string-valued option.
     *
     * @param array $input Input.
     * @param string $key Key.
     * @param string $default Default.
     * @return string
     */
    private static function array_string(array $input, string $key, string $default): string {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return $default;
        }
        return is_scalar($input[$key]) ? (string)$input[$key] : $default;
    }

    /**
     * Clean plain editor text and bound database/response sizes.
     *
     * @param string $value Raw text.
     * @param int $maxlength Maximum characters.
     * @return string
     */
    private static function plain_text(string $value, int $maxlength): string {
        $value = trim((string)clean_param($value, PARAM_TEXT));
        if (\core_text::strlen($value) > $maxlength) {
            $value = \core_text::substr($value, 0, $maxlength);
        }
        return $value;
    }

    /**
     * Normalise a boolean-like JSON value.
     *
     * @param mixed $value Value.
     * @return bool
     */
    private static function bool_value($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, [1, '1', 'true', 'on'], true);
    }

    /**
     * Normalise a bounded integer and record fallback use.
     *
     * @param mixed $value Raw value.
     * @param int $default Default.
     * @param int $min Minimum.
     * @param int $max Maximum.
     * @param string $field Error field.
     * @param array $errors Mutable errors.
     * @return int
     */
    private static function normalise_int(
        $value,
        int $default,
        int $min,
        int $max,
        string $field,
        array &$errors
    ): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            self::error($errors, $field, 'invalid');
            return $default;
        }
        $number = (int)$value;
        if ($number < $min || $number > $max) {
            self::error($errors, $field, 'out_of_range');
            return max($min, min($max, $number));
        }
        return $number;
    }

    /**
     * Normalise a finite bounded float.
     *
     * @param mixed $value Raw value.
     * @param float $default Default.
     * @param float $min Minimum.
     * @param float $max Maximum.
     * @param string $field Error field.
     * @param array $errors Mutable errors.
     * @return float
     */
    private static function normalise_float(
        $value,
        float $default,
        float $min,
        float $max,
        string $field,
        array &$errors
    ): float {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            self::error($errors, $field, 'invalid');
            return $default;
        }
        $number = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($number === false || !is_finite((float)$number)) {
            self::error($errors, $field, 'invalid');
            return $default;
        }
        $number = (float)$number;
        if ($number < $min || $number > $max) {
            self::error($errors, $field, 'out_of_range');
            return max($min, min($max, $number));
        }
        return $number;
    }

    /**
     * Normalise an enum.
     *
     * @param mixed $value Value.
     * @param string[] $allowed Allowed values.
     * @param string $default Default.
     * @param string $field Error field.
     * @param array $errors Mutable errors.
     * @return string
     */
    private static function enum_value(
        $value,
        array $allowed,
        string $default,
        string $field,
        array &$errors
    ): string {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            self::error($errors, $field, 'invalid');
            return $default;
        }
        return $value;
    }

    /**
     * Normalise a bounded list of plain strings.
     *
     * @param mixed $raw Raw list.
     * @param int $maxitems Maximum entries.
     * @param int $maxlength Maximum characters each.
     * @param string $field Error field.
     * @param array $errors Mutable errors.
     * @param bool $keepone Keep a blank starter.
     * @return string[]
     */
    private static function normalise_text_list(
        $raw,
        int $maxitems,
        int $maxlength,
        string $field,
        array &$errors,
        bool $keepone
    ): array {
        if (!is_array($raw) || !array_is_list($raw)) {
            self::error($errors, $field, 'invalid');
            return $keepone ? [''] : [];
        }
        if (count($raw) > $maxitems) {
            self::error($errors, $field, 'too_many');
        }
        $values = [];
        foreach (array_slice($raw, 0, $maxitems) as $index => $value) {
            if (!is_scalar($value) && $value !== null) {
                self::error($errors, "{$field}.{$index}", 'invalid');
                continue;
            }
            $values[] = self::plain_text((string)($value ?? ''), $maxlength);
        }
        if ($keepone && !$values) {
            $values[] = '';
        }
        return $values;
    }

    /**
     * Return a safe stable option identifier.
     *
     * @param mixed $raw Raw value.
     * @param string $fallback Fallback.
     * @return string
     */
    private static function option_id($raw, string $fallback): string {
        if (!is_string($raw) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $raw)) {
            return $fallback;
        }
        return $raw;
    }

    /**
     * Build an exact stored path to MIME lookup.
     *
     * Moodle's stored MIME is authoritative when present. The filename is used
     * only for older/restored records whose MIME value is empty.
     *
     * @param array $manifest Stored-file manifest.
     * @return array<string, string>
     */
    private static function manifest_mimetypes(array $manifest): array {
        $mimetypes = [];
        foreach ($manifest as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null) || $file['path'] === '') {
                continue;
            }
            $mimetype = is_string($file['mimetype'] ?? null)
                ? strtolower(trim($file['mimetype']))
                : '';
            if ($mimetype === '') {
                $filename = is_string($file['filename'] ?? null)
                    ? $file['filename']
                    : basename($file['path']);
                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $mimetype = self::IMAGE_MIME_FALLBACKS[$extension]
                    ?? self::VIDEO_MIME_FALLBACKS[$extension]
                    ?? '';
            }
            $mimetypes[$file['path']] = $mimetype;
        }
        return $mimetypes;
    }

    /**
     * Check the MIME family of one exact manifest path.
     *
     * @param mixed $path Canonical media path.
     * @param array<string, string> $mimetypes Manifest MIME lookup.
     * @param string $prefix Required MIME prefix.
     * @return bool
     */
    private static function manifest_path_has_type($path, array $mimetypes, string $prefix): bool {
        return is_string($path)
            && $path !== ''
            && isset($mimetypes[$path])
            && str_starts_with($mimetypes[$path], $prefix);
    }

    /**
     * Accept only plugin-relative media paths. The editor service later
     * reconciles them against stored files, so client values are never trusted.
     *
     * @param mixed $raw Raw value.
     * @return string|null
     */
    private static function media_path($raw): ?string {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)
                || $raw[0] !== '/'
                || str_contains($raw, '://')
                || str_starts_with($raw, '//')
                || str_contains($raw, '..')
                || str_contains($raw, '\\')
                || preg_match('/[\x00-\x1F\x7F]/', $raw)) {
            return null;
        }
        return \core_text::strlen($raw) <= 1024 ? $raw : null;
    }

    /**
     * Append a structured validation error.
     *
     * @param array $errors Mutable list.
     * @param string $field Field path.
     * @param string $code Stable code.
     * @return void
     */
    private static function error(array &$errors, string $field, string $code): void {
        $errors[] = ['field' => $field, 'code' => $code];
    }

    /**
     * Remove duplicate field/code pairs.
     *
     * @param array $errors Errors.
     * @return array
     */
    private static function unique_errors(array $errors): array {
        $unique = [];
        foreach ($errors as $error) {
            $unique[$error['field'] . "\0" . $error['code']] = $error;
        }
        return array_values($unique);
    }
}
