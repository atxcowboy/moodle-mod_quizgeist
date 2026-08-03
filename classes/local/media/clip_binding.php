<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Binding between a recorded clip and the answer it stands for (F9).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\media;

defined('MOODLE_INTERNAL') || die();

/**
 * Ties one clip to one answer, and later carries the transcript across.
 *
 * The split with the question strategies is deliberate. A strategy validates
 * the SHAPE of a submission and knows nothing about who is submitting; it may
 * therefore accept `{"clipId": 12}` but must never resolve clip 12 into text,
 * because it cannot tell whose recording that is. Ownership lives here, where
 * the acting user is known, and it runs inside the same transaction as the
 * answer — a foreign clip ID does not produce a rejected binding next to a
 * stored answer, it produces no answer at all.
 *
 * The transcript is written INTO the answer payload once it exists, rather than
 * joined at read time. Every existing reader — live aggregate, report, response
 * clusterer, export — then sees a spoken answer as the ordinary text answer it
 * is, and none of them had to learn about clips.
 */
final class clip_binding {

    /**
     * Bind a submitted clip to the answer it was submitted for.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $clipid Clip claimed by the submission.
     * @param int $answerid Freshly stored answer.
     * @param int $userid Submitting user.
     * @param string $purpose Expected purpose of the clip.
     * @return \stdClass The bound clip.
     */
    public static function attach(
        int $quizgeistid,
        int $clipid,
        int $answerid,
        int $userid,
        string $purpose
    ): \stdClass {
        global $DB;

        $clip = clip_service::get($quizgeistid, $clipid);
        if ($clip === null
                || (int)$clip->userid !== $userid
                || (string)$clip->purpose !== $purpose) {
            // One diagnosis for every way of not being entitled to this clip,
            // so the endpoint cannot be used to enumerate other people's IDs.
            throw new clip_exception('clip_not_found');
        }
        if ($clip->answerid !== null && (int)$clip->answerid !== $answerid) {
            throw new clip_exception('clip_already_bound');
        }

        clip_service::bind_to_answer($clipid, $answerid, $userid);
        $clip->answerid = $answerid;

        // A transcript that already exists is carried over immediately; one
        // that does not yet exist arrives through publish_transcript().
        if ((string)$clip->transcriptstate === 'done' && is_string($clip->transcript)) {
            self::write_answer_text($answerid, (string)$clip->transcript);
        }
        return $clip;
    }

    /**
     * Carry a finished transcript into the answer the clip is bound to.
     *
     * @param int $clipid Clip ID.
     * @return bool Whether an answer was updated.
     */
    public static function publish_transcript(int $clipid): bool {
        global $DB;

        $clip = $DB->get_record('quizgeist_clips', ['id' => $clipid]);
        if (!$clip
                || $clip->answerid === null
                || (string)$clip->transcriptstate !== 'done'
                || !is_string($clip->transcript)) {
            return false;
        }
        return self::write_answer_text((int)$clip->answerid, (string)$clip->transcript);
    }

    /**
     * Return the clip bound to one answer, if any.
     *
     * @param int $answerid Answer ID.
     * @return \stdClass|null
     */
    public static function of_answer(int $answerid): ?\stdClass {
        global $DB;

        if ($answerid <= 0) {
            return null;
        }
        $clip = $DB->get_record('quizgeist_clips', ['answerid' => $answerid]);
        return $clip ?: null;
    }

    /**
     * Return the clips bound to a set of answers, keyed by answer ID.
     *
     * One query for a whole report page rather than one per row.
     *
     * @param int[] $answerids Answer IDs.
     * @return array<int,\stdClass>
     */
    public static function of_answers(array $answerids): array {
        global $DB;

        $answerids = array_values(array_unique(array_filter(
            array_map('intval', $answerids),
            static fn(int $id): bool => $id > 0
        )));
        if (!$answerids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($answerids, SQL_PARAMS_NAMED, 'a');
        $rows = $DB->get_records_select('quizgeist_clips', "answerid {$insql}", $params);
        $byanswer = [];
        foreach ($rows as $row) {
            $byanswer[(int)$row->answerid] = $row;
        }
        return $byanswer;
    }

    /**
     * Replace the `text` field of one stored answer payload.
     *
     * Only that one field: the payload also carries server-owned bookkeeping
     * (visit token, score before) that a transcription must not touch.
     *
     * @param int $answerid Answer ID.
     * @param string $text Transcribed text.
     * @return bool
     */
    private static function write_answer_text(int $answerid, string $text): bool {
        global $DB;

        $answer = $DB->get_record('quizgeist_answers', ['id' => $answerid], 'id, answerjson');
        if (!$answer) {
            return false;
        }
        $payload = json_decode((string)$answer->answerjson, true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['text'] = clean_param($text, PARAM_TEXT);
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($encoded)) {
            return false;
        }
        $DB->set_field('quizgeist_answers', 'answerjson', $encoded, ['id' => $answerid]);
        return true;
    }
}
