<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for pure Kahoot question mapping.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\editor\question_schema;
use mod_quizgeist\local\kahoot\question_mapper;
use mod_quizgeist\local\live\qtype\registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers every supported Kahoot source type and its fail-closed boundaries.
 */
final class question_mapper_test extends \advanced_testcase {

    /**
     * Classic quiz preserves points, time, multiple correctness and media slots.
     *
     * @return void
     */
    public function test_maps_classic_quiz_with_plain_text_and_opaque_ids(): void {
        $source = self::choice_question('quiz');
        $source['question'] = 'Zeile <b>eins</b><br>Zeile zwei';
        $source['pointsMultiplier'] = 2;
        $source['image'] = 'https://media.kahoot.test/question';
        $source['imageMetadata'] = ['altText' => 'Source alt'];
        $source['choices'][0]['correct'] = true;
        $source['choices'][1]['correct'] = true;
        $source['choices'][1]['image'] = 'https://media.kahoot.test/answer';

        $mapped = question_mapper::map($source, 7);

        $this->assertSame('adjusted', $mapped['outcome']);
        $this->assertSame('quiz', $mapped['targetType']);
        $this->assertSame("Zeile eins\nZeile zwei", $mapped['question']['questiontext']);
        $this->assertSame(20, $mapped['question']['timelimit']);
        $this->assertSame('double', $mapped['question']['pointmode']);
        $this->assertTrue($mapped['question']['options']['multiple']);
        $this->assertContains(
            question_mapper::REASON_HTML_FORMATTING_REMOVED,
            $mapped['reasons']
        );
        $this->assertContains(
            question_mapper::REASON_MULTIPLE_INFERRED,
            $mapped['reasons']
        );
        $this->assertContains(
            question_mapper::REASON_MEDIA_METADATA_OMITTED,
            $mapped['reasons']
        );

        $ids = array_column($mapped['question']['options']['answers'], 'id');
        $this->assertCount(2, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^k[0-9a-f]{23}$/D', $id);
            $this->assertNotContains($id, ['a', 'b', 'item-1', 'item-2']);
        }
        $this->assertSame([
            [
                'sourceUrl' => 'https://media.kahoot.test/question',
                'target' => 'question',
                'optionId' => null,
            ],
            [
                'sourceUrl' => 'https://media.kahoot.test/answer',
                'target' => 'answers/' . $ids[1],
                'optionId' => $ids[1],
            ],
        ], $mapped['media']);

        $again = question_mapper::map($source, 7);
        $this->assertSame(
            $ids,
            array_column($again['question']['options']['answers'], 'id')
        );
    }

    /**
     * TRUE_FALSE layout becomes the dedicated target without source labels.
     *
     * @return void
     */
    public function test_maps_truefalse_layout(): void {
        $source = self::choice_question('quiz');
        $source['layout'] = 'TRUE_FALSE';
        $source['choices'] = [
            ['answer' => 'True', 'correct' => false],
            ['answer' => 'False', 'correct' => true],
        ];

        $mapped = question_mapper::map($source);

        $this->assertSame('truefalse', $mapped['targetType']);
        $this->assertFalse($mapped['question']['options']['correct']);
        $this->assertSame('standard', $mapped['question']['pointmode']);
    }

    /**
     * Multiple-select quiz remains multiple even with one selected solution.
     *
     * @return void
     */
    public function test_maps_multiple_select_quiz(): void {
        $source = self::choice_question('multiple_select_quiz');

        $mapped = question_mapper::map($source);

        $this->assertSame('quiz', $mapped['targetType']);
        $this->assertTrue($mapped['question']['options']['multiple']);
        $this->assertTrue($mapped['question']['options']['answers'][0]['correct']);
    }

    /**
     * Single and multiple polls discard source correctness semantically.
     *
     * @return void
     */
    public function test_maps_survey_and_multiple_select_poll(): void {
        $survey = self::choice_question('survey');
        $survey['choices'][0]['correct'] = true;
        $poll = self::choice_question('multiple_select_poll');
        $poll['choices'][0]['correct'] = true;
        $poll['choices'][1]['correct'] = true;

        $mappedsurvey = question_mapper::map($survey);
        $mappedpoll = question_mapper::map($poll);

        $this->assertSame('poll', $mappedsurvey['targetType']);
        $this->assertFalse($mappedsurvey['question']['options']['multiple']);
        $this->assertSame('none', $mappedsurvey['question']['pointmode']);
        $this->assertArrayNotHasKey(
            'correct',
            $mappedsurvey['question']['options']['answers'][0]
        );
        $this->assertContains(
            question_mapper::REASON_POLL_CORRECTNESS_IGNORED,
            $mappedsurvey['reasons']
        );
        $this->assertTrue($mappedpoll['question']['options']['multiple']);
    }

