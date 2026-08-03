<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\report;

use mod_quizgeist\local\licence\feature_gate;
use mod_quizgeist\local\live\state_projector;
use mod_quizgeist\local\live\points_formula;
use mod_quizgeist\local\live\submission_pipeline;
use mod_quizgeist\local\selfstudy\review_presenter;
use mod_quizgeist\local\tagging\tag_repository;

defined('MOODLE_INTERNAL') || die();

/**
 * Capability-safe historical reports over live and self-study ledgers.
 */
final class report_projector {

    /** Semantic open-response question types. */
    private const REVIEW_TYPES = ['brainstorm', 'open', 'wordcloud'];

    /** Interactive review rows are intentionally bounded; exports are full. */
    private const INTERACTIVE_REVIEW_LIMIT = 500;

    /**
     * Construct the report read model from bulk repository rows.
     */
    public static function project(
        array $sources,
        array $accesses,
        source_selection $selection,
        bool $teacher,
        array $omittedsources = [],
        bool $interactive = true,
        ?callable $distributionconsumer = null
    ): array {
        $sessionids = [];
        $assignmentids = [];
        $sourcebysession = [];
        $sourcebyassignment = [];
        foreach ($sources as $source) {
            if ($source['kind'] === 'session') {
                $sessionids[] = (int)$source['id'];
                $sourcebysession[(int)$source['id']] = $source;
            } else {
                $assignmentids[] = (int)$source['id'];
                $sourcebyassignment[(int)$source['id']] = $source;
            }
        }

        $players = [];
        $playersbysession = [];
        $participantids = [];
        $studentmeta = [];
        foreach (report_repository::players($sessionids) as $player) {
            $source = $sourcebysession[(int)$player->sessionid] ?? null;
            $access = is_array($source)
                ? ($accesses[(int)$source['cmid']] ?? null)
                : null;
            if (!$access instanceof report_access
                    || !$access->can_view_user((int)$player->userid)) {
                continue;
            }
            $players[(int)$player->id] = $player;
            $playersbysession[(int)$player->sessionid][] = $player;
            $participantids[(int)$player->userid] = true;
            self::mark_student_source(
                $studentmeta,
                (int)$player->userid,
                (string)$source['key'],
                null
            );
        }

        $attempts = [];
        $completedattemptids = [];
        foreach (report_repository::attempts($assignmentids) as $attempt) {
            $source = $sourcebyassignment[(int)$attempt->assignmentid] ?? null;
            $access = is_array($source)
                ? ($accesses[(int)$source['cmid']] ?? null)
                : null;
            if (!$access instanceof report_access
                    || !$access->can_view_user((int)$attempt->userid)
                    || (string)$attempt->status !== 'completed') {
                continue;
            }
            $participantids[(int)$attempt->userid] = true;
            self::mark_student_source(
                $studentmeta,
                (int)$attempt->userid,
                (string)$source['key'],
                (int)$attempt->id
            );
            $attempts[(int)$attempt->id] = $attempt;
            $completedattemptids[] = (int)$attempt->id;
        }

        $sessionanswers = report_repository::session_answers(
            $sessionids,
            array_keys($players),
            $teacher
        );
        $attemptanswers = report_repository::attempt_answers(
            $completedattemptids
        );
        $userids = array_map('intval', array_keys($participantids));
        if ($teacher) {
            foreach (array_merge($sessionanswers, $attemptanswers) as $answer) {
                if ((int)$answer->userid > 0) {
                    $userids[] = (int)$answer->userid;
                }
            }
        }
        $users = report_repository::users($userids);
        $students = [];
        $reportcourseid = $sources
            ? (int)reset($sources)['courseid']
            : 0;
        foreach ($studentmeta as $userid => $meta) {
            $students[$userid] = self::new_student(
                $userid,
                $meta,
                $users,
                $teacher,
                $reportcourseid
            );
        }

        $eligibleids = [];
        $eligiblebycm = [];
        $sourcecmids = array_fill_keys(array_map(
            static fn(array $source): int => (int)$source['cmid'],
            $sources
        ), true);
        foreach ($accesses as $cmid => $access) {
            if (!isset($sourcecmids[(int)$cmid])) {
                continue;
            }
            foreach ($access->eligible_user_ids() as $userid) {
                $userid = (int)$userid;
                $eligibleids[$userid] = true;
                $eligiblebycm[(int)$cmid][$userid] = true;
            }
        }

        $state = [
            'questions' => [],
            'students' => &$students,
            'openResponses' => [],
            'moderationTrail' => [],
            'sourceStats' => [],
        ];
        foreach ($sources as $source) {
            $state['sourceStats'][$source['key']] = self::new_source_stats(
                $source,
                $eligiblebycm[(int)$source['cmid']] ?? []
            );
        }
        foreach ($studentmeta as $userid => $meta) {
            foreach (array_keys($meta['sources']) as $sourcekey) {
                if (isset($state['sourceStats'][$sourcekey])) {
                    $state['sourceStats'][$sourcekey]['_participants'][
                        (int)$userid
                    ] = true;
                }
            }
        }

        self::project_live(
            $state,
            $sourcebysession,
            $accesses,
            $players,
            $playersbysession,
            $sessionanswers,
            $users,
            $teacher,
            $distributionconsumer
        );
        self::project_attempts(
            $state,
            $sourcebyassignment,
            $accesses,
            $attempts,
            $attemptanswers,
            $users,
            $teacher,
            $distributionconsumer
        );

        $difficultminsample = report_metrics::difficult_min_sample();
        $difficultthreshold = report_metrics::difficult_threshold();
        $questions = report_metrics::finish_questions(
            $state['questions'],
            $difficultminsample,
            $difficultthreshold
        );
        // F6 Kompetenz-Bericht. Die Aggregation gehoert dem reports-Addon:
        // fehlt dessen Code, erscheint die Achse gar nicht (kein gesperrter
        // Koeder). Eine abgelaufene Lizenz aendert hier nichts — einen
        // bestehenden Bericht zu lesen ist nie eine Neuanlage (Abschnitt 2.6).
        $competences = [];
        if (class_exists(feature_gate::class)
                && feature_gate::allows('reports', feature_gate::VIEW_EXISTING)) {
            $competences = report_metrics::finish_competences(
                $questions,
                tag_repository::assignments_for_roots(
                    array_map(
                        static fn(array $question): int
                            => (int)($question['rootId'] ?? 0),
                        $questions
                    ),
                    'competence'
                )
            );
        }
        $students = report_metrics::finish_students($students);
        $studentidentifiers = array_column(
            $students,
            'userIdentifier',
            'userId'
        );
        foreach ($state['openResponses'] as &$response) {
            $responseuserid = (int)($response['userId'] ?? 0);
            if (isset($studentidentifiers[$responseuserid])) {
                $response['userIdentifier'] =
                    $studentidentifiers[$responseuserid];
            }
        }
        unset($response);
        $timeline = report_metrics::finish_timeline($state['sourceStats']);
        $correct = array_sum(array_column($questions, 'correctCount'));
        $gradable = array_sum(array_column($questions, 'gradableCount'));
        $totalpoints = array_sum(array_column($students, 'points'));
        $totalmaximum = array_sum(array_column($students, 'maxPoints'));
        $hardest = null;
        foreach ($questions as $question) {
            if ($question['correctPercent'] === null
                    || $question['gradableCount']
                        < $difficultminsample) {
                continue;
            }
            if ($hardest === null
                    || $question['correctPercent'] < $hardest['percent']
                    || (
                        $question['correctPercent'] === $hardest['percent']
                        && strcmp(
                            $question['rootKey'],
                            $hardest['key']
                        ) < 0
                    )) {
                $hardest = [
                    'key' => $question['rootKey'],
                    'percent' => $question['correctPercent'],
                    'rootId' => $question['rootId'],
                    'title' => $question['title'],
                ];
            }
        }
        $participantcount = count($participantids);
        $eligiblecount = count($eligibleids);
        $openresponses = report_metrics::chronological_rows(
            $state['openResponses']
        );
        $moderationtrail = $teacher
            ? report_metrics::chronological_rows($state['moderationTrail'])
            : [];
        $openresponsestruncated = false;
        $moderationtruncated = false;
        if ($interactive) {
            $openresponsestruncated = count($openresponses)
                > self::INTERACTIVE_REVIEW_LIMIT;
            $moderationtruncated = count($moderationtrail)
                > self::INTERACTIVE_REVIEW_LIMIT;
            $openresponses = array_slice(
                $openresponses,
                0,
                self::INTERACTIVE_REVIEW_LIMIT
            );
            $moderationtrail = array_slice(
                $moderationtrail,
                0,
                self::INTERACTIVE_REVIEW_LIMIT
            );
        }
        return [
            'selection' => $selection->dto(),
            'sources' => array_values($sources),
            'omittedSources' => array_values($omittedsources),
            'kpis' => [
                'pointsPercent' => $totalmaximum > 0
                    ? round($totalpoints * 100 / $totalmaximum, 1)
                    : null,
                'averageCorrectPercent' => $gradable > 0
                    ? round($correct * 100 / $gradable, 1)
                    : null,
                'participationPercent' => $eligiblecount > 0
                    ? round($participantcount * 100 / $eligiblecount, 1)
                    : null,
                'participantCount' => $participantcount,
                'eligibleCount' => $eligiblecount,
                'hardestRootKey' => $hardest['key'] ?? null,
                'hardestQuestion' => $hardest === null ? null : [
                    'rootKey' => $hardest['key'],
                    'rootId' => $hardest['rootId'],
                    'title' => $hardest['title'],
                    'correctPercent' => $hardest['percent'],
                ],
            ],
            'questions' => $questions,
            'competences' => $competences,
            'students' => $students,
            'openResponses' => $openresponses,
            'openResponsesTruncated' => $openresponsestruncated,
            'moderationTrail' => $moderationtrail,
            'moderationTrailTruncated' => $moderationtruncated,
            'timeline' => $timeline,
        ];
    }

