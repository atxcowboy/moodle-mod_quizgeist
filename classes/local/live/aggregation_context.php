<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Role-safe live aggregation context.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Controls correctness and live-data disclosure independently of qtype code.
 */
final class aggregation_context {

    public function __construct(
        public readonly bool $includecorrect,
        public readonly string $role = 'host',
        public readonly string $stage = 'answer',
        public readonly bool $live = false,
        public readonly string $visit = ''
    ) {
        if (!in_array($role, ['host', 'player'], true)
                || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $stage)
                || ($visit !== '' && !preg_match('/^[a-f0-9]{32}$/D', $visit))) {
            throw new \invalid_parameter_exception('Live aggregation context is invalid.');
        }
    }
}
