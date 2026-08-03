<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Role-safe live question DTOs.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

use mod_quizgeist\local\editor\editor_service;
use mod_quizgeist\local\editor\question_schema;
use mod_quizgeist\local\live\qtype\registry;
use mod_quizgeist\local\live\qtype\strategy_support;

defined('MOODLE_INTERNAL') || die();

/**
 * Projects one exact persisted question version into the live API.
 */
final class question_presenter {

    /**
     * Build a question DTO without ever resolving the lineage's active member.
     *
     * @param \stdClass $record Exact quizgeist_questions row.
     * @param \context_module $context Activity context.
     * @param array $state Canonical session state.
     * @param bool $includecorrect Whether correctness may be disclosed.
     * @param string $role host or player.
     * @param string $status Session phase.
     * @param string $stage Interaction stage.
     * @param string $explanationpolicy Worked-solution disclosure policy.
     * @param bool $friendlynew Whether the error-friendly framing applies.
     * @return array
     */
    public static function present(
        \stdClass $record,
        \context_module $context,
        array $state,
        bool $includecorrect,
        string $role = 'host',
        string $status = 'question',
        string $stage = 'answer',
        string $explanationpolicy = explanation_policy::NEVER,
        bool $friendlynew = false
    ): array {
        $record = clone $record;
        $record->timelimit = question_timing::seconds($record);
        $question = editor_service::serialise_question($record, $context);
        $mediafiles = [];
        foreach ($question['files'] as $file) {
            if (isset($file['path'], $file['url'], $file['mimetype'])
                    && is_string($file['path'])
                    && is_string($file['url'])
                    && is_string($file['mimetype'])) {
                $mimetype = strtolower(trim($file['mimetype']));
                if ($mimetype !== '') {
                    $filename = is_string($file['filename'] ?? null)
                        ? $file['filename']
                        : basename($file['path']);
                    $mediafiles[$file['path']] = [
                        'url' => $file['url'],
                        'mimetype' => $mimetype,
                        'contextid' => (int)$context->id,
                        'itemid' => (int)$question['id'],
                        'filename' => $filename,
                    ];
                }
            }
        }
        $strategy = registry::get((string)$question['qtype']);
        $policy = $strategy->policy($question);
        $policydescriptor = $policy->descriptor($stage, $role);
        $projection = $strategy->project(
            $question,
            $mediafiles,
            new projection_context(
                $includecorrect,
                $role,
                $status,
                $stage,
                (string)$state['questionToken']
            )
        );

        $dto = [
            'id' => (int)$question['id'],
            'rootId' => (int)$question['rootid'],
            'version' => (int)$question['version'],
            'index' => (int)$state['currentIndex'],
            'total' => count($state['questionIds']),
            'qtype' => (string)$question['qtype'],
            'questionText' => (string)$question['questiontext'],
            'timeLimit' => (int)$question['timelimit'],
            'pointMode' => (string)$question['pointmode'],
            'multiple' => (bool)($projection['multiple'] ?? false),
            'questionToken' => (string)$state['questionToken'],
            'choices' => array_values($projection['choices'] ?? []),
            'responseType' => (string)(
                $projection['responseType']
                ?? $policy->response_type()
            ),
            'typeData' => is_array($projection['typeData'] ?? null)
                ? $projection['typeData']
                : [],
            'interactionStage' => $stage,
            'allowsMultipleSubmissions' =>
                $policydescriptor['allowsMultipleSubmissions'],
            'policyDescriptor' => $policydescriptor,
            // F1: the error-friendly framing of a question marked "new".
            // Decided on the server from the reserved tag, never guessed
            // in the client.
            'friendlyNew' => $friendlynew,
        ];
        // F8: the one place that may put a worked solution into a question
        // DTO. With `atend` or `never` the key is absent from the payload;
        // there is nothing for a client to un-hide.
        $explanation = trim((string)($question['explanation'] ?? ''));
        if ($includecorrect
                && $explanation !== ''
                && explanation_policy::discloses_on_reveal($explanationpolicy)) {
            $dto['explanation'] = $explanation;
        }
        $media = self::media(
            $question['options']['media'] ?? null,
            $mediafiles,
            (string)$state['questionToken']
        );
        if ($media !== null) {
            $dto['mediaUrl'] = $media['url'];
            $dto['mediaMimeType'] = $media['mimetype'];
        }
        return $dto;
    }

    /**
     * Return answer-choice metadata for aggregate construction.
     *
     * @param \stdClass $record Exact persisted question.
     * @return array<int, array{id:string,correct:?bool}>
     */
    public static function choice_metadata(\stdClass $record): array {
        $record = clone $record;
        $record->timelimit = question_timing::seconds($record);
        $normalised = question_schema::normalise([
            'qtype' => (string)$record->qtype,
            'questiontext' => (string)$record->questiontext,
            'options' => question_schema::decode_options($record->optionsjson ?? null),
            'timelimit' => (int)$record->timelimit,
            'pointmode' => (string)$record->pointmode,
            'explanation' => (string)$record->explanation,
        ]);
        $question = $normalised['question'];
        $aggregate = registry::get((string)$question['qtype'])->aggregate(
            $question,
            [],
            new aggregation_context(true)
        );
        if (!array_is_list($aggregate)) {
            return [];
        }
        return array_map(
            static fn(array $entry): array => [
                'id' => (string)$entry['choiceId'],
                'correct' => array_key_exists('correct', $entry)
                    ? (bool)$entry['correct']
                    : null,
            ],
            array_values(array_filter(
                $aggregate,
                static fn($entry): bool => is_array($entry)
                    && isset($entry['choiceId'])
            ))
        );
    }

    /**
     * Resolve one server-owned media path against its authoritative manifest.
     *
     * @param mixed $path Canonical media path.
     * @param array<string, array{
     *     url:string,
     *     mimetype:string,
     *     contextid:int,
     *     itemid:int,
     *     filename:string
     * }> $mediafiles Manifest lookup.
     * @param string $visit Exact played visit.
     * @return array{url:string,mimetype:string}|null
     */
    private static function media(
        $path,
        array $mediafiles,
        string $visit
    ): ?array {
        if (!is_string($path) || $path === '') {
            return null;
        }
        $media = $mediafiles[$path] ?? null;
        if (!is_array($media)) {
            return null;
        }
        $handle = strategy_support::opaque_ids(
            ['media'],
            ['media'],
            $visit,
            'question:media'
        )[0];
        return [
            'url' => strategy_support::live_media_url(
                $media,
                $handle,
                $visit,
                'question'
            ),
            'mimetype' => (string)$media['mimetype'],
        ];
    }
}
