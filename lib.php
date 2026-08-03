<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Core callbacks for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Declare Moodle features supported by Quizgeist.
 *
 * @param string $feature Feature constant.
 * @return bool|string|null
 */
function quizgeist_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2 => true,
        FEATURE_GROUPS => true,
        FEATURE_GROUPINGS => true,
        FEATURE_GRADE_HAS_GRADE => true,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_COMPLETION_HAS_RULES => true,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_ASSESSMENT,
        default => null,
    };
}

/**
 * Guard the licensed game modes with a message a teacher can act on.
 *
 * The activity form already hides locked options, so this path is only reached
 * when the entitlement lapses between rendering and saving, or on a crafted
 * request. The bare feature exception carries an English developer sentence,
 * which must never reach the settings page.
 *
 * @return void
 */
function quizgeist_require_modes_feature(): void {
    try {
        \mod_quizgeist\local\licence\feature_gate::require('modes');
    } catch (\mod_quizgeist\local\licence\feature_locked_exception) {
        throw new moodle_exception('licence:featurelocked', 'mod_quizgeist');
    }
}

/**
 * Create a Quizgeist activity instance.
 *
 * @param stdClass $data Form data.
 * @param mod_quizgeist_mod_form|null $mform Activity form.
 * @return int New instance ID.
 */
function quizgeist_add_instance($data, $mform = null): int {
    global $DB;

    $record = quizgeist_instance_record($data, null, time());
    if ($record->theme === 'jahreszeiten' || $record->defaultmode !== 'classic') {
        quizgeist_require_modes_feature();
    }

    $record->id = $DB->insert_record('quizgeist', $record);
    quizgeist_grade_item_update($record);

    return (int)$record->id;
}

/**
 * Assemble the complete activity row for insert and update.
 *
 * This is the only place that casts the assembled row to an object, and it is
 * a named place on purpose. `(object)[...] + [...]` reads like an array union
 * but is a precedence trap: PHP applies the cast first and then evaluates
 * `stdClass + array`, which is a fatal TypeError. That trap sat in both
 * callers from P11-C1 until [P11-E1] and made EVERY activity creation fail —
 * the base package hardest, because it has no addon path around this
 * function. With the union inside the function and `stdClass` as the declared
 * return type, the trap cannot come back unseen, and the assembly is provable
 * without a database (checks/p11_logic_proof.php, base-state sub-run).
 *
 * The assembly is deliberately gate-free. It needs no addon, no entitlement
 * and no licence file: a school running only the base package must be able to
 * create and edit activities, which is exactly what `$current === null` (new)
 * and `$current !== null` (edit) cover here. Whether a submission requires the
 * `modes` entitlement is a separate decision and stays with the callers,
 * because creating and expanding are judged differently.
 *
 * @param stdClass $data Submitted form data.
 * @param stdClass|null $current Existing record for an update, or null.
 * @param int $now Timestamp for the time columns.
 * @return stdClass Complete row, ready for insert_record()/update_record().
 */
function quizgeist_instance_record($data, $current, int $now): stdClass {
    $themes = ['hell', 'dunkel', 'weltraum', 'ozean', 'retro-arcade', 'jahreszeiten'];
    $seasons = ['herbst', 'winter', 'fruehling', 'sommer'];
    $modes = ['classic', 'accuracy', 'team', 'security'];
    $grademethods = ['best', 'last', 'average'];
    $fields = [
        'course' => (int)$data->course,
        'name' => (string)$data->name,
        'intro' => (string)($data->intro ?? ''),
        'introformat' => (int)($data->introformat ?? FORMAT_HTML),
        'theme' => in_array($data->theme ?? '', $themes, true)
            ? $data->theme
            : ($current->theme ?? 'hell'),
        'season' => in_array($data->season ?? '', $seasons, true)
            ? $data->season
            : ($current->season ?? 'herbst'),
        'allowbacktrack' => property_exists($data, 'allowbacktrack')
            ? (empty($data->allowbacktrack) ? 0 : 1)
            : (empty($current->allowbacktrack ?? 1) ? 0 : 1),
        'defaultmode' => in_array($data->defaultmode ?? '', $modes, true)
            ? $data->defaultmode
            : ($current->defaultmode ?? 'classic'),
        'grademethod' => in_array($data->grademethod ?? '', $grademethods, true)
            ? $data->grademethod
            : ($current->grademethod ?? 'best'),
        'grade' => (int)($data->grade ?? $current->grade ?? 0),
        // The activity form always submits both completion fields, so they are
        // read from the submission on an update as well; a cleared checkbox
        // must not be resurrected from the stored row.
        'completionparticipate' => empty($data->completionparticipate) ? 0 : 1,
        'completionpercent' => max(0, min(100, (int)($data->completionpercent ?? 0))),
        'timemodified' => $now,
    ];
    if ($current === null) {
        $fields['timecreated'] = $now;
    } else {
        $fields = ['id' => (int)$data->instance] + $fields;
    }

    return (object)($fields + quizgeist_stressfree_fields($data, $current));
}

