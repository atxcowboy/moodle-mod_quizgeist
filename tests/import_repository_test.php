<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for Kahoot import provenance persistence helpers.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\kahoot\import_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers bounded deterministic identities used by Moodle restore copies.
 */
final class import_repository_test extends \advanced_testcase {

    /**
     * Restore-copy UUIDs are opaque, bounded, stable and activity-specific.
     *
     * @return void
     */
    public function test_restored_copy_uuid_is_bounded_and_destination_specific(): void {
        $uuid = '236494fe-85a7-4f86-8372-e8a78764a604';
        $first = import_repository::restored_copy_uuid(
            252,
            'kahoot',
            $uuid,
            1001
        );

        $this->assertMatchesRegularExpression(
            '/^restore-[a-f0-9]{56}$/D',
            $first
        );
        $this->assertSame(64, strlen($first));
        $this->assertSame(
            $first,
            import_repository::restored_copy_uuid(
                252,
                'kahoot',
                $uuid,
                1001
            )
        );
        $this->assertNotSame(
            $first,
            import_repository::restored_copy_uuid(
                252,
                'kahoot',
                $uuid,
                1002
            )
        );
        $this->assertNotSame(
            $first,
            import_repository::restored_copy_uuid(
                253,
                'kahoot',
                $uuid,
                1001
            )
        );
        $this->assertNotSame(
            $first,
            import_repository::restored_copy_uuid(
                252,
                'external',
                $uuid,
                1001
            )
        );
        $this->assertNotSame(
            $first,
            import_repository::restored_copy_uuid(
                252,
                'kahoot',
                $uuid,
                1001,
                1
            )
        );
    }

    /**
     * Invalid destination identifiers cannot create ambiguous provenance.
     *
     * @return void
     */
    public function test_restored_copy_uuid_rejects_invalid_destination(): void {
        $this->expectException(\coding_exception::class);
        import_repository::restored_copy_uuid(0, 'kahoot', 'source', 1001);
    }
}
