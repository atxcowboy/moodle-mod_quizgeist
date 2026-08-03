<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the printable card-code alphabet and check character.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\cards\card_code;

defined('MOODLE_INTERNAL') || die();

/**
 * Card codes are deterministic, legible and detect every single-character error.
 *
 * @covers \mod_quizgeist\local\cards\card_code::code
 * @covers \mod_quizgeist\local\cards\card_code::is_valid
 * @covers \mod_quizgeist\local\cards\card_code::normalise
 */
final class card_code_test extends \advanced_testcase {

    public function test_one_set_has_one_thousand_collision_free_codes(): void {
        $this->resetAfterTest(true);

        $seed = '0123456789abcdef0123456789abcdef';
        $codes = [];
        for ($index = 0; $index < 1000; $index++) {
            $code = card_code::code(17, $index, $seed);
            $codes[] = $code;
            $this->assertSame(card_code::LENGTH, strlen($code));
            $this->assertTrue(card_code::is_valid($code));
        }

        $this->assertCount(1000, array_unique($codes));
    }

    public function test_check_character_rejects_every_single_character_substitution(): void {
        $this->resetAfterTest(true);

        $seed = 'fedcba9876543210fedcba9876543210';
        $alphabet = card_code::ALPHABET;
        $tested = 0;
        for ($index = 0; $index < 100; $index++) {
            $code = card_code::code(23, $index, $seed);
            for ($position = 0; $position < card_code::LENGTH; $position++) {
                for ($value = 0; $value < strlen($alphabet); $value++) {
                    $replacement = $alphabet[$value];
                    if ($replacement === $code[$position]) {
                        continue;
                    }
                    $mutated = substr_replace($code, $replacement, $position, 1);
                    $this->assertFalse(
                        card_code::is_valid($mutated),
                        "Single-character substitution unexpectedly valid: {$code} -> {$mutated}"
                    );
                    $tested++;
                }
            }
        }

        $this->assertSame(100 * 5 * 22, $tested);
    }

    public function test_alphabet_excludes_ambiguous_characters_and_normalise_only_repairs_typing(): void {
        $this->resetAfterTest(true);

        $this->assertSame('234679ACDEFHJKMNPRTVWXY', card_code::ALPHABET);
        foreach (str_split('0O1IL5S8B') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, card_code::ALPHABET);
        }

        $seed = '00112233445566778899aabbccddeeff';
        $canonical = card_code::code(31, 42, $seed);
        $spaced = implode(" \n", str_split(strtolower($canonical)));
        $this->assertSame($canonical, card_code::normalise("  {$spaced}  "));

        foreach (str_split('0O1IL5S8B') as $ambiguous) {
            $candidate = $ambiguous . substr($canonical, 1);
            $this->assertSame('', card_code::normalise($candidate));
        }
    }

    public function test_same_index_in_two_sets_has_different_codes(): void {
        $this->resetAfterTest(true);

        $seed = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $first = card_code::code(41, 73, $seed);
        $second = card_code::code(42, 73, $seed);

        $this->assertNotSame($first, $second);
    }
}
