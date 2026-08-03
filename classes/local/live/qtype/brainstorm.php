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
 * Append-only idea collection, host grouping and player voting.
 */
final class brainstorm implements live_question_type {

    private const MAX_GROUPS = 50;

    public function type(): string {
        return 'brainstorm';
    }

    public function policy(array $question): interaction_policy {
        return new interaction_policy('brainstorm', [
            [
                'key' => 'collect',
                'labelKey' => 'live:brainstorm:collect',
                'advanceLabelKey' => 'host:action:groupideas',
                'duration' => 'option:collectSeconds',
                'submissions' => [
                    'idea' => [
                        'answerType' => 'idea',
                        'role' => 'player',
                        'cardinality' => 'multiple',
                        'maxPerActor' => 5,
                    ],
                    'moderation' => [
                        'answerType' => 'moderation',
                        'role' => 'host',
                        'cardinality' => 'multiple',
                        'maxPerActor' => 1000,
                        'referenceAnswerTypes' => ['idea'],
                    ],
                ],
            ],
            [
                'key' => 'group',
                'labelKey' => 'live:brainstorm:group',
                'advanceLabelKey' => 'host:action:startvote',
                'duration' => 'none',
                'requiredSubmission' => 'group',
                'requiredWhenAnswerTypes' => ['idea'],
                'requiredExclusion' => [
                    'answerType' => 'moderation',
                    'referenceField' => 'ideaId',
                    'decisionField' => 'decision',
                    'excludedValue' => 'rejected',
                ],
                'submissions' => [
                    'group' => [
                        'answerType' => 'group',
                        'role' => 'host',
                        'cardinality' => 'multiple',
                        // Grouping is an editable moderation artefact. Keep an
                        // abuse ceiling, but never exhaust it in an ordinary
                        // lesson with repeated saves.
                        'maxPerActor' => 1000,
                        'referenceAnswerTypes' => ['idea', 'moderation'],
                    ],
                    'moderation' => [
                        'answerType' => 'moderation',
                        'role' => 'host',
                        'cardinality' => 'multiple',
                        'maxPerActor' => 1000,
                        'referenceAnswerTypes' => ['idea'],
                    ],
                ],
            ],
            [
                'key' => 'vote',
                'labelKey' => 'live:brainstorm:vote',
                'duration' => 'option:voteSeconds',
                'submissions' => [
                    'vote' => [
                        'answerType' => 'vote',
                        'role' => 'player',
                        'cardinality' => 'single',
                        'maxPerActor' => 1,
                        'referenceAnswerTypes' => [
                            'group',
                            'idea',
                            'moderation',
                        ],
                    ],
                ],
            ],
        ], true, ['group', 'vote'], true, false);
    }

