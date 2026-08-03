<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Backup structure for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @category   backup
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Complete activity structure backup.
 *
 * Activity content/configuration records are always included. Live sessions,
 * players, answers, attempts, and rewards are included only when user data is
 * enabled. The school-wide template library is site data and is deliberately
 * excluded from activity backups.
 */
class backup_quizgeist_activity_structure_step extends backup_activity_structure_step {

    /**
     * Define the complete backup tree, sources, ID annotations and file areas.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $quizgeist = new backup_nested_element('quizgeist', ['id'], [
            'name',
            'intro',
            'introformat',
            'theme',
            'season',
            'allowbacktrack',
            'defaultmode',
            'grademethod',
            'grade',
            'pacemode',
            'leaderboard',
            'timervisible',
            'soundenabled',
            'friendlynew',
            'reasonstep',
            'explanationpolicy',
            'completionparticipate',
            'completionpercent',
            'timecreated',
            'timemodified',
        ]);

        $questions = new backup_nested_element('questions');
        $imports = new backup_nested_element('imports');
        $import = new backup_nested_element('import', ['id'], [
            'sourceformat',
            'sourceuuid',
            'sourcename',
            'sourcehash',
            'status',
            'questioncount',
            'adaptedcount',
            'skippedcount',
            'mediacount',
            'reportjson',
            'timecreated',
            'timemodified',
        ]);
        $question = new backup_nested_element('question', ['id'], [
            'importid',
            'rootid',
            'version',
            'sortorder',
            'qtype',
            'questiontext',
            'questionformat',
            'optionsjson',
            'timelimit',
            'pointmode',
            'explanation',
            'status',
            'createdby',
            'timecreated',
            'timemodified',
        ]);

        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], [
            'hostuserid',
            'joincode',
            'status',
            'mode',
            'currentquestionid',
            'stateversion',
            'statejson',
            'settingsjson',
            'timestarted',
            'timeended',
            'timecreated',
            'timemodified',
        ]);
        $sessionteamgroups = new backup_nested_element('sessionteamgroups');
        $sessionteamgroup = new backup_nested_element(
            'sessionteamgroup',
            ['id'],
            ['groupid']
        );
        $sessionquestions = new backup_nested_element('sessionquestions');
        $sessionquestion = new backup_nested_element('sessionquestion', ['id'], [
            'sortindex',
            'questionid',
            'visit',
            'visitstate',
            'resolvedvisit',
            'stage',
        ]);
        $players = new backup_nested_element('players');
        $player = new backup_nested_element('player', ['id'], [
            'userid',
            'displayname',
            'groupid',
            'teamname',
            'avatarkey',
            'score',
            'streak',
            'status',
            'timejoined',
            'lastseen',
            'timemodified',
        ]);
        $liveanswers = new backup_nested_element('liveanswers');
        $liveanswer = new backup_nested_element('liveanswer', ['id'], [
            'questionid',
            'userid',
            'answertype',
            'visit',
            'submissionkey',
            'answerjson',
            'iscorrect',
            'points',
            'maxpoints',
            'responsetime',
            'timecreated',
        ]);
        $sessioninteractions = new backup_nested_element('sessioninteractions');
        $sessioninteraction = new backup_nested_element('sessioninteraction', ['id'], [
            'questionid',
            'userid',
            'answertype',
            'visit',
            'submissionkey',
            'answerjson',
            'iscorrect',
            'points',
            'maxpoints',
            'responsetime',
            'timecreated',
        ]);

        $assignments = new backup_nested_element('assignments');
        $assignment = new backup_nested_element('assignment', ['id'], [
            'name',
            'mode',
            'status',
            'timeopen',
            'timedue',
            'createdby',
            // F3: fixed friert exakte Versionen ein, due friert Wurzeln ein.
            'selection',
            'settingsjson',
            'timecreated',
            'timemodified',
        ]);
        $assignmentquestions = new backup_nested_element('assignmentquestions');
        $assignmentquestion = new backup_nested_element('assignmentquestion', ['id'], [
            'sortindex',
            'questionid',
            'rootid',
        ]);
        $attempts = new backup_nested_element('attempts');
        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid',
            'attemptnumber',
            'mode',
            'status',
            'score',
            'maxscore',
            'statejson',
            'flashcardsjson',
            'stateversion',
            'timestarted',
            'timefinished',
            'remindedat',
            'timemodified',
        ]);
        $attemptquestions = new backup_nested_element('attemptquestions');
        $attemptquestion = new backup_nested_element('attemptquestion', ['id'], [
            'sortindex',
            'questionid',
            'visit',
            'status',
            'answerjson',
            'round',
            'timestarted',
            'timesubmitted',
            'timemodified',
        ]);
        $soloanswers = new backup_nested_element('soloanswers');
        $soloanswer = new backup_nested_element('soloanswer', ['id'], [
            'questionid',
            'userid',
            'answertype',
            'visit',
            'submissionkey',
            'answerjson',
            'iscorrect',
            'points',
            'maxpoints',
            'responsetime',
            'timecreated',
        ]);
        $reminders = new backup_nested_element('reminders');
        $reminder = new backup_nested_element('reminder', ['id'], [
            'userid',
            'attemptcount',
            'timesent',
        ]);
        $goals = new backup_nested_element('goals');
        $goal = new backup_nested_element('goal', ['id'], [
            'userid',
            'target',
            'timecreated',
            'timemodified',
        ]);

        $stagereports = new backup_nested_element('stagereports');
        $stagereport = new backup_nested_element('stagereport', ['id'], [
            'userid',
            'questionid',
            'answerid',
            'durationsecs',
            'metricsjson',
            'aiused',
            'feedbackjson',
            'timecreated',
        ]);

        // U1 Tagging-Kern. Tags are course content, not user data, and are
        // therefore backed up regardless of the userinfo setting. Only the
        // authorship of an assignment is a user reference.
        $tags = new backup_nested_element('tags');
        $tag = new backup_nested_element('tag', ['id'], [
            'scope',
            'scopeid',
            'kind',
            'tagkey',
            'label',
            'colorkey',
            'externalref',
            'sortorder',
            'timecreated',
            'timemodified',
        ]);
        $questiontags = new backup_nested_element('questiontags');
        $questiontag = new backup_nested_element('questiontag', ['id'], [
            'rootid',
            'tagid',
            'weight',
            'status',
            'createdby',
            'timecreated',
        ]);

        // F5 misconception labels are course content, not participant data.
        $misconceptions = new backup_nested_element('misconceptions');
        $misconception = new backup_nested_element('misconception', ['id'], [
            'rootid',
            'answerkey',
            'label',
            'hint',
            'timecreated',
            'timemodified',
        ]);

        // F10 curriculum anchors are course content and therefore travel in
        // every activity backup, independently of userinfo.
        $curriculumrefs = new backup_nested_element('curriculumrefs');
        $curriculumref = new backup_nested_element('curriculumref', ['id'], [
            'rootid',
            'subject',
            'grade',
            'variant',
            'learningarea',
            'competency',
            'citationurl',
            'chunkid',
            'timecreated',
        ]);

        // Kartenscans sind flüchtige Klassenfotos mit Aufbewahrungsfrist E-10.
        // Ein Backup würde diese Frist aushebeln, da z. B. ein Backup 2026 im
        // Jahr 2029 das längst zu löschende Foto zurückbringen könnte. Deshalb
        // werden Kartenscans nicht gesichert oder wiederhergestellt.
        $cardsets = new backup_nested_element('cardsets');
        $cardset = new backup_nested_element('cardset', ['id'], [
            'name',
            'layout',
            'seed',
            'createdby',
            'timecreated',
            'timemodified',
        ]);
        $cards = new backup_nested_element('cards');
        $card = new backup_nested_element('card', ['id'], [
            'cardindex',
            'userid',
            'cardcode',
            'timecreated',
        ]);

        // U3 clips are participant data. The answer reference is kept as a
        // plain field because it may point to either the live or solo answer
        // branch; restore resolves it against both mappings.
        $clips = new backup_nested_element('clips');
        $clip = new backup_nested_element('clip', ['id'], [
            'userid',
            'answerid',
            'purpose',
            'itemid',
            'durationms',
            'bytes',
            'language',
            'transcript',
            'transcriptstate',
            'transcriptcode',
            'audiodeleted',
            'timecreated',
            'timemodified',
        ]);

        // F7 workshop submissions and peer ratings are participant data and
        // therefore only travel with userinfo-enabled backups.
        $workshops = new backup_nested_element('workshops');
        $workshop = new backup_nested_element('workshop', ['id'], [
            'questionid',
            'rootid',
            'authorid',
            'state',
            'curatorid',
            'curatornote',
            'aicheckjson',
            'timesubmitted',
            'timedecided',
            'timemodified',
        ]);
        $ratings = new backup_nested_element('ratings');
        $rating = new backup_nested_element('rating', ['id'], [
            'userid',
            'quality',
            'difficulty',
            'comment',
            'timecreated',
            'timemodified',
        ]);

        // U2 Wiederholungs-Kern. Der SM-2-Lernstand ist Teilnehmerdatum und
        // wird nur mit userinfo gesichert. Er haengt an der Fragen-WURZEL,
        // damit eine Bearbeitung der Frage ihn nicht entwertet.
        $schedules = new backup_nested_element('schedules');
        $schedule = new backup_nested_element('schedule', ['id'], [
            'userid',
            'rootid',
            'easiness',
            'intervaldays',
            'repetitions',
            'lapses',
            'duetime',
            'lastreviewed',
            'lastquality',
            'timecreated',
            'timemodified',
        ]);

        $rewards = new backup_nested_element('rewards');
        $reward = new backup_nested_element('reward', ['id'], [
            'userid',
            'rewardkey',
            'rewardtype',
            'metadatajson',
            'timecreated',
            'timemodified',
        ]);

        $quizgeist->add_child($imports);
        $imports->add_child($import);
        $quizgeist->add_child($questions);
        $questions->add_child($question);
        $quizgeist->add_child($curriculumrefs);
        $curriculumrefs->add_child($curriculumref);
        $quizgeist->add_child($cardsets);
        $cardsets->add_child($cardset);
        $cardset->add_child($cards);
        $cards->add_child($card);

        // settingsjson is intentionally the sole runtime source of team
        // configuration. This backup-only helper makes every embedded Moodle
        // group ID visible to Core's group annotation and restore machinery.
        $quizgeist->add_child($sessionteamgroups);
        $sessionteamgroups->add_child($sessionteamgroup);

        $quizgeist->add_child($sessions);
        $sessions->add_child($session);
        $session->add_child($sessionquestions);
        $sessionquestions->add_child($sessionquestion);
        $session->add_child($players);
        $players->add_child($player);
        $player->add_child($liveanswers);
        $liveanswers->add_child($liveanswer);
        $session->add_child($sessioninteractions);
        $sessioninteractions->add_child($sessioninteraction);

        $quizgeist->add_child($assignments);
        $assignments->add_child($assignment);
        $assignment->add_child($assignmentquestions);
        $assignmentquestions->add_child($assignmentquestion);
        $assignment->add_child($attempts);
        $attempts->add_child($attempt);
        $attempt->add_child($attemptquestions);
        $attemptquestions->add_child($attemptquestion);
        $attempt->add_child($soloanswers);
        $soloanswers->add_child($soloanswer);
        $assignment->add_child($reminders);
        $reminders->add_child($reminder);

        $quizgeist->add_child($goals);
        $goals->add_child($goal);

        $quizgeist->add_child($clips);
        $clips->add_child($clip);

        $quizgeist->add_child($stagereports);
        $stagereports->add_child($stagereport);

        $quizgeist->add_child($tags);
        $tags->add_child($tag);
        $quizgeist->add_child($questiontags);
        $questiontags->add_child($questiontag);

        $quizgeist->add_child($misconceptions);
        $misconceptions->add_child($misconception);

        $quizgeist->add_child($workshops);
        $workshops->add_child($workshop);
        $workshop->add_child($ratings);
        $ratings->add_child($rating);

        $quizgeist->add_child($schedules);
        $schedules->add_child($schedule);

        $quizgeist->add_child($rewards);
        $rewards->add_child($reward);

        $quizgeist->set_source_table('quizgeist', ['id' => backup::VAR_ACTIVITYID]);
        $import->set_source_table(
            'quizgeist_imports',
            ['quizgeistid' => backup::VAR_PARENTID],
            'id ASC'
        );
        $question->set_source_table(
            'quizgeist_questions',
            ['quizgeistid' => backup::VAR_PARENTID],
            'sortorder ASC, id ASC'
        );
        $assignment->set_source_table(
            'quizgeist_assignments',
            ['quizgeistid' => backup::VAR_PARENTID],
            'id ASC'
        );
        $assignmentquestion->set_source_table(
            'quizgeist_assignment_questions',
            ['assignmentid' => backup::VAR_PARENTID],
            'sortindex ASC, id ASC'
        );
        // Every tag this activity can reach: its own, its course, the site.
        // The restore merges them by (scope, scopeid, kind, tagkey).
        $tag->set_source_sql(
            "SELECT t.*
               FROM {quizgeist_tags} t
              WHERE (t.scope = 'activity' AND t.scopeid = ?)
                 OR (t.scope = 'course' AND t.scopeid = ?)
                 OR (t.scope = 'site' AND t.scopeid = 0)
           ORDER BY t.id ASC",
            [backup::VAR_PARENTID, backup::VAR_COURSEID]
        );
        $questiontag->set_source_table(
            'quizgeist_question_tags',
            ['quizgeistid' => backup::VAR_PARENTID],
            'id ASC'
        );
        $misconception->set_source_table(
            'quizgeist_misconceptions',
            ['quizgeistid' => backup::VAR_PARENTID],
            'id ASC'
        );
        $curriculumref->set_source_table(
            'quizgeist_curriculum_refs',
            ['quizgeistid' => backup::VAR_PARENTID],
            'id ASC'
        );
        $cardset->set_source_table(
            'quizgeist_cardsets',
            ['quizgeistid' => backup::VAR_PARENTID],
            'id ASC'
        );

        if ($userinfo) {
            $card->set_source_table(
                'quizgeist_cards',
                ['cardsetid' => backup::VAR_PARENTID],
                'cardindex ASC, id ASC'
            );
            $clip->set_source_table(
                'quizgeist_clips',
                ['quizgeistid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $sessionteamgroup->set_source_array(
                $this->configured_team_group_annotations()
            );
            $session->set_source_table(
                'quizgeist_sessions',
                ['quizgeistid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $sessionquestion->set_source_table(
                'quizgeist_session_questions',
                ['sessionid' => backup::VAR_PARENTID],
                'sortindex ASC, id ASC'
            );
            $player->set_source_table(
                'quizgeist_players',
                ['sessionid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $liveanswer->set_source_table(
                'quizgeist_answers',
                ['playerid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $sessioninteraction->set_source_sql(
                'SELECT *
                   FROM {quizgeist_answers}
                  WHERE sessionid = ?
                    AND playerid IS NULL
                    AND attemptid IS NULL
               ORDER BY id ASC',
                [backup::VAR_PARENTID]
            );
            $attempt->set_source_table(
                'quizgeist_attempts',
                ['assignmentid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $attemptquestion->set_source_table(
                'quizgeist_attempt_questions',
                ['attemptid' => backup::VAR_PARENTID],
                'sortindex ASC, id ASC'
            );
            $soloanswer->set_source_table(
                'quizgeist_answers',
                ['attemptid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $reminder->set_source_table(
                'quizgeist_assignment_reminders',
                ['assignmentid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $goal->set_source_table(
                'quizgeist_goals',
                ['quizgeistid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $reward->set_source_table(
                'quizgeist_rewards',
                ['quizgeistid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $schedule->set_source_table(
                'quizgeist_schedule',
                ['quizgeistid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $workshop->set_source_table(
                'quizgeist_workshop',
                ['quizgeistid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $rating->set_source_table(
                'quizgeist_workshop_ratings',
                ['workshopid' => backup::VAR_PARENTID],
                'id ASC'
            );
            $stagereport->set_source_table(
                'quizgeist_stage_reports',
                ['quizgeistid' => backup::VAR_PARENTID],
                'id ASC'
            );
        }

        $quizgeist->annotate_ids('scale', 'grade');
        // The assignment hangs on the question ROOT, never on a version ID.
        $questiontag->annotate_ids('quizgeist_question', 'rootid');
        $questiontag->annotate_ids('quizgeist_tag', 'tagid');
        $questiontag->annotate_ids('user', 'createdby');
        $question->annotate_ids('quizgeist_import', 'importid');
        $question->annotate_ids('quizgeist_question', 'rootid');
        $question->annotate_ids('user', 'createdby');
        $session->annotate_ids('user', 'hostuserid');
        $session->annotate_ids('quizgeist_question', 'currentquestionid');
        $sessionteamgroup->annotate_ids('group', 'groupid');
        $sessionquestion->annotate_ids('quizgeist_question', 'questionid');
        $player->annotate_ids('user', 'userid');
        $player->annotate_ids('group', 'groupid');
        $liveanswer->annotate_ids('quizgeist_question', 'questionid');
        $liveanswer->annotate_ids('user', 'userid');
        $sessioninteraction->annotate_ids('quizgeist_question', 'questionid');
        $sessioninteraction->annotate_ids('user', 'userid');
        $assignment->annotate_ids('user', 'createdby');
        $assignmentquestion->annotate_ids('quizgeist_question', 'questionid');
        $assignmentquestion->annotate_ids('quizgeist_question', 'rootid');
        $attempt->annotate_ids('user', 'userid');
        $attemptquestion->annotate_ids('quizgeist_question', 'questionid');
        $soloanswer->annotate_ids('quizgeist_question', 'questionid');
        $soloanswer->annotate_ids('user', 'userid');
        $reminder->annotate_ids('user', 'userid');
        $goal->annotate_ids('user', 'userid');
        $reward->annotate_ids('user', 'userid');
        $schedule->annotate_ids('user', 'userid');
        // Wie beim Tagging: die Bindung ist die WURZEL, nie eine Versions-ID.
        $schedule->annotate_ids('quizgeist_question', 'rootid');
        $misconception->annotate_ids('quizgeist_question', 'rootid');
        $curriculumref->annotate_ids('quizgeist_question', 'rootid');
        $cardset->annotate_ids('user', 'createdby');
        $workshop->annotate_ids('quizgeist_question', 'questionid');
        $workshop->annotate_ids('quizgeist_question', 'rootid');
        $workshop->annotate_ids('user', 'authorid');
        $workshop->annotate_ids('user', 'curatorid');
        $rating->annotate_ids('user', 'userid');
        if ($userinfo) {
            $card->annotate_ids('user', 'userid');
            $clip->annotate_ids('user', 'userid');
            $clip->annotate_ids('quizgeist_answer', 'answerid');
            $stagereport->annotate_ids('user', 'userid');
            $stagereport->annotate_ids('quizgeist_question', 'questionid');
            $stagereport->annotate_ids('quizgeist_answer', 'answerid');
        }

        $quizgeist->annotate_files('mod_quizgeist', 'intro', null);
        $quizgeist->annotate_files('mod_quizgeist', 'background', null);
        $quizgeist->annotate_files('mod_quizgeist', 'logo', null);
        $import->annotate_files('mod_quizgeist', 'importmedia', 'id');
        $question->annotate_files('mod_quizgeist', 'questionmedia', 'id');
        if ($userinfo) {
            $clip->annotate_files('mod_quizgeist', 'clipaudio', 'id');
            // Bühnen-Checks haben keinen Dateibereich: kein Bild verlässt das Gerät.
        }

        return $this->prepare_activity_structure($quizgeist);
    }

    /**
     * Collect Moodle groups referenced only inside canonical session settings.
     *
     * Player annotations alone are insufficient because a configured team can
     * legitimately have no joined player. Only existing positive groups from
     * this activity's course are handed to Core's group backup.
     *
     * @return \stdClass[] Backup-only rows keyed by a stable synthetic ID.
     */
    private function configured_team_group_annotations(): array {
        global $DB;

        $sessions = $DB->get_records(
            'quizgeist_sessions',
            ['quizgeistid' => (int)$this->task->get_activityid()],
            'id ASC',
            'id,settingsjson'
        );
        $groupids = [];
        foreach ($sessions as $session) {
            try {
                $settings = \mod_quizgeist\local\live\session_settings::decode(
                    $session->settingsjson ?? null
                );
            } catch (\Throwable $exception) {
                continue;
            }
            if (($settings['team']['source'] ?? null) !== 'groups') {
                continue;
            }
            foreach ($settings['team']['teams'] as $team) {
                $groupid = $team['groupId'] ?? null;
                if (is_int($groupid) && $groupid > 0) {
                    $groupids[$groupid] = true;
                }
            }
        }
        if (!$groupids) {
            return [];
        }

        $groups = $DB->get_records_list(
            'groups',
            'id',
            array_keys($groupids),
            'id ASC',
            'id,courseid'
        );
        $courseid = (int)$this->get_courseid();
        $annotations = [];
        foreach ($groups as $group) {
            if ((int)$group->courseid !== $courseid || (int)$group->id <= 0) {
                continue;
            }
            $annotations[] = (object)[
                'id' => (int)$group->id,
                'groupid' => (int)$group->id,
            ];
        }
        return $annotations;
    }
}