    /**
     * Both custom and agreement scale variants preserve five steps.
     *
     * @return void
     */
    public function test_maps_scale_variants(): void {
        $custom = self::base_question('scale');
        $custom['scaleRange'] = [
            'start' => 1,
            'end' => 5,
            'type' => 'custom',
            'labelType' => 'custom',
            'startLabelText' => 'Keine',
            'endLabelText' => 'Stunden',
        ];
        $agreement = self::base_question('scale');
        $agreement['scaleRange'] = [
            'start' => 1,
            'end' => 5,
            'type' => 'likert',
            'labelType' => 'agreement',
        ];

        $mappedcustom = question_mapper::map($custom);
        $mappedagreement = question_mapper::map($agreement);

        $this->assertSame([
            'media' => null,
            'minLabel' => 'Keine',
            'maxLabel' => 'Stunden',
            'steps' => 5,
        ], $mappedcustom['question']['options']);
        $this->assertSame('none', $mappedcustom['question']['pointmode']);
        $this->assertSame(
            'Stimme überhaupt nicht zu',
            $mappedagreement['question']['options']['minLabel']
        );
        $this->assertContains(
            question_mapper::REASON_SCALE_LABELS_ADAPTED,
            $mappedagreement['reasons']
        );
    }

    /**
     * Jumble order is server-side canonical but identifiers reveal no positions.
     *
     * @return void
     */
    public function test_maps_jumble_to_puzzle_with_opaque_ids(): void {
        $source = self::choice_question('jumble');
        $source['choices'] = [
            ['answer' => 'Erstens', 'correct' => true],
            ['answer' => 'Zweitens', 'correct' => true],
            ['answer' => 'Drittens', 'correct' => true],
            ['answer' => 'Viertens', 'correct' => true],
        ];

        $mapped = question_mapper::map($source, 3);

        $this->assertSame('puzzle', $mapped['targetType']);
        $this->assertSame(
            ['Erstens', 'Zweitens', 'Drittens', 'Viertens'],
            array_column($mapped['question']['options']['items'], 'text')
        );
        $ids = array_column($mapped['question']['options']['items'], 'id');
        $this->assertCount(4, array_unique($ids));
        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression('/^k[0-9a-f]{23}$/D', $id);
        }
    }

    /**
     * Targetless drop-pin is explicitly ungraded and has no authoritative target.
     *
     * @return void
     */
    public function test_maps_drop_pin_to_ungraded_heatmap(): void {
        $source = self::base_question('drop_pin');
        $source['image'] = 'https://media.kahoot.test/pin';

        $mapped = question_mapper::map($source);

        $this->assertSame('pin', $mapped['targetType']);
        $this->assertSame('none', $mapped['question']['pointmode']);
        $this->assertFalse($mapped['question']['options']['hasTarget']);
        $this->assertContains(
            question_mapper::REASON_DROP_PIN_UNGRADED,
            $mapped['reasons']
        );
        $this->assertSame('question', $mapped['media'][0]['target']);
    }

    /**
     * Open-ended questions remain native ungraded open responses.
     *
     * @return void
     */
    public function test_maps_open_ended_to_native_open_response(): void {
        $source = self::base_question('open_ended');
        $source['image'] = 'https://media.kahoot.test/open';

        $mapped = question_mapper::map($source, 12);

        $this->assertSame('imported', $mapped['outcome']);
        $this->assertSame('open_ended', $mapped['sourceType']);
        $this->assertSame('open', $mapped['targetType']);
        $this->assertSame('none', $mapped['question']['pointmode']);
        $this->assertSame(
            '',
            $mapped['question']['options']['sampleAnswer']
        );
        $this->assertSame([
            [
                'sourceUrl' => 'https://media.kahoot.test/open',
                'target' => 'question',
                'optionId' => null,
            ],
        ], $mapped['media']);
        $this->assert_native_ready($mapped, 'open');

        $withoutanswerkey = question_mapper::map(
            self::base_question('open_ended')
        );
        $this->assertSame(
            '',
            $withoutanswerkey['question']['options']['sampleAnswer']
        );
        $this->assert_native_ready($withoutanswerkey, 'open');
    }

    /**
     * Word clouds preserve their prompt, duration and local media binding.
     *
     * @return void
     */
    public function test_maps_word_cloud_to_native_wordcloud(): void {
        $source = self::base_question('word_cloud');
        $source['image'] = 'https://media.kahoot.test/word-cloud';

        $mapped = question_mapper::map($source);

        $this->assertSame('imported', $mapped['outcome']);
        $this->assertSame('wordcloud', $mapped['targetType']);
        $this->assertSame(20, $mapped['question']['timelimit']);
        $this->assertSame('none', $mapped['question']['pointmode']);
        $this->assertSame([
            'media' => null,
            'maxChars' => 20,
            'moderation' => false,
        ], $mapped['question']['options']);
        $this->assertSame(
            'https://media.kahoot.test/word-cloud',
            $mapped['media'][0]['sourceUrl']
        );
        $this->assert_native_ready($mapped, 'wordcloud');
    }

    /**
     * Both Kahoot brainstorm spellings and time units reach one native type.
     *
     * @return void
     */
    public function test_maps_brainstorm_and_brainstorming_alias(): void {
        foreach ([
            ['type' => 'brainstorm', 'time' => 90000],
            ['type' => 'brainstorming', 'time' => 90],
        ] as $fixture) {
            $source = self::base_question($fixture['type']);
            $source['time'] = $fixture['time'];
            $source['numberOfAnswersAllowed'] = 3;
            $source['pointsMultiplier'] = 1;

            $mapped = question_mapper::map($source);

            $this->assertSame($fixture['type'], $mapped['sourceType']);
            $this->assertSame('brainstorm', $mapped['targetType']);
            $this->assertSame(90, $mapped['question']['timelimit']);
            $this->assertSame('none', $mapped['question']['pointmode']);
            $this->assertSame([
                'media' => null,
                'collectSeconds' => 90,
                'grouping' => 'manual',
                'voteSeconds' => 45,
            ], $mapped['question']['options']);
            $this->assertContains(
                question_mapper::REASON_BRAINSTORM_ANSWER_LIMIT_ADAPTED,
                $mapped['reasons']
            );
            $this->assertContains(
                question_mapper::REASON_BRAINSTORM_POINTS_OMITTED,
                $mapped['reasons']
            );
            $this->assertContains(
                question_mapper::REASON_BRAINSTORM_WORKFLOW_ADAPTED,
                $mapped['reasons']
            );
            $this->assert_native_ready($mapped, 'brainstorm');
        }
    }

    /**
     * Slider ranges retain their answer and convert fractional tolerance.
     *
     * @return void
     */
    public function test_maps_slider_with_absolute_target_tolerance(): void {
        $source = self::base_question('slider');
        $source['pointsMultiplier'] = 2;
        $source['image'] = 'https://media.kahoot.test/slider';
        $source['choiceRange'] = [
            'start' => -10,
            'end' => 30,
            'step' => 0.5,
            'correct' => 5,
            'tolerance' => 0.1,
            'unit' => '°C',
        ];

        $mapped = question_mapper::map($source);

        $this->assertSame('slider', $mapped['targetType']);
        $this->assertSame('double', $mapped['question']['pointmode']);
        $this->assertSame(-10.0, $mapped['question']['options']['min']);
        $this->assertSame(30.0, $mapped['question']['options']['max']);
        $this->assertSame(0.5, $mapped['question']['options']['step']);
        $this->assertSame(5.0, $mapped['question']['options']['target']);
        $this->assertEqualsWithDelta(
            4.0,
            $mapped['question']['options']['tolerance'],
            0.000001
        );
        $this->assertContains(
            question_mapper::REASON_SLIDER_TOLERANCE_ADAPTED,
            $mapped['reasons']
        );
        $this->assertContains(
            question_mapper::REASON_SLIDER_UNIT_OMITTED,
            $mapped['reasons']
        );
        $this->assertSame(
            'https://media.kahoot.test/slider',
            $mapped['media'][0]['sourceUrl']
        );
        $this->assert_native_ready($mapped, 'slider');
    }

    /**
     * Content without question/time becomes a zero-time native slide.
     *
     * @return void
     */
    public function test_maps_content_without_question_or_time_to_slide(): void {
        $source = [
            'type' => 'content',
            'title' => '<b>Erneuerbare Energie</b>',
            'description' => "• Sonne<br>2) Wind",
            'layout' => 'MEDIA_TITLE_BULLETS',
            'hasReactions' => false,
            'backgroundColor' => '#ffffff',
            'image' => 'https://media.kahoot.test/content',
        ];

        $mapped = question_mapper::map($source, 9);

        $this->assertSame('content', $mapped['sourceType']);
        $this->assertSame('slide', $mapped['targetType']);
        $this->assertSame('', $mapped['question']['questiontext']);
        $this->assertSame(0, $mapped['question']['timelimit']);
        $this->assertSame('none', $mapped['question']['pointmode']);
        $this->assertSame('bullets', $mapped['question']['options']['layout']);
        $this->assertSame(
            'Erneuerbare Energie',
            $mapped['question']['options']['title']
        );
        $this->assertSame(
            ['Sonne', 'Wind'],
            $mapped['question']['options']['bullets']
        );
        $this->assertSame('', $mapped['question']['options']['body']);
        $this->assertFalse($mapped['question']['options']['reactions']);
        $this->assertContains(
            question_mapper::REASON_CONTENT_LAYOUT_ADAPTED,
            $mapped['reasons']
        );
        $this->assertContains(
            question_mapper::REASON_CONTENT_STYLE_OMITTED,
            $mapped['reasons']
        );
        $this->assertSame(
            'https://media.kahoot.test/content',
            $mapped['media'][0]['sourceUrl']
        );
        $this->assert_native_ready($mapped, 'slide');
    }

    /**
     * Image-only content is retained only when its local medium exists.
     *
     * @return void
     */
    public function test_image_only_content_fails_closed_without_media(): void {
        $source = [
            'type' => 'content',
            'layout' => 'IMPORTED_SLIDE',
            'image' => 'https://media.kahoot.test/imported-slide',
        ];

        $mapped = question_mapper::map($source);

        $this->assertSame('slide', $mapped['targetType']);
        $this->assertSame(
            'fullscreen',
            $mapped['question']['options']['layout']
        );
        $this->assertSame(0, $mapped['question']['timelimit']);

        $reconciled = question_mapper::reconcile_media([$mapped], []);
        $this->assertSame('skipped', $reconciled[0]['outcome']);
        $this->assertNull($reconciled[0]['question']);
        $this->assertContains(
            question_mapper::REASON_REQUIRED_MEDIA_MISSING,
            $reconciled[0]['reasons']
        );
    }

    /**
     * Textual content survives when only its optional image is unavailable.
     *
     * @return void
     */
    public function test_textual_content_downgrades_without_optional_media(): void {
        $source = [
            'type' => 'content',
            'title' => 'Titel',
            'description' => 'Erklärender Text',
            'layout' => 'MEDIA_TITLE_TEXT',
            'image' => 'https://media.kahoot.test/optional-slide',
        ];

        $mapped = question_mapper::map($source);
        $reconciled = question_mapper::reconcile_media([$mapped], []);

        $this->assertSame('adjusted', $reconciled[0]['outcome']);
        $this->assertSame('slide', $reconciled[0]['targetType']);
        $this->assertSame(
            'title',
            $reconciled[0]['question']['options']['layout']
        );
        $this->assertContains(
            question_mapper::REASON_MEDIA_MISSING,
            $reconciled[0]['reasons']
        );
        $this->assertNotContains(
            question_mapper::REASON_REQUIRED_MEDIA_MISSING,
            $reconciled[0]['reasons']
        );
        $this->assert_native_ready($reconciled[0], 'slide');
    }

    /**
     * Invalid new-type core fields remain per-question skips.
     *
     * @return void
     */
    public function test_new_source_types_fail_closed_on_invalid_core_data(): void {
        $slider = self::base_question('slider');
        $slider['choiceRange'] = [
            'start' => 0,
            'end' => 10,
            'step' => 0,
            'correct' => 5,
            'tolerance' => 0.1,
        ];
        $tinystep = self::base_question('slider');
        $tinystep['choiceRange'] = [
            'start' => 0,
            'end' => 10,
            'step' => 0.0000001,
            'correct' => 5,
            'tolerance' => 0.1,
        ];
        $unreachabletarget = self::base_question('slider');
        $unreachabletarget['choiceRange'] = [
            'start' => 0,
            'end' => 10,
            'step' => 3,
            'correct' => 5,
            'tolerance' => 0,
        ];
        $content = [
            'type' => 'content',
            'layout' => 'MEDIA_TITLE_TEXT',
            'video' => ['id' => 'remote-video'],
        ];
        $missingprompts = [
            ['type' => 'open_ended', 'time' => 20000],
            ['type' => 'word_cloud', 'time' => 20000],
            ['type' => 'brainstorm', 'time' => 90],
        ];

        foreach ([
            $slider,
            $tinystep,
            $unreachabletarget,
            $content,
            ...$missingprompts,
        ] as $source) {
            $mapped = question_mapper::map($source);
            $this->assertSame('skipped', $mapped['outcome']);
            $this->assertContains(
                question_mapper::REASON_SOURCE_INVALID,
                $mapped['reasons']
            );
        }
    }

    /**
     * Unknown source types and targetless pins fail closed with stable reasons.
     *
     * @return void
     */
    public function test_skips_unmappable_or_invalid_questions(): void {
        $unknown = self::base_question('typing_answer');
        $pin = self::base_question('drop_pin');

        $mappedunknown = question_mapper::map($unknown);
        $mappedpin = question_mapper::map($pin);

        $this->assertSame('skipped', $mappedunknown['outcome']);
        $this->assertContains(
            question_mapper::REASON_TYPE_UNSUPPORTED,
            $mappedunknown['reasons']
        );
        $this->assertSame('skipped', $mappedpin['outcome']);
        $this->assertContains(
            question_mapper::REASON_REQUIRED_MEDIA_MISSING,
            $mappedpin['reasons']
        );
    }

    /**
     * Missing bundle files skip only media-dependent questions.
     */
    public function test_reconciles_missing_required_and_optional_media(): void {
        $pin = self::base_question('drop_pin');
        $pin['image'] = 'https://media.kahoot.test/pin';

        $optional = self::choice_question('quiz');
        $optional['image'] = 'https://media.kahoot.test/question';

        $imageanswer = self::choice_question('quiz');
        $imageanswer['choices'][0]['answer'] = '';
        $imageanswer['choices'][0]['image'] =
            'https://media.kahoot.test/answer';

        $mapped = question_mapper::reconcile_media([
            question_mapper::map($pin),
            question_mapper::map($optional),
            question_mapper::map($imageanswer),
        ], []);

        $this->assertSame('skipped', $mapped[0]['outcome']);
        $this->assertNull($mapped[0]['question']);
        $this->assertContains(
            question_mapper::REASON_REQUIRED_MEDIA_MISSING,
            $mapped[0]['reasons']
        );
        $this->assertContains(
            question_mapper::REASON_MEDIA_MISSING,
            $mapped[0]['reasons']
        );

        $this->assertSame('adjusted', $mapped[1]['outcome']);
        $this->assertIsArray($mapped[1]['question']);
        $this->assertNotContains(
            question_mapper::REASON_REQUIRED_MEDIA_MISSING,
            $mapped[1]['reasons']
        );

        $this->assertSame('skipped', $mapped[2]['outcome']);
        $this->assertNull($mapped[2]['question']);
        $this->assertContains(
            question_mapper::REASON_REQUIRED_MEDIA_MISSING,
            $mapped[2]['reasons']
        );
    }

    /**
     * Assert one mapper row normalises cleanly and has a runtime strategy.
     *
     * @param array $mapped Mapper result.
     * @param string $targettype Expected native target.
     * @return void
     */
    private function assert_native_ready(
        array $mapped,
        string $targettype
    ): void {
        $normalised = question_schema::normalise($mapped['question']);
        $this->assertSame(
            'ready',
            $normalised['question']['status'],
            json_encode($normalised['validationErrors'])
        );
        $this->assertSame(
            $targettype,
            registry::get($targettype)->type()
        );
    }

    /**
     * Build one common source question.
     *
     * @param string $type Kahoot type.
     * @return array
     */
    private static function choice_question(string $type): array {
        return [
            ...self::base_question($type),
            'layout' => 'CLASSIC',
            'pointsMultiplier' => 1,
            'choices' => [
                ['answer' => 'Ja', 'correct' => true],
                ['answer' => 'Nein', 'correct' => false],
            ],
        ];
    }

    /**
     * Build shared scalar fields.
     *
     * @param string $type Kahoot type.
     * @return array
     */
    private static function base_question(string $type): array {
        return [
            'type' => $type,
            'question' => 'Fixture?',
            'time' => 20000,
        ];
    }
}
