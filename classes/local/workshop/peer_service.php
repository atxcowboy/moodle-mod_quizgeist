<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Submission, peer rating and curation of learner-written questions.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\workshop;

use mod_quizgeist\local\editor\media_service;
use mod_quizgeist\local\editor\question_content_lock;
use mod_quizgeist\local\editor\question_schema;
use mod_quizgeist\local\tagging\tag_service;

defined('MOODLE_INTERNAL') || die();

/**
 * The one place that decides what a learner submission may become.
 *
 * Named peer_service, not workshop_service: quizgeistaddon_ai already owns a
 * class of the latter name (the AI workshop), and two identically named
 * services would be indistinguishable in reviews and logs.
 *
 * Four rules carry this file:
 *
 * 1. A submitted question is an ORDINARY quizgeist_questions row with
 *    status='draft' and createdby=<learner>. There is no second question
 *    store, and the existing lifecycle keeps it unplayable ([P10-F14]).
 * 2. Release (state='approved' => question status='ready') additionally
 *    requires mod/quizgeist:manage — curating and releasing are different
 *    levels of trust.
 * 3. Self-rating is refused on the server (authorid !== userid); one rating
 *    per (submission, learner) is enforced by the unique index, not by an if.
 * 4. Learner content never brings media. The submitted options are
 *    synchronised against an EMPTY manifest, so a media path from anywhere
 *    else cannot survive the boundary.
 */
final class peer_service {

