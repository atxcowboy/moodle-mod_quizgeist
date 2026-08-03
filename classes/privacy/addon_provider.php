<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Shared privacy declaration for code-only Quizgeist addons.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Addons own no separate stores; the base provider owns every shared table.
 */
abstract class addon_provider implements
        \core_privacy\local\metadata\null_provider {

    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