    public function validate_answer(
        array $question,
        array $rawanswer,
        ?submission_context $context = null
    ): array {
        $kind = $context?->kind;
        if ($kind === null) {
            $kind = isset($rawanswer['groups']) ? 'group'
                : (isset($rawanswer['groupKey']) ? 'vote'
                    : (isset($rawanswer['ideaId']) ? 'moderation' : 'idea'));
        }
        if ($kind === 'idea') {
            // F9: only an IDEA can be spoken. Grouping and voting are host and
            // navigation acts, where a clip reference would be meaningless.
            $clipid = strategy_support::spoken_clip_id($rawanswer);
            if ($clipid !== null) {
                return ['text' => '', 'clipId' => $clipid];
            }
            return [
                'text' => strategy_support::text(
                    $rawanswer['text'] ?? null,
                    1000,
                    'text'
                ),
            ];
        }
        if ($kind === 'vote') {
            $key = $rawanswer['groupKey'] ?? null;
            if (!is_string($key)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key)) {
                throw new \invalid_parameter_exception('groupKey is invalid.');
            }
            if ($context?->referenceanswers !== null) {
                $latestgroups = null;
                $latestid = -1;
                $activeideas = array_fill_keys(
                    self::active_idea_ids($context->referenceanswers),
                    true
                );
                foreach ($context->referenceanswers as $reference) {
                    if ($reference['answerType'] === 'group'
                            && $reference['id'] >= $latestid
                            && is_array(
                                $reference['payload']['groups'] ?? null
                            )) {
                        $latestid = $reference['id'];
                        $latestgroups = $reference['payload']['groups'];
                    }
                }
                $allowedkeys = [];
                foreach ($latestgroups ?? [] as $group) {
                    if (is_array($group)
                            && is_string($group['key'] ?? null)
                            && is_array($group['ideaIds'] ?? null)) {
                        foreach ($group['ideaIds'] as $ideaid) {
                            if (is_int($ideaid) && isset($activeideas[$ideaid])) {
                                $allowedkeys[$group['key']] = true;
                                break;
                            }
                        }
                    }
                }
                if (!isset($allowedkeys[$key])) {
                    throw new \invalid_parameter_exception(
                        'groupKey does not reference a current group.'
                    );
                }
            }
            return ['groupKey' => $key];
        }
        if ($kind === 'moderation') {
            $ideaid = $rawanswer['ideaId'] ?? null;
            $decision = $rawanswer['decision'] ?? null;
            if (is_string($ideaid) && preg_match('/^[1-9][0-9]*$/D', $ideaid)) {
                $ideaid = (int)$ideaid;
            }
            if (!is_int($ideaid)
                    || $ideaid <= 0
                    || !is_string($decision)
                    || !in_array($decision, ['approved', 'rejected'], true)) {
                throw new \invalid_parameter_exception(
                    'Brainstorm moderation is invalid.'
                );
            }
            $allowed = [];
            foreach ($context?->referenceanswers ?? [] as $reference) {
                if (($reference['answerType'] ?? null) === 'idea') {
                    $allowed[(int)$reference['id']] = true;
                }
            }
            if ($context !== null && !isset($allowed[$ideaid])) {
                throw new \invalid_parameter_exception(
                    'Brainstorm moderation references an unknown idea.'
                );
            }
            return ['ideaId' => $ideaid, 'decision' => $decision];
        }
        if ($kind !== 'group'
                || !is_array($rawanswer['groups'] ?? null)
                || !array_is_list($rawanswer['groups'])
                || !$rawanswer['groups']
                || count($rawanswer['groups']) > self::MAX_GROUPS) {
            throw new \invalid_parameter_exception('Brainstorm groups are invalid.');
        }
        $groups = [];
        $keys = [];
        $ideaids = [];
        foreach ($rawanswer['groups'] as $group) {
            $key = is_array($group) ? ($group['key'] ?? null) : null;
            $ids = is_array($group) ? ($group['ideaIds'] ?? null) : null;
            if (!is_string($key)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key)
                    || isset($keys[$key])
                    || !is_array($ids)
                    || !array_is_list($ids)
                    || !$ids) {
                throw new \invalid_parameter_exception('Brainstorm groups are invalid.');
            }
            $canonicalids = [];
            foreach ($ids as $id) {
                if (is_string($id)
                        && preg_match('/^[1-9][0-9]*$/D', $id)) {
                    $id = (int)$id;
                }
                if (!is_int($id) || $id <= 0 || isset($ideaids[$id])) {
                    throw new \invalid_parameter_exception('Brainstorm idea IDs are invalid.');
                }
                $ideaids[$id] = true;
                $canonicalids[] = $id;
            }
            $keys[$key] = true;
            $groups[] = [
                'key' => $key,
                'label' => strategy_support::text(
                    $group['label'] ?? null,
                    255,
                    'label'
                ),
                'ideaIds' => $canonicalids,
            ];
        }
        if ($context?->referenceanswers !== null) {
            $allowed = self::active_idea_ids($context->referenceanswers);
            $submitted = array_keys($ideaids);
            sort($allowed, SORT_NUMERIC);
            sort($submitted, SORT_NUMERIC);
            if ($allowed !== $submitted) {
                throw new \invalid_parameter_exception(
                    'Brainstorm groups must cover every current idea.'
                );
            }
        }
        return ['groups' => $groups];
    }

    public function evaluate(
        array $question,
        array $rawanswer,
        scoring_context $scoring,
        ?submission_context $context = null
    ): array {
        if ($context === null) {
            throw new \invalid_parameter_exception(
                'Brainstorm evaluation requires a submission context.'
            );
        }
        $answer = $this->validate_answer($question, $rawanswer, $context);
        return strategy_support::evaluated(
            $question,
            $answer,
            null,
            $scoring,
            1.0,
            $context->kind
        );
    }

    public function aggregate(
        array $question,
        array $answers,
        aggregation_context $context
    ): array {
        $ideas = [];
        $latestgroups = null;
        $latestgroupid = -1;
        $votes = [];
        $decisions = [];
        $responders = [];
        foreach (strategy_support::rows($answers) as $row) {
            if ($row['answerType'] === 'idea'
                    && is_string($row['payload']['text'] ?? null)
                    && $row['id'] > 0) {
                $ideas[$row['id']] = [
                    'id' => $row['id'],
                    'text' => $row['payload']['text'],
                    'playerId' => $row['playerId'],
                ];
                if ($context->stage === 'collect' && $row['playerId'] > 0) {
                    $responders[$row['playerId']] = true;
                }
            } else if ($row['answerType'] === 'moderation') {
                $ideaid = $row['payload']['ideaId'] ?? null;
                $decision = $row['payload']['decision'] ?? null;
                if (is_int($ideaid)
                        && isset($ideas[$ideaid])
                        && in_array($decision, ['approved', 'rejected'], true)) {
                    $decisions[$ideaid] = $decision;
                }
            } else if ($row['answerType'] === 'group'
                    && is_array($row['payload']['groups'] ?? null)
                    && $row['id'] >= $latestgroupid) {
                $latestgroupid = $row['id'];
                $latestgroups = $row['payload']['groups'];
            } else if ($row['answerType'] === 'vote'
                    && is_string($row['payload']['groupKey'] ?? null)) {
                $key = $row['payload']['groupKey'];
                $votes[$key] = ($votes[$key] ?? 0) + 1;
                if ($context->stage === 'vote' && $row['playerId'] > 0) {
                    $responders[$row['playerId']] = true;
                }
            }
        }
        foreach ($decisions as $ideaid => $decision) {
            if ($decision === 'rejected') {
                unset($ideas[$ideaid]);
            }
        }

        $activeideaids = array_values(array_map('intval', array_keys($ideas)));
        sort($activeideaids, SORT_NUMERIC);
        $storedgroupideaids = [];
        foreach ($latestgroups ?? [] as $group) {
            foreach (is_array($group['ideaIds'] ?? null)
                    ? $group['ideaIds']
                    : [] as $ideaid) {
                if (is_int($ideaid)) {
                    $storedgroupideaids[] = $ideaid;
                }
            }
        }
        sort($storedgroupideaids, SORT_NUMERIC);
        $groupingcurrent = $latestgroups !== null
            && $storedgroupideaids === $activeideaids;

        $groups = [];
        $assigned = [];
        $totalvotes = 0;
        foreach ($latestgroups ?? [] as $group) {
            if (!is_array($group)
                    || !is_string($group['key'] ?? null)
                    || !is_array($group['ideaIds'] ?? null)) {
                continue;
            }
            $groupideas = [];
            foreach ($group['ideaIds'] as $ideaid) {
                if (is_int($ideaid) && isset($ideas[$ideaid])) {
                    $groupideas[] = [
                        'id' => $ideaid,
                        'text' => $ideas[$ideaid]['text'],
                    ];
                    $assigned[$ideaid] = true;
                }
            }
            if ($groupideas) {
                $groupvotes = (int)($votes[$group['key']] ?? 0);
                $totalvotes += $groupvotes;
                $groups[] = [
                    'key' => $group['key'],
                    'label' => (string)($group['label'] ?? ''),
                    'ideas' => $groupideas,
                    'votes' => $context->role === 'player'
                            && !$context->includecorrect
                        ? null
                        : $groupvotes,
                ];
            }
        }
        foreach ($ideas as $ideaid => $idea) {
            if (!isset($assigned[$ideaid])) {
                $key = 'idea-' . $ideaid;
                $groupvotes = (int)($votes[$key] ?? 0);
                $totalvotes += $groupvotes;
                $groups[] = [
                    'key' => $key,
                    'label' => $idea['text'],
                    'ideas' => [['id' => $ideaid, 'text' => $idea['text']]],
                    'votes' => $context->role === 'player'
                            && !$context->includecorrect
                        ? null
                        : $groupvotes,
                ];
            }
        }
        $aggregate = [
            'kind' => 'brainstorm',
            'stage' => $context->includecorrect ? 'done' : $context->stage,
            'groups' => array_values($groups),
            // Host UI uses this to avoid appending the unchanged grouping
            // again immediately before an advance. It becomes false whenever
            // moderation or privacy changes the active idea set.
            'groupingCurrent' => $groupingcurrent,
            'responseCount' => count($responders),
            'totalIdeas' => count($ideas),
            'totalVotes' => $context->role === 'player'
                    && !$context->includecorrect
                ? null
                : $totalvotes,
        ];
        if ($context->role === 'host') {
            $moderationitems = [];
            foreach (strategy_support::rows($answers) as $row) {
                if ($row['answerType'] !== 'idea'
                        || !is_string($row['payload']['text'] ?? null)
                        || $row['id'] <= 0) {
                    continue;
                }
                $moderationitems[] = [
                    'id' => $row['id'],
                    'text' => $row['payload']['text'],
                    'status' => $decisions[$row['id']] ?? 'approved',
                ];
            }
            $aggregate['moderation'] = ['items' => $moderationitems];
        }
        return $aggregate;
    }

    public function project(
        array $question,
        array $mediafiles,
        projection_context $context
    ): array {
        return [
            'multiple' => true,
            'choices' => [],
            'responseType' => 'brainstorm',
            'typeData' => [
                'stage' => $context->stage,
                'maxIdeaChars' => 1000,
                'collectSeconds' => (int)$question['options']['collectSeconds'],
                'voteSeconds' => (int)$question['options']['voteSeconds'],
                'grouping' => (string)$question['options']['grouping'],
                'moderation' => true,
            ],
        ];
    }

    /**
     * Resolve ideas which the latest host decision has not rejected.
     *
     * @param array<int,array{id:int,answerType:string,payload:array}> $references
     * @return int[]
     */
    private static function active_idea_ids(array $references): array {
        $ideas = [];
        $decisions = [];
        foreach ($references as $reference) {
            if (($reference['answerType'] ?? null) === 'idea') {
                $ideas[(int)$reference['id']] = true;
            } else if (($reference['answerType'] ?? null) === 'moderation') {
                $ideaid = $reference['payload']['ideaId'] ?? null;
                $decision = $reference['payload']['decision'] ?? null;
                if (is_int($ideaid)
                        && in_array($decision, ['approved', 'rejected'], true)) {
                    $decisions[$ideaid] = $decision;
                }
            }
        }
        foreach ($decisions as $ideaid => $decision) {
            if ($decision === 'rejected') {
                unset($ideas[$ideaid]);
            }
        }
        return array_values(array_map('intval', array_keys($ideas)));
    }
}
