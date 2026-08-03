<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Custom completion rules for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\completion;

use core_completion\activity_custom_completion;

defined('MOODLE_INTERNAL') || die();

/**
 * Evaluate participation and percentage completion rules.
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetch the state of a configured completion rule.
     *
     * @param string $rule Rule identifier.
     * @return int Completion state constant.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);
        $quizgeist = $DB->get_record(
            'quizgeist',
            ['id' => $this->cm->instance],
            'id,grademethod,completionparticipate,completionpercent',
            MUST_EXIST
        );

        if ($rule === 'completionparticipate') {
            if (empty($quizgeist->completionparticipate)) {
                return COMPLETION_INCOMPLETE;
            }

            $liveparticipation = $DB->record_exists_sql(
                "SELECT 1
                   FROM {quizgeist_players} p
                   JOIN {quizgeist_sessions} s ON s.id = p.sessionid
                  WHERE s.quizgeistid = :quizgeistid
                    AND p.userid = :userid",
                ['quizgeistid' => $quizgeist->id, 'userid' => $this->userid]
            );
            $selfstudyparticipation = $DB->record_exists_sql(
                "SELECT 1
                   FROM {quizgeist_attempts} a
                   JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
                  WHERE z.quizgeistid = :quizgeistid
                    AND a.userid = :userid",
                ['quizgeistid' => $quizgeist->id, 'userid' => $this->userid]
            );

            return ($liveparticipation || $selfstudyparticipation)
                ? COMPLETION_COMPLETE
                : COMPLETION_INCOMPLETE;
        }

        $requiredpercent = (int)$quizgeist->completionpercent;
        if ($requiredpercent <= 0) {
            return COMPLETION_INCOMPLETE;
        }

        $summary = \mod_quizgeist\local\selfstudy\grade_calculator::summary(
            $quizgeist,
            (int)$this->userid
        );
        return $summary['percent'] !== null
                && (float)$summary['percent'] >= $requiredpercent
            ? COMPLETION_COMPLETE
            : COMPLETION_INCOMPLETE;
    }

    /**
     * List all custom rules defined by Quizgeist.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionparticipate', 'completionpercent'];
    }

    /**
     * Describe active rules in the activity completion UI.
     *
     * @return array<string, string>
     */
    public function get_custom_rule_descriptions(): array {
        global $DB;

        $quizgeist = $DB->get_record(
            'quizgeist',
            ['id' => $this->cm->instance],
            'completionpercent',
            MUST_EXIST
        );

        return [
            'completionparticipate' => get_string('completiondetail:participate', 'mod_quizgeist'),
            'completionpercent' => get_string(
                'completiondetail:percent',
                'mod_quizgeist',
                (int)$quizgeist->completionpercent
            ),
        ];
    }

    /**
     * Define display order for completion rules.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionparticipate',
            'completionpercent',
            'completionusegrade',
            'completionpassgrade',
        ];
    }
}