/**
 * Normalise the F1/F2/F8 activity fields for insert and update.
 *
 * These settings are deliberately gate-free: the stress-free standard is the
 * strongest argument of the base package and must never depend on an addon.
 *
 * @param stdClass $data Submitted form data.
 * @param stdClass|null $current Existing record for an update, or null.
 * @return array<string,mixed>
 */
function quizgeist_stressfree_fields($data, $current): array {
    $flag = static function ($submitted, $fallback): int {
        if ($submitted === null) {
            return empty($fallback) ? 0 : 1;
        }
        return empty($submitted) ? 0 : 1;
    };
    return [
        'pacemode' => \mod_quizgeist\local\live\scoring_context::normalise_pace(
            $data->pacemode
                ?? $current->pacemode
                ?? \mod_quizgeist\local\live\scoring_context::DEFAULT_PACE
        ),
        'leaderboard' => \mod_quizgeist\local\live\leaderboard_policy::normalise(
            $data->leaderboard
                ?? $current->leaderboard
                ?? \mod_quizgeist\local\live\leaderboard_policy::DEFAULT_VISIBILITY
        ),
        'timervisible' => $flag(
            property_exists($data, 'timervisible') ? $data->timervisible : null,
            $current->timervisible ?? 1
        ),
        'soundenabled' => $flag(
            property_exists($data, 'soundenabled') ? $data->soundenabled : null,
            $current->soundenabled ?? 1
        ),
        'friendlynew' => $flag(
            property_exists($data, 'friendlynew') ? $data->friendlynew : null,
            $current->friendlynew ?? 1
        ),
        'reasonstep' => $flag(
            property_exists($data, 'reasonstep') ? $data->reasonstep : null,
            $current->reasonstep ?? 0
        ),
        'explanationpolicy' =>
            \mod_quizgeist\local\live\explanation_policy::normalise(
                $data->explanationpolicy
                    ?? $current->explanationpolicy
                    ?? \mod_quizgeist\local\live\explanation_policy::DEFAULT_POLICY
            ),
    ];
}

/**
 * Update a Quizgeist activity instance.
 *
 * @param stdClass $data Form data.
 * @param mod_quizgeist_mod_form|null $mform Activity form.
 * @return bool
 */
function quizgeist_update_instance($data, $mform = null): bool {
    global $CFG, $DB;

    $current = $DB->get_record('quizgeist', ['id' => $data->instance], '*', MUST_EXIST);
    $record = quizgeist_instance_record($data, $current, time());
    // Only an EXPANSION needs the entitlement. An activity that already runs a
    // licensed theme or mode must stay editable when the licence lapses.
    $expandsmodes = (
        $record->theme === 'jahreszeiten'
        && (string)$current->theme !== 'jahreszeiten'
    ) || (
        $record->defaultmode !== 'classic'
        && $record->defaultmode !== (string)$current->defaultmode
    );
    if ($expandsmodes) {
        quizgeist_require_modes_feature();
    }
    $grademethodchanged = (string)$record->grademethod
        !== (string)$current->grademethod;

    $updated = $DB->update_record('quizgeist', $record);
    $merged = (object)array_merge((array)$current, (array)$record);
    quizgeist_grade_item_update($merged);
    quizgeist_update_grades($merged);
    if ($grademethodchanged) {
        require_once($CFG->libdir . '/completionlib.php');
        $cm = get_coursemodule_from_instance(
            'quizgeist',
            (int)$merged->id,
            (int)$merged->course,
            false,
            MUST_EXIST
        );
        $completion = new completion_info(
            get_course((int)$merged->course)
        );
        if ($completion->is_enabled($cm)) {
            // completionpercent uses the same aggregation method as the
            // gradebook, so a method change can make completed users
            // incomplete (or vice versa).
            $completion->reset_all_state($cm);
        }
    }

    return $updated;
}

/**
 * Delete an activity and all data scoped to it.
 *
 * School-wide templates are independent site records and are never touched
 * when an activity is removed.
 *
 * @param int $id Instance ID.
 * @return bool
 */
