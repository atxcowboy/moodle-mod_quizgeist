<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Privacy provider for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @category   privacy
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\privacy;

use context;
use context_module;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Describe, locate, export and erase personal data held by Quizgeist.
 *
 * Shared teaching content is retained when a user is erased, but its nullable
 * author/host reference is anonymised. Player responses, attempts and rewards
 * belong to the user and are deleted. This avoids deleting other learners'
 * records merely because their teacher account is removed.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe all database fields that can contain personal data.
     *
     * @param collection $collection Metadata collection.
     * @return collection Completed collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('quizgeist_questions', [
            'createdby' => 'privacy:metadata:quizgeist_questions:createdby',
            'questiontext' => 'privacy:metadata:quizgeist_questions',
            'explanation' => 'privacy:metadata:quizgeist_questions',
            'optionsjson' => 'privacy:metadata:quizgeist_questions',
            'timecreated' => 'privacy:metadata:quizgeist_questions',
            'timemodified' => 'privacy:metadata:quizgeist_questions',
        ], 'privacy:metadata:quizgeist_questions');

        $collection->add_database_table('quizgeist_sessions', [
            'hostuserid' => 'privacy:metadata:quizgeist_sessions:hostuserid',
            'joincode' => 'privacy:metadata:quizgeist_sessions',
            'statejson' => 'privacy:metadata:quizgeist_sessions',
            'settingsjson' => 'privacy:metadata:quizgeist_sessions',
            'timestarted' => 'privacy:metadata:quizgeist_sessions',
            'timeended' => 'privacy:metadata:quizgeist_sessions',
            'timecreated' => 'privacy:metadata:quizgeist_sessions',
            'timemodified' => 'privacy:metadata:quizgeist_sessions',
        ], 'privacy:metadata:quizgeist_sessions');

        $collection->add_database_table('quizgeist_players', [
            'userid' => 'privacy:metadata:quizgeist_players:userid',
            'displayname' => 'privacy:metadata:quizgeist_players:displayname',
            'groupid' => 'privacy:metadata:quizgeist_players',
            'teamname' => 'privacy:metadata:quizgeist_players',
            'avatarkey' => 'privacy:metadata:quizgeist_players',
            'score' => 'privacy:metadata:quizgeist_players',
            'streak' => 'privacy:metadata:quizgeist_players',
            'timejoined' => 'privacy:metadata:quizgeist_players',
            'lastseen' => 'privacy:metadata:quizgeist_players',
            'timemodified' => 'privacy:metadata:quizgeist_players',
        ], 'privacy:metadata:quizgeist_players');

        $collection->add_database_table('quizgeist_answers', [
            'userid' => 'privacy:metadata:quizgeist_answers:userid',
            'answerjson' => 'privacy:metadata:quizgeist_answers:answerjson',
            'submissionkey' => 'privacy:metadata:quizgeist_answers',
            'iscorrect' => 'privacy:metadata:quizgeist_answers',
            'points' => 'privacy:metadata:quizgeist_answers',
            'maxpoints' => 'privacy:metadata:quizgeist_answers',
            'responsetime' => 'privacy:metadata:quizgeist_answers',
            'timecreated' => 'privacy:metadata:quizgeist_answers',
        ], 'privacy:metadata:quizgeist_answers');

        $collection->add_database_table('quizgeist_stage_reports', [
            'quizgeistid' => 'privacy:metadata:quizgeist_stage_reports',
            'userid' => 'privacy:metadata:quizgeist_stage_reports',
            'questionid' => 'privacy:metadata:quizgeist_stage_reports',
            'answerid' => 'privacy:metadata:quizgeist_stage_reports',
            'durationsecs' => 'privacy:metadata:quizgeist_stage_reports',
            'metricsjson' => 'privacy:metadata:quizgeist_stage_reports',
            'aiused' => 'privacy:metadata:quizgeist_stage_reports',
            'feedbackjson' => 'privacy:metadata:quizgeist_stage_reports',
            'timecreated' => 'privacy:metadata:quizgeist_stage_reports',
        ], 'privacy:metadata:quizgeist_stage_reports');
        // No external location link: stage metrics have no external recipient.

        $collection->add_database_table('quizgeist_assignments', [
            'createdby' => 'privacy:metadata:quizgeist_assignments:createdby',
            'name' => 'privacy:metadata:quizgeist_assignments',
            'settingsjson' => 'privacy:metadata:quizgeist_assignments',
            'timecreated' => 'privacy:metadata:quizgeist_assignments',
            'timemodified' => 'privacy:metadata:quizgeist_assignments',
        ], 'privacy:metadata:quizgeist_assignments');

        $collection->add_database_table('quizgeist_attempts', [
            'userid' => 'privacy:metadata:quizgeist_attempts:userid',
            'score' => 'privacy:metadata:quizgeist_attempts:statejson',
            'maxscore' => 'privacy:metadata:quizgeist_attempts:statejson',
            'statejson' => 'privacy:metadata:quizgeist_attempts:statejson',
            'flashcardsjson' => 'privacy:metadata:quizgeist_attempts:statejson',
            'timestarted' => 'privacy:metadata:quizgeist_attempts',
            'timefinished' => 'privacy:metadata:quizgeist_attempts',
            'remindedat' => 'privacy:metadata:quizgeist_attempts',
            'timemodified' => 'privacy:metadata:quizgeist_attempts',
        ], 'privacy:metadata:quizgeist_attempts');

        $collection->add_database_table('quizgeist_attempt_questions', [
            'visit' => 'privacy:metadata:quizgeist_attempts:statejson',
            'status' => 'privacy:metadata:quizgeist_attempts:statejson',
            'answerjson' => 'privacy:metadata:quizgeist_answers:answerjson',
            'timestarted' => 'privacy:metadata:quizgeist_attempts',
            'timesubmitted' => 'privacy:metadata:quizgeist_attempts',
            'timemodified' => 'privacy:metadata:quizgeist_attempts',
        ], 'privacy:metadata:quizgeist_attempts');

        $collection->add_database_table('quizgeist_assignment_reminders', [
            'userid' => 'privacy:metadata:quizgeist_attempts:userid',
            'attemptcount' => 'privacy:metadata:quizgeist_attempts',
            'timesent' => 'privacy:metadata:quizgeist_attempts',
        ], 'privacy:metadata:quizgeist_attempts');

        $collection->add_database_table('quizgeist_goals', [
            'userid' => 'privacy:metadata:quizgeist_attempts:userid',
            'target' => 'privacy:metadata:quizgeist_attempts:statejson',
            'timecreated' => 'privacy:metadata:quizgeist_attempts',
            'timemodified' => 'privacy:metadata:quizgeist_attempts',
        ], 'privacy:metadata:quizgeist_attempts');

        $collection->add_database_table('quizgeist_templates', [
            'createdby' => 'privacy:metadata:quizgeist_templates:createdby',
            'name' => 'privacy:metadata:quizgeist_templates',
            'description' => 'privacy:metadata:quizgeist_templates',
            'contentjson' => 'privacy:metadata:quizgeist_templates',
            'tags' => 'privacy:metadata:quizgeist_templates',
            'timecreated' => 'privacy:metadata:quizgeist_templates',
            'timemodified' => 'privacy:metadata:quizgeist_templates',
        ], 'privacy:metadata:quizgeist_templates');

        $collection->add_database_table('quizgeist_cardsets', [
            'createdby' => 'privacy:metadata:quizgeist_cardsets',
        ], 'privacy:metadata:quizgeist_cardsets');

        $collection->add_database_table('quizgeist_cards', [
            'cardsetid' => 'privacy:metadata:quizgeist_cards',
            'userid' => 'privacy:metadata:quizgeist_cards',
            'cardcode' => 'privacy:metadata:quizgeist_cards',
            'timecreated' => 'privacy:metadata:quizgeist_cards',
        ], 'privacy:metadata:quizgeist_cards');

        $collection->add_database_table('quizgeist_card_scans', [
            'scannedby' => 'privacy:metadata:quizgeist_card_scans',
            'state' => 'privacy:metadata:quizgeist_card_scans',
            'recognised' => 'privacy:metadata:quizgeist_card_scans',
            'expected' => 'privacy:metadata:quizgeist_card_scans',
            'imagedeleted' => 'privacy:metadata:quizgeist_card_scans',
            'timecreated' => 'privacy:metadata:quizgeist_card_scans',
        ], 'privacy:metadata:quizgeist_card_scans');

        // U1 Tagging-Kern. quizgeist_tags itself is course vocabulary and has
        // no user column; only the assignment records who made it
        // (P11_PLAN.md, decision E-4: learners may suggest in the workshop).
        $collection->add_database_table('quizgeist_question_tags', [
            'createdby' => 'privacy:metadata:quizgeist_question_tags:createdby',
            'rootid' => 'privacy:metadata:quizgeist_question_tags',
            'tagid' => 'privacy:metadata:quizgeist_question_tags',
            'weight' => 'privacy:metadata:quizgeist_question_tags',
            'status' => 'privacy:metadata:quizgeist_question_tags',
            'timecreated' => 'privacy:metadata:quizgeist_question_tags',
        ], 'privacy:metadata:quizgeist_question_tags');

        // U2 Wiederholungs-Kern (SM-2). Die Tabelle gehoert der Basis, damit
        // Lernstaende auch ohne selfstudy-Addon exportierbar und loeschbar
        // bleiben (ARCHITECTURE.md, "Bewusst gemeinsam im Basisplugin").
        $collection->add_database_table('quizgeist_schedule', [
            'userid' => 'privacy:metadata:quizgeist_schedule:userid',
            'rootid' => 'privacy:metadata:quizgeist_schedule',
            'easiness' => 'privacy:metadata:quizgeist_schedule',
            'intervaldays' => 'privacy:metadata:quizgeist_schedule',
            'repetitions' => 'privacy:metadata:quizgeist_schedule',
            'lapses' => 'privacy:metadata:quizgeist_schedule',
            'duetime' => 'privacy:metadata:quizgeist_schedule',
            'lastreviewed' => 'privacy:metadata:quizgeist_schedule',
            'lastquality' => 'privacy:metadata:quizgeist_schedule',
            'timecreated' => 'privacy:metadata:quizgeist_schedule',
            'timemodified' => 'privacy:metadata:quizgeist_schedule',
        ], 'privacy:metadata:quizgeist_schedule');

        $collection->add_database_table('quizgeist_rewards', [
            'userid' => 'privacy:metadata:quizgeist_rewards:userid',
            'rewardkey' => 'privacy:metadata:quizgeist_rewards',
            'rewardtype' => 'privacy:metadata:quizgeist_rewards',
            'metadatajson' => 'privacy:metadata:quizgeist_rewards',
            'timecreated' => 'privacy:metadata:quizgeist_rewards',
            'timemodified' => 'privacy:metadata:quizgeist_rewards',
        ], 'privacy:metadata:quizgeist_rewards');

        $collection->add_database_table('quizgeist_workshop', [
            'questionid' => 'privacy:metadata:quizgeist_questions',
            'rootid' => 'privacy:metadata:quizgeist_questions',
            'authorid' => 'privacy:metadata:quizgeist_questions:createdby',
            'state' => 'privacy:metadata:quizgeist_questions',
            'curatorid' => 'privacy:metadata:quizgeist_questions:createdby',
            'curatornote' => 'privacy:metadata:quizgeist_questions',
            'aicheckjson' => 'privacy:metadata:quizgeist_questions',
            'timesubmitted' => 'privacy:metadata:quizgeist_questions',
            'timedecided' => 'privacy:metadata:quizgeist_questions',
            'timemodified' => 'privacy:metadata:quizgeist_questions',
        ], 'privacy:metadata:quizgeist_questions');

        $collection->add_database_table('quizgeist_workshop_ratings', [
            'workshopid' => 'privacy:metadata:quizgeist_answers',
            'userid' => 'privacy:metadata:quizgeist_answers:userid',
            'quality' => 'privacy:metadata:quizgeist_answers',
            'difficulty' => 'privacy:metadata:quizgeist_answers',
            'comment' => 'privacy:metadata:quizgeist_answers',
            'timecreated' => 'privacy:metadata:quizgeist_answers',
            'timemodified' => 'privacy:metadata:quizgeist_answers',
        ], 'privacy:metadata:quizgeist_answers');

        $collection->add_database_table('quizgeist_clips', [
            'userid' => 'privacy:metadata:clips:userid',
            'purpose' => 'privacy:metadata:clips:purpose',
            'durationms' => 'privacy:metadata:clips:durationms',
            'language' => 'privacy:metadata:clips:language',
            'transcript' => 'privacy:metadata:clips:transcript',
            'transcriptstate' => 'privacy:metadata:clips:transcriptstate',
            'timecreated' => 'privacy:metadata:clips:timecreated',
        ], 'privacy:metadata:clips');

        $collection->add_external_location_link(
            'gpuq',
            [
                'audio' => 'privacy:metadata:gpuq:audio',
                'language' => 'privacy:metadata:gpuq:language',
                'transcript' => 'privacy:metadata:gpuq:transcript',
            ],
            'privacy:metadata:gpuq'
        );

        // quizgeist_misconceptions und quizgeist_curriculum_refs sind geteilte
        // Lehrinhalte ohne personenbezogene Spalten; deshalb gibt es für sie
        // keinen Metadateneintrag, und sie bleiben beim Kursreset erhalten.

        return $collection;
    }

    /**
     * Find module contexts and the site-template system context containing
     * data about a user.
     *
     * @param int $userid User ID.
     * @return contextlist Matching contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $params = [
            'userid' => $userid,
            'modulename' => 'quizgeist',
            'contextlevel' => CONTEXT_MODULE,
        ];

        $queries = [
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_questions} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.createdby = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_sessions} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.hostuserid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_players} d
               JOIN {quizgeist_sessions} s ON s.id = d.sessionid
               JOIN {course_modules} cm ON cm.instance = s.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_answers} d
               JOIN {quizgeist_questions} q ON q.id = d.questionid
               JOIN {course_modules} cm ON cm.instance = q.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_stage_reports} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_clips} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_assignments} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.createdby = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_question_tags} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.createdby = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_attempts} d
               JOIN {quizgeist_assignments} a ON a.id = d.assignmentid
               JOIN {course_modules} cm ON cm.instance = a.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_assignment_reminders} d
               JOIN {quizgeist_assignments} a ON a.id = d.assignmentid
               JOIN {course_modules} cm ON cm.instance = a.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_goals} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_rewards} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_schedule} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_workshop} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.authorid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_workshop} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.curatorid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_workshop_ratings} d
               JOIN {quizgeist_workshop} w ON w.id = d.workshopid
               JOIN {course_modules} cm ON cm.instance = w.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_cardsets} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.createdby = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_cards} d
               JOIN {quizgeist_cardsets} cs ON cs.id = d.cardsetid
               JOIN {course_modules} cm ON cm.instance = cs.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.userid = :userid",
            "SELECT DISTINCT ctx.id
               FROM {quizgeist_card_scans} d
               JOIN {course_modules} cm ON cm.instance = d.quizgeistid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
               JOIN {context} ctx ON ctx.instanceid = cm.id
                                  AND ctx.contextlevel = :contextlevel
              WHERE d.scannedby = :userid",
        ];

        foreach ($queries as $sql) {
            $contextlist->add_from_sql($sql, $params);
        }

        $hassitedata =
            $DB->record_exists('quizgeist_templates', ['createdby' => $userid])
            || $DB->record_exists_select(
                'quizgeist_rewards',
                'quizgeistid IS NULL AND userid = :userid',
                ['userid' => $userid]
            );
        if ($hassitedata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Add every user referenced by personal-data records in one context.
     *
     * @param userlist $userlist Context user list.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof context_system) {
            $userlist->add_from_sql(
                'userid',
                'SELECT createdby AS userid
                   FROM {quizgeist_templates}
                  WHERE createdby IS NOT NULL',
                []
            );
            $userlist->add_from_sql(
                'userid',
                'SELECT userid
                   FROM {quizgeist_rewards}
                  WHERE quizgeistid IS NULL',
                []
            );
            return;
        }

        $quizgeistid = self::get_quizgeist_id_for_context($context);
        if ($quizgeistid === null) {
            return;
        }

        $directqueries = [
            ['createdby', 'quizgeist_questions', 'createdby'],
            ['hostuserid', 'quizgeist_sessions', 'hostuserid'],
            ['createdby', 'quizgeist_assignments', 'createdby'],
            ['createdby', 'quizgeist_question_tags', 'createdby'],
            ['userid', 'quizgeist_stage_reports', 'userid'],
            ['userid', 'quizgeist_clips', 'userid'],
            ['userid', 'quizgeist_rewards', 'userid'],
            ['userid', 'quizgeist_goals', 'userid'],
            ['userid', 'quizgeist_schedule', 'userid'],
            ['authorid', 'quizgeist_workshop', 'authorid'],
            ['curatorid', 'quizgeist_workshop', 'curatorid'],
            ['createdby', 'quizgeist_cardsets', 'createdby'],
        ];
        foreach ($directqueries as [$field, $table, $column]) {
            $userlist->add_from_sql(
                $field,
                "SELECT {$column} AS {$field}
                   FROM {{$table}}
                  WHERE quizgeistid = :quizgeistid
                    AND {$column} IS NOT NULL",
                ['quizgeistid' => $quizgeistid]
            );
        }

        $userlist->add_from_sql(
            'userid',
            'SELECT p.userid
               FROM {quizgeist_players} p
               JOIN {quizgeist_sessions} s ON s.id = p.sessionid
              WHERE s.quizgeistid = :quizgeistid',
            ['quizgeistid' => $quizgeistid]
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT a.userid
               FROM {quizgeist_answers} a
               JOIN {quizgeist_questions} q ON q.id = a.questionid
              WHERE q.quizgeistid = :quizgeistid',
            ['quizgeistid' => $quizgeistid]
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT r.userid
               FROM {quizgeist_assignment_reminders} r
               JOIN {quizgeist_assignments} a ON a.id = r.assignmentid
              WHERE a.quizgeistid = :quizgeistid',
            ['quizgeistid' => $quizgeistid]
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT a.userid
               FROM {quizgeist_attempts} a
               JOIN {quizgeist_assignments} sa ON sa.id = a.assignmentid
              WHERE sa.quizgeistid = :quizgeistid',
            ['quizgeistid' => $quizgeistid]
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT r.userid
               FROM {quizgeist_workshop_ratings} r
               JOIN {quizgeist_workshop} w ON w.id = r.workshopid
              WHERE w.quizgeistid = :quizgeistid
                AND r.userid IS NOT NULL',
            ['quizgeistid' => $quizgeistid]
        );
        $userlist->add_from_sql(
            'userid',
            'SELECT c.userid
               FROM {quizgeist_cards} c
               JOIN {quizgeist_cardsets} cs ON cs.id = c.cardsetid
              WHERE cs.quizgeistid = :cardquizgeist
                AND c.userid IS NOT NULL',
            ['cardquizgeist' => $quizgeistid]
        );
        $userlist->add_from_sql(
            'scannedby',
            'SELECT s.scannedby
               FROM {quizgeist_card_scans} s
              WHERE s.quizgeistid = :scanquizgeist
                AND s.scannedby IS NOT NULL',
            ['scanquizgeist' => $quizgeistid]
        );
    }

    /**
     * Export all personal data for a user in approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        if ($contextlist->count() === 0) {
            return;
        }

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_system) {
                self::export_site_data($context, (int) $user->id);
                continue;
            }

            $quizgeistid = self::get_quizgeist_id_for_context($context);
            if ($quizgeistid === null) {
                continue;
            }
            writer::with_context($context)->export_data(
                [],
                helper::get_context_data($context, $user)
            );
            helper::export_context_files($context, $user);
            self::export_activity_data($context, $quizgeistid, (int) $user->id);
        }
    }

    /**
     * Erase all personal data in a context while retaining shared content.
     *
     * @param context $context Context to clear.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if ($context instanceof context_system) {
            $DB->delete_records_select('quizgeist_rewards', 'quizgeistid IS NULL');
            \cache::make('mod_quizgeist', 'liveprojection')->purge();
            $DB->execute(
                'UPDATE {quizgeist_templates}
                    SET createdby = NULL'
            );
            return;
        }

        $quizgeistid = self::get_quizgeist_id_for_context($context);
        if ($quizgeistid === null) {
            return;
        }

        require_once(__DIR__ . '/../../lib.php');
        $quizgeist = $DB->get_record('quizgeist', ['id' => $quizgeistid]);
        // Buehnen-Berichte loescht quizgeist_purge_instance_userdata() mit —
        // dieselbe Stelle, die auch Aktivitaetsloeschung und Kursreset nutzen.
        \quizgeist_purge_instance_userdata($quizgeistid);
        if ($quizgeist) {
            \quizgeist_grade_item_update($quizgeist, 'reset');
        }
        // Unlike normal activity deletion/reset, Privacy erasure must remove
        // the persistent personal unlocks rather than detach their source.
        $DB->delete_records('quizgeist_rewards', ['quizgeistid' => $quizgeistid]);
        \cache::make('mod_quizgeist', 'liveprojection')->purge();

        // U1/E-4: learner tag suggestions are personal content and go; the
        // approved course tagging survives with released authorship.
        $DB->delete_records('quizgeist_question_tags', [
            'quizgeistid' => $quizgeistid,
            'status' => 'suggested',
        ]);

        $DB->delete_records_select(
            'quizgeist_workshop_ratings',
            'workshopid IN (
                SELECT id
                  FROM {quizgeist_workshop}
                 WHERE quizgeistid = :workshopquiz
            )',
            ['workshopquiz' => $quizgeistid]
        );

        self::anonymise_all_content_authors($quizgeistid);
    }

    /**
     * Erase one user's data in all approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts and user.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if ($contextlist->count() === 0) {
            return;
        }

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_system) {
                self::delete_site_user_data([$userid]);
                continue;
            }
            $quizgeistid = self::get_quizgeist_id_for_context($context);
            if ($quizgeistid !== null) {
                self::delete_activity_user_data($quizgeistid, [$userid]);
            }
        }
    }

    /**
     * Erase an approved list of users in one context.
     *
     * @param approved_userlist $userlist Approved user list.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $userids = array_map('intval', $userlist->get_userids());
        if (empty($userids)) {
            return;
        }

        $context = $userlist->get_context();
        if ($context instanceof context_system) {
            self::delete_site_user_data($userids);
            return;
        }

        $quizgeistid = self::get_quizgeist_id_for_context($context);
        if ($quizgeistid !== null) {
            self::delete_activity_user_data($quizgeistid, $userids);
        }
    }

    /**
     * Export module-context records belonging to one user.
     *
     * @param context $context Module context.
     * @param int $quizgeistid Activity ID.
     * @param int $userid User ID.
     * @return void
     */
    private static function export_activity_data(context $context, int $quizgeistid, int $userid): void {
        global $DB;

        $questions = $DB->get_records('quizgeist_questions', [
            'quizgeistid' => $quizgeistid,
            'createdby' => $userid,
        ], 'sortorder ASC, id ASC');

        $data = (object) [
            'authored_questions' => self::normalise_records(
                $questions,
                ['optionsjson'],
                ['timecreated', 'timemodified']
            ),
            'hosted_sessions' => self::normalise_records(
                $DB->get_records('quizgeist_sessions', [
                    'quizgeistid' => $quizgeistid,
                    'hostuserid' => $userid,
                ], 'id ASC'),
                ['statejson', 'settingsjson'],
                ['timestarted', 'timeended', 'timecreated', 'timemodified']
            ),
            'live_player_records' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT p.*
                       FROM {quizgeist_players} p
                       JOIN {quizgeist_sessions} s ON s.id = p.sessionid
                      WHERE s.quizgeistid = :quizgeistid
                        AND p.userid = :userid
                   ORDER BY p.id ASC',
                    ['quizgeistid' => $quizgeistid, 'userid' => $userid]
                ),
                [],
                ['timejoined', 'lastseen', 'timemodified']
            ),
            'answers' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT a.*
                       FROM {quizgeist_answers} a
                       JOIN {quizgeist_questions} q ON q.id = a.questionid
                      WHERE q.quizgeistid = :quizgeistid
                        AND a.userid = :userid
                   ORDER BY a.id ASC',
                    ['quizgeistid' => $quizgeistid, 'userid' => $userid]
                ),
                ['answerjson'],
                ['timecreated']
            ),
            'stage_reports' => self::normalise_records(
                $DB->get_records('quizgeist_stage_reports', [
                    'quizgeistid' => $quizgeistid,
                    'userid' => $userid,
                ], 'id ASC'),
                ['metricsjson', 'feedbackjson'],
                ['timecreated']
            ),
            'cardsets' => self::normalise_records(
                $DB->get_records('quizgeist_cardsets', [
                    'quizgeistid' => $quizgeistid,
                    'createdby' => $userid,
                ], 'id ASC'),
                [],
                ['timecreated', 'timemodified']
            ),
            'cards' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT c.id, c.cardsetid, c.userid, c.cardcode, c.timecreated,
                            cs.name AS cardsetname
                       FROM {quizgeist_cards} c
                       JOIN {quizgeist_cardsets} cs ON cs.id = c.cardsetid
                      WHERE cs.quizgeistid = :cardquizgeist
                        AND c.userid = :carduserid
                   ORDER BY c.id ASC',
                    ['cardquizgeist' => $quizgeistid, 'carduserid' => $userid]
                ),
                [],
                ['timecreated']
            ),
            // Card-scan images are deliberately not exported: only the
            // bookkeeping survives long enough to be useful to the user.
            'card_scans' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT s.id, s.scannedby, s.state, s.recognised, s.expected,
                            s.imagedeleted, s.timecreated,
                            cs.name AS cardsetname
                       FROM {quizgeist_card_scans} s
                  LEFT JOIN {quizgeist_cardsets} cs ON cs.id = s.cardsetid
                      WHERE s.quizgeistid = :scanquizgeist
                        AND s.scannedby = :scannedby
                   ORDER BY s.id ASC',
                    ['scanquizgeist' => $quizgeistid, 'scannedby' => $userid]
                ),
                [],
                ['imagedeleted', 'timecreated']
            ),
            'clips' => self::normalise_records(
                $DB->get_records_select(
                    'quizgeist_clips',
                    'quizgeistid = :clipquizgeist AND userid = :clipuserid',
                    ['clipquizgeist' => $quizgeistid, 'clipuserid' => $userid],
                    'id ASC',
                    'id,quizgeistid,userid,purpose,durationms,language,transcript,' .
                    'transcriptstate,timecreated,itemid'
                ),
                [],
                ['timecreated']
            ),
            // U1 Tagging-Kern: which question roots this user tagged or
            // suggested a tag for.
            'tag_assignments' => self::normalise_records(
                $DB->get_records('quizgeist_question_tags', [
                    'quizgeistid' => $quizgeistid,
                    'createdby' => $userid,
                ], 'id ASC'),
                [],
                ['timecreated']
            ),
            'authored_assignments' => self::normalise_records(
                $DB->get_records('quizgeist_assignments', [
                    'quizgeistid' => $quizgeistid,
                    'createdby' => $userid,
                ], 'id ASC'),
                ['settingsjson'],
                ['timeopen', 'timedue', 'timecreated', 'timemodified']
            ),
            'attempts' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT a.*
                       FROM {quizgeist_attempts} a
                       JOIN {quizgeist_assignments} sa ON sa.id = a.assignmentid
                      WHERE sa.quizgeistid = :quizgeistid
                        AND a.userid = :userid
                   ORDER BY a.id ASC',
                    ['quizgeistid' => $quizgeistid, 'userid' => $userid]
                ),
                ['statejson', 'flashcardsjson'],
                ['timestarted', 'timefinished', 'remindedat', 'timemodified']
            ),
            'attempt_questions' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT aq.*
                       FROM {quizgeist_attempt_questions} aq
                       JOIN {quizgeist_attempts} a ON a.id = aq.attemptid
                       JOIN {quizgeist_assignments} sa ON sa.id = a.assignmentid
                      WHERE sa.quizgeistid = :quizgeistid
                        AND a.userid = :userid
                   ORDER BY aq.attemptid, aq.sortindex, aq.id',
                    ['quizgeistid' => $quizgeistid, 'userid' => $userid]
                ),
                ['answerjson'],
                ['timestarted', 'timesubmitted', 'timemodified']
            ),
            'deadline_reminders' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT r.*
                       FROM {quizgeist_assignment_reminders} r
                       JOIN {quizgeist_assignments} a ON a.id = r.assignmentid
                      WHERE a.quizgeistid = :quizgeistid
                        AND r.userid = :userid
                   ORDER BY r.id',
                    ['quizgeistid' => $quizgeistid, 'userid' => $userid]
                ),
                [],
                ['timesent']
            ),
            'weekly_goal' => self::normalise_records(
                $DB->get_records('quizgeist_goals', [
                    'quizgeistid' => $quizgeistid,
                    'userid' => $userid,
                ], 'id ASC'),
                [],
                ['timecreated', 'timemodified']
            ),
            'rewards' => self::normalise_records(
                $DB->get_records('quizgeist_rewards', [
                    'quizgeistid' => $quizgeistid,
                    'userid' => $userid,
                ], 'id ASC'),
                ['metadatajson'],
                ['timecreated', 'timemodified']
            ),
            // U2 Wiederholungs-Kern: the learner's own repetition rhythm per
            // question root, exported in full including the next due date.
            'repetition_schedule' => self::normalise_records(
                $DB->get_records('quizgeist_schedule', [
                    'quizgeistid' => $quizgeistid,
                    'userid' => $userid,
                ], 'id ASC'),
                [],
                ['duetime', 'lastreviewed', 'timecreated', 'timemodified']
            ),
            'workshop_submissions' => self::normalise_records(
                $DB->get_records('quizgeist_workshop', [
                    'quizgeistid' => $quizgeistid,
                    'authorid' => $userid,
                ], 'id ASC'),
                ['aicheckjson'],
                ['timesubmitted', 'timedecided', 'timemodified']
            ),
            'workshop_curations' => self::normalise_records(
                $DB->get_records_select(
                    'quizgeist_workshop',
                    'quizgeistid = :quizgeistid AND curatorid = :curatorid',
                    ['quizgeistid' => $quizgeistid, 'curatorid' => $userid],
                    'id ASC',
                    'id,quizgeistid,questionid,rootid,state,curatorid,curatornote,' .
                    'timesubmitted,timedecided,timemodified'
                ),
                [],
                ['timesubmitted', 'timedecided', 'timemodified']
            ),
            'workshop_ratings' => self::normalise_records(
                $DB->get_records_sql(
                    'SELECT r.*
                       FROM {quizgeist_workshop_ratings} r
                       JOIN {quizgeist_workshop} w ON w.id = r.workshopid
                      WHERE w.quizgeistid = :quizgeistid
                        AND r.userid = :userid
                   ORDER BY r.id ASC',
                    ['quizgeistid' => $quizgeistid, 'userid' => $userid]
                ),
                [],
                ['timecreated', 'timemodified']
            ),
        ];

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'mod_quizgeist')],
            $data
        );

        foreach ($questions as $question) {
            writer::with_context($context)->export_area_files(
                [
                    get_string('pluginname', 'mod_quizgeist'),
                    'question_' . $question->id,
                ],
                'mod_quizgeist',
                'questionmedia',
                $question->id
            );
        }
        $clips = $DB->get_records_select(
            'quizgeist_clips',
            'quizgeistid = :clipquizgeist AND userid = :clipuserid',
            ['clipquizgeist' => $quizgeistid, 'clipuserid' => $userid],
            'id ASC',
            'id,itemid'
        );
        foreach ($clips as $clip) {
            writer::with_context($context)->export_area_files(
                [
                    get_string('pluginname', 'mod_quizgeist'),
                    'clip_' . $clip->id,
                ],
                'mod_quizgeist',
                'clipaudio',
                $clip->itemid
            );
        }
    }

    /**
     * Export school-library records and source-less rewards in system context.
     *
     * @param context_system $context System context.
     * @param int $userid User ID.
     * @return void
     */
    private static function export_site_data(context_system $context, int $userid): void {
        global $DB;

        $templates = $DB->get_records(
            'quizgeist_templates',
            ['createdby' => $userid],
            'id ASC'
        );
        $data = (object) [
            'site_templates' => self::normalise_records(
                $templates,
                ['contentjson', 'tags'],
                ['timecreated', 'timemodified']
            ),
            'site_rewards' => self::normalise_records(
                $DB->get_records_select(
                    'quizgeist_rewards',
                    'quizgeistid IS NULL AND userid = :userid',
                    ['userid' => $userid],
                    'id ASC'
                ),
                ['metadatajson'],
                ['timecreated', 'timemodified']
            ),
        ];
        writer::with_context($context)->export_data(
            [get_string('pluginname', 'mod_quizgeist')],
            $data
        );
        foreach ($templates as $template) {
            writer::with_context($context)->export_area_files(
                [
                    get_string('pluginname', 'mod_quizgeist'),
                    'template_' . $template->id,
                ],
                'mod_quizgeist',
                'templatemedia',
                $template->id
            );
        }
    }

    /**
     * Convert JSON and Unix timestamps into readable export values.
     *
     * @param array $records Records keyed by ID.
     * @param array $jsonfields JSON field names.
     * @param array $timefields Timestamp field names.
     * @return array Export-ready records.
     */
    private static function normalise_records(array $records, array $jsonfields, array $timefields): array {
        $export = [];
        foreach ($records as $record) {
            $item = clone $record;
            foreach ($jsonfields as $field) {
                if (!property_exists($item, $field) || $item->{$field} === null || $item->{$field} === '') {
                    continue;
                }
                $decoded = json_decode($item->{$field}, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $item->{$field} = $decoded;
                }
            }
            foreach ($timefields as $field) {
                if (property_exists($item, $field)) {
                    $item->{$field} = empty($item->{$field})
                        ? null
                        : transform::datetime((int) $item->{$field});
                }
            }
            $export[] = $item;
        }
        return $export;
    }

    /**
     * Delete personal records for selected users in one activity.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[] $userids User IDs.
     * @return void
     */
    private static function delete_activity_user_data(int $quizgeistid, array $userids): void {
        global $DB;

        $questionids = array_keys($DB->get_records('quizgeist_questions', ['quizgeistid' => $quizgeistid], '', 'id'));
        $sessionids = array_keys($DB->get_records('quizgeist_sessions', ['quizgeistid' => $quizgeistid], '', 'id'));
        $assignmentids = array_keys(
            $DB->get_records('quizgeist_assignments', ['quizgeistid' => $quizgeistid], '', 'id')
        );
        [$attemptusersql, $attemptuserparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'privacyattemptuser'
        );
        $attemptids = $assignmentids
            ? (function () use (
                $DB,
                $assignmentids,
                $attemptusersql,
                $attemptuserparams
            ): array {
                [$assignmentsql, $assignmentparams] = $DB->get_in_or_equal(
                    $assignmentids,
                    SQL_PARAMS_NAMED,
                    'privacyattemptassignment'
                );
                return $DB->get_fieldset_sql(
                    "SELECT id
                       FROM {quizgeist_attempts}
                      WHERE assignmentid {$assignmentsql}
                        AND userid {$attemptusersql}",
                    $assignmentparams + $attemptuserparams
                );
            })()
            : [];

        // Identify snapshots before player/answer rows are removed. P3's
        // canonical state is PII-free, while legacy/future JSON may contain
        // undeclared fields that must be removed without destroying the
        // immutable played question-ID sequence.
        $affectedsessionids = self::get_affected_session_ids(
            $quizgeistid,
            $questionids,
            $sessionids,
            $userids
        );
        self::clear_session_snapshots($affectedsessionids);

        // Clips reference answers, so remove their files and rows before the
        // answer deletion below. The helper also serves the selected-user
        // path used by the Moodle Privacy API.
        self::delete_by_parent_and_users(
            'quizgeist_clips',
            'quizgeistid',
            [$quizgeistid],
            $userids
        );
        self::delete_by_parent_and_users(
            'quizgeist_card_scans',
            'quizgeistid',
            [$quizgeistid],
            $userids
        );
        self::delete_by_parent_and_users(
            'quizgeist_stage_reports',
            'quizgeistid',
            [$quizgeistid],
            $userids
        );

        // Card ownership is personal, while the printed card and its set are
        // shared course content. Release only the selected holder and creator.
        [$cardusersql, $carduserparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'privacycarduser'
        );
        $DB->execute(
            "UPDATE {quizgeist_cards}
                SET userid = NULL
              WHERE userid {$cardusersql}
                AND cardsetid IN (
                    SELECT id
                      FROM {quizgeist_cardsets}
                     WHERE quizgeistid = :privacycardquiz
                )",
            ['privacycardquiz' => $quizgeistid] + $carduserparams
        );
        $DB->execute(
            "UPDATE {quizgeist_cardsets}
                SET createdby = NULL
              WHERE quizgeistid = :privacycardsetquiz
                AND createdby {$cardusersql}",
            ['privacycardsetquiz' => $quizgeistid] + $carduserparams
        );
        self::delete_personal_answers($questionids, $userids);
        self::delete_by_parent_and_users('quizgeist_players', 'sessionid', $sessionids, $userids);
        if ($attemptids) {
            [$attemptsql, $attemptparams] = $DB->get_in_or_equal(
                array_map('intval', $attemptids),
                SQL_PARAMS_NAMED,
                'privacyattemptquestion'
            );
            $DB->delete_records_select(
                'quizgeist_attempt_questions',
                "attemptid {$attemptsql}",
                $attemptparams
            );
        }
        self::delete_by_parent_and_users('quizgeist_attempts', 'assignmentid', $assignmentids, $userids);
        self::delete_by_parent_and_users(
            'quizgeist_assignment_reminders',
            'assignmentid',
            $assignmentids,
            $userids
        );

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'rewarduser');
        $DB->delete_records_select(
            'quizgeist_goals',
            "quizgeistid = :goalquiz AND userid {$usersql}",
            ['goalquiz' => $quizgeistid] + $userparams
        );
        $DB->delete_records_select(
            'quizgeist_rewards',
            "quizgeistid = :quizgeistid AND userid {$usersql}",
            ['quizgeistid' => $quizgeistid] + $userparams
        );
        // U2: repetition state is personal through and through — there is no
        // course content inside it that could be released instead of erased.
        $DB->delete_records_select(
            'quizgeist_schedule',
            "quizgeistid = :schedulequiz AND userid {$usersql}",
            ['schedulequiz' => $quizgeistid] + $userparams
        );
        \mod_quizgeist\local\live\reward_service::invalidate_catalogues($userids);

        // U1/E-4: a learner's own tag suggestion is personal content and is
        // erased. An already approved assignment is teacher-made course
        // content; anonymise_selected_content_authors() releases its author
        // below without destroying the course's tagging.
        $DB->delete_records_select(
            'quizgeist_question_tags',
            "quizgeistid = :tagquiz AND status = :suggested AND createdby {$usersql}",
            [
                'tagquiz' => $quizgeistid,
                'suggested' => 'suggested',
            ] + $userparams
        );

        [$workshopusersql, $workshopuserparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'workshopratinguser'
        );
        $DB->delete_records_select(
            'quizgeist_workshop_ratings',
            "userid {$workshopusersql}
                AND workshopid IN (
                    SELECT id
                      FROM {quizgeist_workshop}
                     WHERE quizgeistid = :workshopquiz
                )",
            ['workshopquiz' => $quizgeistid] + $workshopuserparams
        );

        self::anonymise_selected_content_authors($quizgeistid, $userids);
        self::invalidate_live_projections($affectedsessionids);
        require_once(__DIR__ . '/../../lib.php');
        $quizgeist = $DB->get_record('quizgeist', ['id' => $quizgeistid]);
        if ($quizgeist) {
            foreach ($userids as $userid) {
                \quizgeist_update_grades($quizgeist, (int)$userid, true);
            }
        }
    }

    /**
     * Delete site-context personal data while retaining shared templates.
     *
     * @param int[] $userids User IDs.
     * @return void
     */
    private static function delete_site_user_data(array $userids): void {
        global $DB;

        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'systemuser');
        $DB->delete_records_select(
            'quizgeist_rewards',
            "quizgeistid IS NULL AND userid {$usersql}",
            $userparams
        );
        \mod_quizgeist\local\live\reward_service::invalidate_catalogues($userids);
        $DB->execute(
            "UPDATE {quizgeist_templates}
                SET createdby = NULL
              WHERE createdby {$usersql}",
            $userparams
        );
    }

    /**
     * Delete player responses but retain anonymised host moderation artefacts.
     *
     * Groupings and rejection decisions are shared teaching/session content:
     * deleting them could re-publish rejected terms in historical reports.
     * The NOT NULL author reference is therefore reassigned to the site admin.
     *
     * @param int[] $questionids Activity question IDs.
     * @param int[] $userids Users whose personal data is being erased.
     */
    private static function delete_personal_answers(
        array $questionids,
        array $userids
    ): void {
        global $DB;

        if (!$questionids || !$userids) {
            return;
        }
        [$questionsql, $questionparams] = $DB->get_in_or_equal(
            $questionids,
            SQL_PARAMS_NAMED,
            'privacyanswerquestion'
        );
        [$usersql, $userparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'privacyansweruser'
        );
        $params = $questionparams + $userparams;
        $DB->delete_records_select(
            'quizgeist_answers',
            "questionid {$questionsql}
                 AND userid {$usersql}
                 AND (
                     attemptid IS NOT NULL
                     OR playerid IS NOT NULL
                     OR sessionid IS NULL
                     OR answertype NOT IN ('group', 'moderation')
                 )",
            $params
        );
        $DB->execute(
            "UPDATE {quizgeist_answers}
                SET userid = :privacyansweradmin
              WHERE questionid {$questionsql}
                AND sessionid IS NOT NULL
                AND playerid IS NULL
                AND attemptid IS NULL
                AND answertype IN ('group', 'moderation')
                AND userid {$usersql}",
            ['privacyansweradmin' => (int)get_admin()->id] + $params
        );
    }

    /**
     * Find sessions whose JSON snapshots can mention any selected user.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[] $questionids Activity question IDs.
     * @param int[] $sessionids Activity session IDs.
     * @param int[] $userids User IDs.
     * @return int[] Session IDs.
     */
    private static function get_affected_session_ids(
        int $quizgeistid,
        array $questionids,
        array $sessionids,
        array $userids
    ): array {
        global $DB;

        if (empty($sessionids) || empty($userids)) {
            return [];
        }

        [$usersql, $userparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'snapshotuser'
        );
        $affected = $DB->get_fieldset_sql(
            "SELECT id
               FROM {quizgeist_sessions}
              WHERE quizgeistid = :quizgeistid
                AND hostuserid {$usersql}",
            ['quizgeistid' => $quizgeistid] + $userparams
        );

        [$sessionsql, $sessionparams] = $DB->get_in_or_equal(
            $sessionids,
            SQL_PARAMS_NAMED,
            'snapshotsession'
        );
        // Use a fresh user placeholder prefix because named SQL parameters may
        // not be repeated in another statement on all supported databases.
        [$playerusersql, $playeruserparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'snapshotplayer'
        );
        $affected = array_merge($affected, $DB->get_fieldset_sql(
            "SELECT DISTINCT sessionid
               FROM {quizgeist_players}
              WHERE sessionid {$sessionsql}
                AND userid {$playerusersql}",
            $sessionparams + $playeruserparams
        ));

        if (!empty($questionids)) {
            [$questionsql, $questionparams] = $DB->get_in_or_equal(
                $questionids,
                SQL_PARAMS_NAMED,
                'snapshotquestion'
            );
            [$answerusersql, $answeruserparams] = $DB->get_in_or_equal(
                $userids,
                SQL_PARAMS_NAMED,
                'snapshotanswer'
            );
            $affected = array_merge($affected, $DB->get_fieldset_sql(
                "SELECT DISTINCT sessionid
                   FROM {quizgeist_answers}
                  WHERE questionid {$questionsql}
                    AND userid {$answerusersql}
                    AND sessionid IS NOT NULL",
                $questionparams + $answeruserparams
            ));
        }

        return array_values(array_unique(array_map('intval', $affected)));
    }

    /**
     * Canonicalise reconnect JSON without deleting the played-question snapshot.
     *
     * Current P3 state stores only question IDs, an index, a random visit token
     * and phase timestamps. Names, user IDs, scores and answers live solely in
     * their relational tables. Decoding through session_state also drops every
     * unknown legacy field, so personal extensions cannot survive erasure.
     *
     * @param int[] $sessionids Session IDs.
     * @return void
     */
    private static function clear_session_snapshots(array $sessionids): void {
        global $DB;

        if (empty($sessionids)) {
            return;
        }
        $records = $DB->get_records_list(
            'quizgeist_sessions',
            'id',
            $sessionids,
            'id ASC',
            'id,mode,statejson,settingsjson'
        );
        foreach ($records as $record) {
            $DB->update_record('quizgeist_sessions', (object)[
                'id' => (int)$record->id,
                'statejson' =>
                    \mod_quizgeist\local\live\session_state::redact_personal_data(
                        $record->statejson
                ),
                'settingsjson' => self::canonical_session_settings(
                    $record->settingsjson,
                    (string)$record->mode
                ),
            ]);
        }
    }

    /**
     * Preserve only the declared non-personal session settings.
     *
     * @param string|null $rawjson Stored settings.
     * @param string $mode Session mode.
     * @return string
     */
    private static function canonical_session_settings(
        ?string $rawjson,
        string $mode
    ): string {
        $namemode = 'real';
        try {
            $settings = \mod_quizgeist\local\live\session_settings::decode(
                $rawjson
            );
        } catch (\Throwable $exception) {
            $settings = [
                'schemaVersion' => 3,
                'nameMode' => $namemode,
                'team' => null,
                'blockedNames' => [],
            ];
        }
        if ($mode !== 'team') {
            $settings['team'] = null;
        }
        if ($mode !== 'security') {
            $settings['blockedNames'] = [];
        }
        return \mod_quizgeist\local\live\session_settings::encode($settings);
    }

    /**
     * Make privacy deletions visible to pollers and discard derived PII.
     *
     * @param int[] $sessionids Affected session IDs.
     * @return void
     */
    private static function invalidate_live_projections(array $sessionids): void {
        global $DB;

        if (!empty($sessionids)) {
            [$sessionsql, $params] = $DB->get_in_or_equal(
                $sessionids,
                SQL_PARAMS_NAMED,
                'invalidatesession'
            );
            $params['timemodified'] = time();
            $DB->execute(
                "UPDATE {quizgeist_sessions}
                    SET stateversion = stateversion + 1,
                        timemodified = :timemodified
                  WHERE id {$sessionsql}",
                $params
            );
        }
        // The same cache also stores personal completed-attempt projections,
        // which must be discarded even if this user had no live session.
        \cache::make('mod_quizgeist', 'liveprojection')->purge();
    }

    /**
     * Delete selected users' records under supplied parents.
     *
     * @param string $table Table name.
     * @param string $parentfield Parent field.
     * @param int[] $parentids Parent IDs.
     * @param int[] $userids User IDs.
     * @return void
     */
    private static function delete_by_parent_and_users(
        string $table,
        string $parentfield,
        array $parentids,
        array $userids
    ): void {
        global $DB;

        if (empty($parentids) || empty($userids)) {
            return;
        }
        // F13: quizgeist_stage_reports braucht KEINEN Sonderweg. Die Zeile
        // haengt an `quizgeistid` und `userid` — genau der Form, die der
        // allgemeine Pfad unten loescht. Ein zweiter Weg waere eine zweite
        // Wahrheit ueber dieselbe Loeschung.
        [$parentsql, $parentparams] = $DB->get_in_or_equal(
            $parentids,
            SQL_PARAMS_NAMED,
            'selectedparent'
        );
        [$usersql, $userparams] = $DB->get_in_or_equal(
            $userids,
            SQL_PARAMS_NAMED,
            'selecteduser'
        );
        $userfield = $table === 'quizgeist_card_scans' ? 'scannedby' : 'userid';
        $select = "{$parentfield} {$parentsql} AND {$userfield} {$usersql}";
        $params = $parentparams + $userparams;
        if ($table === 'quizgeist_clips') {
            $clips = $DB->get_records_select(
                $table,
                $select,
                $params,
                'id ASC',
                'id,quizgeistid,itemid'
            );
            self::delete_clip_records($clips);
            return;
        }
        if ($table === 'quizgeist_card_scans') {
            $scans = $DB->get_records_select(
                $table,
                $select,
                $params,
                'id ASC',
                'id,quizgeistid,itemid'
            );
            self::delete_card_scan_records($scans);
            return;
        }
        $DB->delete_records_select($table, $select, $params);
    }

    /**
     * Remove clip audio before deleting the corresponding bookkeeping rows.
     *
     * @param array<int, \stdClass> $clips Clip rows with item IDs.
     * @return void
     */
    private static function delete_clip_records(array $clips): void {
        global $DB;

        if (empty($clips)) {
            return;
        }

        $fs = get_file_storage();
        $contexts = [];
        $clipids = [];
        foreach ($clips as $clip) {
            $clipids[] = (int)$clip->id;
            $quizgeistid = (int)$clip->quizgeistid;
            if (!array_key_exists($quizgeistid, $contexts)) {
                $cmid = $DB->get_field_sql(
                    'SELECT cm.id
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.instance = :quizgeistid
                        AND m.name = :modulename',
                    ['quizgeistid' => $quizgeistid, 'modulename' => 'quizgeist']
                );
                $context = $cmid === false
                    ? null
                    : context_module::instance((int)$cmid, IGNORE_MISSING);
                $contexts[$quizgeistid] = $context instanceof context_module
                    ? $context
                    : null;
            }
            if ($contexts[$quizgeistid] !== null) {
                $fs->delete_area_files(
                    $contexts[$quizgeistid]->id,
                    'mod_quizgeist',
                    'clipaudio',
                    (int)$clip->itemid
                );
            }
        }
        if ($clipids) {
            $DB->delete_records_list('quizgeist_clips', 'id', $clipids);
        }
    }

    /**
     * Remove card-scan pictures before deleting their bookkeeping rows.
     *
     * @param array<int, \stdClass> $scans Scan rows with item IDs.
     * @return void
     */
    private static function delete_card_scan_records(array $scans): void {
        global $DB;

        if (empty($scans)) {
            return;
        }

        $fs = get_file_storage();
        $contexts = [];
        $scanids = [];
        foreach ($scans as $scan) {
            $scanids[] = (int)$scan->id;
            $quizgeistid = (int)$scan->quizgeistid;
            if (!array_key_exists($quizgeistid, $contexts)) {
                $cmid = $DB->get_field_sql(
                    'SELECT cm.id
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.instance = :quizgeistid
                        AND m.name = :modulename',
                    ['quizgeistid' => $quizgeistid, 'modulename' => 'quizgeist']
                );
                $context = $cmid === false
                    ? null
                    : context_module::instance((int)$cmid, IGNORE_MISSING);
                $contexts[$quizgeistid] = $context instanceof context_module
                    ? $context
                    : null;
            }
            if ($contexts[$quizgeistid] !== null && (int)$scan->itemid > 0) {
                $fs->delete_area_files(
                    $contexts[$quizgeistid]->id,
                    'mod_quizgeist',
                    'cardscan',
                    (int)$scan->itemid
                );
            }
        }
        if ($scanids) {
            $DB->delete_records_list('quizgeist_card_scans', 'id', $scanids);
        }
    }

    /**
     * Remove all nullable author and host references in an activity.
     *
     * @param int $quizgeistid Activity ID.
     * @return void
     */
    private static function anonymise_all_content_authors(int $quizgeistid): void {
        global $DB;

        foreach ([
            ['quizgeist_questions', 'createdby'],
            ['quizgeist_sessions', 'hostuserid'],
            ['quizgeist_assignments', 'createdby'],
            ['quizgeist_question_tags', 'createdby'],
            ['quizgeist_workshop', 'authorid'],
            ['quizgeist_workshop', 'curatorid'],
            ['quizgeist_cardsets', 'createdby'],
        ] as [$table, $field]) {
            $DB->execute(
                "UPDATE {{$table}}
                    SET {$field} = NULL
                  WHERE quizgeistid = :quizgeistid",
                ['quizgeistid' => $quizgeistid]
            );
        }
        $DB->execute(
            'UPDATE {quizgeist_cards}
                SET userid = NULL
              WHERE cardsetid IN (
                  SELECT id
                    FROM {quizgeist_cardsets}
                   WHERE quizgeistid = :quizgeistid
              )',
            ['quizgeistid' => $quizgeistid]
        );
    }

    /**
     * Remove selected users' nullable author and host references.
     *
     * @param int $quizgeistid Activity ID.
     * @param int[] $userids User IDs.
     * @return void
     */
    private static function anonymise_selected_content_authors(int $quizgeistid, array $userids): void {
        global $DB;

        foreach ([
            ['quizgeist_questions', 'createdby'],
            ['quizgeist_sessions', 'hostuserid'],
            ['quizgeist_assignments', 'createdby'],
            // U1: an approved tag assignment is course content and survives;
            // only its authorship is released (P11_PLAN.md, decision E-4).
            ['quizgeist_question_tags', 'createdby'],
            ['quizgeist_workshop', 'authorid'],
            ['quizgeist_workshop', 'curatorid'],
        ] as $index => [$table, $field]) {
            [$usersql, $userparams] = $DB->get_in_or_equal(
                $userids,
                SQL_PARAMS_NAMED,
                'author' . $index
            );
            $DB->execute(
                "UPDATE {{$table}}
                    SET {$field} = NULL
                  WHERE quizgeistid = :quizgeistid
                    AND {$field} {$usersql}",
                ['quizgeistid' => $quizgeistid] + $userparams
            );
        }
    }

    /**
     * Resolve a Quizgeist instance from a verified module context.
     *
     * @param context $context Context to inspect.
     * @return int|null Activity ID, or null for another context/module.
     */
    private static function get_quizgeist_id_for_context(context $context): ?int {
        global $DB;

        if (!$context instanceof context_module) {
            return null;
        }
        $instanceid = $DB->get_field_sql(
            'SELECT cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid
                AND m.name = :modulename',
            ['cmid' => $context->instanceid, 'modulename' => 'quizgeist']
        );
        return $instanceid === false ? null : (int) $instanceid;
    }
}
