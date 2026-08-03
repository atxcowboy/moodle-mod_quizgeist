<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Persistence and arithmetic of the F5 misconception radar.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\report;

use mod_quizgeist\local\editor\question_schema;

defined('MOODLE_INTERNAL') || die();

/**
 * Misconception labels of a question root, plus the hinge traffic light.
 *
 * Unlike report_repository this file also writes: a label is a tiny,
 * self-contained teacher annotation with exactly one validation rule worth
 * naming (the answer key must belong to the root question), and a separate
 * service class for one insert would hide that rule behind another door.
 *
 * Two rules carry this file:
 *
 * 1. The binding is quizgeist_questions.rootid, never the version ID
 *    (P11_PLAN.md 2.3) — a label survives every edit of its question.
 * 2. An answer key is validated against the actual answer IDs of the root
 *    question. A key is never accepted because a client sent it.
 */
final class misconception_repository {

    /** Traffic light: fewer answers than the sample floor. */
    public const STATUS_INSUFFICIENT = 'insufficient';

    /** Traffic light: below the hinge threshold — reteach. */
    public const STATUS_RETEACH = 'reteach';

    /** Traffic light: at or above the hinge threshold — move on. */
    public const STATUS_MOVE_ON = 'move_on';

    /** Site default of the hinge threshold in percent. */
    public const DEFAULT_THRESHOLD = 70;

    /** Maximum stored label length. */
    public const MAX_LABEL = 255;

    /** Maximum stored hint length. */
    public const MAX_HINT = 1000;

    /** Maximum labels accepted for one question root. */
    public const MAX_LABELS = 12;

    /**
     * Canonical answer keys of one question, in projection order.
     *
     * The order is the contract that lets a live aggregate row be matched to
     * its label: choice aggregates iterate the very same option list, and the
     * live choice IDs are opaque per visit, so position is the only stable
     * bridge between an aggregate row and its canonical key.
     *
     * @param array $question Canonical normalised question.
     * @return string[] Canonical answer keys, empty for types without choices.
     */
    public static function answer_keys(array $question): array {
        $qtype = (string)($question['qtype'] ?? '');
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        if ($qtype === 'truefalse') {
            return ['true', 'false'];
        }
        if (($qtype === 'quiz' || $qtype === 'poll')
                && is_array($options['answers'] ?? null)) {
            return array_values(array_map(
                static fn(array $answer): string => (string)$answer['id'],
                array_filter(
                    $options['answers'],
                    static fn($answer): bool => is_array($answer)
                        && isset($answer['id'])
                )
            ));
        }
        return [];
    }

    /**
     * Canonical keys that count as correct, or an empty list.
     *
     * An empty list means "this question has no hinge": a poll has no right
     * answer, and a multiple-answer question has no single share that a
     * traffic light could honestly summarise.
     *
     * @param array $question Canonical normalised question.
     * @return string[]
     */
    public static function correct_keys(array $question): array {
        $qtype = (string)($question['qtype'] ?? '');
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        if ($qtype === 'truefalse') {
            return [empty($options['correct']) ? 'false' : 'true'];
        }
        if ($qtype !== 'quiz'
                || !is_array($options['answers'] ?? null)
                || !empty($options['multiple'])) {
            return [];
        }
        $correct = [];
        foreach ($options['answers'] as $answer) {
            if (is_array($answer) && !empty($answer['correct'])) {
                $correct[] = (string)$answer['id'];
            }
        }
        return count($correct) === 1 ? $correct : [];
    }

    /**
     * Site-wide hinge threshold in percent.
     *
     * Deliberately the same shape as report_metrics::difficult_threshold():
     * an unset, malformed or out-of-range administrative value falls back to
     * the documented default rather than to zero.
     *
     * @return int Percent from 1 to 100.
     */
    public static function site_threshold(): int {
        $configured = get_config('mod_quizgeist', 'report_hinge_threshold');
        if ($configured === false || !is_numeric($configured)) {
            return self::DEFAULT_THRESHOLD;
        }
        $value = (int)$configured;
        return $value >= 1 && $value <= 100 ? $value : self::DEFAULT_THRESHOLD;
    }