function quizgeist_delete_instance($id): bool {
    global $DB;

    $quizgeist = $DB->get_record('quizgeist', ['id' => $id]);
    if (!$quizgeist) {
        return false;
    }

    $transaction = \mod_quizgeist\local\transaction_scope::begin();
    try {
        $params = ['quizgeistid' => $quizgeist->id];
        quizgeist_purge_instance_userdata((int)$quizgeist->id);
        // The purge above removes the audio and rows before answer deletion;
        // keep this explicit guard for callers that invoke instance deletion
        // with a partially purged activity.
        $DB->delete_records('quizgeist_clips', $params);
        // Earned site-wide unlocks survive removal of their source activity.
        $DB->set_field('quizgeist_rewards', 'quizgeistid', null, $params);
        $DB->delete_records_select(
            'quizgeist_assignment_questions',
            'assignmentid IN (
                SELECT id FROM {quizgeist_assignments} WHERE quizgeistid = :quizgeistid
            )',
            $params
        );
        // Tag assignments hang on the question root, so they die with the
        // questions. The tag vocabulary of the activity scope goes with the
        // activity; course- and site-scoped tags outlive it.
        $DB->delete_records('quizgeist_question_tags', $params);
        $DB->delete_records('quizgeist_tags', [
            'scope' => 'activity',
            'scopeid' => (int)$quizgeist->id,
        ]);
        // Peer-Bewertungen hängen an der Einreichung und müssen vor der
        // Werkstatt selbst entfernt werden.
        $DB->delete_records_select(
            'quizgeist_workshop_ratings',
            'workshopid IN (
                SELECT id FROM {quizgeist_workshop} WHERE quizgeistid = :quizgeistid
            )',
            $params
        );
        $DB->delete_records('quizgeist_workshop', $params);
        $DB->delete_records('quizgeist_misconceptions', $params);
        // Curriculum references are activity-owned course content and are
        // removed only when the activity itself is deleted (not on reset).
        $DB->delete_records('quizgeist_curriculum_refs', $params);
        $DB->delete_records('quizgeist_questions', $params);
        $DB->delete_records('quizgeist_imports', $params);
        $DB->delete_records('quizgeist_assignments', $params);
        $DB->delete_records('quizgeist', ['id' => $quizgeist->id]);

        quizgeist_grade_item_delete($quizgeist);
        $transaction->allow_commit();
    } catch (\Throwable $exception) {
        $transaction->rollback($exception);
    }

    return true;
}

/**
 * Delete session- and attempt-scoped participant records for one activity.
 *
 * Questions and assignment definitions remain intact so the same primitive can
 * be used by activity deletion, course reset and the Privacy API. Persistent
 * site-wide rewards are deliberately handled by each caller: normal deletion
 * and reset detach their source, while Privacy erasure deletes them.
 *
 * @param int $quizgeistid Activity instance ID.
 * @return void
 */
function quizgeist_purge_instance_userdata(int $quizgeistid): void {
    global $DB;

    $params = ['quizgeistid' => $quizgeistid];
    // F13: Ein Buehnen-Bericht verweist auf eine Antwort und eine Frage. Er
    // geht deshalb VOR den Antworten — sonst bliebe nach einer
    // Aktivitaetsloeschung eine personenbezogene Zeile mit einem ins Leere
    // zeigenden Verweis zurueck. Der Fund gehoert Codex (C6-Bericht): der
    // Kursreset allein haette diese Luecke nicht geschlossen, weil eine
    // Aktivitaetsloeschung nicht ueber den Reset laeuft.
    $DB->delete_records('quizgeist_stage_reports', $params);
    // Clip rows reference answers, so remove their files and rows before any
    // answer deletion below. Curriculum references deliberately stay: they
    // are shared course content, not participant data.
    $clips = $DB->get_records(
        'quizgeist_clips',
        $params,
        'id ASC',
        'id,itemid'
    );
    if ($clips) {
        $clipids = array_map(static function ($clip): int {
            return (int)$clip->id;
        }, $clips);
        $context = quizgeist_clip_module_context($quizgeistid);
        if ($context) {
            $fs = get_file_storage();
            foreach ($clips as $clip) {
                $fs->delete_area_files(
                    $context->id,
                    'mod_quizgeist',
                    'clipaudio',
                    (int)$clip->itemid
                );
            }
        }
        $DB->delete_records_list('quizgeist_clips', 'id', $clipids);
    }
    // F11a: Ein Kartenscan ist ein Klassenfoto und damit durch und durch
    // Teilnehmerdatum — Bild UND Zeile gehen. Der Kartensatz selbst bleibt:
    // die gedruckten Boegen liegen weiter in der Schultasche.
    $scans = $DB->get_records(
        'quizgeist_card_scans',
        $params,
        'id ASC',
        'id,itemid'
    );
    if ($scans) {
        $scanids = array_map(static function ($scan): int {
            return (int)$scan->id;
        }, $scans);
        $context = quizgeist_clip_module_context($quizgeistid);
        if ($context) {
            $fs = get_file_storage();
            foreach ($scans as $scan) {
                $fs->delete_area_files(
                    $context->id,
                    'mod_quizgeist',
                    \mod_quizgeist\local\cards\card_limits::FILE_AREA,
                    (int)$scan->itemid
                );
            }
        }
        $DB->delete_records_list('quizgeist_card_scans', 'id', $scanids);
    }
    // Peer-Bewertungen sind Teilnehmerdaten; die Einreichungen selbst bleiben
    // als Kursinhalt erhalten.
    $DB->delete_records_select(
        'quizgeist_workshop_ratings',
        'workshopid IN (
            SELECT id FROM {quizgeist_workshop} WHERE quizgeistid = :quizgeistid
        )',
        $params
    );
    // Remove reconnect/scoreboard snapshots before their session records so a
    // partially interrupted Privacy purge cannot retain duplicated names.
    $DB->set_field('quizgeist_sessions', 'statejson', null, $params);
    $DB->set_field('quizgeist_sessions', 'settingsjson', null, $params);
    $DB->delete_records_select(
        'quizgeist_answers',
        'sessionid IN (SELECT id FROM {quizgeist_sessions} WHERE quizgeistid = :sessionquiz)
         OR attemptid IN (
             SELECT a.id
               FROM {quizgeist_attempts} a
               JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
              WHERE z.quizgeistid = :attemptquiz
         )
         OR questionid IN (SELECT id FROM {quizgeist_questions} WHERE quizgeistid = :questionquiz)',
        [
            'sessionquiz' => $quizgeistid,
            'attemptquiz' => $quizgeistid,
            'questionquiz' => $quizgeistid,
        ]
    );
    $DB->delete_records_select(
        'quizgeist_players',
        'sessionid IN (SELECT id FROM {quizgeist_sessions} WHERE quizgeistid = :quizgeistid)',
        $params
    );
    $DB->delete_records_select(
        'quizgeist_attempt_questions',
        'attemptid IN (
            SELECT a.id
              FROM {quizgeist_attempts} a
              JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
             WHERE z.quizgeistid = :quizgeistid
        )',
        $params
    );
    $DB->delete_records_select(
        'quizgeist_attempts',
        'assignmentid IN (SELECT id FROM {quizgeist_assignments} WHERE quizgeistid = :quizgeistid)',
        $params
    );
    $DB->delete_records_select(
        'quizgeist_assignment_reminders',
        'assignmentid IN (
            SELECT id FROM {quizgeist_assignments} WHERE quizgeistid = :quizgeistid
        )',
        $params
    );
    $DB->delete_records('quizgeist_goals', $params);
    // U2 Wiederholungs-Kern: der SM-2-Lernstand ist Teilnehmerdatum. Fragen
    // und Merkmale bleiben Kursinhalt, der persoenliche Rhythmus geht.
    $DB->delete_records('quizgeist_schedule', $params);
    $DB->delete_records_select(
        'quizgeist_session_questions',
        'sessionid IN (SELECT id FROM {quizgeist_sessions} WHERE quizgeistid = :quizgeistid)',
        $params
    );
    $DB->delete_records('quizgeist_sessions', $params);
    // Completed-attempt result DTOs contain personal answer projections.
    \cache::make('mod_quizgeist', 'liveprojection')->purge();
}

