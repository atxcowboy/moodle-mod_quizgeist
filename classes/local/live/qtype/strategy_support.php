<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Shared helpers for type strategies.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live\qtype;

use mod_quizgeist\local\live\points_formula;
use mod_quizgeist\local\live\scoring_context;

defined('MOODLE_INTERNAL') || die();

/**
 * Keeps canonical text, row decoding and scoring identical across types.
 */
final class strategy_support {

    /** Public option handles are a marker plus the complete SHA-256 HMAC. */
    private const OPAQUE_HANDLE_PATTERN = '/^h[a-f0-9]{64}$/D';

    /** Maximum length of one F2 reason text. */
    public const MAX_REASON_LENGTH = 2000;

    /**
     * Whether this question asks for a short justification after the answer.
     *
     * Every strategy built on interaction_policy::standard() passes this
     * through, so the switch itself lives in exactly one place (F2).
     *
     * @param array $question Canonical question.
     * @return bool
     */
    public static function reason_stage(array $question): bool {
        return !empty($question['options']['reasonStep']);
    }

    /**
     * Whether this question carries the F13 stage-check sub-mode.
     *
     * TWO conditions, and the order matters. The option alone is not enough:
     * without the addon CODE the sub-mode must be absent entirely, not locked
     * (P11_PLAN.md 2.6). `play_existing` asks exactly that — is the component
     * installed — and never asks the licence, because presenting an existing
     * task is not a new creation.
     *
     * @param array $question Canonical question.
     * @return bool
     */
    public static function stage_check(array $question): bool {
        if (empty($question['options']['stageCheck'])) {
            return false;
        }
        return \mod_quizgeist\local\licence\feature_gate::allows(
            'buehne',
            \mod_quizgeist\local\licence\feature_gate::PLAY_EXISTING
        );
    }

    /**
     * Canonicalise one submitted stage marker.
     *
     * The live answer of a stage check carries NO measurement at all — the
     * numbers live in quizgeist_stage_reports and got there through their own
     * validated path. All that is stored here is which report it was, so the
     * ordinary live ledger stays complete. A stage marker is never scored.
     *
     * @param array $rawanswer Submitted payload.
     * @return array{reportId:int}
     */
    public static function stage_answer(array $rawanswer): array {
        $reportid = $rawanswer['reportId'] ?? 0;
        if (is_string($reportid) && preg_match('/^\d{1,10}$/D', $reportid) === 1) {
            $reportid = (int)$reportid;
        }
        if (!is_int($reportid) || $reportid < 0) {
            throw new \invalid_parameter_exception('Stage report reference is invalid.');
        }
        return ['reportId' => $reportid];
    }

    /**
     * Canonicalise one submitted reason text.
     *
     * A reason is plain text, never HTML, and never scored.
     *
     * @param array $rawanswer Submitted payload.
     * @return array{text:string}
     */
    public static function reason_answer(array $rawanswer): array {
        return [
            'text' => self::multiline_text(
                $rawanswer['text'] ?? null,
                self::MAX_REASON_LENGTH,
                'reason.text'
            ),
        ];
    }

    /**
     * Add central scoring fields to a canonical semantic evaluation.
     *
     * @param array $question Question.
     * @param array $answer Canonical payload.
     * @param bool|null $iscorrect Correctness.
     * @param scoring_context $scoring Server-owned scoring context.
     * @param float $quality Accuracy factor.
     * @param string $answertype Persisted answer type.
     * @return array
     */
    public static function evaluated(
        array $question,
        array $answer,
        ?bool $iscorrect,
        scoring_context $scoring,
        float $quality = 1.0,
        string $answertype = 'answer'
    ): array {
        return [
            'answer' => $answer,
            'answerType' => $answertype,
            'quality' => max(0.0, min(1.0, $quality)),
        ] + $answer + points_formula::calculate(
            $question,
            $iscorrect,
            $scoring,
            $quality
        );
    }

