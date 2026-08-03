<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Public read API of one learner's Quizgeist practice state.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\parent;

use mod_quizgeist\local\licence\feature_gate;
use mod_quizgeist\local\schedule\schedule_repository;
use mod_quizgeist\local\schedule\schedule_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Viewer-agnostic practice digest for parent and tutor dashboards.
 *
 * mod_quizgeist deliberately delivers nothing and depends on nothing. It
 * publishes a stable read API and any authorised plugin — `local_elternkompass`
 * first — fetches it behind a `class_exists()` guard. That keeps both sides
 * installable alone, which is the only property that survives a school
 * uninstalling one of them.
 *
 * The contract is documented in `PARENT_API.md` and, by promise, never throws.
 */
final class digest {

    /** Highest number of competence rows returned. */
    private const MAX_COMPETENCES = 12;

    /** Days scanned backwards while counting the practice streak. */
    private const STREAK_WINDOW_DAYS = 400;

    /**
     * Summarise one learner's practice state.
     *
     * Intentionally viewer-agnostic so any authorised plugin can reuse the same
     * week, streak and competence calculation. Callers must perform their own
     * access check before passing a user id — this method performs none and
     * makes no assumption about who is asking.
     *
     * It never throws. A missing table, a missing addon, a learner without a
     * single answer: all of them return the empty form, so a dashboard tile can
     * call it without a guard of its own.
     *
     * The empty form is the CONTRACT, never the diagnosis. A broken statement
     * inside this plugin used to leave through the same door as "this learner
     * has not practised yet", and the two are indistinguishable from outside:
     * a duplicated SQL placeholder made every single call fail while the tile
     * kept reporting a tidy zero, and a counter-test passed for the wrong
     * reason ([P11-E11]). An internal failure therefore names itself through
     * `debugging()` as well, so a developer run turns it red instead of quiet.
     *
     * @param int $userid Learner whose practice state is aggregated.
     * @param int $courseid Restrict to one course; zero means every course.
     * @return array{
     *   hasData:bool, weeklyGoal:?int, weeklyDone:int, weeklyPercent:int,
     *   streakDays:int, dueCount:int, nextDue:?int,
     *   competences:array<int,array{key:string,label:string,color:?string,
     *                               percent:int,sample:int}>,
     *   generatedAt:int
     * }
     */
    public static function for_user(int $userid, int $courseid = 0): array {
        try {
            return self::build($userid, $courseid);
        } catch (\Throwable $exception) {
            // A parent dashboard must not break because a learning plugin had a
            // bad day. The empty form is the honest answer, and the reason is
            // written where an administrator can find it.
            $reason = get_class($exception) . ': ' . $exception->getMessage();
            error_log(
                '[mod_quizgeist] parent digest for user '
                . $userid
                . ' could not be built: '
                . $reason
            );
            // error_log() lands in a file nobody reads during development.
            // debugging() is the channel Moodle itself watches, and it is what
            // makes the masking visible instead of merely logged.
            debugging(
                "mod_quizgeist parent digest for user {$userid} fell back to "
                . "the empty form: {$reason}",
                DEBUG_DEVELOPER
            );
            return self::empty_digest();
        }
    }

    /**
     * The empty form, identical in shape to a populated digest.
     *
     * @return array
     */
    public static function empty_digest(): array {
        return [
            'hasData' => false,
            'weeklyGoal' => null,
            'weeklyDone' => 0,
            'weeklyPercent' => 0,
            'streakDays' => 0,
            'dueCount' => 0,
            'nextDue' => null,
            'competences' => [],
            'generatedAt' => time(),
        ];
    }

    /**
     * Build the digest for one learner.
     *
     * @param int $userid Learner.
     * @param int $courseid Course restriction.
     * @return array
     */
    private static function build(int $userid, int $courseid): array {
        global $DB;

        if ($userid <= 0 || !self::tables_exist()) {
            return self::empty_digest();
        }
        $user = $DB->get_record(
            'user',
            ['id' => $userid, 'deleted' => 0],
            'id, timezone',
            IGNORE_MISSING
        );
        if (!$user) {
            return self::empty_digest();
        }
        $activities = schedule_repository::learner_activities(
            $userid,
            max(0, $courseid)
        );
        if (!$activities) {
            return self::empty_digest();
        }

        $now = time();
        $timezone = \core_date::get_user_timezone_object($user->timezone ?? 99);
        [$weekstart, $weekend] = self::week_bounds($now, $timezone);

        $weeklygoal = null;
        $weeklydone = 0;
        $duecount = 0;
        $nextdue = null;
        $answertimes = [];
        foreach ($activities as $activity) {
            $quizgeistid = (int)$activity->id;
            $goal = schedule_repository::weekly_goal($quizgeistid, $userid);
            if ($goal !== null) {
                $weeklygoal = (int)$weeklygoal + $goal;
            }
            $weeklydone += schedule_repository::answered_roots_between(
                $quizgeistid,
                $userid,
                $weekstart,
                $weekend
            );
            $summary = schedule_service::summary($quizgeistid, $userid, $now);
            $duecount += (int)$summary['dueCount'];
            if ($summary['nextDue'] !== null
                    && ($nextdue === null || $summary['nextDue'] < $nextdue)) {
                $nextdue = (int)$summary['nextDue'];
            }
            foreach (schedule_repository::answer_times_since(
                $quizgeistid,
                $userid,
                $now - (self::STREAK_WINDOW_DAYS * DAYSECS)
            ) as $timecreated) {
                $answertimes[] = $timecreated;
            }
        }

        $streak = self::streak_days($answertimes, $now, $timezone);
        return [
            'hasData' => $weeklydone > 0
                || $duecount > 0
                || $streak > 0
                || $answertimes !== [],
            'weeklyGoal' => $weeklygoal,
            'weeklyDone' => $weeklydone,
            'weeklyPercent' => $weeklygoal !== null && $weeklygoal > 0
                ? min(100, (int)floor(100 * $weeklydone / $weeklygoal))
                : 0,
            'streakDays' => $streak,
            'dueCount' => $duecount,
            'nextDue' => $nextdue,
            'competences' => self::competences($activities, $userid),
            'generatedAt' => $now,
        ];
    }