/**
 * Resolve the module context that owns one activity's clip file area.
 *
 * @param int $quizgeistid Activity instance ID.
 * @return \context_module|null Module context, or null when it was removed.
 */
function quizgeist_clip_module_context(int $quizgeistid): ?\context_module {
    global $DB;

    $cmid = $DB->get_field_sql(
        'SELECT cm.id
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.instance = :quizgeistid
            AND m.name = :modulename',
        ['quizgeistid' => $quizgeistid, 'modulename' => 'quizgeist']
    );
    if ($cmid === false) {
        return null;
    }
    $context = \context_module::instance((int)$cmid, IGNORE_MISSING);
    return $context instanceof \context_module ? $context : null;
}

/**
 * Add Quizgeist participant-data options to the Moodle course reset form.
 *
 * @param MoodleQuickForm $mform Course reset form.
 * @return void
 */
function quizgeist_reset_course_form_definition($mform): void {
    $mform->addElement('header', 'quizgeistheader', get_string('modulenameplural', 'mod_quizgeist'));
    $mform->addElement(
        'advcheckbox',
        'reset_quizgeist_userdata',
        get_string('resetuserdata', 'mod_quizgeist')
    );
}

/**
 * Provide safe defaults for Moodle's course reset form.
 *
 * @param stdClass $course Course being reset.
 * @return array<string, int>
 */
function quizgeist_reset_course_form_defaults($course): array {
    return ['reset_quizgeist_userdata' => 1];
}

/**
 * Remove participant records and grades from every Quizgeist in a course.
 *
 * @param stdClass $data Course reset request.
 * @return array<int, array<string, mixed>> Reset status entries.
 */
