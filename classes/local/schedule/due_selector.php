<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Pure draw strategies for due question roots.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\schedule;

defined('MOODLE_INTERNAL') || die();

/**
 * Orders already fetched candidates. No database, no clock, no global state.
 *
 * The interleaved strategy is the only place in Quizgeist that deliberately
 * mixes topics. Its order is derived from an explicit seed, so a reload of the
 * same page for the same learner on the same calendar day is not merely
 * similar — it is identical.
 */
final class due_selector {

    /** Frozen order, as authored by the teacher. */
    public const STRATEGY_SEQUENTIAL = 'sequential';

    /** Deterministically shuffled order. */
    public const STRATEGY_SHUFFLED = 'shuffled';

    /** Deterministic round-robin across topic groups. */
    public const STRATEGY_INTERLEAVED = 'interleaved';

    /** Every accepted draw strategy. */
    public const STRATEGIES = [
        self::STRATEGY_SEQUENTIAL,
        self::STRATEGY_SHUFFLED,
        self::STRATEGY_INTERLEAVED,
    ];

    /** Group key used for candidates without an approved topic tag. */
    public const UNTAGGED_GROUP = '';

    /**
     * Normalise an untrusted draw strategy.
     *
     * @param mixed $raw Raw value.
     * @return string
     */
    public static function normalise_strategy($raw): string {
        return is_string($raw) && in_array($raw, self::STRATEGIES, true)
            ? $raw
            : self::STRATEGY_SEQUENTIAL;
    }

    /**
     * Order candidates by due date, most overdue first.
     *
     * @param \stdClass[] $candidates Rows carrying rootid and duetime.
     * @param int $limit Maximum entries; zero means every candidate.
     * @return \stdClass[]
     */
    public static function due(array $candidates, int $limit = 0): array {
        $ordered = array_values($candidates);
        usort(
            $ordered,
            static function (\stdClass $left, \stdClass $right): int {
                // A never-answered root carries duetime 0 and therefore sorts
                // ahead of everything overdue — new material first.
                return [(int)$left->duetime, (int)$left->rootid]
                    <=> [(int)$right->duetime, (int)$right->rootid];
            }
        );
        return $limit > 0 ? array_slice($ordered, 0, $limit) : $ordered;
    }

    /**
     * Order candidates round-robin across topic groups.
     *
     * Inside a group the most overdue root comes first; the group order itself
     * is shuffled once from the seed and then kept for every round. Keeping it
     * — instead of reshuffling per round — is what guarantees that no two
     * neighbouring positions share a topic while at least two groups still
     * have entries. The three-in-a-row promise of F4 follows from that.
     *
     * @param \stdClass[] $candidates Rows carrying rootid and duetime.
     * @param array<int,string> $groupbyroot Group key per root ID.
     * @param int $seed Deterministic seed.
     * @param int $limit Maximum entries; zero means every candidate.
     * @return \stdClass[]
     */
    public static function interleaved(
        array $candidates,
        array $groupbyroot,
        int $seed,
        int $limit = 0
    ): array {
        $ordered = self::due($candidates);
        $groups = [];
        foreach ($ordered as $candidate) {
            $key = (string)($groupbyroot[(int)$candidate->rootid]
                ?? self::UNTAGGED_GROUP);
            $groups[$key][] = $candidate;
        }
        if (count($groups) <= 1) {
            // A single topic cannot be interleaved. Falling back to the shuffled
            // order keeps the promise honest instead of pretending to mix.
            return self::shuffled($candidates, $seed, $limit);
        }
        $keys = array_keys($groups);
        sort($keys, SORT_STRING);
        $keys = self::deterministic_shuffle($keys, $seed);

        $result = [];
        $remaining = true;
        while ($remaining) {
            $remaining = false;
            foreach ($keys as $key) {
                if (!$groups[$key]) {
                    continue;
                }
                $result[] = array_shift($groups[$key]);
                $remaining = $remaining || (bool)$groups[$key];
            }
        }
        return $limit > 0 ? array_slice($result, 0, $limit) : $result;
    }

