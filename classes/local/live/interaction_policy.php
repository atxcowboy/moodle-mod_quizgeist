<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Declarative question interaction flow.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Lets the state machine support staged types without qtype conditionals.
 */
final class interaction_policy {

    /**
     * @param string $responsetype Public response renderer key.
     * @param array $stages Stable ordered stage definitions.
     * @param bool $liveaggregate Whether aggregates change during the question.
     * @param string[] $aggregateplayers Player-visible aggregate phases.
     * @param bool $showscorrectness Whether reveal has a right/wrong result.
     */
    public function __construct(
        private readonly string $responsetype,
        private readonly array $stages,
        private readonly bool $liveaggregate = false,
        private readonly array $aggregateplayers = [],
        private readonly bool $playerrevealedaggregate = true,
        private readonly bool $showscorrectness = true
    ) {
        if (!$stages || !array_is_list($stages)) {
            throw new \coding_exception('A live interaction policy needs stages.');
        }
        foreach ($stages as $stage) {
            if (!is_array($stage)
                    || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $stage['key'] ?? '')
                    || !is_array($stage['submissions'] ?? null)) {
                throw new \coding_exception('Live interaction policy is malformed.');
            }
            foreach (['labelKey', 'advanceLabelKey'] as $labelkey) {
                if (isset($stage[$labelkey])
                        && (!is_string($stage[$labelkey])
                            || !preg_match('/^[a-z][a-z0-9:_-]{0,79}$/D', $stage[$labelkey]))) {
                    throw new \coding_exception('Live interaction policy label is malformed.');
                }
            }
            if (isset($stage['requiredExclusion'])) {
                $exclusion = $stage['requiredExclusion'];
                if (!is_array($exclusion)
                        || !self::policy_identifier($exclusion['answerType'] ?? null)
                        || !self::policy_identifier($exclusion['referenceField'] ?? null)
                        || !self::policy_identifier($exclusion['decisionField'] ?? null)
                        || !is_string($exclusion['excludedValue'] ?? null)
                        || $exclusion['excludedValue'] === '') {
                    throw new \coding_exception(
                        'Live interaction requirement exclusion is malformed.'
                    );
                }
            }
        }
    }

    /**
     * One ordinary, single-response question.
     *
     * With $reasonstage the flow becomes `answer → reason`: after the answer
     * and before the reveal the learner writes one short justification (F2
     * Denk-Moment). That is a pure interaction-policy matter — no new table,
     * no touched state machine — and a reason never carries points.
     *
     * With $stagestage one further stage follows (F13 Bühnen-Check): the
     * learner presents the answer in front of the camera. Same construction,
     * same promise — the stage check is a SUB-MODE of an existing type, never
     * a type of its own, and it never carries points either.
     */
    public static function standard(
        string $responsetype,
        bool $liveaggregate = false,
        bool $playerliveaggregate = false,
        bool $playerrevealedaggregate = true,
        bool $showscorrectness = true,
        bool $reasonstage = false,
        bool $stagestage = false
    ): self {
        $stages = [[
            'key' => 'answer',
            'labelKey' => 'live:stage:answer',
            'duration' => 'question',
            'submissions' => [
                'answer' => [
                    'answerType' => 'answer',
                    'role' => 'player',
                    'cardinality' => 'single',
                    'maxPerActor' => 1,
                ],
            ],
        ]];
        if ($reasonstage) {
            $stages[0]['advanceLabelKey'] = 'live:stage:reason';
            $stages[] = [
                'key' => 'reason',
                'labelKey' => 'live:stage:reason',
                'duration' => 'question',
                'submissions' => [
                    'reason' => [
                        'answerType' => 'reason',
                        'role' => 'player',
                        'cardinality' => 'single',
                        'maxPerActor' => 1,
                        'referenceAnswerTypes' => ['answer'],
                    ],
                ],
            ];
        }
        if ($stagestage) {
            $stages[count($stages) - 1]['advanceLabelKey'] = 'live:stage:stage';
            $stages[] = self::stage_stage(['answer']);
        }
        return new self(
            $responsetype,
            $stages,
            $liveaggregate,
            $playerliveaggregate ? ['answer'] : [],
            $playerrevealedaggregate,
            $showscorrectness
        );
    }

    /**
     * The one stage definition of the F13 stage check.
     *
     * It is built HERE and nowhere else, so `open` (which is assembled by
     * standard()) and `slide` (which builds its stages by hand) can never
     * drift apart. Duration is `none`: the teacher decides when the class
     * moves on, because a presentation is not a countdown.
     *
     * @param string[] $referenceanswertypes Answer types the presentation
     *                 belongs to; empty for a slide, which carries no answer.
     * @return array The stage definition.
     */
    public static function stage_stage(array $referenceanswertypes = []): array {
        $submission = [
            'answerType' => 'stage',
            'role' => 'player',
            'cardinality' => 'single',
            'maxPerActor' => 1,
        ];
        if ($referenceanswertypes !== []) {
            $submission['referenceAnswerTypes'] = array_values($referenceanswertypes);
        }
        return [
            'key' => 'stage',
            'labelKey' => 'live:stage:stage',
            'duration' => 'none',
            'submissions' => ['stage' => $submission],
        ];
    }

    public function response_type(): string {
        return $this->responsetype;
    }

    public function initial_stage(): string {
        return (string)$this->stages[0]['key'];
    }

    public function supports_stage(string $stage): bool {
        return $this->stage_definition($stage) !== null;
    }

    public function final_stage(string $stage): bool {
        $keys = array_column($this->stages, 'key');
        return $stage === (string)end($keys);
    }

    public function next_stage(string $stage): ?string {
        $keys = array_values(array_column($this->stages, 'key'));
        $index = array_search($stage, $keys, true);
        if ($index === false) {
            throw new \invalid_parameter_exception('Live interaction stage is invalid.');
        }
        return $keys[$index + 1] ?? null;
    }

    /**
     * Resolve one allowed submission without inspecting qtype in the service.
     *
     * @return array{answerType:string,role:string,cardinality:string,maxPerActor:int}
     */
    public function submission(string $stage, string $kind, string $role): array {
        $definition = $this->stage_definition($stage);
        $submission = $definition['submissions'][$kind] ?? null;
        if (!is_array($submission)
                || !hash_equals((string)$submission['role'], $role)) {
            throw new live_domain_exception(
                'interaction_not_allowed',
                'live:error:questionnotopen'
            );
        }
        return $submission;
    }

    /**
     * Resolve the sole allowed kind for one role at a stage.
     */
    public function default_kind(string $stage, string $role): string {
        $definition = $this->stage_definition($stage);
        $kinds = [];
        foreach (($definition['submissions'] ?? []) as $kind => $submission) {
            if (is_array($submission)
                    && hash_equals((string)($submission['role'] ?? ''), $role)) {
                $kinds[] = (string)$kind;
            }
        }
        if (count($kinds) !== 1) {
            throw new live_domain_exception(
                'interaction_not_allowed',
                'live:error:questionnotopen'
            );
        }
        return $kinds[0];
    }

    public function required_submission(string $stage): ?string {
        $definition = $this->stage_definition($stage);
        $required = $definition['requiredSubmission'] ?? null;
        return is_string($required) ? $required : null;
    }

    /**
     * Answer types which make the stage's host submission mandatory.
     *
     * An empty list means that a declared required submission is always
     * required. This keeps staged-flow decisions independent of qtype names.
     *
     * @return string[]
     */
    public function required_when_answer_types(string $stage): array {
        $definition = $this->stage_definition($stage);
        $types = $definition['requiredWhenAnswerTypes'] ?? [];
        if (!is_array($types) || !array_is_list($types)) {
            throw new \coding_exception('Live interaction policy is malformed.');
        }
        return array_values(array_filter(
            $types,
            static fn($type): bool => is_string($type) && $type !== ''
        ));
    }

    /**
     * Answer types needed to evaluate whether a host submission is required.
     *
     * @return string[]
     */
    public function requirement_reference_types(string $stage): array {
        $definition = $this->stage_definition($stage);
        if ($definition === null) {
            throw new \invalid_parameter_exception('Live interaction stage is invalid.');
        }
        $types = $this->required_when_answer_types($stage);
        $exclusiontype = $definition['requiredExclusion']['answerType'] ?? null;
        if (is_string($exclusiontype)) {
            $types[] = $exclusiontype;
        }
        return array_values(array_unique($types));
    }

    /**
     * Apply a declarative latest-decision filter to a stage requirement.
     *
     * @param string $stage Current stage.
     * @param array<int,array{id:int,answerType:string,payload:array}> $references
     */
    public function required_submission_applies(
        string $stage,
        array $references
    ): bool {
        $definition = $this->stage_definition($stage);
        if ($definition === null) {
            throw new \invalid_parameter_exception('Live interaction stage is invalid.');
        }
        $requiredtypes = $this->required_when_answer_types($stage);
        if (!$requiredtypes) {
            return true;
        }
        $active = [];
        foreach ($references as $reference) {
            if (in_array($reference['answerType'] ?? null, $requiredtypes, true)) {
                $active[(int)$reference['id']] = true;
            }
        }
        $exclusion = $definition['requiredExclusion'] ?? null;
        if (!is_array($exclusion)) {
            return (bool)$active;
        }
        $decisions = [];
        foreach ($references as $reference) {
            if (($reference['answerType'] ?? null) !== $exclusion['answerType']) {
                continue;
            }
            $referenceid = $reference['payload'][$exclusion['referenceField']] ?? null;
            $decision = $reference['payload'][$exclusion['decisionField']] ?? null;
            if (is_int($referenceid) && is_string($decision)) {
                $decisions[$referenceid] = $decision;
            }
        }
        foreach ($decisions as $referenceid => $decision) {
            if (hash_equals((string)$exclusion['excludedValue'], $decision)) {
                unset($active[$referenceid]);
            }
            // A non-excluding (for example later approved) decision leaves an
            // existing idea active. It must never recreate a reference whose
            // personal answer row has meanwhile been erased.
        }
        return (bool)$active;
    }

    /**
     * Persisted answer types used by the complete aggregate.
     *
     * @return string[]
     */
    public function answer_types(): array {
        $types = [];
        foreach ($this->stages as $stage) {
            foreach ($stage['submissions'] as $submission) {
                $types[(string)$submission['answerType']] = true;
            }
        }
        return array_keys($types);
    }

    /**
     * Player answer types which count as a response in one stage.
     *
     * @return string[]
     */
    public function player_answer_types(string $stage): array {
        $definition = $this->stage_definition($stage);
        if ($definition === null) {
            throw new \invalid_parameter_exception('Live interaction stage is invalid.');
        }
        $types = [];
        foreach ($definition['submissions'] as $submission) {
            if (($submission['role'] ?? null) === 'player') {
                $types[(string)$submission['answerType']] = true;
            }
        }
        return array_keys($types);
    }

    public function duration_seconds(array $question, string $stage): int {
        $definition = $this->stage_definition($stage);
        if ($definition === null) {
            throw new \invalid_parameter_exception('Live interaction stage is invalid.');
        }
        $duration = (string)$definition['duration'];
        if ($duration === 'none') {
            return 0;
        }
        if ($duration === 'question') {
            return question_timing::normalise((int)$question['timelimit']);
        }
        $field = substr($duration, strlen('option:'));
        return max(0, (int)($question['options'][$field] ?? 0));
    }

    public function has_live_aggregate(): bool {
        return $this->liveaggregate;
    }

    /**
     * Project the server-owned interaction contract for one current role/stage.
     *
     * @return array{
     *     stages:array<int,array{key:string,labelKey:string}>,
     *     currentStage:string,
     *     allowsMultipleSubmissions:bool,
     *     canAdvance:bool,
     *     nextStageKey:?string,
     *     nextStageLabelKey:?string,
     *     showsCorrectness:bool
     * }
     */
    public function descriptor(string $stage, string $role): array {
        $definition = $this->stage_definition($stage);
        if ($definition === null || !in_array($role, ['host', 'player'], true)) {
            throw new \invalid_parameter_exception(
                'Live interaction descriptor context is invalid.'
            );
        }
        $multiple = false;
        foreach ($definition['submissions'] as $submission) {
            if (is_array($submission)
                    && hash_equals((string)($submission['role'] ?? ''), $role)
                    && ($submission['cardinality'] ?? null) === 'multiple') {
                $multiple = true;
                break;
            }
        }
        $nextstage = $this->next_stage($stage);
        return [
            'stages' => array_values(array_map(
                static fn(array $entry): array => [
                    'key' => (string)$entry['key'],
                    'labelKey' => (string)(
                        $entry['labelKey']
                        ?? ('live:stage:' . $entry['key'])
                    ),
                ],
                $this->stages
            )),
            'currentStage' => $stage,
            'allowsMultipleSubmissions' => $multiple,
            'canAdvance' => $role === 'host' && $nextstage !== null,
            'nextStageKey' => $nextstage,
            'nextStageLabelKey' => $nextstage === null
                ? null
                : (string)(
                    $definition['advanceLabelKey']
                    ?? ('live:stage:' . $nextstage)
                ),
            'showsCorrectness' => $this->showscorrectness,
        ];
    }

    public function aggregate_visible(string $role, string $stage, bool $revealed): bool {
        if ($role === 'host') {
            return $this->liveaggregate || $revealed;
        }
        return ($revealed && $this->playerrevealedaggregate)
            || in_array($stage, $this->aggregateplayers, true);
    }

    private function stage_definition(string $stage): ?array {
        foreach ($this->stages as $definition) {
            if (hash_equals((string)$definition['key'], $stage)) {
                return $definition;
            }
        }
        return null;
    }

    /**
     * Validate one compact policy-owned identifier.
     *
     * @param mixed $value Candidate.
     */
    private static function policy_identifier($value): bool {
        return is_string($value)
            && preg_match('/^[a-z][a-zA-Z0-9_-]{0,31}$/D', $value) === 1;
    }
}
