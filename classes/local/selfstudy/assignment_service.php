<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Self-study assignment lifecycle and immutable question snapshots.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\selfstudy;

use mod_quizgeist\local\editor\edit_conflict_exception;
use mod_quizgeist\local\editor\question_content_lock;
use mod_quizgeist\local\live\answer_evaluator;
use mod_quizgeist\local\live\qtype\registry as question_type_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns teacher assignment CRUD while question content stays immutable.
 */
final class assignment_service {

    /**
     * List every assignment for the activity without personal attempt data.
     *
     * @param \stdClass $quizgeist Activity record.
     * @return array
     */
    public static function list_assignments(\stdClass $quizgeist): array {
        global $DB;

        $records = $DB->get_records(
            'quizgeist_assignments',
            ['quizgeistid' => (int)$quizgeist->id],
            'timecreated DESC, id DESC'
        );
        $result = [];
        foreach ($records as $record) {
            $result[] = self::dto($record, true);
        }
        $readyquestions = $DB->get_records(
            'quizgeist_questions',
            ['quizgeistid' => (int)$quizgeist->id, 'status' => 'ready'],
            'sortorder ASC, id ASC'
        );
        return [
            'assignments' => $result,
            'readyQuestionCount' => count($readyquestions),
            'readyMultistageQuestionCount' =>
                self::multistage_question_count($readyquestions),
            'serverTimeMs' => time() * 1000,
        ];
    }

