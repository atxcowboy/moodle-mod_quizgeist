<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Card code alphabet and check character (F11a).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

defined('MOODLE_INTERNAL') || die();

/**
 * The one place that decides what a card code looks like.
 *
 * Pure arithmetic: no database, no configuration, no clock, no randomness.
 * That is not an aesthetic preference — a card code has to be reproducible
 * from `(cardsetid, index, seed)` alone, because a lost printout must be
 * reprintable and must yield the SAME cards. A code that came out of a random
 * generator would make a reprint a different class set.
 *
 * Two promises are made here and both are tested:
 *
 * 1. **No collisions.** `code()` is a bijection on the payload space, not a
 *    hash. Two different indices of one set can therefore never produce the
 *    same code, no matter how many cards a school prints. A hash would only
 *    make collisions unlikely, and "unlikely" is the wrong guarantee when the
 *    consequence is that two learners are booked as one.
 * 2. **Every single-character error is detected.** The check character is a
 *    weighted sum modulo a PRIME alphabet size. Position weights 1..4 are all
 *    coprime to 23, so a wrong character at any position always changes the
 *    check character. Adjacent transpositions are detected for the same reason.
 */
final class card_code {

    /**
     * The printable alphabet, 23 characters, deliberately prime.
     *
     * Removed as required by the brief: `0/O`, `1/I/L`, `5/S`, `8/B`.
     * Removed in addition, because the same argument applies to them and the
     * count has to reach a prime for the check character to be exhaustive:
     * `Z` (against `2`), `G` (against `6`), `U` (against `V`), `Q` (against the
     * already removed `O`). What remains is legible on a printed card at
     * classroom distance and to a vision model on a phone photograph.
     */
    public const ALPHABET = '234679ACDEFHJKMNPRTVWXY';

    /** Payload characters before the check character. */
    public const PAYLOAD_LENGTH = 4;

    /** Total code length including the check character. */
    public const LENGTH = 5;

    /**
     * Size of the payload space, 23^4 = 279 841 distinct codes per set.
     *
     * A class has thirty cards. The room above that is for reserve cards,
     * re-issues and a school that prints one set per year group.
     */
    public const CAPACITY = 279841;

    /**
     * Build the code of one card.
     *
     * @param int $cardsetid Owning card set.
     * @param int $index Zero-based card index inside the set.
     * @param string $seed Set seed, 32 hexadecimal characters.
     * @return string Five characters from ALPHABET.
     */
    public static function code(int $cardsetid, int $index, string $seed): string {
        if ($cardsetid <= 0) {
            throw new \invalid_parameter_exception('A card set ID must be positive.');
        }
        if ($index < 0 || $index >= self::CAPACITY) {
            throw new \invalid_parameter_exception('A card index is out of range.');
        }
        self::assert_seed($seed);

        [$multiplier, $offset] = self::permutation($cardsetid, $seed);
        // A linear congruence with a multiplier coprime to 23^4 is a bijection
        // on [0, CAPACITY): distinct indices give distinct payloads. This is
        // the collision-freedom promise, and it is arithmetic, not luck.
        $payload = (int)((($multiplier * $index) + $offset) % self::CAPACITY);

        $characters = [];
        $remainder = $payload;
        for ($position = self::PAYLOAD_LENGTH - 1; $position >= 0; $position--) {
            $characters[$position] = $remainder % 23;
            $remainder = intdiv($remainder, 23);
        }
        ksort($characters);

        $code = '';
        foreach ($characters as $value) {
            $code .= self::ALPHABET[$value];
        }
        return $code . self::ALPHABET[self::check_value($characters)];
    }

    /**
     * Whether a string is a well-formed code with a correct check character.
     *
     * Deliberately does NOT say whether the code belongs to a set — that is a
     * database question and it is asked separately. This method only rejects
     * what cannot be a code at all, so a misread never reaches the lookup.
     *
     * @param string $code Candidate.
     * @return bool
     */
    public static function is_valid(string $code): bool {
        $code = self::normalise($code);
        if ($code === '') {
            return false;
        }
        $values = [];
        for ($position = 0; $position < self::PAYLOAD_LENGTH; $position++) {
            $values[$position] = strpos(self::ALPHABET, $code[$position]);
        }
        return self::ALPHABET[self::check_value($values)] === $code[self::PAYLOAD_LENGTH];
    }

    /**
     * Bring a candidate into canonical form, or return the empty string.
     *
     * Upper-casing and whitespace removal are safe repairs of how a code was
     * TYPED. Substituting `O` for `0` would be a repair of what was READ, and
     * that is exactly the guessing this class refuses to do: an ambiguous
     * character is a rejected code, not a corrected one.
     *
     * @param string $code Candidate.
     * @return string Canonical code, or '' when the shape is already wrong.
     */
    public static function normalise(string $code): string {
        $code = strtoupper(preg_replace('/\s+/u', '', $code) ?? '');
        if (strlen($code) !== self::LENGTH) {
            return '';
        }
        for ($position = 0; $position < self::LENGTH; $position++) {
            if (strpos(self::ALPHABET, $code[$position]) === false) {
                return '';
            }
        }
        return $code;
    }

    /**
     * Produce a fresh set seed.
     *
     * @return string 32 hexadecimal characters.
     */
    public static function seed(): string {
        return bin2hex(random_bytes(16));
    }

    /**
     * Whether a seed has the shape this class requires.
     *
     * @param string $seed Candidate seed.
     * @return bool
     */
    public static function is_seed(string $seed): bool {
        return (bool)preg_match('/^[a-f0-9]{32}$/D', $seed);
    }

    /**
     * The weighted check value of four payload values.
     *
     * @param array<int,int> $values Payload values, index 0..3.
     * @return int Value in [0, 22].
     */
    private static function check_value(array $values): int {
        $sum = 0;
        for ($position = 0; $position < self::PAYLOAD_LENGTH; $position++) {
            $sum += ($position + 1) * (int)$values[$position];
        }
        return $sum % 23;
    }

    /**
     * Derive the per-set bijection from the seed.
     *
     * @param int $cardsetid Card set.
     * @param string $seed Set seed.
     * @return array{0:int,1:int} Multiplier coprime to 23, offset.
     */
    private static function permutation(int $cardsetid, string $seed): array {
        $digest = hash_hmac('sha256', 'quizgeist-cardset:' . $cardsetid, $seed);
        $multiplier = (int)hexdec(substr($digest, 0, 8)) % self::CAPACITY;
        $offset = (int)hexdec(substr($digest, 8, 8)) % self::CAPACITY;
        // Coprimality to 23^4 means exactly "not a multiple of 23". Stepping
        // forward keeps the derivation deterministic while guaranteeing it.
        while ($multiplier % 23 === 0 || $multiplier === 0) {
            $multiplier++;
        }
        return [$multiplier, $offset];
    }

    /**
     * Refuse a seed that is not a seed.
     *
     * @param string $seed Candidate.
     * @return void
     */
    private static function assert_seed(string $seed): void {
        if (!self::is_seed($seed)) {
            throw new \invalid_parameter_exception('A card set seed must be 32 hex characters.');
        }
    }
}
