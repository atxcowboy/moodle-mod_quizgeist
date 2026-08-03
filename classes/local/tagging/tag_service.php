<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Validation and transactional writes for the tagging core.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\tagging;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns every tagging write.
 *
 * The single load-bearing rule of this file: an assignment is bound to
 * quizgeist_questions.rootid, never to the version ID. Questions are
 * versioned; every edit inserts a new row and keeps the root. A tag on the
 * version ID would be gone after the first edit.
 */
final class tag_service {

    /**
     * Serialise one tag for the client.
     *
     * @param \stdClass $record Tag row.
     * @return array
     */
    public static function serialise_tag(\stdClass $record): array {
        return [
            'id' => (int)$record->id,
            'scope' => (string)$record->scope,
            'scopeId' => (int)$record->scopeid,
            'kind' => (string)$record->kind,
            'tagKey' => (string)$record->tagkey,
            'label' => (string)$record->label,
            'colorKey' => $record->colorkey === null
                ? null
                : (string)$record->colorkey,
            'externalRef' => $record->externalref === null
                ? null
                : (string)$record->externalref,
            'sortOrder' => (int)$record->sortorder,
            'reserved' => tag_schema::is_reserved_new(
                (string)$record->kind,
                (string)$record->tagkey
            ),
        ];
    }

    /**
     * Serialise one assignment row for the client.
     *
     * @param \stdClass $record Joined assignment row.
     * @return array
     */
    public static function serialise_assignment(\stdClass $record): array {
        return [
            'tagId' => (int)$record->tagid,
            'rootId' => (int)$record->rootid,
            'weight' => (int)$record->weight,
            'status' => (string)$record->status,
            'kind' => (string)$record->kind,
            'tagKey' => (string)$record->tagkey,
            'label' => (string)$record->label,
            'colorKey' => $record->colorkey === null
                ? null
                : (string)$record->colorkey,
        ];
    }

    /**
     * Project the complete tagging read model of one activity.
     *
     * @param \stdClass $quizgeist Activity record.
     * @return array
     */
    public static function project_activity(\stdClass $quizgeist): array {
        $tags = tag_repository::visible_tags(
            (int)$quizgeist->id,
            (int)$quizgeist->course
        );
        $byroot = tag_repository::assignments_by_root((int)$quizgeist->id);
        $assignments = [];
        foreach ($byroot as $rootid => $rows) {
            $assignments[] = [
                'rootId' => (int)$rootid,
                'tags' => array_map(
                    [self::class, 'serialise_assignment'],
                    $rows
                ),
            ];
        }
        return [
            'tags' => array_map([self::class, 'serialise_tag'], $tags),
            'assignments' => $assignments,
            'kinds' => tag_schema::KINDS,
            'scopes' => tag_schema::SCOPES,
            'reserved' => [
                'kind' => tag_schema::RESERVED_NEW_KIND,
                'tagKey' => tag_schema::RESERVED_NEW_KEY,
            ],
        ];
    }