function quizgeist_reset_userdata($data): array {
    global $DB;

    if (empty($data->reset_quizgeist_userdata)) {
        return [];
    }

    $transaction = \mod_quizgeist\local\transaction_scope::begin();
    try {
        $instances = $DB->get_records('quizgeist', [
            'course' => (int)$data->courseid,
        ]);
        foreach ($instances as $quizgeist) {
            quizgeist_purge_instance_userdata((int)$quizgeist->id);
            // A course reset removes the course link, not a previously earned
            // site-wide avatar/accessory unlock.
            $DB->set_field(
                'quizgeist_rewards',
                'quizgeistid',
                null,
                ['quizgeistid' => (int)$quizgeist->id]
            );
            // U1/E-4: a learner tag suggestion from the question workshop is
            // participant data and goes. An approved assignment is teacher-made
            // course content and stays — only its authorship is released.
            $DB->delete_records('quizgeist_question_tags', [
                'quizgeistid' => (int)$quizgeist->id,
                'status' => 'suggested',
            ]);
            $DB->set_field(
                'quizgeist_question_tags',
                'createdby',
                null,
                ['quizgeistid' => (int)$quizgeist->id]
            );
            // Einreichungen und Kuratierungen bleiben als Kursinhalt; nur
            // personenbezogene Urheberschaften werden beim Reset gelöst.
            $DB->set_field(
                'quizgeist_workshop',
                'authorid',
                null,
                ['quizgeistid' => (int)$quizgeist->id]
            );
            $DB->set_field(
                'quizgeist_workshop',
                'curatorid',
                null,
                ['quizgeistid' => (int)$quizgeist->id]
            );
            // F11a: Der Kartensatz ist Kursinhalt und ueberlebt — die
            // gedruckten Boegen existieren weiter. Was geht, ist die
            // Zuordnung Karte -> Person und die Urheberschaft des Satzes.
            // Die Karten selbst bleiben und werden zu Reservekarten.
            $DB->set_field(
                'quizgeist_cardsets',
                'createdby',
                null,
                ['quizgeistid' => (int)$quizgeist->id]
            );
            $DB->set_field_select(
                'quizgeist_cards',
                'userid',
                null,
                'cardsetid IN (
                    SELECT id FROM {quizgeist_cardsets} WHERE quizgeistid = :quizgeistid
                )',
                ['quizgeistid' => (int)$quizgeist->id]
            );
            // The tag vocabulary itself (quizgeist_tags) is course content,
            // carries no user column and deliberately survives a reset.
            // U2: quizgeist_schedule is cleared inside
            // quizgeist_purge_instance_userdata() above — repetition state is
            // participant data through and through and has nothing to release.
            quizgeist_grade_item_update($quizgeist, 'reset');
        }
        $transaction->allow_commit();
    } catch (\Throwable $exception) {
        $transaction->rollback($exception);
    }

    return [[
        'component' => get_string('modulenameplural', 'mod_quizgeist'),
        'item' => get_string('resetuserdata:success', 'mod_quizgeist'),
        'error' => false,
    ]];
}

/**
 * Create or update this activity's grade item.
 *
 * @param stdClass $quizgeist Activity record.
 * @param mixed $grades Optional grades or Moodle's "reset" sentinel.
 * @return int Grade update status.
 */
function quizgeist_grade_item_update($quizgeist, $grades = null): int {
    global $CFG;

    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname' => clean_param($quizgeist->name, PARAM_NOTAGS),
    ];
    if ((int)$quizgeist->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademin'] = 0;
        $item['grademax'] = (float)$quizgeist->grade;
    } else if ((int)$quizgeist->grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid'] = -(int)$quizgeist->grade;
    } else {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/quizgeist',
        (int)$quizgeist->course,
        'mod',
        'quizgeist',
        (int)$quizgeist->id,
        0,
        $grades,
        $item
    );
}

/**
 * Delete this activity's grade item.
 *
 * @param stdClass $quizgeist Activity record.
 * @return int Grade update status.
 */