    /**
     * Effective threshold of one question: its own option, else the site value.
     *
     * @param array $question Canonical normalised question.
     * @return int Percent from 1 to 100.
     */
    public static function question_threshold(array $question): int {
        $options = is_array($question['options'] ?? null) ? $question['options'] : [];
        $own = (int)($options['hingeThreshold'] ?? 0);
        return $own >= 1 && $own <= 100 ? $own : self::site_threshold();
    }

    /**
     * Sample floor below which the traffic light says "too little data".
     *
     * Reuses the existing administrative setting of the difficulty metric
     * (report_metrics::difficult_min_sample()): a school that has decided how
     * many answers make a statement should not have to decide it twice.
     *
     * @return int
     */
    public static function min_sample(): int {
        return report_metrics::difficult_min_sample();
    }

    /**
     * Decide the traffic light for one question.
     *
     * @param int $correct Number of correct responses.
     * @param int $sample Number of responses in total.
     * @param int $threshold Effective threshold in percent.
     * @return string One of the STATUS_* constants.
     */
    public static function hinge_status(
        int $correct,
        int $sample,
        int $threshold
    ): string {
        if ($sample < self::min_sample() || $sample <= 0) {
            return self::STATUS_INSUFFICIENT;
        }
        $percent = ($correct * 100) / $sample;
        return $percent >= $threshold
            ? self::STATUS_MOVE_ON
            : self::STATUS_RETEACH;
    }

    /**
     * Labels of one question root, keyed by canonical answer key.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @return array<string,\stdClass>
     */
    public static function labels_for_root(int $quizgeistid, int $rootid): array {
        if ($quizgeistid <= 0 || $rootid <= 0) {
            return [];
        }
        return self::labels_for_roots($quizgeistid, [$rootid])[$rootid] ?? [];
    }

    /**
     * Labels of several question roots in one read.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[] $rootids Question root IDs.
     * @return array<int,array<string,\stdClass>>
     */
    public static function labels_for_roots(int $quizgeistid, array $rootids): array {
        global $DB;

        $rootids = array_values(array_unique(array_filter(
            array_map('intval', $rootids),
            static fn(int $rootid): bool => $rootid > 0
        )));
        if ($quizgeistid <= 0 || !$rootids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $rootids,
            SQL_PARAMS_NAMED,
            'misconceptionroot'
        );
        $params['misconceptionquiz'] = $quizgeistid;
        $records = $DB->get_records_sql(
            "SELECT m.*
               FROM {quizgeist_misconceptions} m
              WHERE m.quizgeistid = :misconceptionquiz
                AND m.rootid {$insql}
           ORDER BY m.rootid ASC, m.id ASC",
            $params
        );
        $byroot = [];
        foreach ($records as $record) {
            $byroot[(int)$record->rootid][(string)$record->answerkey] = $record;
        }
        return $byroot;
    }

    /**
     * Every root of this activity that carries at least one label.
     *
     * @param int $quizgeistid Activity ID.
     * @return int[]
     */
    public static function labelled_roots(int $quizgeistid): array {
        global $DB;

        if ($quizgeistid <= 0) {
            return [];
        }
        $records = $DB->get_records_sql(
            'SELECT DISTINCT m.rootid
               FROM {quizgeist_misconceptions} m
              WHERE m.quizgeistid = :quizgeistid
           ORDER BY m.rootid ASC',
            ['quizgeistid' => $quizgeistid]
        );
        return array_values(array_map(
            static fn(\stdClass $record): int => (int)$record->rootid,
            $records
        ));
    }

    /**
     * Cheap change marker so a cached projection cannot outlive an edit.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @return string
     */
    public static function signature(int $quizgeistid, int $rootid): string {
        global $DB;

        if ($quizgeistid <= 0 || $rootid <= 0) {
            return '0-0';
        }
        $row = $DB->get_record_sql(
            'SELECT COUNT(1) AS labelcount, COALESCE(MAX(timemodified), 0) AS newest
               FROM {quizgeist_misconceptions}
              WHERE quizgeistid = :quizgeistid
                AND rootid = :rootid',
            ['quizgeistid' => $quizgeistid, 'rootid' => $rootid]
        );
        return $row === false
            ? '0-0'
            : ((int)$row->labelcount . '-' . (int)$row->newest);
    }