    /**
     * Aggregate the learner's correctness per competence label.
     *
     * The competence axis belongs to the reports addon. Without its code the
     * digest still answers — with `competences => []` — instead of failing or
     * pretending. An expired licence changes nothing here: reading an existing
     * learning state is never a new creation.
     *
     * @param \stdClass[] $activities Learner activities.
     * @param int $userid Learner.
     * @return array
     */
    private static function competences(array $activities, int $userid): array {
        global $DB;

        if (!class_exists(feature_gate::class)
                || !feature_gate::allows('reports', feature_gate::VIEW_EXISTING)) {
            return [];
        }
        $quizgeistids = array_map(
            static fn(\stdClass $activity): int => (int)$activity->id,
            $activities
        );
        if (!$quizgeistids) {
            return [];
        }
        [$activitysql, $activityparams] = $DB->get_in_or_equal(
            $quizgeistids,
            SQL_PARAMS_NAMED,
            'digestactivity'
        );
        $records = $DB->get_records_sql(
            "SELECT t.id AS tagid, t.tagkey, t.label, t.colorkey,
                    COUNT(1) AS sample,
                    SUM(CASE WHEN a.iscorrect > 0 THEN 1 ELSE 0 END) AS correct
               FROM {quizgeist_answers} a
               JOIN {quizgeist_questions} q ON q.id = a.questionid
               JOIN {quizgeist_question_tags} qt
                 ON qt.rootid = CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END
                AND qt.status = :approved
               JOIN {quizgeist_tags} t ON t.id = qt.tagid AND t.kind = :competence
              WHERE a.userid = :userid
                AND a.iscorrect IS NOT NULL
                AND q.quizgeistid {$activitysql}
           GROUP BY t.id, t.tagkey, t.label, t.colorkey
           ORDER BY COUNT(1) DESC, t.label ASC, t.id ASC",
            [
                'userid' => $userid,
                'approved' => 'approved',
                'competence' => 'competence',
            ] + $activityparams,
            0,
            self::MAX_COMPETENCES
        );
        $competences = [];
        foreach ($records as $record) {
            $sample = (int)$record->sample;
            $competences[] = [
                'key' => (string)$record->tagkey,
                'label' => (string)$record->label,
                'color' => $record->colorkey !== null && $record->colorkey !== ''
                    ? (string)$record->colorkey
                    : null,
                'percent' => $sample > 0
                    ? (int)round(100 * (int)$record->correct / $sample)
                    : 0,
                'sample' => $sample,
            ];
        }
        return $competences;
    }

    /**
     * Count consecutive local calendar days that carry at least one answer.
     *
     * A streak stays alive while the learner practised today or yesterday, so a
     * dashboard opened before the afternoon's practice does not report a broken
     * streak that is not broken.
     *
     * @param int[] $answertimes Answer timestamps.
     * @param int $now Current timestamp.
     * @param \DateTimeZone $timezone Learner timezone.
     * @return int
     */
    private static function streak_days(
        array $answertimes,
        int $now,
        \DateTimeZone $timezone
    ): int {
        if (!$answertimes) {
            return 0;
        }
        $days = [];
        foreach ($answertimes as $timestamp) {
            $days[(new \DateTimeImmutable('@' . (int)$timestamp))
                ->setTimezone($timezone)
                ->format('Y-m-d')] = true;
        }
        $cursor = (new \DateTimeImmutable('@' . $now))
            ->setTimezone($timezone)
            ->setTime(0, 0, 0);
        if (!isset($days[$cursor->format('Y-m-d')])) {
            // Nothing practised today: yesterday may still carry the streak.
            $cursor = $cursor->modify('-1 day');
            if (!isset($days[$cursor->format('Y-m-d')])) {
                return 0;
            }
        }
        $streak = 0;
        while (isset($days[$cursor->format('Y-m-d')])
                && $streak < self::STREAK_WINDOW_DAYS) {
            $streak++;
            $cursor = $cursor->modify('-1 day');
        }
        return $streak;
    }

    /**
     * Local Monday boundaries of the current week.
     *
     * @param int $now Current timestamp.
     * @param \DateTimeZone $timezone Learner timezone.
     * @return array{0:int,1:int}
     */
    private static function week_bounds(int $now, \DateTimeZone $timezone): array {
        $moment = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);
        $monday = $moment->modify('monday this week')->setTime(0, 0, 0);
        return [$monday->getTimestamp(), $monday->modify('+7 days')->getTimestamp()];
    }

    /**
     * Whether every table this digest reads actually exists.
     *
     * Memoised for the request, exactly like the guard `local_elternkompass`
     * uses for foreign tables: a plugin that is half-upgraded must degrade, not
     * explode.
     *
     * @return bool
     */
    private static function tables_exist(): bool {
        global $DB;

        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        $manager = $DB->get_manager();
        $exists = true;
        foreach ([
            'quizgeist',
            'quizgeist_answers',
            'quizgeist_questions',
            'quizgeist_schedule',
        ] as $table) {
            if (!$manager->table_exists(new \xmldb_table($table))) {
                $exists = false;
                break;
            }
        }
        return $exists;
    }
}