    /**
     * Live occurrences: only resolved visits enter learner metrics.
     */
    private static function project_live(
        array &$state,
        array $sourcebysession,
        array $accesses,
        array $players,
        array $playersbysession,
        array $answerrows,
        array $users,
        bool $teacher,
        ?callable $distributionconsumer
    ): void {
        $answersbyoccurrence = [];
        $answersbyquestion = [];
        foreach ($answerrows as $answer) {
            $source = $sourcebysession[(int)$answer->sessionid] ?? null;
            if (!is_array($source)) {
                continue;
            }
            if ($answer->playerid !== null
                    && !isset($players[(int)$answer->playerid])) {
                continue;
            }
            if ($answer->playerid === null && !$teacher) {
                continue;
            }
            $key = (int)$answer->sessionid . ':'
                . (int)$answer->questionid . ':'
                . (string)$answer->visit;
            $answersbyoccurrence[$key][] = $answer;
            $questionkey = (int)$answer->sessionid . ':'
                . (int)$answer->questionid;
            $answersbyquestion[$questionkey][] = $answer;
        }
        $occurrences = report_repository::live_questions(
            array_keys($sourcebysession)
        );
        $totals = [];
        foreach ($occurrences as $occurrence) {
            $totals[(int)$occurrence->sessionid] =
                max(
                    $totals[(int)$occurrence->sessionid] ?? 0,
                    (int)$occurrence->sortindex + 1
                );
        }
        $interactions = [];
        $correctness = [];
        foreach ($occurrences as $occurrence) {
            $source = $sourcebysession[(int)$occurrence->sessionid] ?? null;
            $access = is_array($source)
                ? ($accesses[(int)$source['cmid']] ?? null)
                : null;
            if (!is_array($source) || !$access instanceof report_access) {
                continue;
            }
            $resolvedvisit = (string)($occurrence->resolvedvisit ?? '');
            $enteredvisit = (string)($occurrence->visit ?? '');
            if ($resolvedvisit === '' && $enteredvisit === '') {
                // The frozen sequence contains future positions as soon as a
                // lobby is created. A report traces played versions only.
                continue;
            }
            $question = self::question_record($occurrence);
            $questionid = (int)$question->id;
            $interaction = $interactions[$questionid] ??=
                submission_pipeline::interaction(
                    $question,
                    null,
                    'player'
                );
            $policy = $interaction['policy'];
            $projectionvisit = $resolvedvisit !== ''
                ? $resolvedvisit
                : $enteredvisit;
            $occurrencekey = (int)$occurrence->sessionid . ':'
                . (int)$occurrence->questionid . ':' . $resolvedvisit;
            $allresolved = $resolvedvisit === ''
                ? []
                : ($answersbyoccurrence[$occurrencekey] ?? []);
            $semantictypes = array_fill_keys($policy->answer_types(), true);
            // F2: a reason is never part of a type aggregate. It is collected
            // separately and disclosed under its own visibility rule. F13 adds
            // the stage marker for the same reason — it is a pointer to a
            // report, not a response to the question.
            unset($semantictypes['reason'], $semantictypes['stage']);
            $semantic = array_values(array_filter(
                $allresolved,
                static fn(\stdClass $answer): bool =>
                    isset($semantictypes[(string)$answer->answertype])
            ));
            // A resolved visit could be retained while a later revisit resets
            // the persisted stage. Resolution is possible only at the
            // policy's final stage; unresolved visits retain their own stage.
            $projectionstage = $resolvedvisit !== ''
                ? null
                : (
                    isset($occurrence->stage)
                        ? (string)$occurrence->stage
                        : null
                );
            $reportnamespace = self::projection_namespace(
                $source,
                $question,
                $projectionvisit
            );
            $includecorrect = $teacher || $resolvedvisit !== '';
            $projection = state_projector::historical_question(
                $question,
                $access->context(),
                $semantic,
                $reportnamespace,
                (int)$occurrence->sortindex,
                $totals[(int)$occurrence->sessionid] ?? 1,
                $projectionstage,
                $includecorrect,
                $teacher ? 'host' : 'player'
            );
            $root = &self::root(
                $state['questions'],
                $question
            );
            // F2 Denk-Moment: collect the justifications of this occurrence.
            // can_view_user() is the same boundary the rest of the report
            // uses — a learner's visible set contains exactly themselves, so
            // no foreign reason can enter this payload.
            foreach ($allresolved as $answer) {
                if ((string)$answer->answertype !== 'reason'
                        || $answer->playerid === null) {
                    continue;
                }
                $player = $players[(int)$answer->playerid] ?? null;
                if ($player === null
                        || !$access->can_view_user((int)$player->userid)) {
                    continue;
                }
                $decoded = json_decode((string)$answer->answerjson, true);
                $text = is_array($decoded) && is_string($decoded['text'] ?? null)
                    ? trim($decoded['text'])
                    : '';
                if ($text === '') {
                    continue;
                }
                $root['reasons'][] = [
                    'questionId' => (int)$question->id,
                    'userId' => (int)$player->userid,
                    'own' => (int)$player->userid
                        === (int)($access->viewer_id()),
                    'text' => $text,
                    'timeCreated' => (int)$answer->timecreated,
                ];
            }
            self::add_distribution(
                $root,
                $source,
                $question,
                $projection,
                $resolvedvisit,
                $teacher,
                1,
                $distributionconsumer
            );
            $metricbyuser = [];
            foreach ($allresolved as $answer) {
                if ($answer->playerid === null
                        || !isset($semantictypes[(string)$answer->answertype])) {
                    continue;
                }
                $player = $players[(int)$answer->playerid] ?? null;
                if ($player) {
                    $metricbyuser[(int)$player->userid][] = $answer;
                }
            }
            $primarytypes = array_fill_keys(
                $policy->player_answer_types($policy->initial_stage()),
                true
            );
            $responders = [];
            $showscorrectness = null;
            foreach ($metricbyuser as $userid => $rows) {
                foreach ($rows as $row) {
                    if (isset($primarytypes[(string)$row->answertype])) {
                        $responders[(int)$userid] = true;
                        break;
                    }
                }
                if ($showscorrectness === null) {
                    $showscorrectness = $correctness[$questionid] ??=
                        review_presenter::shows_correctness($question);
                }
                report_metrics::apply_observation(
                    $root,
                    $state['students'][$userid],
                    $state['sourceStats'][$source['key']],
                    $userid,
                    $rows,
                    $primarytypes,
                    $showscorrectness,
                    false
                );
            }
            if ($resolvedvisit !== '' && $primarytypes) {
                // Missing is a learner metric and therefore belongs only to
                // the exactly-once authoritative visit. A later skipped
                // revisit may leave `visit` populated, but its scorevoid
                // neutralisation must not alter the retained result.
                $eligibleplayers = self::eligible_live_players(
                    $playersbysession[(int)$occurrence->sessionid] ?? [],
                    $allresolved,
                    $responders
                );
                // 'live' ist KEIN gueltiger Wertungsmodus — scoring_context
                // kennt nur classic/accuracy/team/security/selfstudy. Verwendet
                // wird der tatsaechliche Modus der Session; fehlt er, gilt der
                // Standardmodus 'classic'.
                $sessionmode = (string)($source['mode'] ?? '');
                if ($sessionmode === '') {
                    $sessionmode = 'classic';
                }
                if ($showscorrectness === null) {
                    $showscorrectness = $correctness[$questionid] ??=
                        review_presenter::shows_correctness($question);
                }
                $maximum = $showscorrectness
                    ? points_formula::maximum(
                        $interaction['question'],
                        $sessionmode,
                        0
                    )
                    : 0;
                foreach ($eligibleplayers as $player) {
                    $userid = (int)$player->userid;
                    if (isset($responders[$userid])
                            || !isset($state['students'][$userid])) {
                        continue;
                    }
                    report_metrics::apply_missing_observation(
                        $root,
                        $state['students'][$userid],
                        $state['sourceStats'][$source['key']],
                        $userid,
                        $showscorrectness,
                        $maximum
                    );
                }
            }
            if (in_array((string)$question->qtype, self::REVIEW_TYPES, true)) {
                self::add_live_review(
                    $state,
                    $source,
                    $question,
                    $answersbyquestion[
                        (int)$occurrence->sessionid . ':'
                        . (int)$occurrence->questionid
                    ] ?? [],
                    $players,
                    $users,
                    $resolvedvisit,
                    $teacher
                );
            }
        }
    }

