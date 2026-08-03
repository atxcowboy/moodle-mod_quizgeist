<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\live\qtype;

use mod_quizgeist\local\live\aggregation_context;
use mod_quizgeist\local\live\interaction_policy;
use mod_quizgeist\local\live\projection_context;
use mod_quizgeist\local\live\scoring_context;
use mod_quizgeist\local\live\submission_context;

defined('MOODLE_INTERNAL') || die();

/**
 * Ungraded word cloud with stable server-side grouping.
 */
final class wordcloud implements live_question_type {

    public function type(): string {
        return 'wordcloud';
    }

    public function policy(array $question): interaction_policy {
        if (empty($question['options']['moderation'])) {
            return interaction_policy::standard(
                'text',
                true,
                true,
                true,
                false,
                strategy_support::reason_stage($question)
            );
        }

        // Moderation is an append-only, type-owned host interaction in the
        // ordinary answer stage. Players can therefore see an approved live
        // cloud without the state machine knowing anything about this qtype.
        return new interaction_policy('text', [[
            'key' => 'answer',
            'duration' => 'question',
            'submissions' => [
                'answer' => [
                    'answerType' => 'answer',
                    'role' => 'player',
                    'cardinality' => 'single',
                    'maxPerActor' => 1,
                ],
                'moderation' => [
                    'answerType' => 'moderation',
                    'role' => 'host',
                    'cardinality' => 'multiple',
                    'maxPerActor' => 1000,
                    'referenceAnswerTypes' => ['answer'],
                ],
            ],
        ]], true, ['answer'], true, false);
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        if ($context?->kind === 'moderation') {
            $termkey = $rawanswer['termKey'] ?? null;
            $decision = $rawanswer['decision'] ?? null;
            if (!is_string($termkey)
                    || !is_string($decision)
                    || !in_array($decision, ['approved', 'rejected'], true)
                    || \core_text::strlen($termkey) > 255) {
                throw new \invalid_parameter_exception(
                    'Word-cloud moderation is invalid.'
                );
            }
            $canonicalkey = strategy_support::text_key(
                clean_param($termkey, PARAM_TEXT)
            );
            if ($canonicalkey === '' || !hash_equals($canonicalkey, $termkey)) {
                throw new \invalid_parameter_exception(
                    'Word-cloud moderation is invalid.'
                );
            }
            $allowedkeys = [];
            foreach ($context->referenceanswers ?? [] as $reference) {
                if (($reference['answerType'] ?? null) !== 'answer'
                        || !is_array($reference['payload'] ?? null)) {
                    continue;
                }
                $payload = $reference['payload'];
                $key = is_string($payload['key'] ?? null)
                    ? strategy_support::text_key($payload['key'])
                    : (is_string($payload['text'] ?? null)
                        ? strategy_support::text_key($payload['text'])
                        : '');
                if ($key !== '') {
                    $allowedkeys[$key] = true;
                }
            }
            if (!isset($allowedkeys[$canonicalkey])) {
                throw new \invalid_parameter_exception(
                    'Word-cloud moderation references an unknown term.'
                );
            }
            return ['termKey' => $canonicalkey, 'decision' => $decision];
        }

        // F9: a spoken term has no grouping key yet, because it has no text
        // yet. clip_binding supplies both once the transcript arrives; until
        // then the contribution counts as given and shows as being transcribed.
        $clipid = strategy_support::spoken_clip_id($rawanswer);
        if ($clipid !== null) {
            return ['text' => '', 'key' => '', 'clipId' => $clipid];
        }

        $text = strategy_support::text(
            $rawanswer['text'] ?? null,
            (int)$question['options']['maxChars'],
            'text'
        );
        return ['text' => $text, 'key' => strategy_support::text_key($text)];
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        return strategy_support::evaluated(
            $question,
            $this->validate_answer($question, $rawanswer, $context),
            null,
            $scoring
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $counts = [];
        $labels = [];
        $decisions = [];
        foreach (strategy_support::rows($answers) as $row) {
            if ($row['answerType'] === 'moderation') {
                $termkey = $row['payload']['termKey'] ?? null;
                $decision = $row['payload']['decision'] ?? null;
                if (is_string($termkey)
                        && in_array($decision, ['approved', 'rejected'], true)) {
                    // Rows arrive in insertion order, so the latest host
                    // decision deterministically supersedes earlier choices.
                    $decisions[$termkey] = $decision;
                }
                continue;
            }
            if ($row['answerType'] !== 'answer') {
                continue;
            }
            $text = $row['payload']['text'] ?? null;
            if (!is_string($text)) {
                continue;
            }
            $key = is_string($row['payload']['key'] ?? null)
                ? $row['payload']['key']
                : strategy_support::text_key($text);
            $labels[$key] ??= $text;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $total = array_sum($counts);
        $keys = array_keys($counts);
        usort($keys, static function(string $left, string $right) use ($counts): int {
            $count = $counts[$right] <=> $counts[$left];
            return $count !== 0 ? $count : strnatcasecmp($left, $right);
        });
        $ismoderated = !empty($question['options']['moderation']);
        $publishedtotal = 0;
        foreach ($keys as $key) {
            if (!$ismoderated || ($decisions[$key] ?? null) === 'approved') {
                $publishedtotal += $counts[$key];
            }
        }
        $words = [];
        $moderationitems = [];
        $statuscounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($keys as $key) {
            $status = $ismoderated ? ($decisions[$key] ?? 'pending') : 'approved';
            $statuscounts[$status] += $counts[$key];
            if ($status === 'approved') {
                $words[] = [
                    'key' => $key,
                    'text' => $labels[$key],
                    'count' => $counts[$key],
                    'percent' => $publishedtotal > 0
                        ? round($counts[$key] * 100 / $publishedtotal, 1)
                        : 0,
                ];
            }
            if ($ismoderated && $context->role === 'host') {
                $moderationitems[] = [
                    'key' => $key,
                    'text' => $labels[$key],
                    'count' => $counts[$key],
                    'status' => $status,
                ];
            }
        }

        $aggregate = [
            'kind' => 'wordcloud',
            'total' => $total,
            'responseCount' => $total,
            'publishedCount' => $publishedtotal,
            'words' => $words,
        ];
        if ($ismoderated && $context->role === 'host') {
            $aggregate['moderation'] = [
                'pendingCount' => $statuscounts['pending'],
                'approvedCount' => $statuscounts['approved'],
                'rejectedCount' => $statuscounts['rejected'],
                'items' => $moderationitems,
            ];
        }
        return $aggregate;
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        return [
            'multiple' => false,
            'choices' => [],
            'responseType' => 'text',
            'typeData' => [
                'maxChars' => (int)$question['options']['maxChars'],
                'moderation' => !empty($question['options']['moderation']),
            ],
        ];
    }
}
