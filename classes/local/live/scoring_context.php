<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Server-owned scoring context.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Separates session mode from question-type correctness evaluation.
 *
 * The pace axis is deliberately orthogonal to the mode. `accuracy` belongs to
 * the paid modes addon; the stress-free standard of the base package must not
 * borrow it, or the base would depend on a sold feature and
 * session_settings::assert_creatable() would block it.
 */
final class scoring_context {

    /** Response time counts and the streak bonus applies. */
    public const PACE_TIMED = 'timed';

    /** Response time is ignored; the streak bonus and display stay intact. */
    public const PACE_EVEN = 'even';

    /** @var string[] Stable persisted point-axis catalogue. */
    public const PACES = [self::PACE_TIMED, self::PACE_EVEN];

    /**
     * One point axis is the works default; changing it is a single line.
     *
     * The stress-free standard is the strongest argument of the base package
     * (P11_PLAN.md, F1). It is free of charge and free of any addon.
     */
    public const DEFAULT_PACE = self::PACE_EVEN;

    public function __construct(
        public readonly string $mode,
        public readonly int $responsetimems,
        public readonly int $priorstreak,
        public readonly string $pace = self::PACE_TIMED
    ) {
        if (!in_array(
            $mode,
            ['classic', 'accuracy', 'team', 'security', 'selfstudy'],
            true
        )
                || !in_array($pace, self::PACES, true)
                || $responsetimems < 0
                || $priorstreak < 0) {
            throw new \invalid_parameter_exception('Live scoring context is invalid.');
        }
    }

    /**
     * Normalise one persisted or submitted point axis.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    public static function normalise_pace($value): string {
        return is_string($value) && in_array($value, self::PACES, true)
            ? $value
            : self::DEFAULT_PACE;
    }
}
