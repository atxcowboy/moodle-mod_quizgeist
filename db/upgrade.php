<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Database upgrades for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade mod_quizgeist.
 *
 * @param int $oldversion Installed version.
 * @return bool
 */
function xmldb_quizgeist_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026072901) {
        $importtable = new xmldb_table('quizgeist_imports');
        $importtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $importtable->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_field('sourceformat', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'kahoot');
        $importtable->add_field('sourceuuid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $importtable->add_field('sourcename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $importtable->add_field('sourcehash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $importtable->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'pending');
        $importtable->add_field('questioncount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_field('adaptedcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_field('skippedcount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_field('mediacount', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_field('reportjson', XMLDB_TYPE_TEXT, null, null, null);
        $importtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $importtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $importtable->add_key('quizgeist_fk', XMLDB_KEY_FOREIGN, ['quizgeistid'], 'quizgeist', ['id']);
        $importtable->add_key('course_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $importtable->add_index(
            'quiz_source_uix',
            XMLDB_INDEX_UNIQUE,
            ['quizgeistid', 'sourceformat', 'sourceuuid']
        );
        $importtable->add_index(
            'course_source_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['courseid', 'sourceformat', 'sourceuuid']
        );
        $importtable->add_index('status_modified_idx', XMLDB_INDEX_NOTUNIQUE, ['status', 'timemodified']);
        if (!$dbman->table_exists($importtable)) {
            $dbman->create_table($importtable);
        }

        $questiontable = new xmldb_table('quizgeist_questions');
        $importfield = new xmldb_field(
            'importid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'quizgeistid'
        );
        if (!$dbman->field_exists($questiontable, $importfield)) {
            $dbman->add_field($questiontable, $importfield);
        }
        $importkey = new xmldb_key(
            'import_fk',
            XMLDB_KEY_FOREIGN,
            ['importid'],
            'quizgeist_imports',
            ['id']
        );
        if (!$dbman->find_key_name($questiontable, $importkey)) {
            $dbman->add_key($questiontable, $importkey);
        }

        $topiatable = new xmldb_table('quizgeist_topia');
        $topiatable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $topiatable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $topiatable->add_field('starscache', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $topiatable->add_field('unlocksjson', XMLDB_TYPE_TEXT, null, null, null);
        $topiatable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $topiatable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        // Fremdschluessel UND Unique-Index auf demselben Feld weist XMLDB als
        // Kollision zurueck. XMLDB_KEY_FOREIGN_UNIQUE leistet beides: eine
        // Geistopia-Welt je Kurs, referenziell abgesichert.
        $topiatable->add_key(
            'course_fk',
            XMLDB_KEY_FOREIGN_UNIQUE,
            ['courseid'],
            'course',
            ['id']
        );
        if (!$dbman->table_exists($topiatable)) {
            $dbman->create_table($topiatable);
        }

        upgrade_mod_savepoint(true, 2026072901, 'quizgeist');
    }

    if ($oldversion < 2026072902) {
        // Geistopia is a user-specific projection. Its former course-wide
        // derived cache was neither read nor safe to share across viewers.
        $topiatable = new xmldb_table('quizgeist_topia');
        if ($dbman->table_exists($topiatable)) {
            $dbman->drop_table($topiatable);
        }

        upgrade_mod_savepoint(true, 2026072902, 'quizgeist');
    }

    if ($oldversion < 2026073000) {
        // P10 introduces Moodle-discovered addons and a new MUC definition.
        // Shared tables remain basis-owned, so no data migration is needed.
        upgrade_mod_savepoint(true, 2026073000, 'quizgeist');
    }

    // ===================================================================
    // P11/C1. Endabnahme 2026-08-01: Platzhalter aufgeloest, Stufe 2026080104
    // ist endgueltig. Sie liegt unter der Endversion 2026080110 aus
    // version.php und db/install.xml; die Kette 2026080104..2026080109
    // ist lueckenlos.
    // ===================================================================
    if ($oldversion < 2026080104) {
        $activity = new xmldb_table('quizgeist');
        $stressfreefields = [
            // F1 Stressarm-Standard: die Punktachse ist bewusst orthogonal
            // zum Spielmodus. Sie gehoert der Basis, nie dem modes-Addon.
            new xmldb_field(
                'pacemode',
                XMLDB_TYPE_CHAR,
                '16',
                null,
                XMLDB_NOTNULL,
                null,
                'even',
                'grade'
            ),
            new xmldb_field(
                'leaderboard',
                XMLDB_TYPE_CHAR,
                '16',
                null,
                XMLDB_NOTNULL,
                null,
                'own',
                'pacemode'
            ),
            new xmldb_field(
                'timervisible',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '1',
                'leaderboard'
            ),
            new xmldb_field(
                'soundenabled',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '1',
                'timervisible'
            ),
            new xmldb_field(
                'friendlynew',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '1',
                'soundenabled'
            ),
            // F2 Denk-Moment (Textvariante) und F8 Offenlegungspolitik.
            new xmldb_field(
                'reasonstep',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'friendlynew'
            ),
            new xmldb_field(
                'explanationpolicy',
                XMLDB_TYPE_CHAR,
                '16',
                null,
                XMLDB_NOTNULL,
                null,
                'immediate',
                'reasonstep'
            ),
        ];
        foreach ($stressfreefields as $field) {
            if (!$dbman->field_exists($activity, $field)) {
                $dbman->add_field($activity, $field);
            }
        }

        // U1 Tagging-Kern. Eigene Tabellen statt core_tag, weil schulweiter
        // Farbcode, LehrplanPLUS-Fundstelle und eigene Backup-/Restore-
        // Kontrolle gebraucht werden (P11_PLAN.md, Entscheidung E-5).
        $tagtable = new xmldb_table('quizgeist_tags');
        $tagtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $tagtable->add_field('scope', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'activity');
        $tagtable->add_field('scopeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tagtable->add_field('kind', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'topic');
        $tagtable->add_field('tagkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $tagtable->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $tagtable->add_field('colorkey', XMLDB_TYPE_CHAR, '32', null, null);
        $tagtable->add_field('externalref', XMLDB_TYPE_CHAR, '255', null, null);
        $tagtable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tagtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tagtable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tagtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $tagtable->add_index(
            'scope_kind_uix',
            XMLDB_INDEX_UNIQUE,
            ['scope', 'scopeid', 'kind', 'tagkey']
        );
        $tagtable->add_index('kind_sort_idx', XMLDB_INDEX_NOTUNIQUE, ['kind', 'sortorder']);
        if (!$dbman->table_exists($tagtable)) {
            $dbman->create_table($tagtable);
        }

        $questiontagtable = new xmldb_table('quizgeist_question_tags');
        $questiontagtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $questiontagtable->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $questiontagtable->add_field('rootid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $questiontagtable->add_field('tagid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $questiontagtable->add_field('weight', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '100');
        $questiontagtable->add_field('status', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'approved');
        $questiontagtable->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, null);
        $questiontagtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $questiontagtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $questiontagtable->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $questiontagtable->add_key(
            'tag_fk',
            XMLDB_KEY_FOREIGN,
            ['tagid'],
            'quizgeist_tags',
            ['id']
        );
        $questiontagtable->add_key(
            'createdby_fk',
            XMLDB_KEY_FOREIGN,
            ['createdby'],
            'user',
            ['id']
        );
        $questiontagtable->add_index('root_tag_uix', XMLDB_INDEX_UNIQUE, ['rootid', 'tagid']);
        $questiontagtable->add_index('quiz_tag_idx', XMLDB_INDEX_NOTUNIQUE, ['quizgeistid', 'tagid']);
        if (!$dbman->table_exists($questiontagtable)) {
            $dbman->create_table($questiontagtable);
        }

        upgrade_mod_savepoint(true, 2026080104, 'quizgeist');
    }

    // ===================================================================
    // P11/C2. Endabnahme 2026-08-01: Platzhalter aufgeloest, Stufe 2026080105
    // ist endgueltig. Sie liegt unter der Endversion 2026080110 aus
    // version.php und db/install.xml; die Kette 2026080104..2026080109
    // ist lueckenlos.
    // ===================================================================
    if ($oldversion < 2026080105) {
        // U2 Wiederholungs-Kern (SM-2). Die Tabelle gehoert der Basis, nicht
        // dem selfstudy-Addon: Lernstaende muessen lesbar, exportierbar und
        // loeschbar bleiben, auch wenn ein Addon-Codepaket fehlt
        // (ARCHITECTURE.md, "Bewusst gemeinsam im Basisplugin").
        $scheduletable = new xmldb_table('quizgeist_schedule');
        $scheduletable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $scheduletable->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('rootid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('easiness', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '250');
        $scheduletable->add_field('intervaldays', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('repetitions', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('lapses', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('duetime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('lastreviewed', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('lastquality', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scheduletable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $scheduletable->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $scheduletable->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $scheduletable->add_index(
            'user_root_uix',
            XMLDB_INDEX_UNIQUE,
            ['quizgeistid', 'userid', 'rootid']
        );
        $scheduletable->add_index('due_idx', XMLDB_INDEX_NOTUNIQUE, ['quizgeistid', 'duetime']);
        $scheduletable->add_index('user_due_idx', XMLDB_INDEX_NOTUNIQUE, ['userid', 'duetime']);
        if (!$dbman->table_exists($scheduletable)) {
            $dbman->create_table($scheduletable);
        }

        // F3: eine gewoehnliche Zuweisung friert die exakte Fragenversion ein,
        // eine Wiederholungs-Zuweisung friert die Wurzel ein und loest die
        // Version erst beim Start auf.
        $assignments = new xmldb_table('quizgeist_assignments');
        $selection = new xmldb_field(
            'selection',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'fixed',
            'createdby'
        );
        if (!$dbman->field_exists($assignments, $selection)) {
            $dbman->add_field($assignments, $selection);
        }

        $assignmentquestions = new xmldb_table('quizgeist_assignment_questions');
        $snapshotroot = new xmldb_field(
            'rootid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'questionid'
        );
        if (!$dbman->field_exists($assignmentquestions, $snapshotroot)) {
            $dbman->add_field($assignmentquestions, $snapshotroot);
            // Existing snapshots are exact versions. Backfilling the root keeps
            // the denormalised column truthful from the first upgrade onwards;
            // it stays advisory for selection=fixed and authoritative only for
            // selection=due, which no historical row can be.
            $DB->execute(
                'UPDATE {quizgeist_assignment_questions}
                    SET rootid = COALESCE((
                            SELECT CASE WHEN q.rootid > 0 THEN q.rootid ELSE q.id END
                              FROM {quizgeist_questions} q
                             WHERE q.id = questionid
                        ), 0)'
            );
        }

        upgrade_mod_savepoint(true, 2026080105, 'quizgeist');
    }

    // ===================================================================
    // P11/C3. Endabnahme 2026-08-01: Platzhalter aufgeloest, Stufe 2026080106
    // ist endgueltig. Sie liegt unter der Endversion 2026080110 aus
    // version.php und db/install.xml; die Kette 2026080104..2026080109
    // ist lueckenlos.
    // ===================================================================
    if ($oldversion < 2026080106) {
        // F5 Fehlkonzept-Radar. Etiketten sind Kursinhalt einer Lehrkraft und
        // tragen keine Nutzerspalte; sie haengen an der Fragen-WURZEL, damit
        // eine Bearbeitung der Frage sie nicht entwertet (P11_PLAN.md 2.3).
        $misconceptions = new xmldb_table('quizgeist_misconceptions');
        $misconceptions->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $misconceptions->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $misconceptions->add_field('rootid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $misconceptions->add_field('answerkey', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL);
        $misconceptions->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $misconceptions->add_field('hint', XMLDB_TYPE_TEXT, null, null, null);
        $misconceptions->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $misconceptions->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $misconceptions->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $misconceptions->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $misconceptions->add_index(
            'root_answer_uix',
            XMLDB_INDEX_UNIQUE,
            ['rootid', 'answerkey']
        );
        $misconceptions->add_index(
            'quiz_root_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['quizgeistid', 'rootid']
        );
        if (!$dbman->table_exists($misconceptions)) {
            $dbman->create_table($misconceptions);
        }

        // F7 Fragenwerkstatt. Die eingereichte Frage selbst bleibt eine
        // gewoehnliche quizgeist_questions-Zeile mit status='draft'; hier
        // liegt nur der Einreichungs- und Kuratierungsvorgang.
        $workshop = new xmldb_table('quizgeist_workshop');
        $workshop->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $workshop->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $workshop->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $workshop->add_field('rootid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $workshop->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, null);
        $workshop->add_field('state', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'submitted');
        $workshop->add_field('curatorid', XMLDB_TYPE_INTEGER, '10', null, null);
        $workshop->add_field('curatornote', XMLDB_TYPE_TEXT, null, null, null);
        $workshop->add_field('aicheckjson', XMLDB_TYPE_TEXT, null, null, null);
        $workshop->add_field('timesubmitted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $workshop->add_field('timedecided', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $workshop->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $workshop->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $workshop->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $workshop->add_key('author_fk', XMLDB_KEY_FOREIGN, ['authorid'], 'user', ['id']);
        $workshop->add_key('curator_fk', XMLDB_KEY_FOREIGN, ['curatorid'], 'user', ['id']);
        $workshop->add_index('root_uix', XMLDB_INDEX_UNIQUE, ['rootid']);
        $workshop->add_index(
            'quiz_state_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['quizgeistid', 'state', 'timesubmitted']
        );
        if (!$dbman->table_exists($workshop)) {
            $dbman->create_table($workshop);
        }

        // Eine Bewertung je (Einreichung, Nutzer) wird vom Unique-Index
        // erzwungen, nicht von der Anwendungslogik.
        $ratings = new xmldb_table('quizgeist_workshop_ratings');
        $ratings->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $ratings->add_field('workshopid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $ratings->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $ratings->add_field('quality', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $ratings->add_field('difficulty', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $ratings->add_field('comment', XMLDB_TYPE_TEXT, null, null, null);
        $ratings->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $ratings->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $ratings->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $ratings->add_key(
            'workshop_fk',
            XMLDB_KEY_FOREIGN,
            ['workshopid'],
            'quizgeist_workshop',
            ['id']
        );
        $ratings->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $ratings->add_index(
            'workshop_user_uix',
            XMLDB_INDEX_UNIQUE,
            ['workshopid', 'userid']
        );
        if (!$dbman->table_exists($ratings)) {
            $dbman->create_table($ratings);
        }

        upgrade_mod_savepoint(true, 2026080106, 'quizgeist');
    }

    // ===================================================================
    // P11/C4. Endabnahme 2026-08-01: Platzhalter aufgeloest, Stufe 2026080107
    // ist endgueltig. Sie liegt unter der Endversion 2026080110 aus
    // version.php und db/install.xml; die Kette 2026080104..2026080109
    // ist lueckenlos.
    // ===================================================================
    if ($oldversion < 2026080107) {
        // U3 Kurzclip-Kanal. Die Audiodatei liegt im Dateibereich clipaudio;
        // diese Zeile ist ihre Buchfuehrung und nach dem Loeschlauf (E-10)
        // ihr einziger Rest. Ein Clip haengt an genau EINER Antwort — das
        // erzwingt der Unique-Index, nicht die Anwendungslogik.
        $clips = new xmldb_table('quizgeist_clips');
        $clips->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $clips->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_field('answerid', XMLDB_TYPE_INTEGER, '10', null, null);
        $clips->add_field('purpose', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'answer');
        $clips->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_field('durationms', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_field('bytes', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_field('language', XMLDB_TYPE_CHAR, '8', null, XMLDB_NOTNULL, null, 'de');
        $clips->add_field('transcript', XMLDB_TYPE_TEXT, null, null, null);
        $clips->add_field('transcriptstate', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'none');
        $clips->add_field('transcriptcode', XMLDB_TYPE_CHAR, '32', null, null);
        $clips->add_field('audiodeleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $clips->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $clips->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $clips->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $clips->add_key(
            'answer_fk',
            XMLDB_KEY_FOREIGN_UNIQUE,
            ['answerid'],
            'quizgeist_answers',
            ['id']
        );
        $clips->add_index('quiz_user_idx', XMLDB_INDEX_NOTUNIQUE, ['quizgeistid', 'userid']);
        $clips->add_index(
            'state_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['transcriptstate', 'timecreated']
        );
        if (!$dbman->table_exists($clips)) {
            $dbman->create_table($clips);
        }

        // F10 Lehrplan-Anker. Kursinhalt, keine Nutzerdaten: die Zeile
        // ueberlebt einen Kursreset und haengt an der Fragen-WURZEL.
        $refs = new xmldb_table('quizgeist_curriculum_refs');
        $refs->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $refs->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $refs->add_field('rootid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $refs->add_field('subject', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $refs->add_field('grade', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $refs->add_field('variant', XMLDB_TYPE_CHAR, '100', null, null);
        $refs->add_field('learningarea', XMLDB_TYPE_CHAR, '255', null, null);
        $refs->add_field('competency', XMLDB_TYPE_TEXT, null, null, null);
        $refs->add_field('citationurl', XMLDB_TYPE_CHAR, '255', null, null);
        $refs->add_field('chunkid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $refs->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $refs->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $refs->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $refs->add_index('root_uix', XMLDB_INDEX_UNIQUE, ['rootid']);
        $refs->add_index('quiz_grade_idx', XMLDB_INDEX_NOTUNIQUE, ['quizgeistid', 'grade']);
        if (!$dbman->table_exists($refs)) {
            $dbman->create_table($refs);
        }

        upgrade_mod_savepoint(true, 2026080107, 'quizgeist');
    }

    // ===================================================================
    // P11/C5. Endabnahme 2026-08-01: Platzhalter aufgeloest, Stufe 2026080108
    // ist endgueltig. Sie liegt unter der Endversion 2026080110 aus
    // version.php und db/install.xml; die Kette 2026080104..2026080109
    // ist lueckenlos.
    // ===================================================================
    if ($oldversion < 2026080108) {
        // F11a Karten-Modus. Ein Kartensatz ist Kursinhalt: die gedruckten
        // Boegen liegen in der Schultasche und muessen einen Kursreset
        // ueberleben. Nur die Zuordnung Karte -> Person ist personenbezogen.
        $cardsets = new xmldb_table('quizgeist_cardsets');
        $cardsets->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $cardsets->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $cardsets->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $cardsets->add_field('layout', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'abcd');
        $cardsets->add_field('seed', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
        $cardsets->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, null);
        $cardsets->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $cardsets->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $cardsets->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $cardsets->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $cardsets->add_key('createdby_fk', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);
        if (!$dbman->table_exists($cardsets)) {
            $dbman->create_table($cardsets);
        }

        // Eine Karte je Person; `userid` null ist die Reservekarte fuer
        // Gaeste. Der Code ist je Satz eindeutig — das erzwingt der Index,
        // nicht die Anwendungslogik.
        $cards = new xmldb_table('quizgeist_cards');
        $cards->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $cards->add_field('cardsetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $cards->add_field('cardindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $cards->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, null);
        $cards->add_field('cardcode', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
        $cards->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $cards->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $cards->add_key(
            'cardset_fk',
            XMLDB_KEY_FOREIGN,
            ['cardsetid'],
            'quizgeist_cardsets',
            ['id']
        );
        $cards->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $cards->add_index('set_code_uix', XMLDB_INDEX_UNIQUE, ['cardsetid', 'cardcode']);
        $cards->add_index('set_user_idx', XMLDB_INDEX_NOTUNIQUE, ['cardsetid', 'userid']);
        $cards->add_index('set_index_uix', XMLDB_INDEX_UNIQUE, ['cardsetid', 'cardindex']);
        if (!$dbman->table_exists($cards)) {
            $dbman->create_table($cards);
        }

        // Ein Kartenscan ist ein fluechtiges Klassenfoto. Die Zeile ist seine
        // Buchfuehrung und nach dem Loeschlauf (E-10) sein einziger Rest;
        // `imagedeleted` macht die Loeschung beweisbar. Der Dateibereich
        // `cardscan` wird NIE ueber quizgeist_pluginfile() ausgeliefert.
        $scans = new xmldb_table('quizgeist_card_scans');
        $scans->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $scans->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('visit', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
        $scans->add_field('cardsetid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('scannedby', XMLDB_TYPE_INTEGER, '10', null, null);
        $scans->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('state', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'pending');
        $scans->add_field('reasoncode', XMLDB_TYPE_CHAR, '32', null, null);
        $scans->add_field('resultjson', XMLDB_TYPE_TEXT, null, null, null);
        $scans->add_field('recognised', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('expected', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('imagedeleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $scans->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $scans->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $scans->add_key(
            'session_fk',
            XMLDB_KEY_FOREIGN,
            ['sessionid'],
            'quizgeist_sessions',
            ['id']
        );
        $scans->add_key(
            'question_fk',
            XMLDB_KEY_FOREIGN,
            ['questionid'],
            'quizgeist_questions',
            ['id']
        );
        $scans->add_key(
            'cardset_fk',
            XMLDB_KEY_FOREIGN,
            ['cardsetid'],
            'quizgeist_cardsets',
            ['id']
        );
        $scans->add_key('scannedby_fk', XMLDB_KEY_FOREIGN, ['scannedby'], 'user', ['id']);
        $scans->add_index(
            'session_question_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['sessionid', 'questionid', 'visit']
        );
        $scans->add_index('state_idx', XMLDB_INDEX_NOTUNIQUE, ['state', 'timemodified']);
        $scans->add_index('quiz_image_idx', XMLDB_INDEX_NOTUNIQUE, ['quizgeistid', 'imagedeleted']);
        if (!$dbman->table_exists($scans)) {
            $dbman->create_table($scans);
        }

        upgrade_mod_savepoint(true, 2026080108, 'quizgeist');
    }

    // ===================================================================
    // P11/C6. Endabnahme 2026-08-01: Platzhalter aufgeloest, Stufe 2026080109
    // ist endgueltig. Sie liegt unter der Endversion 2026080110 aus
    // version.php und db/install.xml; die Kette 2026080104..2026080109
    // ist lueckenlos.
    // ===================================================================
    if ($oldversion < 2026080109) {
        // F13 Buehnen-Check. Die Tabelle gehoert der BASIS, obwohl nur das
        // Addon sie fuellt: ein Bericht muss lesbar, exportierbar und
        // loeschbar bleiben, auch wenn das Addon-Codepaket fehlt
        // (ARCHITECTURE.md, P11_PLAN.md 6). In der Zeile stehen
        // ausschliesslich KENNZAHLEN — kein Bild, kein Video und kein einziger
        // roher Koerper-Landmark, weil nichts davon das Geraet verlaesst.
        $stagereports = new xmldb_table('quizgeist_stage_reports');
        $stagereports->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $stagereports->add_field('quizgeistid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $stagereports->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $stagereports->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $stagereports->add_field('answerid', XMLDB_TYPE_INTEGER, '10', null, null);
        $stagereports->add_field('durationsecs', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $stagereports->add_field('metricsjson', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
        $stagereports->add_field('aiused', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $stagereports->add_field('feedbackjson', XMLDB_TYPE_TEXT, null, null, null);
        $stagereports->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $stagereports->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $stagereports->add_key(
            'quizgeist_fk',
            XMLDB_KEY_FOREIGN,
            ['quizgeistid'],
            'quizgeist',
            ['id']
        );
        $stagereports->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $stagereports->add_key(
            'question_fk',
            XMLDB_KEY_FOREIGN,
            ['questionid'],
            'quizgeist_questions',
            ['id']
        );
        $stagereports->add_key(
            'answer_fk',
            XMLDB_KEY_FOREIGN,
            ['answerid'],
            'quizgeist_answers',
            ['id']
        );
        $stagereports->add_index(
            'quiz_user_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['quizgeistid', 'userid']
        );
        if (!$dbman->table_exists($stagereports)) {
            $dbman->create_table($stagereports);
        }

        upgrade_mod_savepoint(true, 2026080109, 'quizgeist');
    }

    return true;
}
