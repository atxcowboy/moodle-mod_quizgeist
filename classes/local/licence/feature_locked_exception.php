<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Feature-gate denial.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries only feature state, never customer or licence data.
 */
final class feature_locked_exception extends \RuntimeException {

    public function __construct(
        private readonly string $feature,
        private readonly string $status
    ) {
        parent::__construct("Quizgeist feature {$feature} is {$status}.");
    }

    public function feature(): string {
        return $this->feature;
    }

    public function status(): string {
        return $this->status;
    }
}