    /**
     * Create an assignment and freeze exact ready question versions atomically.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \stdClass $user Creating teacher.
     * @param array $data Validated-shape request data.
     * @return array
     */
    public static function create(
        \stdClass $quizgeist,
        \stdClass $user,
        array $data
    ): array {
        global $DB;

        $name = self::name($data['name'] ?? null);
        $mode = assignment_settings::mode($data['mode'] ?? 'solo');
        // [P11-C4-O4]: `assignment_settings::AI_MODES` was data without a
        // consumer, so a school could create a speaking assignment that could
        // never work — an empty room a class gets sent into. This is that
        // consumer, and it sits in the CREATION path only: an existing
        // speaking assignment stays playable, assessable and exportable when
        // a licence expires (2.6). The check runs before anything is written.
        if (in_array($mode, assignment_settings::AI_MODES, true)) {
            \mod_quizgeist\local\licence\feature_gate::require(
                'ai',
                \mod_quizgeist\local\licence\feature_gate::CREATE_NEW
            );
        }
        $selection = assignment_settings::selection($data['selection'] ?? 'fixed');
        $status = assignment_settings::status($data['status'] ?? 'open');
        $timeopen = self::timestamp($data['timeOpen'] ?? 0, 'timeOpen');
        $timedue = self::timestamp($data['timeDue'] ?? 0, 'timeDue');
        self::validate_times($timeopen, $timedue);
        $settings = assignment_settings::create(
            $data['settings'] ?? [],
            $timedue
        );
        $requestedids = self::question_ids($data['questionIds'] ?? null);

        $contentlocks = question_content_lock::acquire_for_activity(
            (int)$quizgeist->id
        );
        try {
            $transaction = \mod_quizgeist\local\transaction_scope::begin();
            try {
                $questions = self::lock_snapshot_questions(
                    (int)$quizgeist->id,
                    $requestedids
                );
                $now = time();
                $assignmentid = (int)$DB->insert_record(
                    'quizgeist_assignments',
                    (object)[
                        'quizgeistid' => (int)$quizgeist->id,
                        'name' => $name,
                        'mode' => $mode,
                        'status' => $status,
                        'timeopen' => $timeopen,
                        'timedue' => $timedue,
                        'createdby' => (int)$user->id,
                        'selection' => $selection,
                        'settingsjson' => self::encode_settings($settings),
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]
                );
                $seenroots = [];
                $sortindex = 0;
                foreach ($questions as $question) {
                    $rootid = (int)($question->rootid ?? 0) > 0
                        ? (int)$question->rootid
                        : (int)$question->id;
                    if ($selection === 'due') {
                        // F3: a repetition assignment freezes ROOTS. Two
                        // versions of the same question are one entry here,
                        // and the version is resolved when an attempt starts.
                        if (isset($seenroots[$rootid])) {
                            continue;
                        }
                        $seenroots[$rootid] = true;
                    }
                    $DB->insert_record(
                        'quizgeist_assignment_questions',
                        (object)[
                            'assignmentid' => $assignmentid,
                            'sortindex' => $sortindex,
                            'questionid' => (int)$question->id,
                            'rootid' => $rootid,
                        ]
                    );
                    $sortindex++;
                }
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            question_content_lock::release_all($contentlocks);
        }

        return self::list_assignments($quizgeist);
    }

    /**
     * Update mutable assignment metadata without changing its frozen questions.
     *
     * @param \stdClass $quizgeist Activity.
     * @param int $assignmentid Assignment ID.
     * @param array $data Request data.
     * @return array
     */
    public static function update(
        \stdClass $quizgeist,
        int $assignmentid,
        array $data
    ): array {
        global $DB;

        $gradepolicychanged = false;
        $affecteduserids = [];
        $ambienttransaction = $DB->is_transaction_started();
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $assignment = self::locked_assignment(
                (int)$quizgeist->id,
                $assignmentid
            );
            $expectedmodified = self::required_modified_token($data);
            if ((int)$assignment->timemodified !== $expectedmodified) {
                throw new edit_conflict_exception(
                    'assignment',
                    self::dto($assignment, true)
                );
            }
            $update = (object)['id' => (int)$assignment->id];
            $hasattempts = (
                array_key_exists('mode', $data)
                || array_key_exists('status', $data)
            ) && $DB->record_exists(
                'quizgeist_attempts',
                ['assignmentid' => (int)$assignment->id]
            );
            if (array_key_exists('name', $data)) {
                $update->name = self::name($data['name']);
            }
            if (array_key_exists('mode', $data)) {
                $mode = assignment_settings::mode($data['mode']);
                if ($mode !== (string)$assignment->mode
                        && $hasattempts) {
                    throw new \invalid_parameter_exception(
                        'The mode of an attempted assignment is immutable.'
                    );
                }
                $update->mode = $mode;
            }
            if (array_key_exists('status', $data)) {
                $update->status = assignment_settings::transition(
                    $assignment->status,
                    $data['status'],
                    $hasattempts
                );
            }
            $timeopen = array_key_exists('timeOpen', $data)
                ? self::timestamp($data['timeOpen'], 'timeOpen')
                : (int)$assignment->timeopen;
            $timedue = array_key_exists('timeDue', $data)
                ? self::timestamp($data['timeDue'], 'timeDue')
                : (int)$assignment->timedue;
            self::validate_times($timeopen, $timedue);
            if (array_key_exists('timeOpen', $data)) {
                $update->timeopen = $timeopen;
            }
            if (array_key_exists('timeDue', $data)) {
                $update->timedue = $timedue;
            }
            $oldsettings = assignment_settings::decode(
                $assignment->settingsjson ?? null,
                (int)$assignment->timedue
            );
            if (array_key_exists('settings', $data)) {
                $settingspatch = $data['settings'];
                if ($settingspatch === null) {
                    $settingspatch = [];
                }
                if (!is_array($settingspatch)
                        || ($settingspatch !== [] && array_is_list($settingspatch))) {
                    throw new \invalid_parameter_exception(
                        'settings must be an object.'
                    );
                }
                $update->settingsjson = self::encode_settings(
                    assignment_settings::create(
                        array_replace($oldsettings, $settingspatch),
                        $timedue
                    )
                );
            } else if ($timedue !== (int)$assignment->timedue) {
                $deadlinepolicy = $oldsettings;
                $deadlinepolicy['reminderEnabled'] = $timedue > 0;
                $update->settingsjson = self::encode_settings(
                    $deadlinepolicy
                );
            }
            $newsettings = isset($update->settingsjson)
                ? assignment_settings::decode($update->settingsjson, $timedue)
                : assignment_settings::decode(
                    $assignment->settingsjson ?? null,
                    $timedue
                );
            $deadlinechanged = $timedue !== (int)$assignment->timedue
                || $oldsettings['reminderEnabled']
                    !== $newsettings['reminderEnabled'];
            $gradepolicychanged =
                $oldsettings['countsTowardsGrade']
                    !== $newsettings['countsTowardsGrade'];
            if ($gradepolicychanged) {
                $affecteduserids = array_map(
                    'intval',
                    $DB->get_fieldset_sql(
                        'SELECT DISTINCT userid
                           FROM {quizgeist_attempts}
                          WHERE assignmentid = :assignmentid',
                        ['assignmentid' => (int)$assignment->id]
                    )
                );
            }
            if (count((array)$update) > 1) {
                $update->timemodified = max(
                    time(),
                    (int)$assignment->timemodified + 1
                );
                $DB->update_record('quizgeist_assignments', $update);
            }
            if ($deadlinechanged) {
                $DB->delete_records(
                    'quizgeist_assignment_reminders',
                    ['assignmentid' => (int)$assignment->id]
                );
            }
            if ($gradepolicychanged) {
                foreach ($affecteduserids as $userid) {
                    attempt_submission_service::queue_grade_synchronisation(
                        (int)$quizgeist->id,
                        $userid
                    );
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        if ($gradepolicychanged && $affecteduserids) {
            $cm = get_coursemodule_from_instance(
                'quizgeist',
                (int)$quizgeist->id,
                (int)$quizgeist->course,
                false,
                MUST_EXIST
            );
            foreach ($affecteduserids as $userid) {
                try {
                    attempt_submission_service::
                        synchronise_grade_and_completion(
                            $cm,
                            $quizgeist,
                            $userid
                        );
                } catch (\Throwable $exception) {
                    if ($ambienttransaction) {
                        // The caller owns atomicity here; a failed synchronous
                        // side effect must invalidate that whole operation.
                        throw $exception;
                    }
                    // The policy change is already durable. One broken grade
                    // or completion record must not prevent every later
                    // learner from being recalculated or make the successful
                    // metadata update look like an edit failure.
                    // This runs in an AJAX edit request; do not corrupt its
                    // JSON payload with mtrace/debugging output.
                    error_log(
                        '[mod_quizgeist] could not synchronise the changed '
                        . 'assignment grade policy for user '
                        . $userid
                        . ': '
                        . $exception->getMessage()
                    );
                }
            }
        }

        $assignment = $DB->get_record(
            'quizgeist_assignments',
            ['id' => $assignmentid, 'quizgeistid' => (int)$quizgeist->id],
            '*',
            MUST_EXIST
        );
        return self::list_assignments($quizgeist);
    }

    /**
     * Close an assignment while preserving every attempt and snapshot.
     *
     * @param \stdClass $quizgeist Activity.
     * @param int $assignmentid Assignment ID.
     * @return array
     */
    public static function close(
        \stdClass $quizgeist,
        int $assignmentid,
        int $expectedmodified
    ): array {
        return self::update($quizgeist, $assignmentid, [
            'status' => 'closed',
            'timeModified' => $expectedmodified,
        ]);
    }

    /**
     * Build a public teacher-facing assignment DTO.
     *
     * @param \stdClass $assignment Assignment record.
     * @param bool $includequestionids Include exact snapshot IDs.
     * @return array
     */
    public static function dto(
        \stdClass $assignment,
        bool $includequestionids = false
    ): array {
        global $DB;

        $questions = $DB->get_records_sql(
            'SELECT q.*
               FROM {quizgeist_assignment_questions} aq
               JOIN {quizgeist_questions} q ON q.id = aq.questionid
              WHERE aq.assignmentid = :assignmentid
           ORDER BY aq.sortindex ASC',
            ['assignmentid' => (int)$assignment->id]
        );
        $questionids = array_map('intval', array_keys($questions));
        $participantcount = (int)$DB->count_records_sql(
            'SELECT COUNT(DISTINCT userid)
               FROM {quizgeist_attempts}
              WHERE assignmentid = :assignmentid',
            ['assignmentid' => (int)$assignment->id]
        );
        $dto = [
            'attemptId' => null,
            'completed' => false,
            'id' => (int)$assignment->id,
            'name' => (string)$assignment->name,
            'mode' => (string)$assignment->mode,
            'selection' => (string)($assignment->selection ?? 'fixed'),
            'status' => (string)$assignment->status,
            'timeOpenMs' => (int)$assignment->timeopen * 1000,
            'timeDueMs' => (int)$assignment->timedue * 1000,
            'settings' => assignment_settings::decode(
                $assignment->settingsjson ?? null,
                (int)$assignment->timedue
            ),
            'progress' => [
                'answered' => 0,
                'total' => count($questionids),
            ],
            'questionCount' => count($questionids),
            'multistageQuestionCount' =>
                self::multistage_question_count($questions),
            'participantCount' => $participantcount,
            'timeCreated' => (int)$assignment->timecreated,
            'timeModified' => (int)$assignment->timemodified,
        ];
        if ($includequestionids) {
            $dto['questionIds'] = $questionids;
        }
        return $dto;
    }

    /**
     * Count questions whose declarative interaction policy has several stages.
     *
     * @param \stdClass[] $questions Persisted question records.
     * @return int
     */
    private static function multistage_question_count(array $questions): int {
        static $cache = [];

        $count = 0;
        foreach ($questions as $question) {
            $cachekey = (int)$question->id . ':'
                . (int)($question->timemodified ?? 0);
            if (!array_key_exists($cachekey, $cache)) {
                $canonical = answer_evaluator::canonical_question($question);
                $policy = question_type_registry::get(
                    (string)$canonical['qtype']
                )->policy($canonical);
                $cache[$cachekey] = $policy->next_stage(
                    $policy->initial_stage()
                ) !== null;
            }
            if ($cache[$cachekey]) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Lock and return one activity-owned assignment.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $assignmentid Assignment ID.
     * @return \stdClass
     */
    private static function locked_assignment(
        int $quizgeistid,
        int $assignmentid
    ): \stdClass {
        global $DB;

        return $DB->get_record_sql(
            'SELECT *
               FROM {quizgeist_assignments}
              WHERE id = :id
                AND quizgeistid = :quizgeistid
                    FOR UPDATE',
            ['id' => $assignmentid, 'quizgeistid' => $quizgeistid],
            MUST_EXIST
        );
    }

    /**
     * Lock and order the exact ready question rows for a new snapshot.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[]|null $requestedids Explicit order, or all activity questions.
     * @return \stdClass[]
     */
    private static function lock_snapshot_questions(
        int $quizgeistid,
        ?array $requestedids
    ): array {
        global $DB;

        $questions = $DB->get_records_sql(
            'SELECT *
               FROM {quizgeist_questions}
              WHERE quizgeistid = :quizgeistid
                AND status = :ready
           ORDER BY sortorder ASC, id ASC
                FOR UPDATE',
            ['quizgeistid' => $quizgeistid, 'ready' => 'ready']
        );
        if (!$questions) {
            throw new \invalid_parameter_exception(
                'The assignment needs at least one ready question.'
            );
        }
        $supported = question_type_registry::types();
        $byid = [];
        foreach ($questions as $question) {
            $byid[(int)$question->id] = $question;
        }
        $ordered = [];
        $ids = $requestedids ?? array_keys($byid);
        foreach ($ids as $id) {
            $question = $byid[$id] ?? null;
            if (!$question
                    || (string)$question->status !== 'ready'
                    || !in_array((string)$question->qtype, $supported, true)) {
                throw new \invalid_parameter_exception(
                    'A selected question is not ready for self-study.'
                );
            }
            $ordered[] = $question;
        }
        if (!$ordered) {
            throw new \invalid_parameter_exception(
                'The assignment needs at least one ready question.'
            );
        }
        return $ordered;
    }

    /**
     * Validate an optional exact question order.
     *
     * @param mixed $raw Raw list.
     * @return int[]|null
     */
    private static function question_ids($raw): ?array {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !array_is_list($raw) || !$raw) {
            throw new \invalid_parameter_exception('questionIds must be a non-empty list.');
        }
        $ids = [];
        foreach ($raw as $value) {
            if (!is_int($value) || $value <= 0 || isset($ids[$value])) {
                throw new \invalid_parameter_exception('questionIds is invalid.');
            }
            $ids[$value] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * Validate a bounded plain assignment name.
     *
     * @param mixed $raw Raw value.
     * @return string
     */
    private static function name($raw): string {
        if (!is_string($raw)) {
            throw new \invalid_parameter_exception('name is required.');
        }
        $name = trim(clean_param($raw, PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new \invalid_parameter_exception('name is invalid.');
        }
        return $name;
    }

    /**
     * Strictly validate a non-negative integer timestamp.
     *
     * @param mixed $raw Raw value.
     * @param string $field Field name.
     * @return int
     */
    private static function timestamp($raw, string $field): int {
        if (is_string($raw) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw)) {
            $raw = (int)$raw;
        }
        if (!is_int($raw) || $raw < 0) {
            throw new \invalid_parameter_exception("{$field} is invalid.");
        }
        return $raw;
    }

    /**
     * Ensure a real deadline follows an explicit opening time.
     *
     * @param int $timeopen Opening timestamp.
     * @param int $timedue Due timestamp.
     * @return void
     */
    private static function validate_times(int $timeopen, int $timedue): void {
        if ($timedue > 0 && $timeopen > 0 && $timedue <= $timeopen) {
            throw new \invalid_parameter_exception(
                'timeDue must be later than timeOpen.'
            );
        }
    }

    /**
     * Require the teacher's optimistic assignment timestamp.
     *
     * @param array $data Request assignment object.
     * @return int
     */
    private static function required_modified_token(array $data): int {
        $raw = $data['timeModified'] ?? null;
        if (is_string($raw) && preg_match('/^[1-9][0-9]*$/D', $raw)) {
            $raw = (int)$raw;
        }
        if (!is_int($raw) || $raw <= 0) {
            throw new \invalid_parameter_exception(
                'timeModified is required.'
            );
        }
        return $raw;
    }

    /**
     * Encode already canonical settings without changing deadline defaults.
     *
     * @param array $settings Canonical settings.
     * @return string
     */
    private static function encode_settings(array $settings): string {
        return json_encode(
            $settings,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
    }
}