    /**
     * Decode rows while retaining their immutable row IDs and answer types.
     *
     * @param \stdClass[] $answers Persisted database rows.
     * @return array<int, array{
     *     id:int,answerType:string,playerId:int,payload:array,isCorrect:?bool
     * }>
     */
    public static function rows(array $answers): array {
        $rows = [];
        foreach ($answers as $answer) {
            if (!$answer instanceof \stdClass) {
                throw new \coding_exception(
                    'Live aggregation accepts persisted answer rows only.'
                );
            }
            $decoded = json_decode((string)($answer->answerjson ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $rows[] = [
                'id' => (int)($answer->id ?? 0),
                'answerType' => (string)($answer->answertype ?? 'answer'),
                'playerId' => (int)($answer->playerid ?? 0),
                'payload' => $decoded,
                'isCorrect' => $answer->iscorrect === null
                    ? null
                    : !empty($answer->iscorrect),
            ];
        }
        return $rows;
    }

    /**
     * A spoken submission: an answer whose text does not exist yet (F9).
     *
     * A strategy validates the SHAPE of a submission and does not know who is
     * submitting, so it may recognise a clip reference but must never resolve
     * it into text — it cannot tell whose recording that ID names. Ownership
     * and the transcript therefore belong to
     * \mod_quizgeist\local\media\clip_binding, which runs with the acting user
     * inside the same transaction that stores the answer.
     *
     * The answer counts as given the moment it is spoken. Until the transcript
     * arrives its text is empty, and that is a state the host is shown, not an
     * error the learner has to solve.
     *
     * @param array $rawanswer Raw submission.
     * @return int|null Clip ID, or null when this is not a spoken submission.
     */
    public static function spoken_clip_id(array $rawanswer): ?int {
        if (!array_key_exists('clipId', $rawanswer)) {
            return null;
        }
        $clipid = $rawanswer['clipId'];
        if (!is_int($clipid) || $clipid <= 0) {
            throw new \invalid_parameter_exception('clipId is invalid.');
        }
        if (isset($rawanswer['text']) && trim((string)$rawanswer['text']) !== '') {
            // Both at once would leave open which of the two is the answer.
            // Refusing is the only reading that cannot be wrong.
            throw new \invalid_parameter_exception('clipId and text are exclusive.');
        }
        return $clipid;
    }

    /**
     * Plain bounded user text.
     */
    public static function text($raw, int $maxlength, string $field): string {
        if (!is_string($raw)) {
            throw new \invalid_parameter_exception($field . ' is invalid.');
        }
        $text = trim(clean_param($raw, PARAM_TEXT));
        $text = preg_replace('/\\s+/u', ' ', $text);
        $text = is_string($text) ? trim($text) : '';
        if ($text === '' || \core_text::strlen($text) > $maxlength) {
            throw new \invalid_parameter_exception($field . ' is invalid.');
        }
        return $text;
    }

    /**
     * Bounded user text which preserves intentional paragraph breaks.
     */
    public static function multiline_text(
        $raw,
        int $maxlength,
        string $field
    ): string {
        if (!is_string($raw)) {
            throw new \invalid_parameter_exception($field . ' is invalid.');
        }
        $text = str_replace(
            ["\r\n", "\r"],
            "\n",
            clean_param($raw, PARAM_TEXT)
        );
        $text = preg_replace('/[^\\S\\n]+/u', ' ', $text);
        $text = preg_replace('/ *\\n */u', "\n", (string)$text);
        $text = trim((string)$text);
        if ($text === '' || \core_text::strlen($text) > $maxlength) {
            throw new \invalid_parameter_exception($field . ' is invalid.');
        }
        return $text;
    }

    /**
     * Unicode-aware comparison/grouping key.
     */
    public static function text_key(string $text): string {
        if (class_exists('\\Normalizer')) {
            $normalised = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($normalised)) {
                $text = $normalised;
            }
        }
        $text = \core_text::strtolower(trim($text));
        $text = preg_replace('/\\s+/u', ' ', $text);
        return is_string($text) ? trim($text) : '';
    }

    /**
     * Project canonical option IDs to visit-bound, non-enumerable handles.
     *
     * The complete option set is deliberately part of this operation so the
     * cryptographically improbable HMAC collision can be detected. A public
     * sort may coincide with author order by pure chance, but it carries no
     * information about that order; conditioning the map to forbid such a
     * coincidence would itself leak information (and for two items would make
     * reverse-sort a guaranteed solution).
     *
     * An empty visit is retained only for server-internal projections such as
     * choice metadata, which never cross a client boundary.
     *
     * @param string[] $internalids Canonical IDs in author order.
     * @param string $visit Exact played visit token.
     * @param string $namespace Type-specific domain separator.
     * @return array<string, string> Internal ID to public handle.
     */
    public static function opaque_id_map(
        array $internalids,
        string $visit,
        string $namespace
    ): array {
        self::assert_opaque_inputs($internalids, $visit, $namespace);
        if ($visit === '') {
            return array_combine($internalids, $internalids) ?: [];
        }

        $map = [];
        $seenhandles = [];
        foreach ($internalids as $internalid) {
            $handle = 'h' . hash_hmac(
                'sha256',
                "option\0{$namespace}\0{$visit}\0{$internalid}",
                self::opaque_key()
            );
            if (isset($seenhandles[$handle])) {
                throw new \coding_exception(
                    'Opaque live option handle collision detected.'
                );
            }
            $seenhandles[$handle] = true;
            $map[$internalid] = $handle;
        }

        return $map;
    }

    /**
     * Project IDs while preserving their semantic order.
     *
     * @param string[] $internalids IDs to project.
     * @param string[] $available Complete canonical ID set.
     * @return string[]
     */
    public static function opaque_ids(
        array $internalids,
        array $available,
        string $visit,
        string $namespace
    ): array {
        $map = self::opaque_id_map($available, $visit, $namespace);
        $result = [];
        foreach ($internalids as $internalid) {
            if (!is_string($internalid) || !isset($map[$internalid])) {
                throw new \coding_exception(
                    'Canonical live option ID is not in its option set.'
                );
            }
            $result[] = $map[$internalid];
        }
        return $result;
    }

    /**
     * Resolve submitted public handles to canonical server-only IDs.
     *
     * @param string[] $handles Submitted public handles.
     * @param string[] $available Complete canonical ID set.
     * @return string[]
     */
    public static function resolve_opaque_ids(
        array $handles,
        array $available,
        string $visit,
        string $namespace
    ): array {
        $map = self::opaque_id_map($available, $visit, $namespace);
        if ($visit === '') {
            foreach ($handles as $handle) {
                if (!is_string($handle) || !isset($map[$handle])) {
                    throw new \invalid_parameter_exception(
                        'Unknown live option handle.'
                    );
                }
            }
            return array_values($handles);
        }

        $reverse = array_flip($map);
        $resolved = [];
        foreach ($handles as $handle) {
            if (!is_string($handle)
                    || !preg_match(self::OPAQUE_HANDLE_PATTERN, $handle)
                    || !isset($reverse[$handle])) {
                throw new \invalid_parameter_exception(
                    'Unknown live option handle.'
                );
            }
            $resolved[] = $reverse[$handle];
        }
        return $resolved;
    }

    /**
     * Replace canonical IDs in a persisted answer before client projection.
     *
     * Non-option question types are returned unchanged.
     */
    public static function public_answer(
        array $question,
        array $answer,
        string $visit
    ): array {
        $type = (string)($question['qtype'] ?? '');
        if (in_array($type, ['quiz', 'poll'], true)
                && is_array($answer['choiceIds'] ?? null)) {
            $available = array_values(array_column(
                $question['options']['answers'] ?? [],
                'id'
            ));
            $answer['choiceIds'] = self::opaque_ids(
                $answer['choiceIds'],
                $available,
                $visit,
                $type . ':choices'
            );
        } else if ($type === 'puzzle'
                && is_array($answer['orderIds'] ?? null)) {
            $available = array_values(array_column(
                $question['options']['items'] ?? [],
                'id'
            ));
            $answer['orderIds'] = self::opaque_ids(
                $answer['orderIds'],
                $available,
                $visit,
                'puzzle:items'
            );
        }
        return $answer;
    }

    /**
     * Stable secret-keyed per-visit ordering without touching the global RNG.
     *
     * A plain hash is insufficient: visit tokens are public and fallback
     * author IDs such as a/b/c are enumerable, so a client could reconstruct
     * the permutation and recover the canonical puzzle order.
     */
    public static function shuffled(array $rows, string $visit, string $salt): array {
        $decorated = [];
        foreach (array_values($rows) as $index => $row) {
            $id = is_array($row) ? (string)($row['id'] ?? $index) : (string)$index;
            $decorated[] = [
                'key' => hash_hmac(
                    'sha256',
                    "shuffle\0{$salt}\0{$visit}\0{$id}",
                    self::opaque_key()
                ),
                'index' => $index,
                'row' => $row,
            ];
        }
        usort($decorated, static function(array $left, array $right): int {
            $key = strcmp($left['key'], $right['key']);
            return $key !== 0 ? $key : ($left['index'] <=> $right['index']);
        });
        return array_values(array_column($decorated, 'row'));
    }

    /**
     * Build a visit-bound pluginfile alias for live question media.
     *
     * @param array $media Authoritative manifest entry.
     * @param string $publicid Opaque visit-bound handle.
     * @param string $visit Exact played visit.
     * @param string $mediakind Alias kind: question, answer or item.
     */
    public static function live_media_url(
        array $media,
        string $publicid,
        string $visit,
        string $mediakind
    ): string {
        if (!preg_match(self::OPAQUE_HANDLE_PATTERN, $publicid)
                || !preg_match('/^[a-f0-9]{32}$/D', $visit)
                || !in_array(
                    $mediakind,
                    ['question', 'answer', 'item'],
                    true
                )
                || !is_int($media['contextid'] ?? null)
                || $media['contextid'] <= 0
                || !is_int($media['itemid'] ?? null)
                || $media['itemid'] <= 0
                || !is_string($media['filename'] ?? null)
                || $media['filename'] === '') {
            throw new \coding_exception(
                'Live question media alias metadata is invalid.'
            );
        }
        $url = \moodle_url::make_pluginfile_url(
            $media['contextid'],
            'mod_quizgeist',
            'questionmedia',
            $media['itemid'],
            "/live/{$mediakind}/{$publicid}/",
            $media['filename'],
            false
        );
        $url->param('visit', $visit);
        return $url->out(false);
    }

    /**
     * Validate the closed inputs used for a public option map.
     *
     * @param string[] $internalids Canonical option IDs.
     */
    private static function assert_opaque_inputs(
        array $internalids,
        string $visit,
        string $namespace
    ): void {
        if (!array_is_list($internalids)
                || !$internalids
                || ($visit !== ''
                    && !preg_match('/^[a-f0-9]{32}$/D', $visit))
                || !preg_match('/^[a-z][a-z0-9:_-]{0,63}$/D', $namespace)) {
            throw new \coding_exception('Opaque live option inputs are invalid.');
        }
        $seen = [];
        foreach ($internalids as $internalid) {
            if (!is_string($internalid)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $internalid)
                    || isset($seen[$internalid])) {
                throw new \coding_exception(
                    'Canonical live option IDs are invalid.'
                );
            }
            $seen[$internalid] = true;
        }
    }

    /**
     * Derive a process-local binary key from Moodle's private site identity.
     */
    private static function opaque_key(): string {
        static $key = null;
        if (is_string($key)) {
            return $key;
        }
        $siteidentifier = function_exists('\\get_site_identifier')
            ? (string)\get_site_identifier()
            : '';
        if ($siteidentifier === '') {
            throw new \coding_exception(
                'Moodle site identity is unavailable for live option handles.'
            );
        }
        $key = hash(
            'sha256',
            "mod_quizgeist:live-option-handles:v1\0{$siteidentifier}",
            true
        );
        return $key;
    }

    /**
     * Deterministic median.
     *
     * @param float[]|int[] $values Values.
     * @return float|null
     */
    public static function median(array $values): ?float {
        if (!$values) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2
            ? (float)$values[$middle]
            : ((float)$values[$middle - 1] + (float)$values[$middle]) / 2;
    }

    /**
     * Resolve local media through the server-owned manifest.
     *
     * @return array{url:string,mimetype:string}|null
     */
    public static function media($path, array $mediafiles): ?array {
        return is_string($path) && $path !== ''
            ? ($mediafiles[$path] ?? null)
            : null;
    }
}