    /**
     * Create or update one tag.
     *
     * The merge identity is (scope, scopeid, kind, tagkey). Saving a tag whose
     * identity already exists updates that row instead of failing on the
     * unique index or creating a near-duplicate.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param array $input Raw client payload.
     * @return array{tag:array,validationErrors:array}
     */
    public static function save_tag(\stdClass $quizgeist, array $input): array {
        global $DB;

        $normalised = tag_schema::normalise($input);
        $tag = $normalised['tag'];
        $errors = $normalised['validationErrors'];
        // The scope owner is never taken from the client: an activity tag
        // always belongs to this activity, a course tag to its course.
        if ($tag['scope'] === 'activity') {
            $tag['scopeid'] = (int)$quizgeist->id;
        } else if ($tag['scope'] === 'course') {
            $tag['scopeid'] = (int)$quizgeist->course;
        } else {
            $tag['scopeid'] = 0;
        }
        $errors = array_values(array_filter(
            $errors,
            static fn(array $error): bool => $error['field'] !== 'scopeid'
        ));
        if ($errors) {
            return ['tag' => $tag, 'validationErrors' => $errors];
        }

        $now = time();
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $existing = tag_repository::find_by_identity(
                $tag['scope'],
                $tag['scopeid'],
                $tag['kind'],
                $tag['tagkey']
            );
            if ($existing !== null) {
                $DB->update_record('quizgeist_tags', (object)[
                    'id' => (int)$existing->id,
                    'label' => $tag['label'],
                    'colorkey' => $tag['colorkey'],
                    'externalref' => $tag['externalref'],
                    'sortorder' => $tag['sortorder'],
                    'timemodified' => $now,
                ]);
                $tag['id'] = (int)$existing->id;
            } else {
                $tag['id'] = (int)$DB->insert_record('quizgeist_tags', (object)[
                    'scope' => $tag['scope'],
                    'scopeid' => $tag['scopeid'],
                    'kind' => $tag['kind'],
                    'tagkey' => $tag['tagkey'],
                    'label' => $tag['label'],
                    'colorkey' => $tag['colorkey'],
                    'externalref' => $tag['externalref'],
                    'sortorder' => $tag['sortorder'],
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
        return ['tag' => $tag, 'validationErrors' => []];
    }

    /**
     * Ensure the reserved error-friendly system tag exists for one activity.
     *
     * @param \stdClass $quizgeist Activity record.
     * @return int Tag ID.
     */
    public static function ensure_reserved_new_tag(\stdClass $quizgeist): int {
        global $DB;

        $existing = tag_repository::find_by_identity(
            'activity',
            (int)$quizgeist->id,
            tag_schema::RESERVED_NEW_KIND,
            tag_schema::RESERVED_NEW_KEY
        );
        if ($existing !== null) {
            return (int)$existing->id;
        }
        $now = time();
        return (int)$DB->insert_record('quizgeist_tags', (object)[
            'scope' => 'activity',
            'scopeid' => (int)$quizgeist->id,
            'kind' => tag_schema::RESERVED_NEW_KIND,
            'tagkey' => tag_schema::RESERVED_NEW_KEY,
            'label' => get_string('tag:reserved:new', 'mod_quizgeist'),
            'colorkey' => null,
            'externalref' => null,
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Replace the complete tag assignment list of one question root.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param int $questionid Any exact question version of the lineage.
     * @param mixed $rawassignments Raw client list.
     * @param int $userid Acting user.
     * @param string $status approved (teacher) or suggested (workshop).
     * @return array{rootId:int,tags:array,validationErrors:array}
     */
    public static function save_question_tags(
        \stdClass $quizgeist,
        int $questionid,
        $rawassignments,
        int $userid,
        string $status = 'approved'
    ): array {
        global $DB;

        if (!in_array($status, tag_schema::ASSIGNMENT_STATUSES, true)) {
            throw new \coding_exception('Unknown Quizgeist tag assignment status.');
        }
        $rootid = tag_repository::root_of_question((int)$quizgeist->id, $questionid);
        if ($rootid <= 0) {
            throw new \invalid_parameter_exception('The tagged question is unavailable.');
        }
        $normalised = tag_schema::normalise_assignments($rawassignments);
        if ($normalised['validationErrors']) {
            return [
                'rootId' => $rootid,
                'tags' => array_map(
                    [self::class, 'serialise_assignment'],
                    tag_repository::assignments_for_root($rootid)
                ),
                'validationErrors' => $normalised['validationErrors'],
            ];
        }

        $errors = [];
        $allowed = [];
        foreach (
            tag_repository::visible_tags(
                (int)$quizgeist->id,
                (int)$quizgeist->course
            ) as $tag
        ) {
            $allowed[(int)$tag->id] = true;
        }
        $accepted = [];
        foreach ($normalised['assignments'] as $index => $assignment) {
            if (!isset($allowed[$assignment['tagId']])) {
                // A tag outside this activity's visible scopes is refused
                // rather than silently attached across course boundaries.
                $errors[] = [
                    'field' => 'assignments.' . $index . '.tagId',
                    'code' => 'invalid',
                ];
                continue;
            }
            $accepted[$assignment['tagId']] = $assignment['weight'];
        }
        if ($errors) {
            return [
                'rootId' => $rootid,
                'tags' => array_map(
                    [self::class, 'serialise_assignment'],
                    tag_repository::assignments_for_root($rootid)
                ),
                'validationErrors' => $errors,
            ];
        }

        $now = time();
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $current = $DB->get_records(
                'quizgeist_question_tags',
                ['rootid' => $rootid],
                'id ASC'
            );
            $keep = [];
            foreach ($current as $record) {
                $tagid = (int)$record->tagid;
                if (!array_key_exists($tagid, $accepted)) {
                    $DB->delete_records(
                        'quizgeist_question_tags',
                        ['id' => (int)$record->id]
                    );
                    continue;
                }
                $keep[$tagid] = true;
                if ((int)$record->weight !== $accepted[$tagid]
                        || (string)$record->status !== $status) {
                    $DB->update_record('quizgeist_question_tags', (object)[
                        'id' => (int)$record->id,
                        'weight' => $accepted[$tagid],
                        'status' => $status,
                    ]);
                }
            }
            foreach ($accepted as $tagid => $weight) {
                if (isset($keep[$tagid])) {
                    continue;
                }
                $DB->insert_record('quizgeist_question_tags', (object)[
                    'quizgeistid' => (int)$quizgeist->id,
                    'rootid' => $rootid,
                    'tagid' => $tagid,
                    'weight' => $weight,
                    'status' => $status,
                    'createdby' => $userid > 0 ? $userid : null,
                    'timecreated' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        return [
            'rootId' => $rootid,
            'tags' => array_map(
                [self::class, 'serialise_assignment'],
                tag_repository::assignments_for_root($rootid)
            ),
            'validationErrors' => [],
        ];
    }

    /**
     * Add learner tag SUGGESTIONS without touching approved assignments.
     *
     * save_question_tags() replaces the complete list — correct for a teacher,
     * destructive for a learner. This path is therefore purely additive: it
     * can create and remove rows with status `suggested` and never sees an
     * approved one. A suggestion is inert until approve_suggestions() runs
     * (P11_PLAN.md, decision E-4; [P11-C1-O2]).
     *
     * @param \stdClass $quizgeist Activity record.
     * @param int $questionid Any exact question version of the lineage.
     * @param mixed $rawassignments Raw client list.
     * @param int $userid Suggesting learner.
     * @return array{rootId:int,tags:array,validationErrors:array}
     */
    public static function suggest_question_tags(
        \stdClass $quizgeist,
        int $questionid,
        $rawassignments,
        int $userid
    ): array {
        global $DB;

        $rootid = tag_repository::root_of_question((int)$quizgeist->id, $questionid);
        if ($rootid <= 0) {
            throw new \invalid_parameter_exception('The tagged question is unavailable.');
        }
        $normalised = tag_schema::normalise_assignments($rawassignments);
        $errors = $normalised['validationErrors'];
        $allowed = [];
        foreach (
            tag_repository::visible_tags(
                (int)$quizgeist->id,
                (int)$quizgeist->course
            ) as $tag
        ) {
            $allowed[(int)$tag->id] = true;
        }
        $accepted = [];
        if (!$errors) {
            foreach ($normalised['assignments'] as $index => $assignment) {
                if (!isset($allowed[$assignment['tagId']])) {
                    $errors[] = [
                        'field' => 'assignments.' . $index . '.tagId',
                        'code' => 'invalid',
                    ];
                    continue;
                }
                $accepted[$assignment['tagId']] = $assignment['weight'];
            }
        }
        if ($errors) {
            return [
                'rootId' => $rootid,
                'tags' => array_map(
                    [self::class, 'serialise_assignment'],
                    tag_repository::assignments_for_root($rootid)
                ),
                'validationErrors' => $errors,
            ];
        }

        $now = time();
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $current = $DB->get_records(
                'quizgeist_question_tags',
                ['rootid' => $rootid],
                'id ASC'
            );
            $keep = [];
            foreach ($current as $record) {
                $tagid = (int)$record->tagid;
                if ((string)$record->status !== 'suggested') {
                    // An approved assignment is course content. A learner
                    // cannot remove it and cannot re-suggest it either.
                    $keep[$tagid] = true;
                    unset($accepted[$tagid]);
                    continue;
                }
                if (!array_key_exists($tagid, $accepted)) {
                    $DB->delete_records(
                        'quizgeist_question_tags',
                        ['id' => (int)$record->id]
                    );
                    continue;
                }
                $keep[$tagid] = true;
                if ((int)$record->weight !== $accepted[$tagid]) {
                    $DB->update_record('quizgeist_question_tags', (object)[
                        'id' => (int)$record->id,
                        'weight' => $accepted[$tagid],
                    ]);
                }
            }
            foreach ($accepted as $tagid => $weight) {
                if (isset($keep[$tagid])) {
                    continue;
                }
                $DB->insert_record('quizgeist_question_tags', (object)[
                    'quizgeistid' => (int)$quizgeist->id,
                    'rootid' => $rootid,
                    'tagid' => $tagid,
                    'weight' => $weight,
                    'status' => 'suggested',
                    'createdby' => $userid > 0 ? $userid : null,
                    'timecreated' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        return [
            'rootId' => $rootid,
            'tags' => array_map(
                [self::class, 'serialise_assignment'],
                tag_repository::assignments_for_root($rootid)
            ),
            'validationErrors' => [],
        ];
    }

    /**
     * Confirm every learner suggestion on one question root.
     *
     * This is the release path E-4 asks for and the reason the capability
     * mod/quizgeist:suggesttag is not inert. It is called by the curation of
     * the question workshop, which itself requires mod/quizgeist:manage.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @return int Number of confirmed suggestions.
     */
    public static function approve_suggestions(int $quizgeistid, int $rootid): int {
        global $DB;

        if ($quizgeistid <= 0 || $rootid <= 0) {
            return 0;
        }
        $suggestions = $DB->get_records('quizgeist_question_tags', [
            'quizgeistid' => $quizgeistid,
            'rootid' => $rootid,
            'status' => 'suggested',
        ], 'id ASC', 'id');
        if (!$suggestions) {
            return 0;
        }
        $DB->set_field_select(
            'quizgeist_question_tags',
            'status',
            'approved',
            'quizgeistid = :quizgeistid AND rootid = :rootid AND status = :suggested',
            [
                'quizgeistid' => $quizgeistid,
                'rootid' => $rootid,
                'suggested' => 'suggested',
            ]
        );
        return count($suggestions);
    }

    /**
     * Whether one question root uses the error-friendly framing of F1.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param int $rootid Question root ID.
     * @return bool
     */
    public static function is_friendly_new(\stdClass $quizgeist, int $rootid): bool {
        if (empty($quizgeist->friendlynew)) {
            return false;
        }
        return tag_repository::root_has_tag(
            $rootid,
            tag_schema::RESERVED_NEW_KIND,
            tag_schema::RESERVED_NEW_KEY
        );
    }

    /**
     * Remove assignments whose question lineage no longer exists.
     *
     * @param int $quizgeistid Activity ID.
     * @return int Number of removed assignments.
     */
    public static function prune_orphaned_assignments(int $quizgeistid): int {
        global $DB;

        // MariaDB refuses to read the delete target inside its own subquery.
        // The orphans are therefore resolved first and deleted by ID list.
        $orphans = $DB->get_records_sql(
            'SELECT qt.id
               FROM {quizgeist_question_tags} qt
          LEFT JOIN {quizgeist_questions} q
                 ON q.quizgeistid = qt.quizgeistid
                AND COALESCE(NULLIF(q.rootid, 0), q.id) = qt.rootid
              WHERE qt.quizgeistid = :quizgeistid
                AND q.id IS NULL',
            ['quizgeistid' => $quizgeistid]
        );
        if (!$orphans) {
            return 0;
        }
        $DB->delete_records_list(
            'quizgeist_question_tags',
            'id',
            array_map('intval', array_keys($orphans))
        );
        return count($orphans);
    }
}