    /**
     * Order candidates deterministically at random.
     *
     * @param \stdClass[] $candidates Rows carrying rootid.
     * @param int $seed Deterministic seed.
     * @param int $limit Maximum entries; zero means every candidate.
     * @return \stdClass[]
     */
    public static function shuffled(
        array $candidates,
        int $seed,
        int $limit = 0
    ): array {
        // Sorting by root ID first makes the input order irrelevant, so two
        // callers with the same candidates and seed always agree.
        $ordered = array_values($candidates);
        usort(
            $ordered,
            static fn(\stdClass $left, \stdClass $right): int
                => (int)$left->rootid <=> (int)$right->rootid
        );
        $shuffled = self::deterministic_shuffle($ordered, $seed);
        return $limit > 0 ? array_slice($shuffled, 0, $limit) : $shuffled;
    }

    /**
     * Apply one named strategy.
     *
     * @param string $strategy One of STRATEGIES.
     * @param \stdClass[] $candidates Rows carrying rootid and duetime.
     * @param array<int,string> $groupbyroot Group key per root ID.
     * @param int $seed Deterministic seed.
     * @param int $limit Maximum entries; zero means every candidate.
     * @return \stdClass[]
     */
    public static function order(
        string $strategy,
        array $candidates,
        array $groupbyroot,
        int $seed,
        int $limit = 0
    ): array {
        return match (self::normalise_strategy($strategy)) {
            self::STRATEGY_INTERLEAVED => self::interleaved(
                $candidates,
                $groupbyroot,
                $seed,
                $limit
            ),
            self::STRATEGY_SHUFFLED => self::shuffled($candidates, $seed, $limit),
            default => $limit > 0
                ? array_slice(array_values($candidates), 0, $limit)
                : array_values($candidates),
        };
    }

    /**
     * Derive the seed of one learner, one calendar day and one assignment.
     *
     * @param int $userid Learner.
     * @param int $daykey Local calendar day number.
     * @param int $assignmentid Assignment; zero for an unbound draw.
     * @return int Non-negative seed.
     */
    public static function seed(int $userid, int $daykey, int $assignmentid): int {
        return (int)crc32("{$userid}:{$daykey}:{$assignmentid}") & 0x7fffffff;
    }

    /**
     * Turn a timestamp into a local calendar day number.
     *
     * @param int $now Unix timestamp.
     * @param \DateTimeZone|null $timezone Viewer timezone; UTC when absent.
     * @return int
     */
    public static function day_key(int $now, ?\DateTimeZone $timezone = null): int {
        $moment = (new \DateTimeImmutable('@' . max(0, $now)))
            ->setTimezone($timezone ?? new \DateTimeZone('UTC'));
        return (int)$moment->format('Ymd');
    }

    /**
     * Shuffle deterministically without touching PHP's global RNG state.
     *
     * mt_srand() would reseed the whole request, which is exactly the kind of
     * invisible side effect a draw strategy must not have. A local
     * Lehmer generator keeps the sequence reproducible and contained.
     *
     * @param array $values Values to shuffle.
     * @param int $seed Deterministic seed.
     * @return array Shuffled copy.
     */
    private static function deterministic_shuffle(array $values, int $seed): array {
        $values = array_values($values);
        $count = count($values);
        if ($count < 2) {
            return $values;
        }
        // Lehmer / MINSTD: state must stay inside 1 .. 2147483646.
        $state = ($seed % 2147483646) + 1;
        for ($index = $count - 1; $index > 0; $index--) {
            $state = (int)(($state * 48271) % 2147483647);
            $pick = $state % ($index + 1);
            $swap = $values[$index];
            $values[$index] = $values[$pick];
            $values[$pick] = $swap;
        }
        return $values;
    }
}
