<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Role- and visit-aware live question projection context.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Gives strategies enough context for safe reveal and deterministic shuffling.
 */
final class projection_context {

    public function __construct(
        public readonly bool $includecorrect,
        public readonly string $role = 'host',
        public readonly string $status = 'question',
        public readonly string $stage = 'answer',
        public readonly string $visit = ''
    ) {
        if (!in_array($role, ['host', 'player'], true)
                || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $stage)
                || ($visit !== '' && !preg_match('/^[a-f0-9]{32}$/D', $visit))) {
            throw new \invalid_parameter_exception('Live projection context is invalid.');
        }
    }
}