    /**
     * Completed self-study attempts including explicit terminal scorevoids.
     */
    private static function project_attempts(
        array &$state,
        array $sourcebyassignment,
        array $accesses,
        array $attempts,
        array $answerrows,
        array $users,
        bool $teacher,
        ?callable $distributionconsumer
    ): void {
        $answers = [];
        foreach ($answerrows as $answer) {
            if (isset($attempts[(int)$answer->attemptid])) {
                $answers[(int)$answer->attemptid . ':'
                    . (int)$answer->questionid][] = $answer;
            }
        }
        $occurrences = report_repository::attempt_questions(
            array_keys($attempts)
        );
        $totals = [];
        foreach ($occurrences as $occurrence) {
            $attempt = $attempts[(int)$occurrence->attemptid] ?? null;
            if ($attempt) {
                $totals[(int)$attempt->assignmentid] = max(
                    $totals[(int)$attempt->assignmentid] ?? 0,
                    (int)$occurrence->sortindex + 1
                );
            }
        }
        $distributiongroups = [];
        $interactions = [];
        $correctness = [];
        foreach ($occurrences as $occurrence) {
            $attempt = $attempts[(int)$occurrence->attemptid] ?? null;
            $source = $attempt
                ? ($sourcebyassignment[(int)$attempt->assignmentid] ?? null)
                : null;
            $access = is_array($source)
                ? ($accesses[(int)$source['cmid']] ?? null)
                : null;
            if (!$attempt || !is_array($source)
                    || !$access instanceof report_access) {
                continue;
            }
            $question = self::question_record($occurrence);
            $questionid = (int)$question->id;
            $interaction = $interactions[$questionid] ??=
                submission_pipeline::interaction(
                    $question,
                    null,
                    'player'
                );
            $policy = $interaction['policy'];
            $rows = $answers[(int)$attempt->id . ':'
                . (int)$question->id] ?? [];
            $semantictypes = array_fill_keys($policy->answer_types(), true);
            $semantic = array_values(array_filter(
                $rows,
                static fn(\stdClass $answer): bool =>
                    isset($semantictypes[(string)$answer->answertype])
            ));
            $metricrows = array_values(array_filter(
                $rows,
                static fn(\stdClass $answer): bool =>
                    (string)$answer->answertype === 'scorevoid'
                    || isset($semantictypes[(string)$answer->answertype])
            ));
            $root = &self::root($state['questions'], $question);
            $distributionkey = (string)$source['key']
                . ':' . (int)$question->id;
            if (!isset($distributiongroups[$distributionkey])) {
                $distributiongroups[$distributionkey] = [
                    'source' => $source,
                    'access' => $access,
                    'question' => $question,
                    'answers' => [],
                    'visits' => [],
                    'occurrenceCount' => 0,
                    'index' => (int)$occurrence->sortindex,
                    'total' => $totals[(int)$attempt->assignmentid] ?? 1,
                    'stage' => $policy->initial_stage(),
                ];
            }
            foreach ($semantic as $answer) {
                // Live strategies key their latest response by playerid.
                // Self-study rows intentionally have no live player, so one
                // completed attempt is the equivalent stable actor here.
                $answer = clone $answer;
                $answer->playerid = (int)$attempt->id;
                $distributiongroups[$distributionkey]['answers'][] =
                    $answer;
            }
            $visit = (string)$occurrence->visit;
            if (preg_match('/^[a-f0-9]{32}$/D', $visit)) {
                $distributiongroups[$distributionkey]['visits'][$visit] =
                    true;
            }
            $distributiongroups[$distributionkey]['occurrenceCount']++;
            $showscorrectness = $correctness[$questionid] ??=
                review_presenter::shows_correctness($question);
            $graded = (string)$attempt->mode !== 'flashcards'
                && $showscorrectness;
            $primarytypes = array_fill_keys(
                $policy->player_answer_types($policy->initial_stage()),
                true
            );
            report_metrics::apply_observation(
                $root,
                $state['students'][(int)$attempt->userid],
                $state['sourceStats'][$source['key']],
                (int)$attempt->userid,
                $metricrows,
                $primarytypes,
                $graded,
                true
            );
            if (in_array((string)$question->qtype, self::REVIEW_TYPES, true)) {
                self::add_attempt_review(
                    $state,
                    $source,
                    $question,
                    $rows,
                    (int)$attempt->userid,
                    $users,
                    $teacher
                );
            }
        }

        uasort(
            $distributiongroups,
            static fn(array $left, array $right): int =>
                strcmp(
                    (string)$left['source']['key'],
                    (string)$right['source']['key']
                )
                ?: ((int)$left['index'] <=> (int)$right['index'])
                ?: ((int)$left['question']->id
                    <=> (int)$right['question']->id)
        );
        foreach ($distributiongroups as $group) {
            $visits = array_keys($group['visits']);
            sort($visits, SORT_STRING);
            $projectionvisit = self::projection_namespace(
                $group['source'],
                $group['question'],
                implode(':', $visits)
            );
            $projection = state_projector::historical_question(
                $group['question'],
                $group['access']->context(),
                $group['answers'],
                $projectionvisit,
                (int)$group['index'],
                (int)$group['total'],
                (string)$group['stage'],
                true,
                $teacher ? 'host' : 'player'
            );
            $root = &self::root(
                $state['questions'],
                $group['question']
            );
            self::add_distribution(
                $root,
                $group['source'],
                $group['question'],
                $projection,
                $visits ? $projectionvisit : '',
                $teacher,
                (int)$group['occurrenceCount'],
                $distributionconsumer
            );
        }
    }
    /**
     * Approximate who could see an occurrence from immutable join/poll times.
     *
     * @param \stdClass[] $players
     * @param \stdClass[] $rows
     * @param array<int,true> $responders
     * @return \stdClass[]
     */
    private static function eligible_live_players(
        array $players,
        array $rows,
        array $responders
    ): array {
        $anchor = 0;
        foreach ($rows as $row) {
            $created = (int)($row->timecreated ?? 0);
            if ($created > 0 && ($anchor === 0 || $created < $anchor)) {
                $anchor = $created;
            }
        }
        if ($anchor <= 0) {
            return $players;
        }
        return array_values(array_filter(
            $players,
            static function(\stdClass $player) use ($anchor, $responders): bool {
                $userid = (int)$player->userid;
                if (isset($responders[$userid])) {
                    return true;
                }
                $joined = (int)($player->timejoined ?? 0);
                $lastseen = (int)($player->lastseen ?? 0);
                return ($joined <= 0 || $joined <= $anchor)
                    && ($lastseen <= 0 || $lastseen >= $anchor);
            }
        ));
    }

