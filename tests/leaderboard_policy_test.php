<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the server-side leaderboard cut (F1).
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\live\leaderboard_policy;

defined('MOODLE_INTERNAL') || die();

/**
 * With `own` the SERVER ANSWER carries no foreign rank — proof in the payload.
 */
final class leaderboard_policy_test extends \advanced_testcase {

    /**
     * Three standings across two teams.
     *
     * @return array<int,array>
     */
    private function ranking(): array {
        return [
            ['playerId' => 1, 'displayName' => 'Ada', 'score' => 900,
                'rank' => 1, 'teamId' => 'team-a'],
            ['playerId' => 2, 'displayName' => 'Bene', 'score' => 700,
                'rank' => 2, 'teamId' => 'team-b'],
            ['playerId' => 3, 'displayName' => 'Cem', 'score' => 500,
                'rank' => 3, 'teamId' => 'team-a'],
        ];
    }

    public function test_own_visibility_leaves_only_the_own_standing(): void {
        $this->resetAfterTest(true);

        $cut = leaderboard_policy::player_ranking($this->ranking(), 'own', 3);

        $this->assertCount(1, $cut);
        $this->assertSame(3, $cut[0]['playerId']);
        // The decisive assertion: no foreign name is anywhere in the payload.
        $encoded = json_encode($cut);
        $this->assertStringNotContainsString('Ada', $encoded);
        $this->assertStringNotContainsString('Bene', $encoded);
    }

    public function test_team_visibility_keeps_only_the_own_team(): void {
        $this->resetAfterTest(true);

        $cut = leaderboard_policy::player_ranking($this->ranking(), 'team', 3);

        $this->assertCount(2, $cut);
        $this->assertSame(
            ['team-a', 'team-a'],
            array_column($cut, 'teamId')
        );
        $this->assertStringNotContainsString('Bene', json_encode($cut));
    }

    public function test_full_visibility_is_unchanged(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            $this->ranking(),
            leaderboard_policy::player_ranking($this->ranking(), 'full', 3)
        );
    }

    public function test_an_unknown_player_receives_nothing(): void {
        $this->resetAfterTest(true);

        $this->assertSame(
            [],
            leaderboard_policy::player_ranking($this->ranking(), 'own', 99)
        );
    }

    public function test_podium_follows_the_same_cut(): void {
        $this->resetAfterTest(true);

        $podium = leaderboard_policy::player_podium($this->ranking(), 'own', 2);
        $this->assertCount(1, $podium);
        $this->assertSame(2, $podium[0]['playerId']);
        $this->assertCount(
            3,
            leaderboard_policy::player_podium($this->ranking(), 'full', 2)
        );
    }

    public function test_team_ranking_is_empty_for_own_visibility(): void {
        $this->resetAfterTest(true);

        $teams = [
            ['teamKey' => 'team-a', 'teamName' => 'Alpha', 'score' => 700],
            ['teamKey' => 'team-b', 'teamName' => 'Beta', 'score' => 500],
        ];
        $this->assertSame(
            [],
            leaderboard_policy::player_team_ranking($teams, 'own', 'team-a')
        );
        $this->assertCount(
            1,
            leaderboard_policy::player_team_ranking($teams, 'team', 'team-a')
        );
        $this->assertCount(
            2,
            leaderboard_policy::player_team_ranking($teams, 'full', 'team-a')
        );
    }

    public function test_normalisation_and_default_are_one_place(): void {
        $this->resetAfterTest(true);

        $this->assertSame('own', leaderboard_policy::DEFAULT_VISIBILITY);
        $this->assertSame('own', leaderboard_policy::normalise('unfug'));
        $this->assertSame('full', leaderboard_policy::normalise('full'));
        $this->assertTrue(leaderboard_policy::shows_full('host', 'own'));
        $this->assertFalse(leaderboard_policy::shows_full('player', 'own'));
    }
}