function quizgeist_grade_item_delete($quizgeist): int {
    global $CFG;

    require_once($CFG->libdir . '/gradelib.php');
    return grade_update(
        'mod/quizgeist',
        (int)$quizgeist->course,
        'mod',
        'quizgeist',
        (int)$quizgeist->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Calculate grades from completed grade-bearing self-study attempts.
 *
 * Each completed solo/test attempt contributes one normalised percentage.
 * The configured best/last/average method is applied across those attempts,
 * independent of which assignment created them.
 *
 * @param stdClass $quizgeist Activity record.
 * @param int $userid A user ID, or zero for all users.
 * @return array<int, stdClass>
 */
function quizgeist_get_user_grades($quizgeist, $userid = 0): array {
    global $DB;

    if ((int)$quizgeist->grade === 0) {
        return [];
    }

    $summaries = \mod_quizgeist\local\selfstudy\grade_calculator::summaries(
        $quizgeist,
        (int)$userid
    );

    $grades = [];
    foreach ($summaries as $attemptuserid => $summary) {
        $percent = (float)$summary['percent'];
        if ((int)$quizgeist->grade > 0) {
            $rawgrade = max(0.0, min(100.0, $percent)) * (float)$quizgeist->grade / 100;
        } else {
            $scale = $DB->get_record('scale', ['id' => -(int)$quizgeist->grade], 'id,scale');
            if (!$scale) {
                continue;
            }
            $scalecount = count(explode(',', $scale->scale));
            $rawgrade = max(1, min($scalecount, (int)ceil(max(0.0, $percent) * $scalecount / 100)));
        }
        $grades[$attemptuserid] = (object)[
            'userid' => $attemptuserid,
            'rawgrade' => round($rawgrade, 5),
        ];
    }

    return $grades;
}

/**
 * Push calculated grades to the Moodle gradebook.
 *
 * @param stdClass $quizgeist Activity record.
 * @param int $userid A user ID, or zero for all users.
 * @param bool $nullifnone Whether to clear missing grades.
 * @return int Moodle grade update status.
 */
function quizgeist_update_grades($quizgeist, $userid = 0, $nullifnone = true): int {
    if ((int)$quizgeist->grade === 0) {
        return quizgeist_grade_item_update($quizgeist);
    }

    $grades = quizgeist_get_user_grades($quizgeist, $userid);
    if ($userid && !$grades && $nullifnone) {
        // grade_update() requires each array key to equal the contained userid.
        $grades = [
            $userid => (object)['userid' => $userid, 'rawgrade' => null],
        ];
    }
    return quizgeist_grade_item_update($quizgeist, $grades ?: null);
}

/**
 * Report whether Quizgeist uses a Moodle scale.
 *
 * @param int $scaleid Scale ID.
 * @return bool
 */
function quizgeist_scale_used_anywhere($scaleid): bool {
    global $DB;

    return $scaleid > 0 && $DB->record_exists('quizgeist', ['grade' => -$scaleid]);
}

/**
 * Provide cached course-module information.
 *
 * @param stdClass $coursemodule Course-module record.
 * @return cached_cm_info
 */
function quizgeist_get_coursemodule_info($coursemodule): cached_cm_info {
    global $DB;

    $quizgeist = $DB->get_record(
        'quizgeist',
        ['id' => $coursemodule->instance],
        'id,name,intro,introformat,completionparticipate,completionpercent',
        MUST_EXIST
    );
    $info = new cached_cm_info();
    $info->name = $quizgeist->name;
    $info->customdata = [
        'customcompletionrules' => [
            'completionparticipate' => (bool)$quizgeist->completionparticipate,
            'completionpercent' => (int)$quizgeist->completionpercent,
        ],
    ];
    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('quizgeist', $quizgeist, $coursemodule->id, false);
    }
    return $info;
}

/**
 * Serve files stored by Quizgeist.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args Path arguments.
 * @param bool $forcedownload Force download.
 * @param array $options Serving options.
 * @return bool
 */
function quizgeist_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
): bool {
    global $CFG;

    // send_stored_file() lives in lib/filelib.php. Core's pluginfile.php
    // happens to have loaded it before calling us, but this callback must not
    // depend on who calls it (P10-F15).
    require_once($CFG->libdir . '/filelib.php');

    if ($context->contextlevel === CONTEXT_SYSTEM) {
        return quizgeist_pluginfile_system_template(
            $context,
            $filearea,
            $args,
            $forcedownload,
            $options
        );
    }
    if ($context->contextlevel === CONTEXT_MODULE) {
        return quizgeist_pluginfile_activity_media(
            $course,
            $cm,
            $context,
            $filearea,
            $args,
            $forcedownload,
            $options
        );
    }
    return false;
}

/**
 * Authorise and serve school-template media from the system context.
 *
 * @param context $context System context.
 * @param string $filearea File area.
 * @param array $args Path arguments.
 * @param bool $forcedownload Force download.
 * @param array $options Serving options.
 * @return bool
 */
function quizgeist_pluginfile_system_template(
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options
): bool {
    global $DB;

    if ($filearea !== 'templatemedia' || !$args) {
        return false;
    }
    $templatecontext = context_system::instance();
    if ((int)$context->id !== (int)$templatecontext->id) {
        return false;
    }
    $accesscmid = optional_param('cmid', 0, PARAM_INT);
    if ($accesscmid <= 0) {
        return false;
    }
    $accesscm = get_coursemodule_from_id(
        'quizgeist',
        $accesscmid,
        0,
        false,
        IGNORE_MISSING
    );
    if (!$accesscm
            || !$DB->record_exists(
                'quizgeist',
                ['id' => $accesscm->instance]
            )) {
        return false;
    }
    $accesscourse = $DB->get_record(
        'course',
        ['id' => $accesscm->course],
        '*'
    );
    if (!$accesscourse) {
        return false;
    }
    $accesscontext = context_module::instance($accesscm->id);
    require_login($accesscourse, false, $accesscm);
    if (!has_capability('mod/quizgeist:manage', $accesscontext)) {
        return false;
    }

    $itemid = (int)array_shift($args);
    if (!$DB->record_exists('quizgeist_templates', [
        'id' => $itemid,
        'visibility' => 'school',
    ])) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $fs = get_file_storage();
    $file = $fs->get_file(
        $templatecontext->id,
        'mod_quizgeist',
        'templatemedia',
        $itemid,
        $filepath,
        $filename
    );
    if (!$file
            || $file->is_directory()
            || !\mod_quizgeist\local\editor\media_service::
                is_safe_for_delivery($file)) {
        return false;
    }

    \core\session\manager::write_close();
    $options['cacheability'] = 'private';
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
    return false;
}

