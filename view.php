<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Main route for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$requestedview = optional_param('view', 'overview', PARAM_ALPHA);
$requestedjoincode = optional_param('code', '', PARAM_ALPHANUM);
$requestedassignmentid = optional_param('assignmentid', 0, PARAM_INT);
$requestedattemptid = optional_param('attemptid', 0, PARAM_INT);
if ($requestedjoincode !== '' && !preg_match('/^[0-9]{6}$/D', $requestedjoincode)) {
    $requestedjoincode = '';
}
$requestedassignmentid = max(0, $requestedassignmentid);
$requestedattemptid = max(0, $requestedattemptid);

$cm = get_coursemodule_from_id('quizgeist', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$quizgeist = $DB->get_record('quizgeist', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);

$canmanage = has_capability('mod/quizgeist:manage', $context);
$canhost = has_capability('mod/quizgeist:host', $context);
$canplay = has_capability('mod/quizgeist:play', $context);
$canviewreports = has_capability('mod/quizgeist:viewreports', $context);
$canviewtopia = has_capability('mod/quizgeist:view', $context)
    && \mod_quizgeist\local\addon\registry::is_installed(
        'quizgeistaddon_modes'
    );
$featureavailability = [];
foreach (array_keys(\mod_quizgeist\local\licence\service::COMPONENTS) as $featurekey) {
    $featureavailability[$featurekey] =
        \mod_quizgeist\local\licence\feature_gate::availability($featurekey);
}
$selfstudyavailability = $featureavailability['selfstudy'];
$reportsavailability = $featureavailability['reports'];
$selfstudyinstalled = (bool)$selfstudyavailability['installed'];
$reportsinstalled = (bool)$reportsavailability['installed'];
$showgracenotice = ($canmanage || $canhost || $canviewreports)
    && array_reduce(
        $featureavailability,
        static fn(bool $found, array $availability): bool =>
            $found
                || (
                    !empty($availability['installed'])
                    && ($availability['status'] ?? '') === 'grace'
                ),
        false
    );
$showreadonlynotice = ($canmanage || $canhost || $canviewreports)
    && array_reduce(
        $featureavailability,
        static fn(bool $found, array $availability): bool =>
            $found
                || (
                    !empty($availability['installed'])
                    && ($availability['status'] ?? '') === 'read_only'
                ),
        false
    );
$clientfeatureavailability = [];
foreach ($featureavailability as $featurekey => $availability) {
    $clientfeatureavailability[$featurekey] = [
        'installed' => (bool)$availability['installed'],
        'canUseExisting' => (bool)$availability['canUseExisting'],
    ];
    // Staff creation surfaces need this safe state, but detailed licence
    // diagnoses remain confined to the site-administration page. Learners
    // receive neither a licence status nor a customer/licence identifier.
    if ($canmanage || $canhost || $canviewreports) {
        $clientfeatureavailability[$featurekey]['canCreate'] =
            (bool)$availability['canCreate'];
        $clientfeatureavailability[$featurekey]['status'] =
            (string)$availability['status'];
    }
}

if (!$canmanage && !$canhost && !$canplay && !$canviewreports) {
    throw new required_capability_exception($context, 'mod/quizgeist:play', 'nopermissions', '');
}

if ($requestedview === 'reports' && ($canviewreports || $canplay)) {
    $route = 'report';
    $bundle = 'app_report';
    $containerid = 'quizgeist-app-report';
    $heading = get_string('reportsheading', 'mod_quizgeist');
} else if ($requestedview === 'play' && ($canplay || $canmanage || $canhost)) {
    $route = 'play';
    $bundle = 'app_play';
    $containerid = 'quizgeist-app-play';
    $heading = get_string('studentheading', 'mod_quizgeist');
} else if (($requestedview === 'host' || (!$canmanage && !$canplay)) && $canhost) {
    $route = 'host';
    $bundle = 'app_host';
    $containerid = 'quizgeist-app-host';
    $heading = get_string('hostheading', 'mod_quizgeist');
} else if ($canmanage) {
    $route = 'manage';
    $bundle = 'app_edit';
    $containerid = 'quizgeist-app-edit';
    $heading = get_string('manageheading', 'mod_quizgeist');
} else if ($canviewreports) {
    $route = 'report';
    $bundle = 'app_report';
    $containerid = 'quizgeist-app-report';
    $heading = get_string('reportsheading', 'mod_quizgeist');
} else {
    $route = 'play';
    $bundle = 'app_play';
    $containerid = 'quizgeist-app-play';
    $heading = get_string('studentheading', 'mod_quizgeist');
}

$PAGE->set_url('/mod/quizgeist/view.php', ['id' => $cm->id, 'view' => $requestedview]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course, $quizgeist);
$PAGE->set_title(format_string($quizgeist->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->add_body_class('quizgeist-route-' . $route);
$assetversion = (string)get_config('mod_quizgeist', 'version');
$PAGE->requires->css(new moodle_url('/mod/quizgeist/styles_app.css', [
    'v' => $assetversion,
]));

$event = \mod_quizgeist\event\course_module_viewed::create([
    'objectid' => (int)$quizgeist->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('quizgeist', $quizgeist);
$event->trigger();

// completion_info lives in lib/completionlib.php. Moodle only pulls that in
// as a side effect of $PAGE->set_cm()/get_fast_modinfo() loading course/lib.php
// - and that side effect is skipped for front-page activities. Name the
// dependency instead of inheriting it by luck (P10-F15).
require_once($CFG->libdir . '/completionlib.php');

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$sharedlivekeys = [
    'live:connection:loading',
    'live:connection:reconnecting',
    'live:connection:offline',
    'live:connection:restored',
    'live:connection:expired',
    'live:connection:reload',
    'live:error:config',
    'live:error:request',
    'live:error:conflict',
    'live:error:noplayablequestions',
    'live:error:namefiltered',
    'live:error:enrolmentrequired',
    'live:error:teamsrequired',
    'live:error:teammembershiprequired',
    'live:error:teamrequired',
    'live:error:rewardlocked',
    'live:error:submissionduplicate',
    'live:error:submissionlimit',
    'live:error:statechanged',
    'live:error:toomanygroups',
    'live:stage:answer',
    'live:stage:react',
    'live:phase:lobby',
    'live:phase:question',
    'live:phase:reveal',
    'live:phase:scoreboard',
    'live:phase:podium',
    'live:phase:ended',
    'live:shape:a',
    'live:shape:b',
    'live:shape:c',
    'live:shape:d',
    'live:shape:e',
    'live:shape:f',
    'live:qtype:quiz',
    'live:qtype:truefalse',
    'live:qtype:poll',
    'live:qtype:shortanswer',
    'live:qtype:puzzle',
    'live:qtype:wordcloud',
    'live:qtype:scale',
    'live:qtype:slider',
    'live:qtype:pin',
    'live:qtype:reveal',
    'live:qtype:brainstorm',
    'live:qtype:open',
    'live:qtype:slide',
    'live:true',
    'live:false',
    'live:points',
    'live:streak',
    'live:sound:label',
    'live:sound:mute',
    'live:sound:unmute',
    'live:tts:play',
    'live:tts:stop',
    'live:tts:unavailable',
    'live:tts:error',
    'live:reward:unlocked',
    'live:team:points',
    'live:aggregate:empty',
    'live:aggregate:mean',
    'live:aggregate:median',
    'live:wordcloud:moderation',
    'live:wordcloud:nopublished',
    'live:wordcloud:pending',
    'live:wordcloud:approved',
    'live:wordcloud:rejected',
    'live:wordcloud:approve',
    'live:wordcloud:reject',
    'live:brainstorm:collect',
    'live:brainstorm:group',
    'live:brainstorm:vote',
    'live:brainstorm:done',
    'live:brainstorm:submit',
    'live:brainstorm:moderation',
    'live:pin:place',
    'live:pin:target',
    'live:puzzle:down',
    'live:puzzle:up',
    'live:reactions:title',
    'live:reaction:heart',
    'live:reaction:clap',
    'live:reaction:idea',
    'live:reaction:laugh',
    'live:reaction:wow',
    'live:response:open',
    'live:response:text',
    'live:reveal:bonus',
    'live:slider:minus',
    'live:slider:plus',
    'editor:field:answerimage',
    'editor:field:media',
    'editor:time:none',
    'host:reveal:correct',
    'play:answer:send',
    'play:answer:text',
];
$clientstringkeys = match ($route) {
    'host' => array_merge($sharedlivekeys, [
        // P11/C5 (F11a): Karten-Modus. Diese Schlüssel erscheinen NUR in der
        // Host-Route — ein Spielergerät hat mit Kartenscans nichts zu tun und
        // bekommt deshalb nicht einmal die Wörter dafür.
        'cards:scan:title',
        'cards:scan:description',
        'cards:scan:setlabel',
        'cards:scan:noset',
        'cards:scan:print',
        'cards:scan:start',
        'cards:scan:capture',
        'cards:scan:retake',
        'cards:scan:cancel',
        'cards:scan:close',
        'cards:scan:cameraunavailable',
        'cards:scan:camerablocked',
        'cards:scan:preview',
        'cards:scan:uploading',
        'cards:scan:recognising',
        'cards:scan:progress',
        'cards:scan:missingheading',
        'cards:scan:recognisedheading',
        'cards:scan:confidence',
        'cards:scan:answerlabel',
        'cards:scan:notrecognised',
        'cards:scan:nochoice',
        'cards:scan:unbookable',
        'cards:scan:apply',
        'cards:scan:discard',
        'cards:scan:applied',
        'cards:scan:skippedheading',
        'cards:scan:imagenotice',
        'cards:state:pending',
        'cards:state:recognised',
        'cards:state:confirmed',
        'cards:state:failed',
        'cards:state:discarded',
        'cards:reason:none',
        'cards:reason:unavailable',
        'cards:reason:unreadable',
        'cards:reason:gone',
        'cards:reason:unsupported',
        'cards:reason:failed',
        'cards:skip:card_without_player',
        'cards:skip:answer_unknown',
        'cards:skip:question_moved_on',
        'cards:skip:answer_too_late',
        'cards:skip:question_not_open',
        'cards:skip:booking_refused',
        'cards:error:scanuploadfailed',
        'cards:error:scanlocked',
        // P11/F5: Fehlkonzept-Radar auf der Host-Bühne. Diese Schlüssel
        // erscheinen bewusst NUR in der Host-Route — die Spielerprojektion
        // trägt weder Etikett noch Ampel (state_projector::poll_fields()).
        'host:hinge:moveon',
        'host:hinge:reteach',
        'host:hinge:insufficient',
        'host:misconception:lead',
        'defaultmode',
        'mode:classic',
        'mode:accuracy',
        'mode:team',
        'mode:security',
        'host:setup:title',
        'host:setup:description',
        'host:namemode:label',
        'host:namemode:real',
        'host:namemode:custom',
        'host:namemode:generated',
        'host:mode:label',
        'host:team:source',
        'host:team:source:free',
        'host:team:source:groups',
        'host:team:names',
        'host:team:nameshint',
        'host:team:add',
        'host:team:remove',
        'host:team:label',
        'host:team:title',
        'host:team:unassigned',
        'host:team:unassignednotice',
        'host:team:toomanygroups',
        'host:security:blockednames',
        'host:security:blockednameshint',
        'host:action:create',
        'host:action:toeditor',
        'host:action:start',
        'host:action:previous',
        'host:action:next',
        'host:action:skip',
        'host:action:reveal',
        'host:action:scoreboard',
        'host:action:groupideas',
        'host:action:startvote',
        'host:brainstorm:description',
        'host:brainstorm:defaultgroup',
        'host:brainstorm:groupname',
        'host:brainstorm:addgroup',
        'host:brainstorm:savegroups',
        'host:action:abort',
        'host:action:abortconfirm',
        'host:action:abortconfirmbutton',
        'host:action:abortcontinue',
        'host:stagemode:toggle',
        'host:stagemode:on',
        'host:stagemode:off',
        'host:action:end',
        'host:action:newround',
        'host:action:retry',
        'host:lobby:pin',
        'host:lobby:qr',
        'host:lobby:joinhint',
        'host:lobby:players',
        'host:lobby:empty',
        'host:lobby:waiting',
        'host:question:progress',
        'host:question:answered',
        'host:question:remaining',
        'host:reveal:title',
        'host:reveal:correct',
        'host:reveal:nopoints',
        'host:reveal:nocorrectness',
        'host:aggregate:wordcloud',
        'host:aggregate:scale',
        'host:aggregate:brainstorm',
        'host:team:scoreboard',
        'host:team:podium',
        'host:scoreboard:title',
        'host:scoreboard:ranking',
        'host:scoreboard:more',
        'host:podium:title',
        'host:podium:ended',
        // P11/F1+F2: Stressarm-Block im Setup und Begründungsfortschritt.
        'host:setup:stressfree',
        'host:setup:pace',
        'host:setup:leaderboard',
        'host:setup:timer',
        'host:setup:sound',
        'host:readiness:ready',
        'host:readiness:none',
        'host:readiness:partial',
        'host:readiness:partialhint',
        'host:reason:progress',
        'pacemode:timed',
        'pacemode:even',
        'leaderboard:own',
        'leaderboard:team',
        'leaderboard:full',
        'live:stage:reason',
    ]),
    'play' => array_merge($sharedlivekeys, [
        'host:namemode:real',
        'host:namemode:generated',
        'host:action:retry',
        'host:question:remaining',
        'play:join:title',
        'play:join:description',
        'play:join:pin',
        'play:join:submit',
        'play:join:notfound',
        'play:join:closed',
        'play:pin:clear',
        'play:pin:back',
        'play:profile:title',
        'play:profile:name',
        'play:profile:namehint',
        'play:profile:avatar',
        'play:profile:accessory',
        'play:profile:locked',
        'play:profile:team',
        'play:profile:teamgroups',
        'play:lobby:ready',
        'play:lobby:count',
        'play:stagemode:toggle',
        'play:stagemode:title',
        'play:stagemode:on',
        'play:stagemode:off',
        'play:countdown:ready',
        'play:question:progress',
        'play:answer:send',
        'play:answer:selected',
        'play:answer:submitted',
        'play:answer:waiting',
        'play:answer:toolate',
        'play:answer:text',
        'play:answer:characters',
        'play:puzzle:up',
        'play:puzzle:down',
        'play:slider:decrease',
        'play:slider:increase',
        'play:pin:confirm',
        'play:brainstorm:idea',
        'play:brainstorm:add',
        'play:brainstorm:collect',
        'play:brainstorm:group',
        'play:brainstorm:vote',
        'play:reaction:label',
        'play:feedback:correct',
        'play:feedback:incorrect',
        'play:feedback:poll',
        'play:feedback:points',
        'play:scoreboard:rank',
        'play:podium:title',
        'play:podium:topthree',
        'play:podium:encouragement',
        'play:ended:overview',
        // P11/F1+F2: Denk-Moment und fehlerfreundliche Rahmung.
        'live:stage:reason',
        'play:reason:title',
        'play:reason:hint',
        'play:reason:placeholder',
        'play:reason:send',
        'play:reason:sent',
        'play:reason:skip',
        'play:friendlynew',
        // P11/C6 (F13): Bühnen-Check. Die Wörter gehen nur an das
        // SPIELERGERÄT — auf der Bühne steht die lernende Person.
        'live:stage:stage',
        'stage:title',
        'stage:intro',
        'stage:privacy',
        'stage:videolabel',
        'stage:mode',
        'stage:mode:stehen',
        'stage:mode:sitzen',
        'stage:action:start',
        'stage:action:stop',
        'stage:action:skip',
        'stage:clock',
        'stage:sent',
        'stage:hint:idle',
        'stage:hint:ok',
        'stage:hint:stepback',
        'stage:hint:center',
        'stage:hint:no-pose',
        'stage:status:starting',
        'stage:status:running',
        'stage:status:saving',
        'stage:status:saved',
        'stage:notice:readonly',
        'stage:failure:camera_denied',
        'stage:failure:camera_missing',
        'stage:failure:engine_unavailable',
        'stage:report:title',
        'stage:metric:eyecontactpct',
        'stage:metric:gesturescore',
        'stage:metric:gestureactivpct',
        'stage:metric:posturescore',
        'stage:metric:movementscore',
        'stage:metric:visibilitypct',
        'stage:coach:visibility',
        'stage:coach:eyegood',
        'stage:coach:eyelow',
        'stage:coach:gesturelow',
        'stage:coach:gesturegood',
        'stage:coach:movementrestless',
        'stage:coach:movementstill',
        'stage:coach:posture',
        'stage:coach:short',
        'stage:error:generic',
        'stage:error:notavailable',
        'stage:error:enginemissing',
    ]),
    'report' => array_merge($sharedlivekeys, [
        // P11/F5: Fehlkonzept-Radar im Bericht.
        'host:hinge:moveon',
        'host:hinge:reteach',
        'host:hinge:insufficient',
        'report:misconception:title',
        'report:misconception:hint',
        'report:misconception:figure',
        'report:misconception:share',
        'report:misconception:correct',
        'report:misconception:distractor',
        'report:misconception:baralt',
        'report:misconception:true',
        'report:misconception:false',
        'report:misconception:unnamed',
        // P11/F6: Kompetenzachse im Bericht.
        'report:competence:title',
        'report:competence:hint',
        'report:competence:value',
        'report:competence:nosample',
        'report:competence:barlabel',
        'report:competence:questions',
        // P11/F3: Lehrer-Dashboard „fällige Themen".
        'schedule:overview:title',
        'schedule:overview:empty',
        'schedule:overview:summary',
        'schedule:overview:untagged',
        'schedule:overview:groupvalue',
        'schedule:overview:barlabel',
        'schedule:overview:learners',
        // P11/F2: Begründungen im Sessionbericht.
        'report:reason:title',
        'report:reason:empty',
        'report:reason:own',
        'report:action:apply',
        'report:action:csv',
        'report:action:csv:hint',
        'report:action:retry',
        'report:action:xlsx',
        'report:aggregate:error',
        'report:empty:description',
        'report:empty:selection',
        'report:empty:title',
        'report:error:config',
        'report:error:request',
        'report:error:title',
        'report:export:dismiss',
        'report:export:error',
        'report:export:label',
        'report:filter:group',
        'report:filter:sessions',
        'report:filter:sources',
        'report:filters:label',
        'report:group:all',
        'report:kpi:averagepoints',
        'report:kpi:correctrate',
        'report:kpi:hardest',
        'report:kpi:hardest:rate',
        'report:kpi:participation',
        'report:kpis:label',
        'report:kpis:gradingnote',
        'report:loaded',
        'report:loading',
        'report:moderation:approved',
        'report:moderation:actor',
        'report:moderation:deleted',
        'report:moderation:decision',
        'report:moderation:empty',
        'report:moderation:grouped',
        'report:moderation:hidden',
        'report:moderation:moderated',
        'report:moderation:rejected',
        'report:moderation:restored',
        'report:moderation:system',
        'report:moderation:title',
        'report:moderation:truncated',
        'report:occurrences:count',
        'report:occurrences:empty',
        'report:open:answer',
        'report:open:empty',
        'report:open:learner',
        'report:open:status',
        'report:open:title',
        'report:open:title:base',
        'report:participant:self',
        'report:participants:caption',
        'report:participants:empty',
        'report:participants:own',
        'report:participants:sorted',
        'report:participants:tablelabel',
        'report:question:answers',
        'report:question:correct',
        'report:question:difficult',
        'report:question:distribution',
        'report:question:missing',
        'report:question:points',
        'report:question:responses',
        'report:question:root',
        'report:question:time',
        'report:question:versions',
        'report:questions:empty',
        'report:questions:medianote',
        'report:questions:missingnote',
        'report:review:notmetric',
        'report:review:question',
        'report:review:truncated',
        'report:scope:combined:summary',
        'report:scope:course:description',
        'report:scope:course:summary',
        'report:scope:label',
        'report:section:participants',
        'report:section:questions',
        'report:sort:ascending',
        'report:sort:descending',
        'report:source:assignment',
        'report:source:session',
        'report:sources:empty',
        'report:sources:omitted',
        'report:sources:selected',
        'report:status:approved',
        'report:status:deleted',
        'report:status:hidden',
        'report:status:pending',
        'report:status:recorded',
        'report:status:rejected',
        'report:subtitle',
        'report:table:averagetime',
        'report:table:correctrate',
        'report:table:name',
        'report:table:points',
        'report:table:sources',
        'report:table:useridentifier',
        'report:timeline:averagepoints',
        'report:timeline:caption',
        'report:timeline:correctrate',
        'report:timeline:date',
        'report:timeline:empty',
        'report:timeline:participants',
        'report:timeline:source',
        'report:timeline:tablelabel',
        'report:timeline:title',
        'report:tab:combined',
        'report:tab:course',
        'report:tab:session',
        'report:time:seconds',
        'report:title',
        'report:version:id',
        'report:version:label',
        'report:versions:empty',
        'report:versions:label',
        'report:visit:authoritative',
        'report:visit:label',
        'report:visit:missing',
        'report:visit:notauthoritative',
        'report:visits:label',
        'selfstudy:review:correctanswer',
        'selfstudy:review:pin',
        'selfstudy:review:selfcheck',
        'selfstudy:review:solution',
        'selfstudy:review:target',
        'selfstudy:review:targettolerance',
    ]),
    default => [],
};

if ($route === 'play' || $route === 'manage') {
    $selfstudystringgroups = [
        'selfstudy:action:' => ['overview', 'retry'],
        'selfstudy:answer:' => ['submitted'],
        'selfstudy:assignment:' => [
            'attempts',
            'graded',
            'opens',
            'progress',
            'resume',
            'review',
            'start',
            'unavailable',
            'ungraded',
        ],
        'selfstudy:attempt:' => [
            'completed', 'finish', 'finishconfirm', 'progress', 'readonly',
        ],
        'selfstudy:completed:' => [
            'cards', 'description', 'review', 'score', 'title',
        ],
        'selfstudy:deadline:' => [
            'comfortable', 'none', 'overdue', 'soon', 'today',
        ],
        'selfstudy:empty:' => ['description', 'title'],
        'selfstudy:error:' => ['config', 'conflict', 'request', 'title'],
        'selfstudy:flashcards:' => [
            'known',
            'knownprogress',
            'markedknown',
            'markedrepeat',
            'notknown',
            'repeatround',
            'reveal',
            'revealed',
            'stacks',
            'think',
        ],
        'selfstudy:goal:' => [
            'edit', 'encouragement', 'locked', 'progress', 'reached', 'save',
            'saved', 'title',
        ],
        'selfstudy:grade:' => [
            'average', 'best', 'empty', 'last', 'title', 'value',
        ],
        'selfstudy:loading:' => ['attempt', 'overview'],
        'selfstudy:multistage:' => ['warning'],
        'selfstudy:navigation:' => [
            'answered', 'label', 'next', 'previous', 'unanswered',
        ],
        'selfstudy:overview:' => ['completed', 'description', 'open', 'title'],
        'selfstudy:question:' => ['unavailable'],
        'selfstudy:review:' => [
            'answersaved',
            'correct',
            'correctanswer',
            'correctshort',
            'explanation',
            'incorrect',
            'incorrectshort',
            'ownanswer',
            'pin',
            'selfcheck',
            'solution',
            'target',
            'targettolerance',
            'ungraded',
        ],
        'selfstudy:status:' => ['archived', 'closed', 'draft', 'open'],
        'selfstudy:solo:' => ['nopoints', 'notimed', 'timerule'],
        'selfstudy:teacher:' => [
            'allowlate',
            'attemptlimit',
            'cancel',
            'close',
            'closeconfirm',
            'closed',
            'conflict',
            'countstowardsgrade',
            'create',
            'createheading',
            'deadline',
            'description',
            'dueat',
            'edit',
            'editheading',
            'empty',
            'graded',
            'invalid',
            'invaliddeadline',
            'list',
            'loading',
            'locked',
            'maxattempts',
            'maxattemptshelp',
            'mode',
            'review',
            'strategy',
            'strategyhelp',
            'multistagewarning',
            'name',
            'noquestions',
            'openat',
            'openlater',
            'opens',
            'participants',
            'questioncount',
            'reminderenabled',
            'save',
            'saved',
            'settings',
            'status',
            'timing',
            'title',
            'ungraded',
        ],
        'selfstudy:test:' => ['feedbacklater', 'finish', 'saved'],
    ];
    foreach ($selfstudystringgroups as $prefix => $suffixes) {
        foreach ($suffixes as $suffix) {
            $clientstringkeys[] = $prefix . $suffix;
        }
    }
    // P11/F12: `speaking` ist ein Zuweisungsmodus wie die vier anderen. Die
    // Liste wird aus assignment_settings gelesen, damit ein weiterer Modus
    // nicht erneut an zwei Stellen nachgezogen werden muss.
    foreach (\mod_quizgeist\local\selfstudy\assignment_settings::MODES as $mode) {
        $clientstringkeys[] = 'selfstudy:mode:' . $mode;
        $clientstringkeys[] = 'selfstudy:mode:' . $mode . ':description';
    }
    // P11/F8: Abschlussbildschirm "Alle Lösungswege".
    foreach ([
        'selfstudy:review:summary:title',
        'selfstudy:review:summary:deferred',
        'selfstudy:review:summary:empty',
        'selfstudy:review:summary:count',
        // P11/F3+F4: Fälligkeitskarte und der Hinweis auf den bewussten
        // Themenwechsel. Beides gehört zum Lernbereich, nicht zum Bericht.
        'schedule:due:title',
        'schedule:due:none',
        'schedule:due:none:next',
        'schedule:due:summary',
        'schedule:due:untagged',
        'schedule:due:topiccount',
        'schedule:interleaving:hint',
        'selfstudy:teacher:strategy:sequential',
        'selfstudy:teacher:strategy:shuffled',
        'selfstudy:teacher:strategy:interleaved',
        // P11/F7: die Fragenwerkstatt aus Sicht der Lernenden — eigene Frage
        // einreichen und fremde Fragen bewerten.
        'workshop:form:title',
        'workshop:form:copy',
        'workshop:form:question',
        'workshop:form:answers',
        'workshop:form:correct',
        'workshop:form:answer',
        'workshop:form:explanation',
        'workshop:form:explanation:hint',
        'workshop:form:submit',
        'workshop:error:explanation:required',
        'workshop:error:explanation:tooshort',
        'workshop:error:questiontext',
        'workshop:error:answers',
        'workshop:error:qtype',
        'workshop:error:selfrating',
        'workshop:error:requiresmanage',
        'workshop:error:note:required',
        'workshop:error:generic',
        'workshop:state:submitted',
        'workshop:state:revising',
        'workshop:state:approved',
        'workshop:state:rejected',
        'workshop:peers:title',
        'workshop:peers:open',
        'workshop:peers:done',
        'workshop:peers:empty',
        'workshop:peers:figures',
        'workshop:peers:norating',
        'workshop:mine:title',
        'workshop:rating:quality',
        'workshop:rating:difficulty',
        'workshop:rating:comment',
        'workshop:rating:save',
    ] as $key) {
        $clientstringkeys[] = $key;
    }
}

if ($route === 'manage') {
    $editorstringgroups = [
        'editor:' => [
            'loading',
            'comingsoon',
            'false',
            'true',
        ],
        'editor:error:' => [
            'config',
            'load:title',
            'network',
            'request',
            'response',
        ],
        'editor:save:' => [
            'saved',
            'saving',
            'dirty',
            'error',
            'conflict',
        ],
        'editor:conflict:' => [
            'title',
            'message',
            'reload',
            'stay',
        ],
        'editor:action:' => [
            'addacceptedanswer',
            'addanswer',
            'addbullet',
            'addmedia',
            'addpuzzleitem',
            'addquestion',
            'aiworkshop',
            'appearance',
            'backtoeditor',
            'cancel',
            'changemedia',
            'choosefile',
            'close',
            'closepreview',
            'delete',
            'done',
            'down',
            'drag',
            'duplicate',
            'import',
            'kahootimport',
            'managemedia',
            'preview',
            'publish',
            'questionmenu',
            'remove',
            'release',
            'releaseall',
            'retry',
            'search',
            'templates',
            'up',
        ],
        'editor:status:' => [
            'ready',
            'draft',
            'label',
        ],
        'editor:readiness:' => [
            'media',
            'unsupported',
            'notreleased',
        ],
        'editor:release:' => [
            'success',
            'working',
            'draft',
            'bulk:result',
            'bulk:reasons',
        ],
        'editor:addpalette:' => [
            'title',
            'description',
        ],
        'editor:ai:' => [
            'unavailable',
            'intro',
            'draft',
            'gatewayavailable',
            'fallbackavailable',
            'outbounddisabled',
            'source',
            'topic',
            'topic:hint',
            'url',
            'wikipedia',
            'wikipedia:placeholder',
            'focus',
            'grade',
            'count',
            'count:hint',
            'count:invalid',
            'format',
            'file:title',
            'file:hint',
            'file:none',
            'file:choose',
            'file:required',
            'file:iframe',
            'generate',
            'generating',
            'generating:detail',
            'apply',
            'applying',
            'applied',
            'fallback',
            'gateway',
            'import',
            'server',
            'warnings',
            'warning:generic',
            'warning:gateway',
            'warning:gatewayfallback',
            'warning:resulttruncated',
            'warning:texttruncated',
            'warning:pdftext',
            'warning:pdfinfo',
            'warning:pdfnotext',
            'warning:pdflayout',
            'warning:pdfpreview',
            'warning:pdfpreviewtimeout',
            'warning:pdfpreviewlimit',
            'warning:pdfpreviewunavailable',
            'warning:noquestions',
            'warning:duplicatequestions',
            'warning:questionstructure',
            'warning:externalignored',
            'warning:imagetotallimit',
            'warning:blankslide',
            'warning:slideimagelimit',
            'warning:slidenonmedia',
            'warning:slideunsupported',
            'warning:slideimageinvalid',
            'selected',
            'selectall',
            'selectnone',
            'validation',
            'expires',
            'explanation:action',
            'explanation:title',
            'explanation:intro',
            'explanation:loading',
            'explanation:confirm',
            'explanation:confirming',
            'explanation:applied',
            'explanation:preview',
            'explanation:draftnotice',
        ],
        'editor:announcement:' => [
            'deleted',
            'duplicated',
            'moved',
        ],
        'editor:appearance:' => [
            'title',
            'background',
            'logo',
            'nofile',
        ],
        'editor:backtrack:' => [
            'title',
            'description',
            'toggle',
        ],
        'editor:confirm:' => [
            'deletequestion',
            'deletetemplate',
        ],
        'editor:empty:' => [
            'title',
            'description',
            'manual',
            'ai',
            'templates',
            'kahoot',
            'future',
        ],
        'editor:field:' => [
            'acceptedanswers',
            'answer',
            'answerimage',
            'answers',
            'attribution',
            'bullets',
            'collectseconds',
            'correct',
            'correctanswer',
            'explanation',
            'explanation:hint',
            'grid',
            'grouping',
            'max',
            'maxchars',
            'maxlabel',
            'media',
            'media:required',
            'media:hint',
            'min',
            'minlabel',
            'moderation',
            'moderation:hint',
            'multiple',
            'multiple:hint',
            'pintarget:hint',
            'pointmode',
            'puzzleitem',
            'puzzleitemmedia',
            'puzzleitems',
            'puzzleitems:hint',
            'questiontext',
            'questiontext:hint',
            'quiztitle',
            'quote',
            'radius',
            'reactions',
            'reactions:hint',
            'revealseconds',
            'sampleanswer',
            'sampleanswer:hint',
            'season',
            'slidebody',
            'slidelayout',
            'slidetitle',
            'step',
            'steps',
            'target',
            'targetx',
            'targety',
            'theme',
            'timelimit',
            'tolerance',
            'typotolerance',
            'typotolerance:hint',
            'videocaption',
            'videourl',
            'voteseconds',
        ],
        'editor:grouping:' => [
            'ai',
            'manual',
            'hint',
        ],
        'editor:layout:' => [
            'title',
            'textimage',
            'bullets',
            'quote',
            'video',
            'fullscreen',
        ],
        'editor:logo:' => [
            'alt',
            'schoolalt',
        ],
        'editor:season:' => [
            'herbst',
            'winter',
            'fruehling',
            'sommer',
        ],
        'editor:media:' => [
            'none',
            'unavailable',
            'iframe:title',
            'title:question',
            'title:background',
            'title:logo',
        ],
        'editor:nextstep:' => [
            'title',
            'ready',
            'incomplete',
            'host',
            'jump',
            'assignment',
        ],
        'editor:pointmode:' => [
            'standard',
            'double',
            'none',
            'forcednone',
        ],
        'editor:preview:' => [
            'title',
            'noquestion',
            'emptyanswer',
            'typeanswer',
            'brainstorm',
        ],
        'editor:questions:' => [
            'count',
            'label',
        ],
        'editor:tts:' => [
            'play',
            'loading',
            'voice',
            'unavailable',
            'error',
        ],
        'editor:templates:' => [
            'eyebrow',
            'title',
            'description',
            'searchlabel',
            'searchplaceholder',
            'loading',
            'empty',
            'nodescription',
            'questioncount',
            'append',
            'replace',
            'deletedsuccess',
        ],
        'editor:publish:' => [
            'title',
            'name',
            'description',
            'tags',
            'tagsplaceholder',
            'tagshint',
            'publishing',
            'success',
        ],
        'editor:import:' => [
            'title',
            'append:description',
            'replace:description',
            'appearance',
            'working',
            'success',
        ],
        'editor:validation:' => [
            'title',
            'item',
            'overview:one',
            'overview:many',
            'jump',
            'field',
            'fieldnumbered',
            'generic',
            'required',
            'invalid',
            'out_of_range',
            'min_two',
            'max_six',
            'too_many',
            'duplicate',
            'correct_required',
            'exactly_one_correct',
            'image_required',
            'text_or_image_required',
            'text_or_media_required',
            'video_required',
            'greater_than_min',
            'slide_content_required',
            'external_media_forbidden',
            'unsupported',
            'pending',
        ],
    ];
    foreach ($editorstringgroups as $prefix => $suffixes) {
        foreach ($suffixes as $suffix) {
            $clientstringkeys[] = $prefix . $suffix;
        }
    }
    foreach ([
        'host:readiness:ready',
        'host:readiness:none',
        'host:readiness:partial',
        'host:readiness:partialhint',
    ] as $key) {
        $clientstringkeys[] = $key;
    }
    foreach ([
        'quiz',
        'truefalse',
        'shortanswer',
        'puzzle',
        'poll',
        'wordcloud',
        'scale',
        'slider',
        'pin',
        'reveal',
        'brainstorm',
        'open',
        'slide',
    ] as $qtype) {
        $clientstringkeys[] = 'editor:qtype:' . $qtype;
        $clientstringkeys[] = 'editor:qtype:' . $qtype . ':description';
    }
    foreach ([
        'topic',
        'pdf',
        'pdf_questions',
        'url',
        'wikipedia',
        'slides',
        'handwriting',
    ] as $aisource) {
        $clientstringkeys[] = 'editor:ai:source:' . $aisource;
        $clientstringkeys[] = 'editor:ai:source:' . $aisource . ':description';
    }
    foreach ([
        'quiz',
        'truefalse',
        'micro_lesson',
        'vocabulary',
        'presentation',
        'practice_test',
        'step_by_step',
    ] as $aiformat) {
        $clientstringkeys[] = 'editor:ai:format:' . $aiformat;
    }
    foreach ([
        // P11/C6 (F13): der eine Lehrkraft-Schalter des Bühnen-Untermodus.
        'editor:field:stagecheck',
        'editor:field:stagecheck:hint',
        'editor:field:stagecheck:locked',
        'editor:pin:heatmap',
        'editor:pin:mediarequired',
        'editor:poll:nopoints',
        'editor:open:nopoints',
        'editor:time:none',
        'editor:unit:grid',
        'editor:unit:percent',
        'editor:unit:seconds',
        'editor:question:untitled',
        'theme:hell',
        'theme:dunkel',
        'theme:weltraum',
        'theme:ozean',
        'theme:retroarcade',
        'theme:jahreszeiten',
        // P11/U1: Tagging-Werkzeug im Editor.
        'tag',
        'tag:reserved:new',
        'tag:kind:topic',
        'tag:kind:qtype',
        'tag:kind:competence',
        'tag:scope:activity',
        'tag:scope:course',
        'tag:scope:site',
        'tag:field:label',
        'tag:field:tagkey',
        'tag:field:colorkey',
        'tag:field:externalref',
        'tag:field:sortorder',
        'tag:field:weight',
        'tag:action:add',
        'tag:action:save',
        'tag:action:remove',
        'tag:empty',
        'tag:assigned:empty',
        'tag:status:approved',
        'tag:status:suggested',
        'tag:saved',
        'tag:error:required',
        'tag:error:invalid',
        'tag:error:duplicate',
        'tag:error:out_of_range',
        'tag:error:too_many',
        'tag:error:generic',
        // P11/F2: der Denk-Moment ist eine Frageneinstellung im Editor.
        'reasonstep',
        // P11/F7: die Kuratierungsliste der Fragenwerkstatt im Editor.
        'workshop:curate:title',
        'workshop:curate:copy',
        'workshop:curate:empty',
        'workshop:curate:figures',
        'workshop:curate:note',
        'workshop:curate:approve',
        'workshop:curate:revise',
        'workshop:curate:reject',
        'workshop:curate:aicheck',
        'workshop:author:anonymous',
        'workshop:state:submitted',
        'workshop:state:revising',
        'workshop:state:approved',
        'workshop:state:rejected',
        'workshop:error:requiresmanage',
        'workshop:error:note:required',
        'workshop:error:generic',
        'workshop:aicheck:gateway',
        'workshop:aicheck:fallback',
        'workshop:aicheck:comprehensible',
        'workshop:aicheck:unique',
        'workshop:aicheck:explanation',
        'workshop:aicheck:other',
        'workshop:aicheck:ok',
        'workshop:aicheck:attention',
    ] as $key) {
        $clientstringkeys[] = $key;
    }
}

// ---------------------------------------------------------------------------
// P11/C4: Kurzclip-Kanal, Sprech-Trainer, Diktat, Lehrplan-Anker.
//
// Die Aufnahme-Schluessel gehen an JEDE Route, auf der eine Aufnahme entstehen
// oder gehoert werden kann — und zwar unabhaengig vom KI-Addon. Ohne Addon
// erscheint der Aufnahmeknopf gar nicht (2.6), aber ein BESTEHENDER Clip
// bleibt hoer- und sichtbar, und dafuer braucht auch die Basis die Saetze.
// ---------------------------------------------------------------------------
$clipclientkeys = [
    'clip:error:notallowed',
    'clip:error:notfound',
    'clip:error:alreadybound',
    'clip:error:uploadfailed',
    'clip:error:empty',
    'clip:error:toolarge',
    'clip:error:toolong',
    'clip:error:typeinvalid',
    'clip:error:durationunreadable',
    'clip:error:ratelimited',
    'clip:error:audiodeleted',
    'clip:record:start',
    'clip:record:stop',
    'clip:record:requesting',
    'clip:record:running',
    'clip:record:stopping',
    'clip:record:failed',
    'clip:record:denied',
    'clip:record:unavailable',
    'clip:record:usetext',
    'clip:upload:running',
    'clip:upload:done',
    'clip:transcript:pending',
    'clip:transcript:done',
    'clip:transcript:failed',
    'clip:transcript:slow',
    'clip:transcript:unavailable',
    'clip:transcript:waiting',
    'clip:play',
    'clip:languagehint',
];
if (in_array($route, ['play', 'host', 'report', 'manage'], true)) {
    foreach ($clipclientkeys as $key) {
        $clientstringkeys[] = $key;
    }
}
if ($route === 'play') {
    // F12: Der Sprech-Trainer laeuft im Selbstlern-Bereich der Spielroute.
    foreach ([
        'speaking:mode',
        'speaking:read',
        'speaking:textlabel',
        'speaking:textplaceholder',
        'speaking:submittext',
        'speaking:empty',
        'speaking:working',
        'speaking:failed',
        'speaking:nomic',
        'speaking:score:passed',
        'speaking:score:open',
        'speaking:transcript',
        'speaking:origin:fallback',
    ] as $key) {
        $clientstringkeys[] = $key;
    }
}
if ($route === 'manage') {
    // F9-Diktat und F10-Lehrplanbezug sind Werkzeuge der Lehrkraft.
    foreach ([
        'dictation:start',
        'dictation:stop',
        'dictation:running',
        'dictation:working',
        'dictation:done',
        'dictation:nothingheard',
        'dictation:empty',
        'dictation:denied',
        'dictation:unavailable',
        'dictation:failed',
        'curriculum:heading',
        'curriculum:filter:subject',
        'curriculum:filter:grade',
        'curriculum:filter:learningarea',
        'curriculum:competency',
        'curriculum:source',
        'curriculum:externalhint',
        'curriculum:nogrounding',
        'curriculum:gradeunsupported',
        'curriculum:nomatches',
        'curriculum:unavailable',
        'curriculum:uncited',
    ] as $key) {
        $clientstringkeys[] = $key;
    }
}
if ($route === 'report') {
    // F9: Die Vorbewertung ist ein Vorschlag im Bericht, keine Note.
    foreach ([
        'opengrader:heading',
        'opengrader:proposal',
        'opengrader:teacherdecides',
        'opengrader:nosample',
        'opengrader:fallback',
        'curriculum:heading',
        'curriculum:competency',
        'curriculum:source',
        'curriculum:externalhint',
    ] as $key) {
        $clientstringkeys[] = $key;
    }
}

$clientstrings = [];
foreach (array_unique($clientstringkeys) as $key) {
    $clientstrings[$key] = get_string($key, 'mod_quizgeist');
}

$ttsconfig = [
    'available' => false,
    'defaultVoiceId' => 0,
    'speakUrl' => '',
    'voices' => [],
];
$systemcontext = context_system::instance();
if (\mod_quizgeist\local\licence\feature_gate::can_create('ai')
        && class_exists('\\local_voces\\api')
        && has_capability('local/voces:use', $systemcontext)) {
    try {
        $rawvoices = \local_voces\api::voices('de');
        $speakurl = (string)\local_voces\api::speak_url();
        foreach ($rawvoices as $rawvoice) {
            $voice = (array)$rawvoice;
            $voiceid = clean_param($voice['id'] ?? 0, PARAM_INT);
            $voicelabel = clean_param($voice['label'] ?? '', PARAM_TEXT);
            if ($voiceid <= 0 || $voicelabel === '') {
                continue;
            }
            $ttsconfig['voices'][] = [
                'id' => $voiceid,
                'label' => $voicelabel,
                'lang' => clean_param($voice['lang'] ?? '', PARAM_ALPHANUMEXT),
                'region' => clean_param($voice['region'] ?? '', PARAM_TEXT),
                'gender' => clean_param($voice['gender'] ?? '', PARAM_ALPHANUMEXT),
            ];
        }
        $ttsconfig['available'] = \local_voces\api::is_available()
            && $speakurl !== ''
            && !empty($ttsconfig['voices']);
        $ttsconfig['defaultVoiceId'] = $ttsconfig['voices'][0]['id'] ?? 0;
        $ttsconfig['speakUrl'] = $speakurl;
    } catch (Throwable $exception) {
        $ttsconfig = [
            'available' => false,
            'defaultVoiceId' => 0,
            'speakUrl' => '',
            'voices' => [],
        ];
    }
}

$initialview = 'editor';
if ($route === 'play') {
    $initialview = !$selfstudyinstalled
        || $requestedview === 'play'
        || $requestedjoincode !== ''
        ? 'live'
        : ($requestedview === 'attempt' || $requestedattemptid > 0
            ? 'attempt'
            : 'overview');
} else if ($route === 'manage') {
    $initialview = $selfstudyinstalled && $requestedview === 'assignments'
        ? 'assignments'
        : ($requestedview === 'templates' ? 'templates' : 'editor');
} else if ($route === 'report') {
    $initialview = 'reports';
}

$showtabs = $canmanage || $canhost || $canviewreports;
$tabselementid = 'quizgeist-tabs-' . (int)$cm->id;

// P11/U3: Der Kurzclip-Kanal beschreibt sich dem Client vollstaendig, damit
// die Oberflaeche keine Grenze raten muss. `canRecord` folgt der Regel aus
// 2.6: OHNE ai-Addon erscheint der Aufnahmeknopf gar nicht (kein gesperrter
// Koeder), mit Addon aber ohne Lizenz ist er da und die Verschriftung ist es
// nicht — der Clip bleibt trotzdem abspielbar.
$clipaiavailable = (bool)($clientfeatureavailability['ai']['installed'] ?? false);
$clipcancreate = $clipaiavailable
    && (bool)\mod_quizgeist\local\licence\feature_gate::can_create('ai');
$clipconfig = [
    'uploadUrl' => (new moodle_url('/mod/quizgeist/clip_upload.php'))->out(false),
    'playUrlBase' => (new moodle_url('/mod/quizgeist/clip.php', [
        'id' => (int)$cm->id,
    ]))->out(false),
    'canRecord' => $clipaiavailable
        && has_capability('mod/quizgeist:recordaudio', $context),
    'canTranscribe' => $clipcancreate,
    'maxBytes' => \mod_quizgeist\local\media\clip_limits::max_bytes(),
    'maxSeconds' => \mod_quizgeist\local\media\clip_limits::max_seconds(),
    // E-12: Die Sprache wird ueberall durchgereicht, damit ein spaeterer
    // mehrsprachiger Dienst eine Konfiguration ist und kein Umbau.
    'language' => \mod_quizgeist\local\media\clip_service::normalise_language(
        (string)get_config('mod_quizgeist', 'clip_language')
    ),
    'retentionDays' => \mod_quizgeist\local\media\clip_limits::retention_days(),
];

// P11/C5 (F11a): Der Karten-Modus beschreibt sich dem Host vollstaendig.
// Nach 2.6 gilt: OHNE ai-Addon fehlt der Schluessel ganz — die Flaeche
// erscheint dann gar nicht, statt gesperrt zu erscheinen. Eine LEERE Liste
// heisst „Addon da, aber noch kein Kartensatz angelegt".
$cardconfig = [];
if ($canhost
        && (bool)($clientfeatureavailability['ai']['installed'] ?? false)
        && has_capability('mod/quizgeist:scancards', $context)) {
    $cardsets = [];
    foreach (\mod_quizgeist\local\cards\card_repository::cardsets((int)$quizgeist->id) as $cardset) {
        $cardsets[] = \mod_quizgeist\local\cards\cardset_service::project($cardset);
    }
    $cardconfig = [
        'cardSets' => $cardsets,
        // Derselbe Multipart-Einstieg wie beim Kurzclip (E-3): EIN Upload-Weg,
        // eine Haertung, eine Ratenbegrenzung.
        'cardScanUploadUrl' => (new moodle_url('/mod/quizgeist/clip_upload.php'))->out(false),
        'cardsPrintUrl' => (new moodle_url('/mod/quizgeist/cards_pdf.php', [
            'id' => (int)$cm->id,
        ]))->out(false),
    ];
}

// P11/C6 (F13): Der Bühnen-Check beschreibt sich dem Spielergerät selbst.
// Nach 2.6 gilt: OHNE das Addon-Codepaket fehlt der Schlüssel GANZ — der
// Untermodus erscheint dann gar nicht, statt gesperrt zu erscheinen. Beide
// URLs zeigen in das Addon-Verzeichnis dieses Plugins; es gibt kein CDN.
$stageconfig = [];
if ($route === 'play'
        && (bool)($clientfeatureavailability['buehne']['installed'] ?? false)
        && has_capability('mod/quizgeist:presentstage', $context)) {
    $stageconfig = [
        'stage' => [
            'assetsUrl' => (new moodle_url(
                '/mod/quizgeist/addon/buehne/thirdparty/mediapipe'
            ))->out(false),
            'bundleUrl' => (new moodle_url(
                '/mod/quizgeist/addon/buehne/amd/build/app_stage.js',
                ['v' => $assetversion]
            ))->out(false),
            'maxSeconds' => \quizgeistaddon_buehne\local\stage_service::max_seconds(),
        ],
    ];
}

$navigationconfig = [];
if ($route === 'manage') {
    $navigationconfig['hostUrl'] = (new moodle_url('/mod/quizgeist/view.php', [
        'id' => (int)$cm->id,
        'view' => 'host',
    ]))->out(false);
} else if ($route === 'host') {
    $navigationconfig = ['tabsElementId' => $tabselementid];
    if ($canviewreports) {
        $navigationconfig['reportsUrl'] = (new moodle_url('/mod/quizgeist/view.php', [
            'id' => (int)$cm->id,
            'view' => 'reports',
        ]))->out(false);
    }
    if ($canmanage) {
        $navigationconfig['editorUrl'] = (new moodle_url('/mod/quizgeist/view.php', [
            'id' => (int)$cm->id,
            'view' => 'editor',
        ]))->out(false);
    }
}

$config = $stageconfig + $cardconfig + $navigationconfig + [
    'ajaxUrl' => (new moodle_url('/mod/quizgeist/ajax.php'))->out(false),
    'bootstrapAction' => 'report_bootstrap',
    'brandIconUrl' => (new moodle_url('/mod/quizgeist/pix/icon.svg'))->out(false),
    'cmid' => (int)$cm->id,
    'containerId' => $containerid,
    'initialView' => $initialview,
    'assignmentId' => $requestedassignmentid,
    'attemptId' => $requestedattemptid,
    'instanceId' => (int)$quizgeist->id,
    'initialJoinCode' => $requestedjoincode,
    'mediaUrl' => (new moodle_url('/mod/quizgeist/media.php', [
        'id' => (int)$cm->id,
    ]))->out(false),
    'playerUrlBase' => (new moodle_url('/mod/quizgeist/view.php', [
        'id' => (int)$cm->id,
        'view' => 'play',
    ]))->out(false),
    'overviewUrl' => (new moodle_url('/mod/quizgeist/view.php', [
        'id' => (int)$cm->id,
        'view' => 'overview',
    ]))->out(false),
    'dataAction' => 'report_data',
    'exportUrl' => (new moodle_url('/mod/quizgeist/export.php', [
        'id' => (int)$cm->id,
    ]))->out(false),
    'kahootImportUrl' => (new moodle_url(
        '/mod/quizgeist/kahoot_import.php',
        ['id' => (int)$cm->id]
    ))->out(false),
    'locale' => current_language(),
    'route' => $route,
    'season' => $quizgeist->season ?? 'herbst',
    'sesskey' => sesskey(),
    'theme' => $quizgeist->theme,
    'tts' => $ttsconfig,
    'userId' => (int)$USER->id,
    'viewerKind' => $canviewreports ? 'teacher' : 'student',
    'capabilities' => [
        'manage' => $canmanage,
        'host' => $canhost,
        'play' => $canplay,
        'viewReports' => $canviewreports,
    ],
    'features' => $clientfeatureavailability,
    'clips' => $clipconfig,
    'strings' => $clientstrings,
];
// Der RequireJS-Sammelcache dieses Servers liefert Plugin-Module unzuverlässig aus
// (wechselnd je lsphp-Worker). Bundles werden deshalb als direkte Script-Tags
// geladen und inline gebootet — Muster local_navordnung.
$quizgeistglobals = [
    'app_edit' => 'QuizgeistEditApp',
    'app_host' => 'QuizgeistHostApp',
    'app_play' => 'QuizgeistPlayApp',
    'app_report' => 'QuizgeistReportApp',
];
$quizgeistglobal = $quizgeistglobals[$bundle];
$quizgeistbundleurl = new moodle_url(
    '/mod/quizgeist/amd/build/' . $bundle . '.js',
    ['v' => $assetversion]
);

echo $OUTPUT->header();

echo html_writer::start_tag('main', [
    'class' => 'quizgeist-shell',
    'data-instance-id' => (int)$quizgeist->id,
    'data-route' => $route,
]);
if ($route !== 'host') {
    echo html_writer::tag('h2', $heading, ['class' => 'quizgeist-shell__title']);
}
if ($showgracenotice) {
    echo html_writer::tag(
        'p',
        get_string('licence:gracenotice', 'mod_quizgeist'),
        [
            'class' => 'alert alert-info quizgeist-licence-grace',
            'role' => 'status',
        ]
    );
}
if ($showreadonlynotice) {
    echo html_writer::tag(
        'p',
        get_string('licence:readonlynotice', 'mod_quizgeist'),
        [
            'class' => 'alert alert-warning quizgeist-licence-readonly',
            'role' => 'status',
        ]
    );
}

if (trim((string)$quizgeist->intro) !== '') {
    echo $OUTPUT->box(
        format_module_intro('quizgeist', $quizgeist, $cm->id),
        'generalbox mod_introbox',
        'quizgeistintro'
    );
}

if ($showtabs) {
    $tabs = [];
    foreach ([
        'editor',
        'live',
        'assignments',
        'reports',
        'templates',
        'kahootimport',
        'topia',
    ] as $candidate) {
        $allowed = $candidate === 'live'
            ? $canhost
            : ($candidate === 'reports'
                ? $canviewreports
                : ($candidate === 'assignments'
                    ? $canmanage && $selfstudyinstalled
                    : ($candidate === 'topia' ? $canviewtopia : $canmanage)));
        if ($allowed) {
            $tabs[] = $candidate;
        }
    }
    echo html_writer::start_tag('nav', [
        'class' => 'quizgeist-tabs',
        'id' => $tabselementid,
        'aria-label' => get_string('nav:tabs', 'mod_quizgeist'),
    ]);
    $activetab = $route === 'report'
        ? 'reports'
        : ($route === 'host'
            ? 'live'
            : ($requestedview === 'host'
                ? 'live'
                : ($requestedview === 'overview' ? 'editor' : $requestedview)));
    foreach ($tabs as $tab) {
        if ($tab === 'live') {
            $url = new moodle_url('/mod/quizgeist/view.php', [
                'id' => $cm->id,
                'view' => 'host',
            ]);
        } else if ($tab === 'kahootimport') {
            $url = new moodle_url('/mod/quizgeist/kahoot_import.php', [
                'id' => $cm->id,
            ]);
        } else if ($tab === 'topia') {
            $url = new moodle_url('/mod/quizgeist/topia.php', [
                'id' => $cm->id,
            ]);
        } else {
            $url = new moodle_url('/mod/quizgeist/view.php', [
                'id' => $cm->id,
                'view' => $tab,
            ]);
        }
        $tabattributes = [
            'class' => 'quizgeist-tabs__item'
                . ($activetab === $tab ? ' is-active' : ''),
        ];
        if ($activetab === $tab) {
            $tabattributes['aria-current'] = 'page';
        }
        echo html_writer::link(
            $url,
            $tab === 'kahootimport'
                ? get_string('editor:action:kahootimport', 'mod_quizgeist')
                : ($tab === 'topia'
                    ? get_string('topia:title', 'mod_quizgeist')
                    : get_string('tab:' . $tab, 'mod_quizgeist')),
            $tabattributes
        );
    }
    echo html_writer::end_tag('nav');
}
if ($route === 'play' && $canviewtopia && !$showtabs) {
    echo html_writer::tag(
        'nav',
        html_writer::link(
            new moodle_url('/mod/quizgeist/topia.php', ['id' => (int)$cm->id]),
            get_string('topia:title', 'mod_quizgeist'),
            ['class' => 'quizgeist-tabs__item']
        ),
        [
            'class' => 'quizgeist-tabs quizgeist-tabs--student',
            'aria-label' => get_string('topia:navigation', 'mod_quizgeist'),
        ]
    );
}

echo html_writer::tag('div', '', [
    'id' => $containerid,
    'class' => 'quizgeist-app',
    'data-quizgeist-root' => $route,
    'data-quizgeist-season' => $quizgeist->season ?? 'herbst',
    'data-quizgeist-theme' => $quizgeist->theme,
]);
echo html_writer::tag('noscript', get_string('javascriptrequired', 'mod_quizgeist'));
echo html_writer::end_tag('main');

echo html_writer::script('', $quizgeistbundleurl->out(false));
$jsonflags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
echo html_writer::script(
    '(function(){var g=' . json_encode($quizgeistglobal, $jsonflags) .
    ',c=' . json_encode($config, $jsonflags) . ',t=0;' .
    '(function w(){var a=window[g];' .
    'if(a&&typeof a.init==="function"){a.init(c);}' .
    'else if((t+=50)<20000){setTimeout(w,50);}' .
    'else{console.error("Quizgeist: Bundle "+g+" wurde nicht geladen.");}})();})();'
);

echo $OUTPUT->footer();
