<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for editor and importer media limits.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\editor\media_service;
use mod_quizgeist\local\live\qtype\strategy_support;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Ensures every media entry point honours the target course.
 */
final class media_service_test extends \advanced_testcase {

    /**
     * File options derive the limit from their context, never global $COURSE.
     *
     * @return void
     */
    public function test_file_options_use_context_course_limit(): void {
        global $COURSE;

        $this->resetAfterTest();
        set_config('maxbytes', 16 * 1024 * 1024);
        $target = $this->getDataGenerator()->create_course(['maxbytes' => 1024]);
        $decoy = $this->getDataGenerator()->create_course(['maxbytes' => 8 * 1024 * 1024]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $target->id]);
        $user = $this->getDataGenerator()->create_and_enrol($target);
        $this->setUser($user);

        // A CLI invocation may leave this unset or pointing at an unrelated course.
        $COURSE = $decoy;

        $courseoptions = media_service::file_options(
            'questionmedia',
            \context_course::instance($target->id)
        );
        $moduleoptions = media_service::file_options(
            'questionmedia',
            \context_module::instance($page->cmid)
        );

        $this->assertSame(1024, $courseoptions['maxbytes']);
        $this->assertSame(1024, $moduleoptions['maxbytes']);
    }

    /**
     * Import preflight applies the same context-derived per-file limit.
     *
     * @return void
     */
    public function test_import_preflight_uses_context_course_limit(): void {
        global $COURSE;

        $this->resetAfterTest();
        set_config('maxbytes', 16 * 1024 * 1024);
        $target = $this->getDataGenerator()->create_course(['maxbytes' => 1024]);
        $decoy = $this->getDataGenerator()->create_course(['maxbytes' => 8 * 1024 * 1024]);
        $user = $this->getDataGenerator()->create_and_enrol($target);
        $this->setUser($user);

        $COURSE = $decoy;

        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('Import media file exceeds the upload limit.');
        media_service::preflight_import_content(
            \context_course::instance($target->id),
            'oversized.png',
            str_repeat('x', 1025)
        );
    }

    /**
     * Visit aliases resolve only their exact question-media namespace.
     *
     * @return void
     */
    public function test_live_media_alias_resolution_remains_exact(): void {
        $visit = str_repeat('a', 32);
        $answerid = 'answer-a';
        $answerhandle = strategy_support::opaque_id_map(
            [$answerid],
            $visit,
            'quiz:choices'
        )[$answerid];
        $quiz = [
            'qtype' => 'quiz',
            'options' => [
                'media' => '/question/cover.png',
                'answers' => [['id' => $answerid]],
            ],
        ];

        $this->assertSame(
            ['answers', $answerid, 'choice.png'],
            \quizgeist_pluginfile_resolve_live_alias(
                $quiz,
                ['live', 'answer', $answerhandle, 'choice.png'],
                $visit
            )
        );

        $questionhandle = strategy_support::opaque_id_map(
            ['media'],
            $visit,
            'question:media'
        )['media'];
        $this->assertSame(
            ['question', 'cover.png'],
            \quizgeist_pluginfile_resolve_live_alias(
                $quiz,
                ['live', 'question', $questionhandle, 'cover.png'],
                $visit
            )
        );
        $this->assertFalse(\quizgeist_pluginfile_resolve_live_alias(
            $quiz,
            ['live', 'question', $questionhandle, 'other.png'],
            $visit
        ));
        $this->assertFalse(\quizgeist_pluginfile_resolve_live_alias(
            $quiz,
            ['live', 'answer', str_repeat('h', 65), 'choice.png'],
            $visit
        ));
    }
}