    /**
     * Add open content and the append-only live moderation audit.
     */
    private static function add_live_review(
        array &$state,
        array $source,
        \stdClass $question,
        array $rows,
        array $players,
        array $users,
        string $resolvedvisit,
        bool $teacher
    ): void {
        $decisions = [];
        $visibletargets = [];
        foreach ($rows as $row) {
            if ($row->playerid === null) {
                continue;
            }
            $payload = self::payload($row);
            if ($question->qtype === 'wordcloud'
                    && (string)$row->answertype === 'answer') {
                $key = (string)($payload['key'] ?? '');
                if ($key !== '') {
                    $visibletargets[(string)$row->visit . ':' . $key] = true;
                }
            } else if ($question->qtype === 'brainstorm'
                    && (string)$row->answertype === 'idea') {
                $visibletargets[(string)$row->visit . ':'
                    . (int)$row->id] = true;
            }
        }
        foreach ($rows as $row) {
            if (!in_array(
                (string)$row->answertype,
                ['moderation', 'group'],
                true
            )) {
                continue;
            }
            $payload = self::payload($row);
            $target = $question->qtype === 'wordcloud'
                ? ($payload['termKey'] ?? null)
                : ($payload['ideaId'] ?? null);
            $targetkey = (string)$row->visit . ':' . (string)$target;
            if ((is_string($target) || is_int($target))
                    && isset($visibletargets[$targetkey])) {
                $decisions[(string)$row->visit . ':' . (string)$target] =
                    (string)($payload['decision'] ?? '');
            }
            if ($teacher
                    && ((string)$row->answertype === 'group'
                        || isset($visibletargets[$targetkey]))) {
                $state['moderationTrail'][] = self::moderation_dto(
                    $row,
                    $source,
                    $question,
                    $users,
                    $payload
                );
            }
        }
        foreach ($rows as $row) {
            $type = (string)$row->answertype;
            if (($question->qtype === 'brainstorm' && $type !== 'idea')
                    || ($question->qtype !== 'brainstorm' && $type !== 'answer')
                    || $row->playerid === null) {
                continue;
            }
            $payload = self::payload($row);
            if (!is_string($payload['text'] ?? null)) {
                continue;
            }
            $player = $players[(int)$row->playerid] ?? null;
            if (!$player) {
                continue;
            }
            $target = $question->qtype === 'wordcloud'
                ? (string)($payload['key'] ?? '')
                : (string)$row->id;
            $questionoptions = json_decode(
                (string)$question->optionsjson,
                true
            );
            $wordcloudmoderated = is_array($questionoptions)
                && !empty($questionoptions['moderation']);
            $status = $teacher
                ? ($decisions[(string)$row->visit . ':' . $target]
                    ?? ($question->qtype === 'wordcloud'
                        ? ($wordcloudmoderated ? 'pending' : 'approved')
                        : 'approved'))
                : 'submitted';
            $state['openResponses'][] = self::response_dto(
                $row,
                $source,
                $question,
                (int)$player->userid,
                $users,
                $payload['text'],
                $status,
                (string)$row->visit === $resolvedvisit,
                $teacher
            );
        }
    }