    /**
     * Accept one learner submission.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param \context_module $context Module context.
     * @param \stdClass $user Submitting learner.
     * @param array $input Raw client payload.
     * @return array{submission:?array,validationErrors:array}
     */
    public static function submit(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        array $input
    ): array {
        global $DB;

        $rawquestion = $input['question'] ?? null;
        if (!is_array($rawquestion) || array_is_list($rawquestion)) {
            throw new \invalid_parameter_exception('question is required.');
        }
        $normalised = workshop_schema::normalise($rawquestion);
        $question = $normalised['question'];
        // Rule 4: no media from anywhere. An empty manifest makes every media
        // path in the payload null, whatever the client sent.
        $question['options'] = media_service::synchronise_question_options(
            $question['options'],
            []
        );
        $errors = question_schema::validate_media(
            ['qtype' => $question['qtype'], 'options' => $question['options']],
            $normalised['validationErrors'],
            []
        );
        if ($errors) {
            // Refused, not stored as a half-question: the mandatory
            // explanation is a schema rule, not a request to the interface.
            return ['submission' => null, 'validationErrors' => $errors];
        }

        $now = time();
        $structurelock = question_content_lock::acquire_structure(
            (int)$quizgeist->id
        );
        try {
            $maxsort = $DB->get_field_sql(
                'SELECT MAX(sortorder)
                   FROM {quizgeist_questions}
                  WHERE quizgeistid = :quizgeistid
                    AND status <> :archived',
                ['quizgeistid' => (int)$quizgeist->id, 'archived' => 'archived']
            );
            $record = (object)[
                'quizgeistid' => (int)$quizgeist->id,
                'rootid' => 0,
                'version' => 1,
                'sortorder' => $maxsort === null ? 0 : ((int)$maxsort + 1),
                'qtype' => $question['qtype'],
                'questiontext' => $question['questiontext'],
                'questionformat' => FORMAT_PLAIN,
                'optionsjson' => question_schema::encode_options($question['options']),
                'timelimit' => $question['timelimit'],
                'pointmode' => $question['pointmode'],
                'explanation' => $question['explanation'],
                // Rule 1: hard-coded, never derived from validity. A complete
                // learner question is still a draft until a teacher says so.
                'status' => 'draft',
                'createdby' => (int)$user->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $transaction = \mod_quizgeist\local\transaction_scope::begin();
            try {
                $record->id = (int)$DB->insert_record('quizgeist_questions', $record);
                $record->rootid = (int)$record->id;
                $DB->set_field('quizgeist_questions', 'rootid', $record->rootid, [
                    'id' => $record->id,
                ]);
                $submissionid = (int)$DB->insert_record('quizgeist_workshop', (object)[
                    'quizgeistid' => (int)$quizgeist->id,
                    'questionid' => (int)$record->id,
                    'rootid' => (int)$record->rootid,
                    'authorid' => (int)$user->id,
                    'state' => 'submitted',
                    'curatorid' => null,
                    'curatornote' => null,
                    'aicheckjson' => null,
                    'timesubmitted' => $now,
                    'timedecided' => 0,
                    'timemodified' => $now,
                ]);
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            $structurelock->release();
        }

        // E-4: a learner may SUGGEST topics. The suggestion is inert until a
        // teacher confirms it; mod/quizgeist:suggesttag can switch the offer
        // off entirely for a school.
        if (array_key_exists('tags', $input)
                && has_capability('mod/quizgeist:suggesttag', $context, $user)) {
            tag_service::suggest_question_tags(
                $quizgeist,
                (int)$record->id,
                $input['tags'],
                (int)$user->id
            );
        }

        $submission = workshop_repository::submission(
            (int)$quizgeist->id,
            $submissionid
        );
        return [
            'submission' => $submission === null
                ? null
                : self::serialise($submission, [], null, false, []),
            'validationErrors' => [],
        ];
    }

    /**
     * Record one peer rating.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param \stdClass $user Rating learner.
     * @param array $input Raw client payload.
     * @return array{rating:?array,validationErrors:array}
     */
    public static function rate(
        \stdClass $quizgeist,
        \stdClass $user,
        array $input
    ): array {
        global $DB;

        $workshopid = (int)($input['workshopId'] ?? 0);
        if ($workshopid <= 0) {
            throw new \invalid_parameter_exception('workshopId is required.');
        }
        $submission = workshop_repository::submission(
            (int)$quizgeist->id,
            $workshopid
        );
        if ($submission === null) {
            throw new \invalid_parameter_exception('The submission is unavailable.');
        }
        // Rule 3, first half: refused on the server, not hidden in the client.
        if ((int)($submission->authorid ?? 0) === (int)$user->id) {
            return [
                'rating' => null,
                'validationErrors' => [
                    ['field' => 'workshopId', 'code' => 'self_rating'],
                ],
            ];
        }
        $normalised = workshop_schema::normalise_rating($input);
        if ($normalised['validationErrors']) {
            return [
                'rating' => null,
                'validationErrors' => $normalised['validationErrors'],
            ];
        }

        $now = time();
        $rating = $normalised['rating'];
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            // Rule 3, second half: the unique index workshop_user_uix owns the
            // "one rating per person" promise. This read only decides between
            // insert and update; a race loses at the index, not at an if.
            $existing = $DB->get_record('quizgeist_workshop_ratings', [
                'workshopid' => $workshopid,
                'userid' => (int)$user->id,
            ]);
            if ($existing === false) {
                $DB->insert_record('quizgeist_workshop_ratings', (object)[
                    'workshopid' => $workshopid,
                    'userid' => (int)$user->id,
                    'quality' => $rating['quality'],
                    'difficulty' => $rating['difficulty'],
                    'comment' => $rating['comment'] === '' ? null : $rating['comment'],
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            } else {
                $DB->update_record('quizgeist_workshop_ratings', (object)[
                    'id' => (int)$existing->id,
                    'quality' => $rating['quality'],
                    'difficulty' => $rating['difficulty'],
                    'comment' => $rating['comment'] === '' ? null : $rating['comment'],
                    'timemodified' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        return [
            'rating' => [
                'workshopId' => $workshopid,
                'quality' => $rating['quality'],
                'difficulty' => $rating['difficulty'],
                'comment' => $rating['comment'],
            ],
            'validationErrors' => [],
        ];
    }

    /**
     * Decide one submission.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param \context_module $context Module context.
     * @param \stdClass $user Deciding teacher.
     * @param array $input Raw client payload.
     * @return array{submission:?array,validationErrors:array}
     */
    public static function curate(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $user,
        array $input
    ): array {
        global $DB;

        $workshopid = (int)($input['workshopId'] ?? 0);
        if ($workshopid <= 0) {
            throw new \invalid_parameter_exception('workshopId is required.');
        }
        $submission = workshop_repository::submission(
            (int)$quizgeist->id,
            $workshopid
        );
        if ($submission === null) {
            throw new \invalid_parameter_exception('The submission is unavailable.');
        }
        $normalised = workshop_schema::normalise_decision($input);
        if ($normalised['validationErrors']) {
            return [
                'submission' => null,
                'validationErrors' => $normalised['validationErrors'],
            ];
        }
        $state = $normalised['decision']['state'];
        $note = $normalised['decision']['note'];

        if ($state === 'approved') {
            // Rule 2: curating is not releasing. Reading, commenting and
            // sending back need :curatequestions; making a learner question
            // playable needs the editing right of the activity.
            if (!has_capability('mod/quizgeist:manage', $context, $user)) {
                return [
                    'submission' => null,
                    'validationErrors' => [
                        ['field' => 'state', 'code' => 'requires_manage'],
                    ],
                ];
            }
            $errors = self::release_blockers($quizgeist, $context, $submission);
            if ($errors) {
                return ['submission' => null, 'validationErrors' => $errors];
            }
        }

        $now = time();
        $contentlock = question_content_lock::acquire_for_question(
            (int)$quizgeist->id,
            (int)$submission->questionid
        );
        try {
            $transaction = \mod_quizgeist\local\transaction_scope::begin();
            try {
                $DB->update_record('quizgeist_workshop', (object)[
                    'id' => (int)$submission->id,
                    'state' => $state,
                    'curatorid' => (int)$user->id,
                    'curatornote' => $note === '' ? null : $note,
                    'timedecided' => $now,
                    'timemodified' => $now,
                ]);
                if ($state === 'approved') {
                    $DB->set_field(
                        'quizgeist_questions',
                        'status',
                        'ready',
                        [
                            'id' => (int)$submission->questionid,
                            'quizgeistid' => (int)$quizgeist->id,
                        ]
                    );
                    // The teacher's release is also the confirmation of the
                    // topics the learner suggested (E-4). Without this the
                    // capability mod/quizgeist:suggesttag would stay inert.
                    tag_service::approve_suggestions(
                        (int)$quizgeist->id,
                        (int)$submission->rootid
                    );
                } else if ($state === 'rejected') {
                    $DB->set_field(
                        'quizgeist_questions',
                        'status',
                        'archived',
                        [
                            'id' => (int)$submission->questionid,
                            'quizgeistid' => (int)$quizgeist->id,
                        ]
                    );
                }
                $transaction->allow_commit();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
        } finally {
            $contentlock->release();
        }

        $updated = workshop_repository::submission(
            (int)$quizgeist->id,
            $workshopid
        );
        return [
            'submission' => $updated === null
                ? null
                : self::serialise($updated, [], null, true, []),
            'validationErrors' => [],
        ];
    }

    /**
     * Attach an advisory AI pre-check to one submission.
     *
     * The result is a hint. It is stored next to the submission and never
     * touches `state` — that is why this method takes no state argument.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param int $workshopid Submission ID.
     * @param array $check Advisory result.
     * @return array Stored advisory result.
     */
    public static function store_ai_check(
        \stdClass $quizgeist,
        int $workshopid,
        array $check
    ): array {
        global $DB;

        $submission = workshop_repository::submission(
            (int)$quizgeist->id,
            $workshopid
        );
        if ($submission === null) {
            throw new \invalid_parameter_exception('The submission is unavailable.');
        }
        $DB->update_record('quizgeist_workshop', (object)[
            'id' => (int)$submission->id,
            'aicheckjson' => json_encode($check, JSON_UNESCAPED_UNICODE),
            'timemodified' => time(),
        ]);
        return $check;
    }

    /**
     * Reasons why a submission may not be released.
     *
     * A release makes the question playable. It therefore has to pass the very
     * same validation an ordinary editor save passes — otherwise a broken
     * question could become "ready" through the side door of a friendly click.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param \context_module $context Module context.
     * @param \stdClass $submission Submission row.
     * @return array Field-addressable objections.
     */
    private static function release_blockers(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $submission
    ): array {
        global $DB;

        $record = $DB->get_record('quizgeist_questions', [
            'id' => (int)$submission->questionid,
            'quizgeistid' => (int)$quizgeist->id,
        ]);
        if ($record === false) {
            return [['field' => 'workshopId', 'code' => 'question_missing']];
        }
        if ((string)$record->status === 'archived') {
            return [['field' => 'workshopId', 'code' => 'question_archived']];
        }
        $normalised = workshop_schema::normalise([
            'qtype' => (string)$record->qtype,
            'questiontext' => (string)$record->questiontext,
            'options' => question_schema::decode_options($record->optionsjson ?? null),
            'timelimit' => (int)$record->timelimit,
            'pointmode' => (string)$record->pointmode,
            'explanation' => (string)$record->explanation,
        ]);
        $manifest = media_service::manifest(
            $context,
            'questionmedia',
            (int)$record->id
        );
        return question_schema::validate_media(
            [
                'qtype' => $normalised['question']['qtype'],
                'options' => media_service::synchronise_question_options(
                    $normalised['question']['options'],
                    $manifest
                ),
            ],
            $normalised['validationErrors'],
            $manifest
        );
    }

    /**
     * Build the complete read model of the workshop for one viewer.
     *
     * @param \stdClass $quizgeist Activity record.
     * @param \stdClass $user Viewer.
     * @param bool $curator Whether the viewer may curate.
     * @return array
     */
    public static function project(
        \stdClass $quizgeist,
        \stdClass $user,
        bool $curator
    ): array {
        $submissions = workshop_repository::submissions((int)$quizgeist->id);
        $ids = array_map(
            static fn(\stdClass $row): int => (int)$row->id,
            $submissions
        );
        $summary = workshop_repository::rating_summary($ids);
        $ownratings = workshop_repository::own_ratings($ids, (int)$user->id);
        $people = $curator ? workshop_repository::people($submissions) : [];

        $mine = [];
        $peers = [];
        $queue = [];
        foreach ($submissions as $submission) {
            $isown = (int)($submission->authorid ?? 0) === (int)$user->id;
            $dto = self::serialise(
                $submission,
                $summary[(int)$submission->id] ?? [],
                $ownratings[(int)$submission->id] ?? null,
                $curator,
                $people
            );
            if ($curator) {
                $queue[] = $dto;
            }
            if ($isown) {
                $mine[] = $dto;
                continue;
            }
            // Peers see submitted and released questions of others in order to
            // rate them; they never see the curator note of a foreign
            // submission, which serialise() strips for non-curators. A
            // rejected or reworked submission is between its author and the
            // teacher and does not belong in a classmate's list.
            if (in_array((string)$submission->state, ['submitted', 'approved'], true)) {
                $peers[] = $dto;
            }
        }

        return [
            'mine' => $mine,
            'peers' => $peers,
            'queue' => $queue,
            'canCurate' => $curator,
            'openRatings' => workshop_repository::unrated_count(
                (int)$quizgeist->id,
                (int)$user->id
            ),
            'states' => workshop_schema::STATES,
            'ratingRange' => [
                'min' => workshop_schema::MIN_RATING,
                'max' => workshop_schema::MAX_RATING,
            ],
        ];
    }

    /**
     * Serialise one submission for a viewer.
     *
     * @param \stdClass $submission Submission row (optionally joined).
     * @param array $summary Aggregated peer ratings.
     * @param \stdClass|null $ownrating The viewer's own rating.
     * @param bool $curator Whether the viewer may curate.
     * @param array<int,\stdClass> $people Bulk-loaded users.
     * @return array
     */
    public static function serialise(
        \stdClass $submission,
        array $summary,
        ?\stdClass $ownrating,
        bool $curator,
        array $people
    ): array {
        $authorid = (int)($submission->authorid ?? 0);
        $dto = [
            'id' => (int)$submission->id,
            'questionId' => (int)$submission->questionid,
            'rootId' => (int)$submission->rootid,
            'state' => (string)$submission->state,
            'questionText' => (string)($submission->questiontext ?? ''),
            'qtype' => (string)($submission->qtype ?? ''),
            'explanation' => (string)($submission->explanation ?? ''),
            'questionStatus' => (string)($submission->questionstatus ?? 'draft'),
            'timeSubmitted' => (int)$submission->timesubmitted,
            'timeDecided' => (int)$submission->timedecided,
            'ratingCount' => (int)($summary['count'] ?? 0),
            'averageQuality' => $summary['quality'] ?? null,
            'averageDifficulty' => $summary['difficulty'] ?? null,
            'ownRating' => $ownrating === null ? null : [
                'quality' => (int)$ownrating->quality,
                'difficulty' => (int)$ownrating->difficulty,
                'comment' => (string)($ownrating->comment ?? ''),
            ],
        ];
        if ($curator) {
            $author = $people[$authorid] ?? null;
            $dto['authorName'] = $authorid > 0 && $author !== null
                ? fullname($author)
                : get_string('workshop:author:anonymous', 'mod_quizgeist');
            $dto['curatorNote'] = (string)($submission->curatornote ?? '');
            $dto['aiCheck'] = self::decode_check($submission->aicheckjson ?? null);
        }
        return $dto;
    }

    /**
     * Decode the advisory AI pre-check.
     *
     * @param string|null $json Stored JSON.
     * @return array|null
     */
    private static function decode_check(?string $json): ?array {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