/**
 * Authorise a question-media path and resolve visit-bound live aliases.
 *
 * @param stdClass $cm Course module.
 * @param context_module $context Module context.
 * @param int $itemid Exact question ID.
 * @param array $args Remaining path arguments.
 * @return array|false Canonical stored-file arguments or false.
 */
function quizgeist_pluginfile_question_media(
    $cm,
    $context,
    int $itemid,
    array $args
): array|false {
    global $DB;

    $question = $DB->get_record(
        'quizgeist_questions',
        ['id' => $itemid, 'quizgeistid' => $cm->instance],
        '*'
    );
    if (!$question) {
        return false;
    }
    $canpreview = has_capability('mod/quizgeist:manage', $context)
        || has_capability('mod/quizgeist:host', $context);
    $isfrozensnapshot = quizgeist_pluginfile_is_frozen_question(
        $question,
        $itemid
    );
    if (($question->status === 'archived' && !$isfrozensnapshot)
            || (!$canpreview
                && $question->status !== 'ready'
                && !$isfrozensnapshot)) {
        return false;
    }

    if (($args[0] ?? null) === 'live') {
        if (count($args) !== 4
                || !in_array(
                    $args[1] ?? null,
                    ['question', 'answer', 'item'],
                    true
                )
                || !is_string($args[2] ?? null)
                || !preg_match('/^h[a-f0-9]{64}$/D', $args[2])
                || !is_string($args[3] ?? null)
                || $args[3] === '') {
            return false;
        }
        $visit = optional_param('visit', '', PARAM_ALPHANUM);
        if (!preg_match('/^[a-f0-9]{32}$/D', $visit)) {
            return false;
        }
        if (!quizgeist_pluginfile_live_alias_access(
            $cm,
            $context,
            $itemid,
            $visit
        )) {
            return false;
        }
        $canonical = \mod_quizgeist\local\live\answer_evaluator::
            canonical_question($question);
        return quizgeist_pluginfile_resolve_live_alias(
            $canonical,
            $args,
            $visit
        );
    }
    if (($args[0] ?? null) === 'answers'
            && !has_capability('mod/quizgeist:manage', $context)) {
        // Players and host-only roles receive only visit-bound aliases. Direct
        // answer paths contain canonical editor IDs and are therefore private.
        return false;
    }
    if (($args[0] ?? null) === 'question'
            && !$canpreview) {
        // Live players receive only an alias bound to the exact played visit;
        // direct question paths would otherwise allow neighbouring question
        // IDs and common filenames to be enumerated before they are played.
        return false;
    }
    return $args;
}

/**
 * Report whether an archived question is retained by an immutable snapshot.
 */
function quizgeist_pluginfile_is_frozen_question(
    stdClass $question,
    int $itemid
): bool {
    global $DB;

    return $question->status === 'archived'
        && (
            $DB->record_exists('quizgeist_session_questions', [
                'questionid' => $itemid,
            ])
            || $DB->record_exists('quizgeist_assignment_questions', [
                'questionid' => $itemid,
            ])
            || $DB->record_exists('quizgeist_attempt_questions', [
                'questionid' => $itemid,
            ])
        );
}

/**
 * Check that the current user owns or may inspect an exact played visit.
 */
function quizgeist_pluginfile_live_alias_access(
    $cm,
    $context,
    int $itemid,
    string $visit
): bool {
    global $DB, $USER;

    $sessionids = $DB->get_fieldset_sql(
        'SELECT sq.sessionid
           FROM {quizgeist_session_questions} sq
           JOIN {quizgeist_sessions} s ON s.id = sq.sessionid
          WHERE sq.questionid = :questionid
            AND (sq.visit = :currentvisit
                 OR sq.resolvedvisit = :resolvedvisit)
            AND s.quizgeistid = :quizgeistid',
        [
            'questionid' => $itemid,
            'currentvisit' => $visit,
            'resolvedvisit' => $visit,
            'quizgeistid' => (int)$cm->instance,
        ]
    );
    $attemptvisitexists = $DB->record_exists_sql(
        'SELECT 1
           FROM {quizgeist_attempt_questions} aq
           JOIN {quizgeist_attempts} a ON a.id = aq.attemptid
           JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
          WHERE aq.questionid = :questionid
            AND aq.visit = :visit
            AND z.quizgeistid = :quizgeistid',
        [
            'questionid' => $itemid,
            'visit' => $visit,
            'quizgeistid' => (int)$cm->instance,
        ]
    );
    $selfstudyowner = $DB->record_exists_sql(
        'SELECT 1
           FROM {quizgeist_attempt_questions} aq
           JOIN {quizgeist_attempts} a ON a.id = aq.attemptid
           JOIN {quizgeist_assignments} z ON z.id = a.assignmentid
          WHERE aq.questionid = :questionid
            AND aq.visit = :visit
            AND a.userid = :userid
            AND z.quizgeistid = :quizgeistid',
        [
            'questionid' => $itemid,
            'visit' => $visit,
            'userid' => (int)$USER->id,
            'quizgeistid' => (int)$cm->instance,
        ]
    );
    $staffaccess = has_capability('mod/quizgeist:manage', $context)
        || has_capability('mod/quizgeist:host', $context)
        || has_capability('mod/quizgeist:viewreports', $context);
    if (($staffaccess && ($sessionids || $attemptvisitexists))
            || $selfstudyowner) {
        return true;
    }
    foreach ($sessionids as $sessionid) {
        if ($DB->record_exists('quizgeist_players', [
            'sessionid' => (int)$sessionid,
            'userid' => (int)$USER->id,
        ])) {
            return true;
        }
    }
    return false;
}

