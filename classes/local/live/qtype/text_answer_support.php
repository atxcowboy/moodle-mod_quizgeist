<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Shared short-text correctness rules.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live\qtype;

defined('MOODLE_INTERNAL') || die();

/**
 * Implements deterministic Unicode typo tolerance.
 */
final class text_answer_support {

    public static function payload(array $rawanswer): array {
        return [
            'text' => strategy_support::text(
                $rawanswer['text'] ?? null,
                255,
                'text'
            ),
        ];
    }

    /**
     * Compare against every accepted answer.
     */
    public static function matches(
        string $submitted,
        array $acceptedanswers,
        bool $tolerant
    ): bool {
        $submitted = strategy_support::text_key($submitted);
        foreach ($acceptedanswers as $accepted) {
            $accepted = strategy_support::text_key((string)$accepted);
            if (hash_equals($accepted, $submitted)) {
                return true;
            }
            if (!$tolerant) {
                continue;
            }
            $length = max(
                \core_text::strlen($accepted),
                \core_text::strlen($submitted)
            );
            $limit = $length <= 4 ? 0 : ($length <= 9 ? 1 : 2);
            if ($limit > 0 && self::distance($submitted, $accepted, $limit) <= $limit) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bounded Unicode Levenshtein distance.
     */
    private static function distance(string $left, string $right, int $limit): int {
        $a = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($a) || !is_array($b)) {
            return $limit + 1;
        }
        if (abs(count($a) - count($b)) > $limit) {
            return $limit + 1;
        }
        $previous = range(0, count($b));
        foreach ($a as $i => $achar) {
            $current = [$i + 1];
            $rowminimum = $i + 1;
            foreach ($b as $j => $bchar) {
                $current[$j + 1] = min(
                    $current[$j] + 1,
                    $previous[$j + 1] + 1,
                    $previous[$j] + ($achar === $bchar ? 0 : 1)
                );
                $rowminimum = min($rowminimum, $current[$j + 1]);
            }
            if ($rowminimum > $limit) {
                return $limit + 1;
            }
            $previous = $current;
        }
        return (int)$previous[count($b)];
    }
}