    /**
     * Add self-study open content (there are no host rows in this runtime).
     */
    private static function add_attempt_review(
        array &$state,
        array $source,
        \stdClass $question,
        array $rows,
        int $userid,
        array $users,
        bool $teacher
    ): void {
        foreach ($rows as $row) {
            $type = (string)$row->answertype;
            if (($question->qtype === 'brainstorm' && $type !== 'idea')
                    || ($question->qtype !== 'brainstorm' && $type !== 'answer')) {
                continue;
            }
            $payload = self::payload($row);
            if (!is_string($payload['text'] ?? null)) {
                continue;
            }
            $state['openResponses'][] = self::response_dto(
                $row,
                $source,
                $question,
                $userid,
                $users,
                $payload['text'],
                'submitted',
                true,
                $teacher
            );
        }
    }

    /**
     * Get/create one question-tree row.
     */
    private static function &root(array &$questions, \stdClass $question): array {
        $rootid = self::root_id($question);
        $rootkey = (int)$question->quizgeistid . ':' . $rootid;
        if (!isset($questions[$rootkey])) {
            $questions[$rootkey] = [
                'rootKey' => $rootkey,
                'quizgeistId' => (int)$question->quizgeistid,
                'rootId' => $rootid,
                'title' => (string)$question->questiontext,
                'qtypes' => [],
                'points' => 0,
                'maxPoints' => 0,
                'correctCount' => 0,
                'gradableCount' => 0,
                'responseCount' => 0,
                'missingCount' => 0,
                '_timeTotal' => 0,
                '_timeSamples' => 0,
                '_latestVersion' => 0,
                '_versions' => [],
                'distributions' => [],
                // F2 Denk-Moment. A teacher sees every justification, a
                // learner only their own. The cut happens on the server, so a
                // learner's payload never carries a foreign reason.
                'reasons' => [],
            ];
        }
        $root = &$questions[$rootkey];
        $root['qtypes'][(string)$question->qtype] = true;
        $root['_versions'][(int)$question->id] = [
            'questionId' => (int)$question->id,
            'version' => (int)$question->version,
            'qtype' => (string)$question->qtype,
            'questionText' => (string)$question->questiontext,
        ];
        if ((int)$question->version >= $root['_latestVersion']) {
            $root['_latestVersion'] = (int)$question->version;
            $root['title'] = (string)$question->questiontext;
        }
        return $root;
    }

