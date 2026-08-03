<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the contractual data-hostage boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\licence\feature_gate;
use mod_quizgeist\local\live\session_settings;

defined('MOODLE_INTERNAL') || die();

/**
 * Existing content stays usable even without a valid licence file.
 */
final class feature_gate_test extends \advanced_testcase {

    public function test_missing_licence_blocks_creation_not_existing_data(): void {
        $this->resetAfterTest(true);
        set_config('version', 2026073000, 'quizgeistaddon_selfstudy');

        $this->assertFalse(feature_gate::can_create('selfstudy'));
        $this->assertTrue(feature_gate::allows(
            'selfstudy',
            feature_gate::PLAY_EXISTING
        ));
        $this->assertTrue(feature_gate::allows(
            'selfstudy',
            feature_gate::EDIT_EXISTING
        ));
        $this->assertTrue(feature_gate::allows(
            'selfstudy',
            feature_gate::EXPORT_EXISTING
        ));

        $availability = feature_gate::availability('selfstudy');
        $this->assertTrue($availability['installed']);
        $this->assertSame('read_only', $availability['status']);
        $this->assertFalse($availability['canCreate']);
        $this->assertTrue($availability['canUseExisting']);
    }

    public function test_missing_addon_is_absent_even_for_existing_operations(): void {
        $this->resetAfterTest(true);
        unset_config('version', 'quizgeistaddon_selfstudy');

        $this->assertFalse(feature_gate::allows(
            'selfstudy',
            feature_gate::VIEW_EXISTING
        ));
        $this->assertSame(
            'not_installed',
            feature_gate::availability('selfstudy')['status']
        );
    }

    public function test_read_only_keeps_configured_premium_mode_playable(): void {
        $this->resetAfterTest(true);
        set_config('version', 2026073000, 'quizgeistaddon_modes');

        $this->assertFalse(feature_gate::can_create('modes'));
        $this->assertSame(
            ['classic'],
            session_settings::creatable_modes()
        );
        $this->assertSame(
            ['classic', 'team'],
            session_settings::creatable_modes('team')
        );

        // Reusing the persisted mode is play of an existing activity, not a
        // new premium-mode selection.
        session_settings::assert_creatable('team', 'team');
        $this->addToAssertionCount(1);
    }

    public function test_missing_modes_addon_does_not_offer_premium_default(): void {
        $this->resetAfterTest(true);
        unset_config('version', 'quizgeistaddon_modes');

        $this->assertSame(
            ['classic'],
            session_settings::creatable_modes('team')
        );
        $this->expectException(\invalid_parameter_exception::class);
        session_settings::assert_creatable('team', 'team');
    }
}
