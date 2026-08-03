<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Minimal course-wide Geistopia projection.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\topia;

defined('MOODLE_INTERNAL') || die();

/**
 * Derives shared scenery from completed course sessions; it grants no currency.
 */
final class course_progress {

    /** @var array<string,int> Stable decoration thresholds. */
    private const ROADMAP = [
        'landing' => 1,
        'garden' => 10,
        'lighthouse' => 25,
    ];

    /**
     * Return the aggregate course projection visible to the current user.
     *
     * @param int $courseid Course ID.
     * @return array
     */
    public static function get(int $courseid): array {
        global $DB, $USER;

        $visibleinstances = [];
        $modinfo = get_fast_modinfo($courseid, (int)$USER->id);
        foreach ($modinfo->get_instances_of('quizgeist') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $context = \context_module::instance((int)$cm->id);
            if (!has_capability('mod/quizgeist:view', $context, (int)$USER->id)) {
                continue;
            }
            $visibleinstances[] = (int)$cm->instance;
        }

        $stars = 0;
        if ($visibleinstances) {
            [$instancesql, $params] = $DB->get_in_or_equal(
                array_values(array_unique($visibleinstances)),
                SQL_PARAMS_NAMED,
                'topiainstance'
            );
            $params['topiaended'] = 'ended';
            // One durably ended live session in an accessible activity contributes
            // one shared course star. Lobby and aborted sessions never contribute.
            $stars = (int)$DB->count_records_sql(
                "SELECT COUNT(s.id)
                   FROM {quizgeist_sessions} s
                  WHERE s.quizgeistid {$instancesql}
                    AND s.status = :topiaended",
                $params
            );
        }
        $unlocks = [];
        foreach (self::ROADMAP as $key => $threshold) {
            if ($stars >= $threshold) {
                $unlocks[] = $key;
            }
        }

        $roadmap = [];
        foreach (self::ROADMAP as $key => $threshold) {
            $roadmap[] = [
                'key' => $key,
                'threshold' => $threshold,
                'unlocked' => $stars >= $threshold,
            ];
        }
        return [
            'stars' => $stars,
            'unlocks' => $unlocks,
            'roadmap' => $roadmap,
        ];
    }
}