    private static function add_distribution(
        array &$root,
        array $source,
        \stdClass $question,
        array $projection,
        string $visit,
        bool $teacher,
        int $occurrencecount = 1,
        ?callable $distributionconsumer = null
    ): void {
        $distribution = [
            'sourceKey' => (string)$source['key'],
            'sourceLabel' => (string)$source['name'],
            'questionId' => (int)$question->id,
            'version' => (int)$question->version,
            'projectionKey' => (string)$projection['visit'],
            'occurrenceCount' => max(1, $occurrencecount),
            'stage' => (string)$projection['stage'],
            'authoritative' => $visit !== '',
            'question' => report_distribution_sanitizer::for_report(
                $projection['question'],
                $teacher
            ),
            'aggregate' => report_distribution_sanitizer::for_report(
                $projection['aggregate'],
                $teacher
            ),
        ];
        if ($distributionconsumer !== null) {
            $distributionconsumer((string)$root['rootKey'], $distribution);
            return;
        }
        $root['distributions'][] = $distribution;
    }
    private static function new_student(
        int $userid,
        array $meta,
        array $users,
        bool $teacher,
        int $courseid
    ): array {
        $user = $users[$userid] ?? null;
        $name = $user
            ? fullname($user)
            : get_string('deleteduser', 'bulkusers');
        $student = [
            'userId' => $userid,
            'displayName' => $name,
            'userIdentifier' => self::user_identifier($user, $userid),
            'points' => 0,
            'maxPoints' => 0,
            'correctCount' => 0,
            'gradableCount' => 0,
            'responseCount' => 0,
            'sourceCount' => count($meta['sources']),
            'attemptCount' => count($meta['attempts']),
            '_timeTotal' => 0,
            '_timeSamples' => 0,
        ];
        if ($teacher) {
            $student['profileUrl'] = (new \moodle_url('/user/view.php', [
                'id' => $userid,
                'course' => $courseid,
            ]))->out(false);
        }
        return $student;
    }

