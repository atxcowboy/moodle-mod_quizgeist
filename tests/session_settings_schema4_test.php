<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for live-settings schema version 4 (F1).
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\leaderboard_policy;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\session_settings;

defined('MOODLE_INTERNAL') || die();

/**
 * A session written before P11 stays readable and keeps its old behaviour.
 */
final class session_settings_schema4_test extends \advanced_testcase {

    public function test_schema_three_sessions_remain_readable(): void {
        $this->resetAfterTest(true);

        $legacy = json_encode([
            'schemaVersion' => 3,
            'nameMode' => 'generated',
            'team' => null,
            'blockedNames' => [],
        ]);
        $decoded = session_settings::decode($legacy);

        $this->assertSame('generated', $decoded['nameMode']);
        // A pre-P11 session played timed with a full leaderboard. Replaying it
        // must not silently apply the new stress-free works defaults.
        $this->assertSame(scoring_context::PACE_TIMED, $decoded['pace']);
        $this->assertSame(leaderboard_policy::FULL, $decoded['leaderboard']);
        $this->assertTrue($decoded['timer']);
        $this->assertTrue($decoded['sound']);
    }

    public function test_schema_one_sessions_remain_readable(): void {
        $this->resetAfterTest(true);

        $decoded = session_settings::decode(json_encode([
            'schemaVersion' => 1,
            'nameMode' => 'real',
        ]));

        $this->assertSame('real', $decoded['nameMode']);
        $this->assertSame(scoring_context::PACE_TIMED, $decoded['pace']);
        $this->assertSame(leaderboard_policy::FULL, $decoded['leaderboard']);
    }

    public function test_new_sessions_take_the_activity_defaults(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $context = \context_module::instance($module->cmid);

        $settings = session_settings::create(
            'classic',
            'real',
            [],
            $context,
            'classic',
            $module
        );

        $this->assertSame(4, $settings['schemaVersion']);
        $this->assertSame(scoring_context::DEFAULT_PACE, $settings['pace']);
        $this->assertSame(
            leaderboard_policy::DEFAULT_VISIBILITY,
            $settings['leaderboard']
        );
    }

    public function test_a_session_override_beats_the_activity_default(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $context = \context_module::instance($module->cmid);

        $settings = session_settings::create(
            'classic',
            'real',
            [
                'pace' => scoring_context::PACE_TIMED,
                'leaderboard' => leaderboard_policy::FULL,
                'timer' => false,
                'sound' => false,
            ],
            $context,
            'classic',
            $module
        );

        $this->assertSame(scoring_context::PACE_TIMED, $settings['pace']);
        $this->assertSame(leaderboard_policy::FULL, $settings['leaderboard']);
        $this->assertFalse($settings['timer']);
        $this->assertFalse($settings['sound']);
    }

    public function test_encode_decode_round_trip_is_stable(): void {
        $this->resetAfterTest(true);

        $original = [
            'schemaVersion' => 4,
            'nameMode' => 'custom',
            'team' => null,
            'blockedNames' => [],
            'pace' => scoring_context::PACE_EVEN,
            'leaderboard' => leaderboard_policy::TEAM,
            'timer' => false,
            'sound' => true,
        ];
        $decoded = session_settings::decode(session_settings::encode($original));

        $this->assertSame($original['pace'], $decoded['pace']);
        $this->assertSame($original['leaderboard'], $decoded['leaderboard']);
        $this->assertFalse($decoded['timer']);
        $this->assertTrue($decoded['sound']);
    }

    public function test_an_unknown_value_falls_back_instead_of_throwing(): void {
        $this->resetAfterTest(true);

        $decoded = session_settings::decode(json_encode([
            'schemaVersion' => 4,
            'nameMode' => 'real',
            'team' => null,
            'blockedNames' => [],
            'pace' => 'raketenmodus',
            'leaderboard' => 'alles',
        ]));

        $this->assertSame(scoring_context::DEFAULT_PACE, $decoded['pace']);
        $this->assertSame(
            leaderboard_policy::DEFAULT_VISIBILITY,
            $decoded['leaderboard']
        );
    }
}