/**
 * Resolve an opaque question, answer or puzzle-item handle.
 *
 * @return array|false Canonical stored-file arguments or false.
 */
function quizgeist_pluginfile_resolve_live_alias(
    array $canonical,
    array $args,
    string $visit
): array|false {
    $kind = (string)$args[1];
    $type = (string)$canonical['qtype'];
    if ($kind === 'question') {
        $canonicalpath = $canonical['options']['media'] ?? null;
        if (!is_string($canonicalpath)
                || !str_starts_with($canonicalpath, '/question/')
                || !hash_equals(
                    basename($canonicalpath),
                    (string)$args[3]
                )) {
            return false;
        }
        $available = ['media'];
        $namespace = 'question:media';
    } else if ($kind === 'answer'
            && in_array($type, ['quiz', 'poll'], true)) {
        $available = array_values(array_column(
            $canonical['options']['answers'],
            'id'
        ));
        $namespace = $type . ':choices';
    } else if ($kind === 'item' && $type === 'puzzle') {
        $available = array_values(array_column(
            $canonical['options']['items'],
            'id'
        ));
        $namespace = 'puzzle:items';
    } else {
        return false;
    }
    try {
        $internalid = \mod_quizgeist\local\live\qtype\strategy_support::
            resolve_opaque_ids(
                [(string)$args[2]],
                $available,
                $visit,
                $namespace
            )[0];
    } catch (\invalid_parameter_exception $exception) {
        return false;
    }
    return $kind === 'question'
        ? ['question', (string)$args[3]]
        : ['answers', $internalid, (string)$args[3]];
}

/**
 * Authorise and serve activity or question media from a module context.
 *
 * @param stdClass $course Course record.
 * @param stdClass $cm Course module.
 * @param context_module $context Module context.
 * @param string $filearea File area.
 * @param array $args Path arguments.
 * @param bool $forcedownload Force download.
 * @param array $options Serving options.
 * @return bool
 */
function quizgeist_pluginfile_activity_media(
    $course,
    $cm,
    $context,
    $filearea,
    $args,
    $forcedownload,
    array $options
): bool {
    require_login($course, false, $cm);
    if (!has_capability('mod/quizgeist:play', $context)
            && !has_capability('mod/quizgeist:manage', $context)
            && !has_capability('mod/quizgeist:host', $context)
            && !has_capability('mod/quizgeist:viewreports', $context)) {
        return false;
    }
    // F11a: the `cardscan` area is DELIBERATELY absent from this allowlist and
    // is refused before anything else, by name. A class photograph must not be
    // retrievable through any URL of this plugin — not even by a teacher, and
    // not even while it still exists. The confirmation screen shows the local
    // ObjectURL of the picture the camera just produced; the server never
    // serves it back. Removing this guard would make the retention deadline of
    // E-10 decorative, so tests/card_scan_no_pluginfile_test.php nails it down.
    if ($filearea === \mod_quizgeist\local\cards\card_limits::FILE_AREA) {
        return false;
    }
    $allowedareas = ['intro', 'background', 'logo', 'questionmedia'];
    if (!in_array($filearea, $allowedareas, true) || !$args) {
        return false;
    }
    $itemid = (int)array_shift($args);
    if (in_array($filearea, ['intro', 'background', 'logo'], true)
            && $itemid !== 0) {
        return false;
    }
    if ($filearea === 'questionmedia') {
        $args = quizgeist_pluginfile_question_media(
            $cm,
            $context,
            $itemid,
            $args
        );
        if ($args === false) {
            return false;
        }
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $fs = get_file_storage();
    $file = $fs->get_file(
        $context->id,
        'mod_quizgeist',
        $filearea,
        $itemid,
        $filepath,
        $filename
    );
    if (!$file || $file->is_directory()) {
        return false;
    }
    $safeinline = \mod_quizgeist\local\editor\media_service::
        is_safe_for_delivery($file);
    if (!$safeinline && $filearea !== 'intro') {
        return false;
    }
    // Intro attachments may include normal Moodle document formats. Keep those
    // available, but never render a type outside the local-media allowlist inline
    // from the Moodle origin.
    if (!$safeinline) {
        $forcedownload = true;
    }

    $options['cacheability'] = 'private';
    \core\session\manager::write_close();
    send_stored_file($file, DAYSECS, 0, $forcedownload, $options);
    return false;
}