    /**
     * Human-readable Moodle account identifier for report rows.
     */
    private static function user_identifier(
        ?\stdClass $user,
        int $userid
    ): string {
        $idnumber = trim((string)($user->idnumber ?? ''));
        if ($idnumber !== '') {
            return $idnumber;
        }
        $username = trim((string)($user->username ?? ''));
        return $username !== '' ? $username : '#' . $userid;
    }

    private static function mark_student_source(
        array &$meta,
        int $userid,
        string $sourcekey,
        ?int $attemptid
    ): void {
        $meta[$userid] ??= ['sources' => [], 'attempts' => []];
        $meta[$userid]['sources'][$sourcekey] = true;
        if ($attemptid !== null) {
            $meta[$userid]['attempts'][$attemptid] = true;
        }
    }

    private static function new_source_stats(
        array $source,
        array $eligibleids
    ): array {
        return [
            'name' => $source['name'],
            'kind' => $source['kind'],
            'quizgeistId' => (int)$source['quizgeistId'],
            'instanceName' => (string)$source['instanceName'],
            'timestamp' => $source['endedAt'] ?: $source['startedAt'],
            '_eligible' => $eligibleids,
            '_participants' => [],
            '_users' => [],
        ];
    }

    private static function response_dto(
        \stdClass $row,
        array $source,
        \stdClass $question,
        int $userid,
        array $users,
        string $text,
        string $status,
        bool $authoritative,
        bool $teacher
    ): array {
        $dto = [
            'id' => (int)$row->id,
            'rootKey' => (int)$question->quizgeistid . ':'
                . self::root_id($question),
            'rootId' => self::root_id($question),
            'questionId' => (int)$question->id,
            'questionTitle' => (string)$question->questiontext,
            'version' => (int)$question->version,
            'sourceKey' => (string)$source['key'],
            'sourceLabel' => (string)$source['name'],
            'kind' => (string)$question->qtype,
            'text' => $text,
            'status' => $status,
            'authoritative' => $authoritative,
            'metricEligible' => $authoritative,
            'timeCreated' => (int)$row->timecreated,
        ];
        $user = $users[$userid] ?? null;
        $dto['displayName'] = $user
            ? fullname($user)
            : get_string('deleteduser', 'bulkusers');
        $dto['userId'] = $userid;
        $dto['userIdentifier'] = self::user_identifier($user, $userid);
        if ($teacher) {
            $dto['profileUrl'] = (new \moodle_url('/user/view.php', [
                'id' => $userid,
                'course' => (int)$source['courseid'],
            ]))->out(false);
        }
        return $dto;
    }