    /**
     * Aggregate persisted choice responses of several roots.
     *
     * Only the append-only answer ledger is read, and only canonical choice
     * IDs are counted. The report needs no live session state for this.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[] $rootids Question root IDs.
     * @return array<int,array{counts:array<string,int>,sample:int}>
     */
    public static function distribution_for_roots(
        int $quizgeistid,
        array $rootids
    ): array {
        global $DB;

        $rootids = array_values(array_unique(array_filter(
            array_map('intval', $rootids),
            static fn(int $rootid): bool => $rootid > 0
        )));
        if ($quizgeistid <= 0 || !$rootids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(
            $rootids,
            SQL_PARAMS_NAMED,
            'distributionroot'
        );
        $params['distributionquiz'] = $quizgeistid;
        $params['distributiontype'] = 'answer';
        $rows = $DB->get_records_sql(
            "SELECT a.id,
                    COALESCE(NULLIF(q.rootid, 0), q.id) AS rootid,
                    a.answerjson
               FROM {quizgeist_answers} a
               JOIN {quizgeist_questions} q ON q.id = a.questionid
              WHERE q.quizgeistid = :distributionquiz
                AND a.answertype = :distributiontype
                AND COALESCE(NULLIF(q.rootid, 0), q.id) {$insql}
           ORDER BY a.id ASC",
            $params
        );
        $result = [];
        foreach ($rootids as $rootid) {
            $result[$rootid] = ['counts' => [], 'sample' => 0];
        }
        foreach ($rows as $row) {
            $rootid = (int)$row->rootid;
            $decoded = json_decode((string)$row->answerjson, true);
            $choiceids = is_array($decoded) ? ($decoded['choiceIds'] ?? null) : null;
            if (!is_array($choiceids) || !$choiceids) {
                continue;
            }
            $result[$rootid]['sample']++;
            foreach ($choiceids as $choiceid) {
                if (!is_string($choiceid)) {
                    continue;
                }
                $result[$rootid]['counts'][$choiceid] =
                    ($result[$rootid]['counts'][$choiceid] ?? 0) + 1;
            }
        }
        return $result;
    }

    /**
     * Resolve the canonical normalised question of one root.
     *
     * The newest non-archived version wins: a label describes the content a
     * class is answering now, not the wording it had two edits ago.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @return array|null Canonical question, or null when the root is gone.
     */
    public static function root_question(int $quizgeistid, int $rootid): ?array {
        global $DB;

        if ($quizgeistid <= 0 || $rootid <= 0) {
            return null;
        }
        $record = $DB->get_record_sql(
            'SELECT q.*
               FROM {quizgeist_questions} q
              WHERE q.quizgeistid = :quizgeistid
                AND COALESCE(NULLIF(q.rootid, 0), q.id) = :rootid
                AND q.status <> :archived
           ORDER BY q.version DESC, q.id DESC',
            [
                'quizgeistid' => $quizgeistid,
                'rootid' => $rootid,
                'archived' => 'archived',
            ],
            IGNORE_MULTIPLE
        );
        if ($record === false) {
            return null;
        }
        $normalised = question_schema::normalise([
            'qtype' => (string)$record->qtype,
            'questiontext' => (string)$record->questiontext,
            'options' => question_schema::decode_options($record->optionsjson ?? null),
            'timelimit' => (int)$record->timelimit,
            'pointmode' => (string)$record->pointmode,
            'explanation' => (string)$record->explanation,
        ]);
        return $normalised['question'];
    }

    /**
     * Replace the complete label list of one question root.
     *
     * @param int $quizgeistid Activity ID.
     * @param int $rootid Question root ID.
     * @param mixed $raw Raw client list.
     * @param string[] $availablekeys Canonical answer keys of the root.
     * @return array{labels:array,validationErrors:array}
     */
    public static function replace_for_root(
        int $quizgeistid,
        int $rootid,
        $raw,
        array $availablekeys
    ): array {
        global $DB;

        $normalised = self::normalise_labels($raw, $availablekeys);
        if ($normalised['validationErrors']) {
            return [
                'labels' => self::serialise(
                    self::labels_for_root($quizgeistid, $rootid)
                ),
                'validationErrors' => $normalised['validationErrors'],
            ];
        }

        $now = time();
        $transaction = \mod_quizgeist\local\transaction_scope::begin();
        try {
            $current = $DB->get_records(
                'quizgeist_misconceptions',
                ['quizgeistid' => $quizgeistid, 'rootid' => $rootid],
                'id ASC'
            );
            $bykey = [];
            foreach ($current as $record) {
                $bykey[(string)$record->answerkey] = $record;
            }
            foreach ($bykey as $answerkey => $record) {
                if (!array_key_exists($answerkey, $normalised['labels'])) {
                    $DB->delete_records(
                        'quizgeist_misconceptions',
                        ['id' => (int)$record->id]
                    );
                }
            }
            foreach ($normalised['labels'] as $answerkey => $label) {
                if (isset($bykey[$answerkey])) {
                    $record = $bykey[$answerkey];
                    if ((string)$record->label === $label['label']
                            && (string)($record->hint ?? '') === $label['hint']) {
                        continue;
                    }
                    $DB->update_record('quizgeist_misconceptions', (object)[
                        'id' => (int)$record->id,
                        'label' => $label['label'],
                        'hint' => $label['hint'] === '' ? null : $label['hint'],
                        'timemodified' => $now,
                    ]);
                    continue;
                }
                $DB->insert_record('quizgeist_misconceptions', (object)[
                    'quizgeistid' => $quizgeistid,
                    'rootid' => $rootid,
                    'answerkey' => $answerkey,
                    'label' => $label['label'],
                    'hint' => $label['hint'] === '' ? null : $label['hint'],
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        return [
            'labels' => self::serialise(
                self::labels_for_root($quizgeistid, $rootid)
            ),
            'validationErrors' => [],
        ];
    }

    /**
     * Serialise stored label rows for the client.
     *
     * @param array<string,\stdClass> $records Rows keyed by answer key.
     * @return array
     */
    public static function serialise(array $records): array {
        $labels = [];
        foreach ($records as $answerkey => $record) {
            $labels[] = [
                'answerKey' => (string)$answerkey,
                'label' => (string)$record->label,
                'hint' => (string)($record->hint ?? ''),
            ];
        }
        return $labels;
    }

    /**
     * Validate a raw label list against the answer keys of the root question.
     *
     * @param mixed $raw Raw client list.
     * @param string[] $availablekeys Canonical answer keys of the root.
     * @return array{labels:array<string,array{label:string,hint:string}>,validationErrors:array}
     */
    public static function normalise_labels($raw, array $availablekeys): array {
        $errors = [];
        if (!is_array($raw) || !array_is_list($raw)) {
            return [
                'labels' => [],
                'validationErrors' => [['field' => 'labels', 'code' => 'invalid']],
            ];
        }
        if (count($raw) > self::MAX_LABELS) {
            return [
                'labels' => [],
                'validationErrors' => [['field' => 'labels', 'code' => 'too_many']],
            ];
        }
        $labels = [];
        foreach ($raw as $index => $entry) {
            $field = 'labels.' . $index;
            if (!is_array($entry)) {
                $errors[] = ['field' => $field, 'code' => 'invalid'];
                continue;
            }
            $answerkey = is_string($entry['answerKey'] ?? null)
                ? $entry['answerKey']
                : '';
            if ($answerkey === '' || !in_array($answerkey, $availablekeys, true)) {
                // The key is checked against the ACTUAL answers of the root
                // question. A client cannot invent a distractor.
                $errors[] = ['field' => $field . '.answerKey', 'code' => 'invalid'];
                continue;
            }
            if (isset($labels[$answerkey])) {
                $errors[] = ['field' => $field . '.answerKey', 'code' => 'duplicate'];
                continue;
            }
            $label = self::plain_text($entry['label'] ?? '', self::MAX_LABEL);
            $hint = self::plain_text($entry['hint'] ?? '', self::MAX_HINT);
            if ($label === '') {
                $errors[] = ['field' => $field . '.label', 'code' => 'required'];
                continue;
            }
            $labels[$answerkey] = ['label' => $label, 'hint' => $hint];
        }
        return [
            'labels' => $errors ? [] : $labels,
            'validationErrors' => $errors,
        ];
    }

    /**
     * Reduce untrusted input to bounded plain text.
     *
     * PARAM_TEXT plus a hard length bound. The client renders the result with
     * textContent, never as HTML.
     *
     * @param mixed $value Raw value.
     * @param int $maximum Maximum length.
     * @return string
     */
    private static function plain_text($value, int $maximum): string {
        if (!is_string($value)) {
            return '';
        }
        $clean = clean_param($value, PARAM_TEXT);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        return \core_text::substr($clean, 0, $maximum);
    }
}
