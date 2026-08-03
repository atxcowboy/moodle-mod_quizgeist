<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared choice validation, projection and aggregation.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live\qtype;

defined('MOODLE_INTERNAL') || die();

/**
 * Reusable mechanics for choice-based live question strategies.
 */
final class choice_support {

    /**
     * Validate a short unique list of canonical choice IDs.
     *
     * @param array $raw Raw IDs.
     * @return string[]
     */
    public static function canonical_ids(array $raw): array {
        if (isset($raw['choiceIds']) && is_array($raw['choiceIds'])) {
            $raw = $raw['choiceIds'];
        }
        if (!array_is_list($raw) || !$raw || count($raw) > 6) {
            throw new \invalid_parameter_exception('choiceIds must be a non-empty list.');
        }
        $choices = [];
        foreach ($raw as $value) {
            if (!is_string($value)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,64}$/D', $value)
                    || isset($choices[$value])) {
                throw new \invalid_parameter_exception('choiceIds is invalid.');
            }
            $choices[$value] = $value;
        }
        return array_values($choices);
    }

    /**
     * Extract legacy or stored choice lists from visit rows.
     *
     * @param array $answers Rows or payloads.
     * @return array<int, string[]>
     */
    public static function answer_lists(array $answers): array {
        $lists = [];
        foreach (strategy_support::rows($answers) as $row) {
            $choiceids = $row['payload']['choiceIds'] ?? null;
            if (is_array($choiceids) && array_is_list($choiceids)) {
                $lists[] = array_values(array_filter($choiceids, 'is_string'));
            }
        }
        return $lists;
    }

    /**
     * Ensure every selected ID belongs to the exact played version.
     *
     * @param string[] $selected Selected IDs.
     * @param string[] $available Available IDs.
     * @return void
     */
    public static function assert_available(array $selected, array $available): void {
        foreach ($selected as $choiceid) {
            if (!in_array($choiceid, $available, true)) {
                throw new \invalid_parameter_exception('Unknown answer choice.');
            }
        }
    }

    /**
     * Project one canonical text/media answer.
     *
     * @param array $answer Canonical answer.
     * @param array $mediafiles Media lookup.
     * @param string|null $publicid Visit-bound public ID.
     * @param string $visit Exact played visit.
     * @param string $mediakind Alias kind, answer or item.
     * @return array
     */
    public static function project_choice(
        array $answer,
        array $mediafiles,
        ?string $publicid = null,
        string $visit = '',
        string $mediakind = ''
    ): array {
        $choice = [
            'id' => $publicid ?? (string)$answer['id'],
            'text' => (string)$answer['text'],
        ];
        $path = $answer['media'] ?? null;
        if (is_string($path) && $path !== '' && isset($mediafiles[$path])) {
            $media = $mediafiles[$path];
            $choice['mediaUrl'] = $visit === ''
                ? (string)$media['url']
                : strategy_support::live_media_url(
                    $media,
                    (string)$publicid,
                    $visit,
                    $mediakind
                );
            $choice['mediaMimeType'] = (string)$media['mimetype'];
        }
        return $choice;
    }

    /**
     * Build a choice distribution from one answer list per player.
     *
     * @param array<int, array{id:string,correct:?bool}> $metadata Choice metadata.
     * @param array<int, string[]> $answers Canonical submitted choice lists.
     * @param bool $includecorrect Whether correctness may be disclosed.
     * @return array<int, array{choiceId:string,count:int,percent:float|int,correct?:bool}>
     */
    public static function aggregate(
        array $metadata,
        array $answers,
        bool $includecorrect
    ): array {
        $counts = [];
        foreach ($answers as $choiceids) {
            if (!is_array($choiceids)) {
                continue;
            }
            foreach ($choiceids as $choiceid) {
                if (is_string($choiceid)) {
                    $counts[$choiceid] = ($counts[$choiceid] ?? 0) + 1;
                }
            }
        }
        $denominator = count($answers);
        $result = [];
        foreach ($metadata as $choice) {
            $count = (int)($counts[$choice['id']] ?? 0);
            $entry = [
                'choiceId' => (string)$choice['id'],
                'count' => $count,
                'percent' => $denominator > 0
                    ? round(($count * 100) / $denominator, 1)
                    : 0,
            ];
            if ($includecorrect && $choice['correct'] !== null) {
                $entry['correct'] = (bool)$choice['correct'];
            }
            $result[] = $entry;
        }
        return $result;
    }
}