    private static function moderation_dto(
        \stdClass $row,
        array $source,
        \stdClass $question,
        array $users,
        array $payload
    ): array {
        $action = (string)($payload['decision'] ?? (
            (string)$row->answertype === 'group' ? 'grouped' : 'moderated'
        ));
        $target = $payload['ideaId']
            ?? $payload['termKey']
            ?? ((string)$row->answertype === 'group' ? 'groups' : '');
        $actor = $users[(int)$row->userid] ?? null;
        return [
            'id' => (int)$row->id,
            'rootKey' => (int)$question->quizgeistid . ':'
                . self::root_id($question),
            'rootId' => self::root_id($question),
            'questionId' => (int)$question->id,
            'questionTitle' => (string)$question->questiontext,
            'version' => (int)$question->version,
            'sourceKey' => (string)$source['key'],
            'sourceLabel' => (string)$source['name'],
            'action' => $action,
            'targetType' => (string)$row->answertype,
            'targetKey' => is_scalar($target) ? (string)$target : 'groups',
            'actorName' => $actor
                ? fullname($actor)
                : get_string('deleteduser', 'bulkusers'),
            'timeCreated' => (int)$row->timecreated,
        ];
    }

    private static function payload(\stdClass $row): array {
        $payload = json_decode((string)$row->answerjson, true);
        return is_array($payload) ? $payload : [];
    }

    /**
     * Derive a report-only opaque namespace from persisted visit evidence.
     */
    private static function projection_namespace(
        array $source,
        \stdClass $question,
        string $visitevidence
    ): string {
        return substr(hash(
            'sha256',
            "quizgeist-report\0"
                . (string)$source['key'] . "\0"
                . (int)$question->id . "\0"
                . $visitevidence
        ), 0, 32);
    }

    private static function question_record(\stdClass $occurrence): \stdClass {
        $question = clone $occurrence;
        $question->id = (int)$occurrence->questionid;
        if ((int)($question->rootid ?? 0) <= 0) {
            $question->rootid = (int)$question->id;
        }
        return $question;
    }

    /**
     * Legacy/restored zero roots remain isolated by their exact question ID.
     */
    private static function root_id(\stdClass $question): int {
        $rootid = (int)($question->rootid ?? 0);
        return $rootid > 0 ? $rootid : (int)$question->id;
    }
}
