<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the stress-free point axis (F1).
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\points_formula;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\session_settings;

defined('MOODLE_INTERNAL') || die();

/**
 * The pace axis is orthogonal to the mode and free of every addon.
 */
final class points_pace_test extends \advanced_testcase {

    /**
     * One canonical graded question.
     *
     * @return array
     */
    private function question(string $pointmode = 'standard'): array {
        return [
            'qtype' => 'quiz',
            'timelimit' => 20,
            'pointmode' => $pointmode,
        ];
    }

    public function test_even_pace_ignores_the_response_time(): void {
        $this->resetAfterTest(true);

        $fast = points_formula::calculate(
            $this->question(),
            true,
            new scoring_context('classic', 500, 0, scoring_context::PACE_EVEN)
        );
        $slow = points_formula::calculate(
            $this->question(),
            true,
            new scoring_context('classic', 19500, 0, scoring_context::PACE_EVEN)
        );

        $this->assertSame($fast['points'], $slow['points']);
        $this->assertSame(1000, $fast['points']);
    }

    public function test_timed_pace_still_rewards_speed(): void {
        $this->resetAfterTest(true);

        $fast = points_formula::calculate(
            $this->question(),
            true,
            new scoring_context('classic', 500, 0, scoring_context::PACE_TIMED)
        );
        $slow = points_formula::calculate(
            $this->question(),
            true,
            new scoring_context('classic', 19500, 0, scoring_context::PACE_TIMED)
        );

        $this->assertGreaterThan($slow['points'], $fast['points']);
    }

    public function test_even_pace_keeps_streak_bonus_and_streak_counter(): void {
        $this->resetAfterTest(true);

        $result = points_formula::calculate(
            $this->question(),
            true,
            new scoring_context('classic', 12000, 3, scoring_context::PACE_EVEN)
        );

        // 1000 base points plus 3 x 50 streak bonus.
        $this->assertSame(1150, $result['points']);
        $this->assertSame(4, $result['streak']);
    }

    public function test_accuracy_mode_stays_stricter_than_even_pace(): void {
        $this->resetAfterTest(true);

        $accuracy = points_formula::calculate(
            $this->question(),
            true,
            new scoring_context('accuracy', 12000, 3, scoring_context::PACE_TIMED)
        );

        // The premium mode drops the clock AND the streak; the free pace axis
        // drops only the clock. That difference is the premium value.
        $this->assertSame(1000, $accuracy['points']);
        $this->assertSame(0, $accuracy['streak']);
    }

    public function test_even_pace_never_touches_assert_creatable(): void {
        $this->resetAfterTest(true);

        // No modes addon is installed here. The stress-free standard must
        // still be creatable, or the base package would depend on a sold one.
        $this->assertTrue(session_settings::is_creatable('classic'));
        $settings = session_settings::create(
            'classic',
            'real',
            ['pace' => scoring_context::PACE_EVEN, 'leaderboard' => 'own'],
            $this->module_context(),
            'classic'
        );
        $this->assertSame(scoring_context::PACE_EVEN, $settings['pace']);
    }

    public function test_pace_normalisation_falls_back_to_the_works_default(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            scoring_context::DEFAULT_PACE,
            scoring_context::normalise_pace('unfug')
        );
        $this->assertSame(
            scoring_context::PACE_TIMED,
            scoring_context::normalise_pace('timed')
        );
    }

    public function test_pointmode_none_stays_pointless_on_every_axis(): void {
        $this->resetAfterTest(true);

        foreach (scoring_context::PACES as $pace) {
            $result = points_formula::calculate(
                $this->question('none'),
                true,
                new scoring_context('classic', 1000, 5, $pace)
            );
            $this->assertSame(0, $result['points'], "pace {$pace}");
        }
    }

    /**
     * Build one throwaway module context.
     *
     * @return \context_module
     */
    private function module_context(): \context_module {
        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        return \context_module::instance($module->cmid);
    }
}
