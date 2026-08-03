<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Restore structure for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @category   backup
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore the complete activity structure and remap every foreign identifier.
 */
class restore_quizgeist_activity_structure_step extends restore_activity_structure_step {

    /**
     * Old lineage identifiers retained until every question mapping exists.
     *
     * @var array<int, array{oldid: int, oldrootid: int}>
     */
    private array $restoredquestionlineages = [];

    /**
     * Old P3 session JSON retained until every question mapping exists.
     *
     * @var array<int, array{json:string|null,currentquestionid:int|null}>
     */
    private array $restoredsessionstates = [];

    /**
     * Restored attempts whose position-based state is validated after children.
     *
     * @var int[]
     */
    private array $restoredattemptids = [];

    /**
     * Tag assignments held back until every question root is final.
     *
     * @var array<int, array{oldrootid:int,oldtagid:int,weight:int,status:string,createdby:?int,timecreated:int}>
     */
    private array $pendingquestiontags = [];

    /** @var array<int,array> Repetition rows held back until roots are final. */
    private array $pendingschedules = [];

    /** @var array<int,array> Misconception labels held back until roots are final. */
    private array $pendingmisconceptions = [];

    /** @var array<int,array> Workshop submissions held back until roots are final. */
    private array $pendingworkshops = [];

    /** @var array<int,array> Peer ratings held back until workshops are written. */
    private array $pendingratings = [];

    /** @var array<int,array> Curriculum anchors held back until roots are final. */
    private array $pendingcurriculumrefs = [];

    /** @var array<int,array> Audio clips held back until answers are mapped. */
    private array $pendingclips = [];

    /** @var array<int,int> Old workshop IDs mapped to restored destination IDs. */
    private array $restoredworkshopmappings = [];

    /** @var int|null Old workshop ID of the currently parsed workshop parent. */
    private ?int $currentoldworkshopid = null;

    /**
     * Define restore paths.
     *
     * @return array
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $paths = [
            new restore_path_element('quizgeist', '/activity/quizgeist'),
            new restore_path_element(
                'quizgeist_import',
                '/activity/quizgeist/imports/import'
            ),
            new restore_path_element(
                'quizgeist_question',
                '/activity/quizgeist/questions/question'
            ),
            new restore_path_element(
                'quizgeist_assignment',
                '/activity/quizgeist/assignments/assignment'
            ),
            new restore_path_element(
                'quizgeist_assignment_question',
                '/activity/quizgeist/assignments/assignment/assignmentquestions/assignmentquestion'
            ),
            // U1 Tagging-Kern: course content, restored regardless of userinfo.
            new restore_path_element(
                'quizgeist_tag',
                '/activity/quizgeist/tags/tag'
            ),
            new restore_path_element(
                'quizgeist_questiontag',
                '/activity/quizgeist/questiontags/questiontag'
            ),
            new restore_path_element(
                'quizgeist_misconception',
                '/activity/quizgeist/misconceptions/misconception'
            ),
            new restore_path_element(
                'quizgeist_curriculumref',
                '/activity/quizgeist/curriculumrefs/curriculumref'
            ),
            new restore_path_element(
                'quizgeist_cardset',
                '/activity/quizgeist/cardsets/cardset'
            ),
        ];

        if ($userinfo) {
            $paths[] = new restore_path_element(
                'quizgeist_card',
                '/activity/quizgeist/cardsets/cardset/cards/card'
            );
            // sessionteamgroups is a backup-only annotation helper. Core has
            // already restored its group mappings before this activity step,
            // so no plugin restore path or duplicate runtime record is needed.
            $paths[] = new restore_path_element(
                'quizgeist_session',
                '/activity/quizgeist/sessions/session'
            );
            $paths[] = new restore_path_element(
                'quizgeist_session_question',
                '/activity/quizgeist/sessions/session/sessionquestions/sessionquestion'
            );
            $paths[] = new restore_path_element(
                'quizgeist_player',
                '/activity/quizgeist/sessions/session/players/player'
            );
            $paths[] = new restore_path_element(
                'quizgeist_liveanswer',
                '/activity/quizgeist/sessions/session/players/player/liveanswers/liveanswer'
            );
            $paths[] = new restore_path_element(
                'quizgeist_sessioninteraction',
                '/activity/quizgeist/sessions/session/sessioninteractions/sessioninteraction'
            );
            $paths[] = new restore_path_element(
                'quizgeist_attempt',
                '/activity/quizgeist/assignments/assignment/attempts/attempt'
            );
            $paths[] = new restore_path_element(
                'quizgeist_attempt_question',
                '/activity/quizgeist/assignments/assignment/attempts/attempt/attemptquestions/attemptquestion'
            );
            $paths[] = new restore_path_element(
                'quizgeist_soloanswer',
                '/activity/quizgeist/assignments/assignment/attempts/attempt/soloanswers/soloanswer'
            );
            $paths[] = new restore_path_element(
                'quizgeist_reminder',
                '/activity/quizgeist/assignments/assignment/reminders/reminder'
            );
            $paths[] = new restore_path_element(
                'quizgeist_goal',
                '/activity/quizgeist/goals/goal'
            );
            $paths[] = new restore_path_element(
                'quizgeist_reward',
                '/activity/quizgeist/rewards/reward'
            );
            $paths[] = new restore_path_element(
                'quizgeist_schedule',
                '/activity/quizgeist/schedules/schedule'
            );
            $paths[] = new restore_path_element(
                'quizgeist_workshop',
                '/activity/quizgeist/workshops/workshop'
            );
            $paths[] = new restore_path_element(
                'quizgeist_workshop_rating',
                '/activity/quizgeist/workshops/workshop/ratings/rating'
            );
            $paths[] = new restore_path_element(
                'quizgeist_clip',
                '/activity/quizgeist/clips/clip'
            );
            $paths[] = new restore_path_element(
                'quizgeist_stagereport',
                '/activity/quizgeist/stagereports/stagereport'
            );
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity record.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->intro = $data->intro ?? '';
        $data->introformat = $data->introformat ?? FORMAT_HTML;
        $themes = ['hell', 'dunkel', 'weltraum', 'ozean', 'retro-arcade', 'jahreszeiten'];
        $seasons = ['herbst', 'winter', 'fruehling', 'sommer'];
        $modes = ['classic', 'accuracy', 'team', 'security'];
        $grademethods = ['best', 'last', 'average'];
        $data->theme = in_array($data->theme ?? '', $themes, true) ? $data->theme : 'hell';
        $data->season = in_array($data->season ?? '', $seasons, true) ? $data->season : 'herbst';
        $data->allowbacktrack = property_exists($data, 'allowbacktrack')
            ? (empty($data->allowbacktrack) ? 0 : 1)
            : 1;
        $data->defaultmode = in_array($data->defaultmode ?? '', $modes, true)
            ? $data->defaultmode
            : 'classic';
        $data->grademethod = in_array($data->grademethod ?? '', $grademethods, true)
            ? $data->grademethod
            : 'best';
        $data->grade = (int)($data->grade ?? 0);
        if ($data->grade < 0) {
            $mappedscaleid = (int)$this->get_mappingid('scale', -$data->grade, 0);
            $data->grade = $mappedscaleid > 0 ? -$mappedscaleid : 0;
        }
        // F1/F2/F8 activity settings. An older backup simply has none of
        // them; the works defaults then apply.
        $data->pacemode = \mod_quizgeist\local\live\scoring_context
            ::normalise_pace($data->pacemode ?? null);
        $data->leaderboard = \mod_quizgeist\local\live\leaderboard_policy
            ::normalise($data->leaderboard ?? null);
        foreach ([
            'timervisible' => 1,
            'soundenabled' => 1,
            'friendlynew' => 1,
            'reasonstep' => 0,
        ] as $switch => $fallback) {
            $data->{$switch} = property_exists($data, $switch)
                ? (empty($data->{$switch}) ? 0 : 1)
                : $fallback;
        }
        $data->explanationpolicy =
            \mod_quizgeist\local\live\explanation_policy::normalise(
                $data->explanationpolicy ?? null
            );
        $data->completionparticipate = $data->completionparticipate ?? 0;
        $data->completionpercent = $data->completionpercent ?? 0;
        $data->timecreated = !empty($data->timecreated) ? $data->timecreated : time();
        $data->timemodified = !empty($data->timemodified) ? $data->timemodified : time();
        unset($data->gradeenabled, $data->settingsjson);

        $newitemid = $DB->insert_record('quizgeist', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore an external-import marker before its questions.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_import($data) {
        global $DB;

        $data = (object)$data;
        $oldid = (int)$data->id;
        $data->quizgeistid = $this->get_new_parentid('quizgeist');
        $data->courseid = $this->get_courseid();
        $data->sourceformat = ($data->sourceformat ?? '') === 'kahoot'
            ? 'kahoot'
            : 'external';
        $data->sourceuuid = is_string($data->sourceuuid ?? null)
            && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $data->sourceuuid)
            ? $data->sourceuuid
            : 'restore-' . $oldid;
        $data->sourcename = clean_param($data->sourcename ?? '', PARAM_TEXT);
        if ($data->sourcename === '') {
            $data->sourcename = get_string('pluginname', 'mod_quizgeist');
        }
        $data->sourcehash = is_string($data->sourcehash ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $data->sourcehash)
            ? $data->sourcehash
            : hash('sha256', $data->sourceuuid);
        $originalsourceformat = $data->sourceformat;
        $originalsourceuuid = $data->sourceuuid;
        $data->status = in_array($data->status ?? '', ['complete', 'failed'], true)
            ? $data->status
            : 'failed';
        foreach (['questioncount', 'adaptedcount', 'skippedcount', 'mediacount'] as $field) {
            $data->{$field} = max(0, (int)($data->{$field} ?? 0));
        }
        $report = json_decode((string)($data->reportjson ?? ''), true);
        $data->reportjson = is_array($report)
            ? json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
        $data->timecreated = max(0, (int)($data->timecreated ?? time()));
        $data->timemodified = max($data->timecreated, (int)($data->timemodified ?? 0));

        // A normal Kahoot import is idempotent across its whole course. Moodle
        // activity duplication must nevertheless retain a complete, playable
        // copy. Serialise against both regular imports and concurrent restores,
        // then re-key only the restored provenance when the original course
        // identity is already occupied. The copied questions remain owned by
        // this restored activity; a later Kahoot retry still resolves exactly
        // one canonical kahoot/UUID marker in the course.
        $lockname = $data->sourceformat === 'kahoot'
            ? 'kahoot:' . hash(
                'sha256',
                $data->courseid . ':' . $data->sourceuuid
            )
            : 'restore-origin:' . hash(
                'sha256',
                $data->courseid . ':' . $data->sourceformat . ':' . $data->sourceuuid
            );
        $factory = \core\lock\lock_config::get_lock_factory('mod_quizgeist');
        $lock = $factory->get_lock($lockname, 120);
        if (!$lock) {
            throw new \moodle_exception('error:importlocked', 'mod_quizgeist');
        }
        try {
            $existing = $DB->get_records(
                'quizgeist_imports',
                [
                    'courseid' => (int)$data->courseid,
                    'sourceformat' => (string)$data->sourceformat,
                    'sourceuuid' => (string)$data->sourceuuid,
                ],
                'id ASC',
                'id',
                0,
                1
            );
            if ($existing) {
                $data->sourceformat = 'external';
                $data->sourceuuid = $this->restored_copy_sourceuuid(
                    (int)$data->courseid,
                    $originalsourceformat,
                    $originalsourceuuid,
                    (int)$data->quizgeistid
                );
                if (is_array($report)) {
                    $report['source'] = is_array($report['source'] ?? null)
                        ? $report['source']
                        : [];
                    $report['source']['uuid'] = $data->sourceuuid;
                    $report['source']['format'] = 'external';
                    $report['source']['restoredFrom'] = [
                        'format' => $originalsourceformat,
                        'uuid' => $originalsourceuuid,
                    ];
                    $report['restoredCopy'] = true;
                    $data->reportjson = json_encode(
                        $report,
                        JSON_UNESCAPED_SLASHES
                            | JSON_UNESCAPED_UNICODE
                            | JSON_THROW_ON_ERROR
                    );
                }
                $this->log(
                    'Re-keyed an already present external source while restoring '
                        . 'a complete Quizgeist activity copy.',
                    backup::LOG_INFO
                );
            }
            $newitemid = $DB->insert_record('quizgeist_imports', $data);
        } finally {
            $lock->release();
        }
        $this->set_mapping('quizgeist_import', $oldid, $newitemid, true);
    }

    /**
     * Restore a question and retain a mapping for answer/session/file references.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_question($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) $data->id;
        $oldrootid = is_numeric($data->rootid ?? null) && (int) $data->rootid > 0
            ? (int) $data->rootid
            : $oldid;
        $data->quizgeistid = $this->get_new_parentid('quizgeist');
        $data->importid = self::mapped_nullable_id(
            $this,
            'quizgeist_import',
            $data->importid ?? null
        );
        // The destination root cannot be resolved reliably until every
        // question mapping exists; after_execute() completes this field.
        $data->rootid = 0;
        $data->version = is_numeric($data->version ?? null)
            ? max(1, (int) $data->version)
            : 1;
        $data->createdby = self::mapped_nullable_id($this, 'user', $data->createdby ?? null);
        $data->questionformat = $data->questionformat ?? FORMAT_HTML;
        $rawtimelimit = is_numeric($data->timelimit ?? null)
            ? (int)$data->timelimit
            : 20;
        $data->timecreated = is_numeric($data->timecreated ?? null)
                && (int) $data->timecreated > 0
            ? (int) $data->timecreated
            : time();
        $data->timemodified = is_numeric($data->timemodified ?? null)
                && (int) $data->timemodified > 0
            ? (int) $data->timemodified
            : $data->timecreated;

        $qtype = is_scalar($data->qtype ?? null) ? (string) $data->qtype : '';
        $pointmode = is_scalar($data->pointmode ?? null) ? (string) $data->pointmode : '';
        $status = is_scalar($data->status ?? null) ? (string) $data->status : '';
        // media_pending is a process-local crash marker, never portable quiz
        // content. A restore makes it an explicit editable/non-playable draft.
        if ($status === 'media_pending') {
            $status = 'draft';
        }
        $validqtype = in_array(
            $qtype,
            \mod_quizgeist\local\editor\question_schema::QTYPES,
            true
        );
        $validpointmode = in_array(
            $pointmode,
            \mod_quizgeist\local\editor\question_schema::POINTMODES,
            true
        );
        $validstatus = in_array($status, ['draft', 'ready', 'archived'], true);

        if (!$validqtype) {
            // Preserve the row as an editable, ungraded draft instead of
            // letting one future/foreign type make the whole editor fail.
            $data->qtype = 'open';
            $data->pointmode = 'none';
            $data->status = 'draft';
        } else {
            $data->qtype = $qtype;
            $data->pointmode = $validpointmode
                ? $pointmode
                : \mod_quizgeist\local\editor\question_schema::defaults($qtype)['pointmode'];
            $data->status = $validstatus && $validpointmode ? $status : 'draft';
        }
        $data->timelimit = $data->qtype === 'slide'
            ? 0
            : max(5, min(240, $rawtimelimit));

        $newitemid = $DB->insert_record('quizgeist_questions', $data);
        $this->set_mapping('quizgeist_question', $oldid, $newitemid, true);
        $this->restoredquestionlineages[(int) $newitemid] = [
            'oldid' => $oldid,
            'oldrootid' => $oldrootid,
        ];
    }

    /**
     * Restore one tag, merging instead of duplicating.
     *
     * The merge identity is (scope, scopeid, kind, tagkey). A course- or
     * site-wide vocabulary must not multiply with every restore, so an
     * already present identity is mapped onto rather than inserted again.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_tag($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) $data->id;
        $scope = in_array(
            $data->scope ?? '',
            \mod_quizgeist\local\tagging\tag_schema::SCOPES,
            true
        ) ? (string) $data->scope : 'activity';
        $kind = in_array(
            $data->kind ?? '',
            \mod_quizgeist\local\tagging\tag_schema::KINDS,
            true
        ) ? (string) $data->kind : 'topic';
        // The owner is never taken from the backup: an activity tag belongs
        // to THIS restored activity, a course tag to THIS course.
        $scopeid = match ($scope) {
            'activity' => (int) $this->get_new_parentid('quizgeist'),
            'course' => (int) $this->get_courseid(),
            default => 0,
        };
        $tagkey = is_string($data->tagkey ?? null)
            && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $data->tagkey)
            ? (string) $data->tagkey
            : 'restore-' . $oldid;
        $label = clean_param((string) ($data->label ?? ''), PARAM_TEXT);
        if ($label === '') {
            $label = $tagkey;
        }
        $existing = $DB->get_record('quizgeist_tags', [
            'scope' => $scope,
            'scopeid' => $scopeid,
            'kind' => $kind,
            'tagkey' => $tagkey,
        ]);
        if ($existing !== false) {
            $this->set_mapping('quizgeist_tag', $oldid, (int) $existing->id, false);
            return;
        }
        $now = time();
        $newitemid = $DB->insert_record('quizgeist_tags', (object) [
            'scope' => $scope,
            'scopeid' => $scopeid,
            'kind' => $kind,
            'tagkey' => $tagkey,
            'label' => \core_text::substr($label, 0, 255),
            'colorkey' => is_string($data->colorkey ?? null)
                && preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/D', $data->colorkey)
                ? (string) $data->colorkey
                : null,
            'externalref' => is_string($data->externalref ?? null)
                && $data->externalref !== ''
                ? \core_text::substr(
                    clean_param($data->externalref, PARAM_TEXT),
                    0,
                    255
                )
                : null,
            'sortorder' => max(0, min(100000, (int) ($data->sortorder ?? 0))),
            'timecreated' => max(0, (int) ($data->timecreated ?? $now)) ?: $now,
            'timemodified' => $now,
        ]);
        $this->set_mapping('quizgeist_tag', $oldid, (int) $newitemid, false);
    }

    /**
     * Hold back one tag assignment until every question root is final.
     *
     * The destination root cannot be resolved before remap_question_roots()
     * has run: a lineage whose root did not survive starts a fresh version-one
     * lineage of its own.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_questiontag($data) {
        $data = (object) $data;
        $oldrootid = (int) ($data->rootid ?? 0);
        $oldtagid = (int) ($data->tagid ?? 0);
        if ($oldrootid <= 0 || $oldtagid <= 0) {
            return;
        }
        $status = in_array(
            $data->status ?? '',
            \mod_quizgeist\local\tagging\tag_schema::ASSIGNMENT_STATUSES,
            true
        ) ? (string) $data->status : 'approved';
        $this->pendingquestiontags[] = [
            'oldrootid' => $oldrootid,
            'oldtagid' => $oldtagid,
            'weight' => max(0, min(100, (int) ($data->weight ?? 100))),
            'status' => $status,
            'createdby' => self::mapped_nullable_id(
                $this,
                'user',
                $data->createdby ?? null
            ),
            'timecreated' => max(0, (int) ($data->timecreated ?? 0)),
        ];
    }

    /**
     * Hold back one misconception label until every question root is final.
     *
     * Misconceptions are course content, so they are restored independently
     * of userinfo. Their destination root is read from the restored question
     * after remap_question_roots(), never guessed from the backup ID.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_misconception($data) {
        $data = (object) $data;
        $oldrootid = (int) ($data->rootid ?? 0);
        $answerkey = is_scalar($data->answerkey ?? null)
            ? \core_text::substr((string) $data->answerkey, 0, 32)
            : '';
        if ($oldrootid <= 0 || $answerkey === '') {
            return;
        }
        $label = clean_param((string) ($data->label ?? ''), PARAM_TEXT);
        $hint = clean_param((string) ($data->hint ?? ''), PARAM_TEXT);
        $this->pendingmisconceptions[] = [
            'oldid' => (int) ($data->id ?? 0),
            'oldrootid' => $oldrootid,
            'answerkey' => $answerkey,
            'label' => \core_text::substr($label, 0, 255),
            'hint' => $hint === '' ? null : $hint,
            'timecreated' => max(0, (int) ($data->timecreated ?? 0)),
            'timemodified' => max(0, (int) ($data->timemodified ?? 0)),
        ];
    }

    /**
     * Hold back one curriculum anchor until every question root is final.
     *
     * The destination root is resolved only after remap_question_roots(); a
     * missing or foreign root therefore drops the anchor rather than storing
     * zero or attaching it to an unrelated question.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_curriculumref($data) {
        $data = (object) $data;
        $oldrootid = (int) ($data->rootid ?? 0);
        if ($oldrootid <= 0) {
            return;
        }
        $subject = clean_param((string) ($data->subject ?? ''), PARAM_TEXT);
        $variant = clean_param((string) ($data->variant ?? ''), PARAM_TEXT);
        $learningarea = clean_param(
            (string) ($data->learningarea ?? ''),
            PARAM_TEXT
        );
        $competency = clean_param((string) ($data->competency ?? ''), PARAM_TEXT);
        $citationurl = trim((string) ($data->citationurl ?? ''));
        $citationurl = preg_match('#^https?://#i', $citationurl)
            ? clean_param($citationurl, PARAM_URL)
            : '';
        $this->pendingcurriculumrefs[] = [
            'oldid' => (int) ($data->id ?? 0),
            'oldrootid' => $oldrootid,
            'subject' => \core_text::substr($subject, 0, 100),
            'grade' => max(0, min(13, (int) ($data->grade ?? 0))),
            'variant' => \core_text::substr($variant, 0, 100),
            'learningarea' => \core_text::substr($learningarea, 0, 255),
            'competency' => \core_text::substr(
                $competency,
                0,
                \mod_quizgeist\local\tagging\curriculum_repository::MAX_COMPETENCY
            ),
            'citationurl' => \core_text::substr($citationurl, 0, 255),
            'chunkid' => max(0, (int) ($data->chunkid ?? 0)),
            'timecreated' => max(0, (int) ($data->timecreated ?? 0)),
        ];
    }

    /**
     * Restore one printable answer-card set.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_cardset($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) ($data->id ?? 0);
        $data->quizgeistid = $this->get_new_parentid('quizgeist');
        $data->createdby = self::mapped_nullable_id(
            $this,
            'user',
            $data->createdby ?? null
        );
        if ($oldid <= 0 || empty($data->quizgeistid)) {
            return;
        }
        $newitemid = $DB->insert_record('quizgeist_cardsets', $data);
        $this->set_mapping('quizgeist_cardset', $oldid, $newitemid);
    }

    /**
     * Restore one printed answer card.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_card($data) {
        global $DB;

        $data = (object) $data;
        $oldid = (int) ($data->id ?? 0);
        $data->cardsetid = $this->get_new_parentid('quizgeist_cardset');
        // Reserve cards deliberately retain a null holder when the user was
        // anonymised or no destination user mapping exists.
        $data->userid = self::mapped_nullable_id(
            $this,
            'user',
            $data->userid ?? null
        );
        if ($oldid <= 0 || empty($data->cardsetid)) {
            return;
        }
        // cardcode is the printed identifier and must remain byte-for-byte
        // unchanged during restore.
        $newitemid = $DB->insert_record('quizgeist_cards', $data);
        $this->set_mapping('quizgeist_card', $oldid, $newitemid);
    }

    /**
     * Restore a live session.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_session($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $oldstatejson = isset($data->statejson) && is_string($data->statejson)
            ? $data->statejson
            : null;
        $data->quizgeistid = $this->get_new_parentid('quizgeist');
        $data->hostuserid = self::mapped_nullable_id($this, 'user', $data->hostuserid ?? null);
        $data->currentquestionid = self::mapped_nullable_id(
            $this,
            'quizgeist_question',
            $data->currentquestionid ?? null
        );
        // Concrete question IDs embedded in statejson need the same mapping as
        // direct foreign keys. Delay that rewrite until after all question
        // elements have been processed.
        $data->statejson = null;
        $data->mode = in_array(
            $data->mode ?? '',
            ['classic', 'accuracy', 'team', 'security'],
            true
        ) ? $data->mode : 'classic';
        try {
            $settings = \mod_quizgeist\local\live\session_settings::decode(
                isset($data->settingsjson) && is_string($data->settingsjson)
                    ? $data->settingsjson
                    : null
            );
            if ($data->mode === 'team') {
                if ($settings['team'] === null) {
                    throw new \invalid_parameter_exception(
                        'A restored team session has no team settings.'
                    );
                }
                $settings = \mod_quizgeist\local\live\session_settings::
                    remap_group_ids(
                        $settings,
                        fn(int $oldgroupid): int => (int)$this->get_mappingid(
                            'group',
                            $oldgroupid,
                            0
                        )
                    );
            } else {
                $settings['team'] = null;
            }
            if ($data->mode !== 'security') {
                $settings['blockedNames'] = [];
            }
            $data->settingsjson = \mod_quizgeist\local\live\session_settings::
                encode($settings);
        } catch (\Throwable $exception) {
            // A restored runtime session is always terminal. If embedded group
            // mappings or legacy settings are unusable, fail closed to a
            // harmless classic projection instead of retaining foreign IDs.
            $data->mode = 'classic';
            $data->settingsjson = \mod_quizgeist\local\live\session_settings::
                encode([
                    'schemaVersion' => 3,
                    'nameMode' => 'real',
                    'team' => null,
                    'blockedNames' => [],
                ]);
        }

        // Join codes are runtime locators and must never collide with an active
        // or historical session in the destination site.
        $data->joincode = null;
        if (!in_array($data->status ?? '', ['ended', 'aborted'], true)) {
            $data->status = 'ended';
            $data->timeended = !empty($data->timeended) ? $data->timeended : time();
        }

        $newitemid = $DB->insert_record('quizgeist_sessions', $data);
        $this->set_mapping('quizgeist_session', $oldid, $newitemid);
        $this->restoredsessionstates[(int)$newitemid] = [
            'json' => $oldstatejson,
            'currentquestionid' => $data->currentquestionid === null
                ? null
                : (int)$data->currentquestionid,
        ];
    }

    /**
     * Restore one frozen session-position row.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_session_question($data) {
        global $DB;

        $data = (object)$data;
        $data->sessionid = $this->get_new_parentid('quizgeist_session');
        $data->questionid = $this->get_mappingid(
            'quizgeist_question',
            $data->questionid ?? 0,
            0
        );
        if (empty($data->sessionid) || empty($data->questionid)) {
            return;
        }
        $data->sortindex = is_numeric($data->sortindex ?? null)
            ? max(0, (int)$data->sortindex)
            : 0;
        $data->visit = self::visit_token($data->visit ?? null);
        $data->resolvedvisit = self::visit_token(
            $data->resolvedvisit ?? null
        );
        $data->visitstate = in_array(
            $data->visitstate ?? '',
            ['pending', 'active', 'revealed', 'skipped'],
            true
        ) ? (string)$data->visitstate : 'pending';
        $data->stage = is_string($data->stage ?? null)
                && preg_match('/^[a-z][a-z0-9_-]{0,15}$/D', $data->stage)
            ? (string)$data->stage
            : 'answer';
        $DB->insert_record('quizgeist_session_questions', $data);
    }

    /**
     * Restore a live player.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_player($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->sessionid = $this->get_new_parentid('quizgeist_session');
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        if (empty($data->sessionid) || empty($data->userid)) {
            return;
        }
        $data->groupid = self::mapped_nullable_id($this, 'group', $data->groupid ?? null);

        $newitemid = $DB->insert_record('quizgeist_players', $data);
        $this->set_mapping('quizgeist_player', $oldid, $newitemid);
    }

    /**
     * Restore an append-only live answer.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_liveanswer($data) {
        $this->restore_answer($data, true);
    }

    /**
     * Restore a host-owned append-only interaction row.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_sessioninteraction($data) {
        $this->restore_answer($data, true, true);
    }

    /**
     * Hold back one audio clip until all answer rows have their destination IDs.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_clip($data) {
        $data = (object) $data;
        $oldid = (int) ($data->id ?? 0);
        $userid = (int) $this->get_mappingid('user', $data->userid ?? 0, 0);
        if ($oldid <= 0 || $userid <= 0) {
            return;
        }
        $purpose = (string) ($data->purpose ?? 'answer');
        if (!\mod_quizgeist\local\media\clip_limits::is_purpose($purpose)) {
            $purpose = 'answer';
        }
        $state = (string) ($data->transcriptstate ?? 'none');
        if (!in_array(
            $state,
            \mod_quizgeist\local\media\clip_limits::STATES,
            true
        )) {
            $state = 'none';
        }
        $transcriptcode = ($data->transcriptcode ?? null) === null
            ? null
            : \core_text::substr((string) $data->transcriptcode, 0, 32);
        if ($state === 'pending') {
            $state = 'failed';
            $transcriptcode = 'restored_pending';
        }
        $transcript = ($data->transcript ?? null) === null
            ? null
            : \core_text::substr(
                clean_param((string) $data->transcript, PARAM_TEXT),
                0,
                \mod_quizgeist\local\media\clip_limits::MAX_TRANSCRIPT_CHARS
            );
        $this->pendingclips[] = [
            'oldid' => $oldid,
            'userid' => $userid,
            'oldanswerid' => empty($data->answerid)
                ? null
                : (int) $data->answerid,
            'purpose' => $purpose,
            'itemid' => max(0, (int) ($data->itemid ?? 0)),
            'durationms' => max(0, (int) ($data->durationms ?? 0)),
            'bytes' => max(0, (int) ($data->bytes ?? 0)),
            'language' => \mod_quizgeist\local\media\clip_service::normalise_language(
                (string) ($data->language ?? 'de')
            ),
            'transcript' => $transcript,
            'transcriptstate' => $state,
            'transcriptcode' => $transcriptcode,
            'audiodeleted' => max(0, (int) ($data->audiodeleted ?? 0)),
            'timecreated' => max(0, (int) ($data->timecreated ?? 0)),
            'timemodified' => max(0, (int) ($data->timemodified ?? 0)),
        ];
    }

    /**
     * Restore one stage-check report.
     *
     * Reports without a mapped user or question are discarded rather than
     * being attached to an unrelated destination record. An answer reference
     * is optional and remains null when its mapping is unavailable.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_stagereport($data) {
        global $DB;

        $data = (object)$data;
        $data->quizgeistid = $this->task->get_activityid();
        $data->userid = $this->get_mappingid('user', $data->userid ?? 0, 0);
        $data->questionid = $this->get_mappingid(
            'quizgeist_question',
            $data->questionid ?? 0,
            0
        );
        if (empty($data->userid) || empty($data->questionid)) {
            return;
        }
        $data->answerid = self::mapped_nullable_id(
            $this,
            'quizgeist_answer',
            $data->answerid ?? null
        );
        $data->durationsecs = (int)($data->durationsecs ?? 0);
        $data->aiused = (int)($data->aiused ?? 0);
        $data->timecreated = (int)($data->timecreated ?? 0);
        // The validated metric and feedback JSON are portable report data;
        // restore carries both strings over without decoding or re-encoding.
        $DB->insert_record('quizgeist_stage_reports', $data);
    }

    /**
     * Restore a self-learning assignment.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_assignment($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->quizgeistid = $this->get_new_parentid('quizgeist');
        $data->createdby = self::mapped_nullable_id($this, 'user', $data->createdby ?? null);
        $data->mode = in_array(
            $data->mode ?? '',
            ['solo', 'practice', 'test', 'flashcards'],
            true
        ) ? (string)$data->mode : 'practice';
        $data->status = in_array(
            $data->status ?? '',
            ['draft', 'open', 'closed', 'archived'],
            true
        ) ? (string)$data->status : 'closed';
        // F3: eine unbekannte Auswahlart wird zu 'fixed'. Das ist die
        // sichere Richtung — eine Zuweisung, die exakte Versionen spielt,
        // kann nie eine fremde Wurzel aufloesen.
        $data->selection = in_array(
            $data->selection ?? '',
            \mod_quizgeist\local\selfstudy\assignment_settings::SELECTIONS,
            true
        ) ? (string)$data->selection : 'fixed';
        $data->timeopen = $this->apply_date_offset($data->timeopen ?? 0);
        $data->timedue = $this->apply_date_offset($data->timedue ?? 0);
        if ((int)$data->timedue > 0
                && (int)$data->timeopen > (int)$data->timedue) {
            $data->status = 'closed';
        }
        $data->settingsjson =
            \mod_quizgeist\local\selfstudy\assignment_settings::encode(
                \mod_quizgeist\local\selfstudy\assignment_settings::decode(
                    $data->settingsjson ?? null,
                    (int)$data->timedue
                )
            );

        $newitemid = $DB->insert_record('quizgeist_assignments', $data);
        $this->set_mapping('quizgeist_assignment', $oldid, $newitemid);
    }

    /**
     * Restore one exact assignment question-version snapshot.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_assignment_question($data) {
        global $DB;

        $data = (object)$data;
        $oldid = (int)$data->id;
        $data->assignmentid = $this->get_new_parentid('quizgeist_assignment');
        $data->questionid = $this->get_mappingid(
            'quizgeist_question',
            $data->questionid ?? 0,
            0
        );
        if (empty($data->assignmentid) || empty($data->questionid)) {
            return;
        }
        $data->sortindex = max(0, (int)($data->sortindex ?? 0));
        // Der Wurzelbezug wird aus der wiederhergestellten Frage zurueck-
        // gelesen, nicht aus dem Backup geraten: eine Abstammung, deren Wurzel
        // den Restore nicht ueberlebt hat, beginnt eine neue Abstammung.
        $restoredquestion = $DB->get_record(
            'quizgeist_questions',
            ['id' => (int)$data->questionid],
            'id, rootid',
            IGNORE_MISSING
        );
        $data->rootid = $restoredquestion === false
            ? (int)$data->questionid
            : ((int)$restoredquestion->rootid > 0
                ? (int)$restoredquestion->rootid
                : (int)$restoredquestion->id);
        $newitemid = $DB->insert_record('quizgeist_assignment_questions', $data);
        $this->set_mapping(
            'quizgeist_assignment_question',
            $oldid,
            $newitemid
        );
    }

    /**
     * Restore a self-learning attempt.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_attempt($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->assignmentid = $this->get_new_parentid('quizgeist_assignment');
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        if (empty($data->assignmentid) || empty($data->userid)) {
            return;
        }
        $restoredassignmentmode = $DB->get_field(
            'quizgeist_assignments',
            'mode',
            ['id' => $data->assignmentid]
        );
        $validattemptmode = in_array(
            $data->mode ?? '',
            ['solo', 'practice', 'test', 'flashcards'],
            true
        ) ? (string)$data->mode : null;
        $data->mode = is_string($restoredassignmentmode)
            && in_array(
                $restoredassignmentmode,
                ['solo', 'practice', 'test', 'flashcards'],
                true
            )
            ? $restoredassignmentmode
            : 'practice';
        $data->status = in_array(
            $data->status ?? '',
            ['inprogress', 'completed', 'abandoned'],
            true
        ) ? (string)$data->status : 'abandoned';
        if ($validattemptmode === null
                || !hash_equals($data->mode, $validattemptmode)) {
            $data->status = 'abandoned';
        }
        $now = time();
        $data->timestarted = self::clamp_restored_timestamp(
            $data->timestarted ?? 0,
            1,
            $now,
            1
        );
        $data->timefinished = $data->status === 'inprogress'
            ? 0
            : self::clamp_restored_timestamp(
                $data->timefinished ?? 0,
                (int)$data->timestarted,
                $now,
                (int)$data->timestarted
            );
        $data->remindedat = self::clamp_restored_timestamp(
            $data->remindedat ?? 0,
            (int)$data->timestarted,
            $now,
            0
        );
        $data->timemodified = self::clamp_restored_timestamp(
            $data->timemodified ?? 0,
            (int)$data->timestarted,
            $now,
            (int)$data->timestarted
        );
        $data->score = max(0, (int)($data->score ?? 0));
        $data->maxscore = max(0, (int)($data->maxscore ?? 0));
        if ($data->score > $data->maxscore) {
            $data->score = $data->maxscore;
        }
        $state = json_decode((string)($data->statejson ?? ''), true);
        $data->statejson = json_encode(
            [
                'currentIndex' => max(
                    0,
                    (int)(is_array($state) ? ($state['currentIndex'] ?? 0) : 0)
                ),
                'streak' => max(
                    0,
                    (int)(is_array($state) ? ($state['streak'] ?? 0) : 0)
                ),
            ],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $flashcards = json_decode((string)($data->flashcardsjson ?? ''), true);
        $data->flashcardsjson = is_array($flashcards)
            ? json_encode(
                $flashcards,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )
            : null;
        $data->stateversion = max(0, (int)($data->stateversion ?? 0));

        $newitemid = $DB->insert_record('quizgeist_attempts', $data);
        $this->set_mapping('quizgeist_attempt', $oldid, $newitemid);
        $this->restoredattemptids[] = (int)$newitemid;
    }

    /**
     * Restore one exact attempt position and its opaque visit token.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_attempt_question($data) {
        global $DB;

        $data = (object)$data;
        $data->attemptid = $this->get_new_parentid('quizgeist_attempt');
        $data->questionid = $this->get_mappingid(
            'quizgeist_question',
            $data->questionid ?? 0,
            0
        );
        if (empty($data->attemptid) || empty($data->questionid)) {
            return;
        }
        $data->sortindex = max(0, (int)($data->sortindex ?? 0));
        $data->visit = self::visit_token($data->visit ?? null)
            ?? bin2hex(random_bytes(16));
        $data->status = in_array(
            $data->status ?? '',
            ['pending', 'draft', 'revealed', 'submitted'],
            true
        ) ? (string)$data->status : 'pending';
        $attemptstarted = (int)$DB->get_field(
            'quizgeist_attempts',
            'timestarted',
            ['id' => (int)$data->attemptid],
            MUST_EXIST
        );
        $now = time();
        $data->timestarted = self::clamp_restored_timestamp(
            $data->timestarted ?? 0,
            $attemptstarted,
            $now,
            $attemptstarted
        );
        $data->timesubmitted = self::clamp_restored_timestamp(
            $data->timesubmitted ?? 0,
            $attemptstarted,
            $now,
            $attemptstarted
        );
        $data->timemodified = self::clamp_restored_timestamp(
            $data->timemodified ?? 0,
            $attemptstarted,
            $now,
            $attemptstarted
        );
        $data->round = max(1, (int)($data->round ?? 1));
        if ($data->status === 'draft') {
            $draft = json_decode((string)($data->answerjson ?? ''), true);
            $data->answerjson = is_array($draft)
                ? json_encode(
                    $draft,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
                : null;
            if ($data->answerjson === null) {
                $data->status = 'pending';
            }
        } else {
            $data->answerjson = null;
        }
        $DB->insert_record('quizgeist_attempt_questions', $data);
    }

    /**
     * Restore an append-only solo answer.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_soloanswer($data) {
        $this->restore_answer($data, false);
    }

    /**
     * Restore a bounded reminder delivery ledger entry.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_reminder($data) {
        global $DB;

        $data = (object)$data;
        $data->assignmentid = $this->get_new_parentid('quizgeist_assignment');
        $data->userid = $this->get_mappingid('user', $data->userid ?? 0, 0);
        if (empty($data->assignmentid) || empty($data->userid)) {
            return;
        }
        $data->timesent = max(0, (int)($data->timesent ?? 0));
        $minimumattempts = $data->timesent > 0 ? 1 : 0;
        $data->attemptcount = min(
            \mod_quizgeist\task\send_assignment_reminders::MAX_DELIVERY_ATTEMPTS,
            max(
                $minimumattempts,
                (int)($data->attemptcount ?? $minimumattempts)
            )
        );
        if (!$DB->record_exists('quizgeist_assignment_reminders', [
            'assignmentid' => $data->assignmentid,
            'userid' => $data->userid,
        ])) {
            $DB->insert_record('quizgeist_assignment_reminders', $data);
        }
    }

    /**
     * Hold back one repetition state until every question root is final.
     *
     * The scheduler hangs on the question ROOT, exactly like a tag assignment.
     * Its destination root can therefore only be resolved after
     * remap_question_roots() has run.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_schedule($data) {
        $data = (object) $data;
        $oldrootid = (int) ($data->rootid ?? 0);
        $userid = (int) $this->get_mappingid('user', $data->userid ?? 0, 0);
        if ($oldrootid <= 0 || $userid <= 0) {
            return;
        }
        $this->pendingschedules[] = [
            'oldrootid' => $oldrootid,
            'userid' => $userid,
            // Values are clamped on the way in: a hand-edited backup must not
            // be able to place a learner outside the SM-2 value range.
            'easiness' => max(
                \mod_quizgeist\local\schedule\sm2::MIN_EASINESS,
                (int) ($data->easiness ?? \mod_quizgeist\local\schedule\sm2::DEFAULT_EASINESS)
            ),
            'intervaldays' => max(
                0,
                min(
                    \mod_quizgeist\local\schedule\sm2::MAX_INTERVAL_DAYS,
                    (int) ($data->intervaldays ?? 0)
                )
            ),
            'repetitions' => max(0, (int) ($data->repetitions ?? 0)),
            'lapses' => max(0, (int) ($data->lapses ?? 0)),
            'duetime' => max(0, (int) ($data->duetime ?? 0)),
            'lastreviewed' => max(0, (int) ($data->lastreviewed ?? 0)),
            'lastquality' => max(
                0,
                min(
                    \mod_quizgeist\local\schedule\sm2::MAX_QUALITY,
                    (int) ($data->lastquality ?? 0)
                )
            ),
            'timecreated' => max(0, (int) ($data->timecreated ?? 0)),
            'timemodified' => max(0, (int) ($data->timemodified ?? 0)),
        ];
    }

    /**
     * Hold back one workshop submission until every question root is final.
     *
     * Both questionid and rootid are source identifiers at this point. The
     * destination root is read from the restored question after
     * remap_question_roots(), then the old-to-new workshop map is recorded for
     * the ratings that follow.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_workshop($data) {
        $data = (object) $data;
        $oldid = (int) ($data->id ?? 0);
        $this->currentoldworkshopid = $oldid > 0 ? $oldid : null;
        $oldquestionid = (int) ($data->questionid ?? 0);
        $oldrootid = (int) ($data->rootid ?? 0);
        if ($oldid <= 0 || $oldquestionid <= 0 || $oldrootid <= 0) {
            return;
        }
        $state = is_string($data->state ?? null) ? (string) $data->state : '';
        if (!in_array(
            $state,
            \mod_quizgeist\local\workshop\workshop_schema::STATES,
            true
        )) {
            $state = 'submitted';
        }
        $this->pendingworkshops[] = [
            'oldid' => $oldid,
            'oldquestionid' => $oldquestionid,
            'oldrootid' => $oldrootid,
            'authorid' => self::mapped_nullable_id(
                $this,
                'user',
                $data->authorid ?? null
            ),
            'state' => $state,
            'curatorid' => self::mapped_nullable_id(
                $this,
                'user',
                $data->curatorid ?? null
            ),
            'curatornote' => \mod_quizgeist\local\workshop\workshop_schema::plain_text(
                $data->curatornote ?? '',
                \mod_quizgeist\local\workshop\workshop_schema::MAX_NOTE
            ),
            'aicheckjson' => is_string($data->aicheckjson ?? null)
                && $data->aicheckjson !== ''
                ? $data->aicheckjson
                : null,
            'timesubmitted' => max(0, (int) ($data->timesubmitted ?? 0)),
            'timedecided' => max(0, (int) ($data->timedecided ?? 0)),
            'timemodified' => max(0, (int) ($data->timemodified ?? 0)),
        ];
    }

    /**
     * Hold back one peer rating until its restored workshop ID is known.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_workshop_rating($data) {
        $data = (object) $data;
        // workshopid is the parent element's ID and is intentionally not a
        // rating field in the backup tree. Keep the parsed parent ID locally;
        // a hand-authored backup that includes the field remains supported.
        $oldworkshopid = (int) ($data->workshopid
            ?? $this->currentoldworkshopid
            ?? 0);
        $userid = (int) $this->get_mappingid('user', $data->userid ?? 0, 0);
        if ($oldworkshopid <= 0 || $userid <= 0) {
            return;
        }
        $this->pendingratings[] = [
            'oldworkshopid' => $oldworkshopid,
            'userid' => $userid,
            'quality' => max(0, min(5, (int) ($data->quality ?? 0))),
            'difficulty' => max(0, min(5, (int) ($data->difficulty ?? 0))),
            'comment' => \mod_quizgeist\local\workshop\workshop_schema::plain_text(
                $data->comment ?? '',
                \mod_quizgeist\local\workshop\workshop_schema::MAX_COMMENT
            ),
            'timecreated' => max(0, (int) ($data->timecreated ?? 0)),
            'timemodified' => max(0, (int) ($data->timemodified ?? 0)),
        ];
    }

    /**
     * Restore a learner's personal weekly target.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_goal($data) {
        global $DB;

        $data = (object)$data;
        $data->quizgeistid = $this->get_new_parentid('quizgeist');
        $data->userid = $this->get_mappingid('user', $data->userid ?? 0, 0);
        if (empty($data->quizgeistid) || empty($data->userid)) {
            return;
        }
        $data->target = max(1, min(500, (int)($data->target ?? 20)));
        if (!$DB->record_exists('quizgeist_goals', [
            'quizgeistid' => $data->quizgeistid,
            'userid' => $data->userid,
        ])) {
            $DB->insert_record('quizgeist_goals', $data);
        }
    }

    /**
     * Restore a user reward without creating duplicate global unlocks.
     *
     * @param array $data Backup data.
     * @return void
     */
    protected function process_quizgeist_reward($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->quizgeistid = $this->get_new_parentid('quizgeist');
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        if (empty($data->userid)) {
            return;
        }

        $existingid = $DB->get_field('quizgeist_rewards', 'id', [
            'userid' => $data->userid,
            'rewardkey' => $data->rewardkey,
        ]);
        if ($existingid) {
            $this->set_mapping('quizgeist_reward', $oldid, $existingid);
            \mod_quizgeist\local\live\reward_service::invalidate_catalogues([
                (int)$data->userid,
            ]);
            return;
        }

        $newitemid = $DB->insert_record('quizgeist_rewards', $data);
        $this->set_mapping('quizgeist_reward', $oldid, $newitemid);
        \mod_quizgeist\local\live\reward_service::invalidate_catalogues([
            (int)$data->userid,
        ]);
    }

    /**
     * Restore one answer through its live or solo parent.
     *
     * @param array $rawdata Backup data.
     * @param bool $live Whether this is a live response.
     * @return void
     */
    private function restore_answer(
        $rawdata,
        bool $live,
        bool $sessioninteraction = false
    ): void {
        global $DB;

        $data = (object) $rawdata;
        $oldid = $data->id;
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        $data->questionid = $this->get_mappingid('quizgeist_question', $data->questionid, 0);
        if (empty($data->userid) || empty($data->questionid)) {
            return;
        }
        $allowedtypes = [
            'answer',
            'scorevoid',
            'reaction',
            'idea',
            'group',
            'vote',
            'moderation',
            'flashcard',
            'view',
        ];
        $data->answertype = in_array(
            $data->answertype ?? '',
            $allowedtypes,
            true
        ) ? (string)$data->answertype : 'answer';
        $data->submissionkey = self::submission_key(
            $data->submissionkey ?? null
        );

        if ($live) {
            $data->sessionid = $this->get_new_parentid('quizgeist_session');
            $data->playerid = $sessioninteraction
                ? null
                : $this->get_new_parentid('quizgeist_player');
            $data->attemptid = null;
            if (empty($data->sessionid)
                    || (!$sessioninteraction && empty($data->playerid))) {
                return;
            }
            $data->visit = self::visit_token($data->visit ?? null);
            if ($data->visit === null) {
                $decoded = json_decode((string)($data->answerjson ?? ''), true);
                $data->visit = is_array($decoded)
                    ? self::visit_token($decoded['questionToken'] ?? null)
                    : null;
            }
            if ($sessioninteraction) {
                if (!in_array(
                    $data->answertype,
                    ['group', 'moderation'],
                    true
                )) {
                    return;
                }
                if ($data->answertype === 'group') {
                    $data->answerjson = $this->remap_group_answer(
                        $data->answerjson ?? null
                    );
                    if ($data->answerjson === null) {
                        return;
                    }
                }
            }
        } else {
            $data->sessionid = null;
            $data->playerid = null;
            $data->attemptid = $this->get_new_parentid('quizgeist_attempt');
            if (empty($data->attemptid)) {
                return;
            }
            $attemptvisit = $DB->get_field('quizgeist_attempt_questions', 'visit', [
                'attemptid' => $data->attemptid,
                'questionid' => $data->questionid,
            ]);
            $data->visit = self::visit_token($data->visit ?? null);
            if (!is_string($data->visit)
                    || !is_string($attemptvisit)
                    || !hash_equals($attemptvisit, $data->visit)) {
                $data->visit = is_string($attemptvisit) ? $attemptvisit : null;
            }
        }
        // The append-only answer ledger is the authoritative scoring record and
        // must survive a backup/restore round trip unchanged.
        //
        // Two persisted shapes are deliberate and must not be "repaired" here:
        // a `scorevoid` compensation row carries NEGATIVE points (it cancels an
        // unresolved visit, see visit_ledger::neutralise_snapshot()), and every
        // live answer stores `maxpoints = 0` because live scoring is
        // speed-based and has no fixed per-answer ceiling
        // (live_submission_service). The previous clamps raised both values
        // (`max(0, points)` and `max(points, maxpoints)`) and therefore silently
        // rewrote restored report and CSV figures: voided points reappeared and
        // every live answer suddenly looked like a full-score answer.
        //
        // Only non-numeric backup input and a negative maximum - which the
        // plugin never persists - are still normalised.
        $data->points = is_numeric($data->points ?? null)
            ? (int)$data->points
            : 0;
        $data->maxpoints = is_numeric($data->maxpoints ?? null)
            ? max(0, (int)$data->maxpoints)
            : 0;

        $newitemid = $DB->insert_record('quizgeist_answers', $data);
        if ($live) {
            $this->set_mapping('quizgeist_liveanswer', $oldid, $newitemid);
        } else {
            $this->set_mapping('quizgeist_soloanswer', $oldid, $newitemid);
        }
        // Keep the existing path mapping and expose the common answer row to
        // clip restore, regardless of whether this was live or solo data.
        $this->set_mapping('quizgeist_answer', $oldid, $newitemid, false);
    }

    /**
     * Return one canonical visit token or null.
     *
     * @param mixed $value Raw token.
     * @return string|null
     */
    private static function visit_token($value): ?string {
        return is_string($value)
                && preg_match('/^[a-f0-9]{32}$/D', $value)
            ? $value
            : null;
    }

    /**
     * Return a bounded portable idempotency key.
     *
     * @param mixed $value Raw key.
     * @return string|null
     */
    private static function submission_key($value): ?string {
        return is_string($value)
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $value)
            ? $value
            : null;
    }

    /**
     * Remap idea-row IDs embedded in a host grouping snapshot.
     *
     * @param mixed $rawjson Stored JSON.
     * @return string|null
     */
    private function remap_group_answer($rawjson): ?string {
        $decoded = json_decode((string)$rawjson, true);
        if (!is_array($decoded) || !is_array($decoded['groups'] ?? null)) {
            return null;
        }
        $groups = [];
        foreach ($decoded['groups'] as $group) {
            if (!is_array($group) || !is_array($group['ideaIds'] ?? null)) {
                continue;
            }
            $ids = [];
            foreach ($group['ideaIds'] as $oldid) {
                $mappedid = (int)$this->get_mappingid(
                    'quizgeist_liveanswer',
                    $oldid,
                    0
                );
                if ($mappedid > 0) {
                    $ids[] = $mappedid;
                }
            }
            if ($ids) {
                $group['ideaIds'] = array_values(array_unique($ids));
                $groups[] = $group;
            }
        }
        if (!$groups) {
            return null;
        }
        $decoded['groups'] = $groups;
        return json_encode(
            $decoded,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Resolve an optional backup identifier without converting it to zero.
     *
     * @param restore_quizgeist_activity_structure_step $step Restore step.
     * @param string $mapping Mapping name.
     * @param mixed $oldid Old identifier.
     * @return int|null Mapped identifier or null.
     */
    private static function mapped_nullable_id(
        restore_quizgeist_activity_structure_step $step,
        string $mapping,
        $oldid
    ): ?int {
        if (empty($oldid)) {
            return null;
        }
        $mappedid = $step->get_mappingid($mapping, $oldid, null);
        return $mappedid === null ? null : (int) $mappedid;
    }

    /**
     * Derive a bounded opaque identity for a restored provenance copy.
     *
     * The new activity ID makes the value stable for this restore while
     * keeping repeated Moodle duplications distinct. A bounded retry protects
     * even a manually seeded hash collision without exposing the source UUID.
     *
     * @param int $courseid Destination course.
     * @param string $sourceformat Original source format.
     * @param string $sourceuuid Original source UUID.
     * @param int $quizgeistid Newly restored activity.
     * @return string
     */
    private function restored_copy_sourceuuid(
        int $courseid,
        string $sourceformat,
        string $sourceuuid,
        int $quizgeistid
    ): string {
        global $DB;

        for ($attempt = 0; $attempt < 16; $attempt++) {
            $candidate = \mod_quizgeist\local\kahoot\import_repository::
                restored_copy_uuid(
                    $courseid,
                    $sourceformat,
                    $sourceuuid,
                    $quizgeistid,
                    $attempt
                );
            if (!$DB->record_exists('quizgeist_imports', [
                'courseid' => $courseid,
                'sourceformat' => 'external',
                'sourceuuid' => $candidate,
            ])) {
                return $candidate;
            }
        }
        throw new \coding_exception(
            'Could not allocate a unique restored import provenance.'
        );
    }

    /**
     * Canonicalise an untrusted timestamp against a server-owned interval.
     *
     * Zero and out-of-range values use the explicit caller fallback. This lets
     * optional fields retain zero while security-relevant future start times
     * fall back to their parent attempt instead of becoming instant responses.
     *
     * @param mixed $raw Backup value.
     * @param int $minimum Inclusive lower bound.
     * @param int $maximum Inclusive upper bound.
     * @param int $zerofallback Canonical value for zero or a missing field.
     * @return int
     */
    private static function clamp_restored_timestamp(
        $raw,
        int $minimum,
        int $maximum,
        int $zerofallback
    ): int {
        $minimum = max(0, $minimum);
        $maximum = max($minimum, $maximum);
        $value = (int)$raw;
        if ($value === 0 || $value < $minimum || $value > $maximum) {
            return $zerofallback;
        }
        return $value;
    }

    /**
     * Restore activity and item-scoped files after mappings exist.
     *
     * @return void
     */
    protected function after_execute() {
        global $CFG, $DB;

        $this->remap_question_roots();
        // Only now is every rootid final; the held-back assignments can be
        // bound to the destination roots.
        $this->restore_pending_misconceptions();
        $this->restore_pending_curriculumrefs();
        $this->restore_pending_workshops();
        $this->restore_pending_ratings();
        $this->restore_pending_question_tags();
        $this->restore_pending_schedules();
        $this->remap_session_states();
        $this->validate_attempt_states();
        $this->neutralise_unresolved_session_scores();
        \mod_quizgeist\local\kahoot\import_reconciler::remap_reports(
            (int)$this->get_new_parentid('quizgeist'),
            (int)$this->get_courseid(),
            (int)$this->get_task()->get_moduleid(),
            fn(int $oldid) => $this->get_mappingid(
                'quizgeist_question',
                $oldid,
                null
            )
        );

        $this->add_related_files('mod_quizgeist', 'intro', null);
        $this->add_related_files('mod_quizgeist', 'background', null);
        $this->add_related_files('mod_quizgeist', 'logo', null);
        $this->add_related_files('mod_quizgeist', 'importmedia', 'quizgeist_import');
        $this->add_related_files('mod_quizgeist', 'questionmedia', 'quizgeist_question');
        $this->restore_pending_clips();
        $this->add_related_files('mod_quizgeist', 'clipaudio', 'quizgeist_clip');
        $this->remove_unsafe_restored_media();
        \mod_quizgeist\local\kahoot\import_reconciler::reconcile(
            (int)$this->get_new_parentid('quizgeist'),
            (int)$this->get_task()->get_contextid(),
            (int)$this->get_task()->get_moduleid(),
            fn(string $message) => $this->log(
                $message,
                backup::LOG_WARNING
            )
        );

        if ($this->get_setting_value('userinfo')) {
            $quizgeist = $DB->get_record(
                'quizgeist',
                ['id' => $this->get_new_parentid('quizgeist')]
            );
            if ($quizgeist) {
                require_once($CFG->dirroot . '/mod/quizgeist/lib.php');
                quizgeist_update_grades($quizgeist);
            }
        }
    }

    /**
     * Validate navigation and position-based flashcard stacks after child rows.
     *
     * Question IDs are relational; JSON may contain sort indexes only. Any
     * malformed or foreign position makes the restored runtime attempt
     * terminal instead of retaining ambiguous learner state.
     *
     * @return void
     */
    private function validate_attempt_states(): void {
        global $DB;

        foreach ($this->restoredattemptids as $attemptid) {
            $attempt = $DB->get_record(
                'quizgeist_attempts',
                ['id' => $attemptid],
                'id,mode,status,statejson,flashcardsjson'
            );
            if (!$attempt) {
                continue;
            }
            $indexes = array_map(
                'intval',
                $DB->get_fieldset_select(
                    'quizgeist_attempt_questions',
                    'sortindex',
                    'attemptid = :attemptid',
                    ['attemptid' => $attemptid],
                    'sortindex ASC'
                )
            );
            if (!$indexes) {
                $DB->update_record('quizgeist_attempts', (object)[
                    'id' => $attemptid,
                    'status' => 'abandoned',
                    'score' => 0,
                    'maxscore' => 0,
                    'statejson' => json_encode(
                        ['currentIndex' => 0],
                        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'flashcardsjson' => null,
                ]);
                continue;
            }
            // The exactly-once answer ledger is authoritative. Rebuild the
            // portable summary instead of trusting independent attempt totals.
            $totals = $DB->get_record_sql(
                'SELECT COALESCE(SUM(points), 0) AS score,
                        COALESCE(SUM(maxpoints), 0) AS maxscore
                   FROM {quizgeist_answers}
                  WHERE attemptid = :attemptid',
                ['attemptid' => $attemptid]
            );
            $DB->update_record('quizgeist_attempts', (object)[
                'id' => $attemptid,
                'score' => (int)($totals->score ?? 0),
                'maxscore' => (int)($totals->maxscore ?? 0),
            ]);
            $allowed = array_fill_keys($indexes, true);
            $state = json_decode((string)$attempt->statejson, true);
            $current = is_array($state)
                    && is_int($state['currentIndex'] ?? null)
                    && isset($allowed[$state['currentIndex']])
                ? $state['currentIndex']
                : ($indexes[0] ?? 0);
            $DB->set_field(
                'quizgeist_attempts',
                'statejson',
                json_encode(
                    ['currentIndex' => $current],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                ['id' => $attemptid]
            );

            if ((string)$attempt->mode !== 'flashcards') {
                $DB->set_field(
                    'quizgeist_attempts',
                    'flashcardsjson',
                    null,
                    ['id' => $attemptid]
                );
                continue;
            }
            $stacks = json_decode((string)$attempt->flashcardsjson, true);
            $valid = is_array($stacks)
                && !array_is_list($stacks)
                && is_int($stacks['round'] ?? null)
                && in_array($stacks['round'], [1, 2], true);
            $seen = [];
            foreach (['queue', 'repeat', 'known', 'missed'] as $key) {
                $values = is_array($stacks) ? ($stacks[$key] ?? null) : null;
                if (!is_array($values) || !array_is_list($values)) {
                    $valid = false;
                    continue;
                }
                foreach ($values as $value) {
                    if (!is_int($value)
                            || !isset($allowed[$value])
                            || isset($seen[$value])) {
                        $valid = false;
                        continue;
                    }
                    $seen[$value] = true;
                }
            }
            if (count($seen) !== count($allowed)) {
                $valid = false;
            }
            if ((string)$attempt->status === 'inprogress') {
                $valid = $valid
                    && !empty($stacks['queue'])
                    && (int)$stacks['queue'][0] === $current;
            } else if ((string)$attempt->status === 'completed') {
                $valid = $valid && empty($stacks['queue']);
            }
            if (!$valid) {
                $DB->update_record('quizgeist_attempts', (object)[
                    'id' => $attemptid,
                    'status' => 'abandoned',
                    'flashcardsjson' => null,
                ]);
            }
        }
    }

    /**
     * Remove provisional score effects before restored sessions become history.
     *
     * Every restored runtime session is terminal. Relational visit state and
     * resolution are therefore the authoritative distinction between mature
     * points and a hidden answer persisted before backup.
     *
     * @return void
     */
    private function neutralise_unresolved_session_scores(): void {
        foreach (array_keys($this->restoredsessionstates) as $sessionid) {
            \mod_quizgeist\local\live\visit_ledger::neutralise_unresolved_visits(
                (int)$sessionid
            );
        }
    }

    /**
     * Write misconception labels once every question root is final.
     *
     * The unique identity is (rootid, answerkey). A duplicate is mapped to
     * the existing row and skipped rather than raising a restore-time index
     * error.
     *
     * @return void
     */
    private function restore_pending_misconceptions(): void {
        global $DB;

        if (!$this->pendingmisconceptions) {
            return;
        }
        $quizgeistid = (int) $this->get_new_parentid('quizgeist');
        $now = time();
        $seen = [];
        foreach ($this->pendingmisconceptions as $pending) {
            $newquestionid = (int) $this->get_mappingid(
                'quizgeist_question',
                $pending['oldrootid'],
                0
            );
            if ($newquestionid <= 0) {
                continue;
            }
            $question = $DB->get_record(
                'quizgeist_questions',
                ['id' => $newquestionid, 'quizgeistid' => $quizgeistid],
                'id, rootid'
            );
            if ($question === false) {
                continue;
            }
            $rootid = (int) $question->rootid > 0
                ? (int) $question->rootid
                : (int) $question->id;
            $identity = $rootid . ':' . (string) $pending['answerkey'];
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $existing = $DB->get_record('quizgeist_misconceptions', [
                'rootid' => $rootid,
                'answerkey' => $pending['answerkey'],
            ], 'id');
            if ($existing !== false) {
                if ((int) $pending['oldid'] > 0) {
                    $this->set_mapping(
                        'quizgeist_misconception',
                        (int) $pending['oldid'],
                        (int) $existing->id,
                        false
                    );
                }
                continue;
            }
            $newitemid = $DB->insert_record('quizgeist_misconceptions', (object)[
                'quizgeistid' => $quizgeistid,
                'rootid' => $rootid,
                'answerkey' => $pending['answerkey'],
                'label' => $pending['label'],
                'hint' => $pending['hint'],
                'timecreated' => $pending['timecreated'] ?: $now,
                'timemodified' => $pending['timemodified'] ?: $now,
            ]);
            if ((int) $pending['oldid'] > 0) {
                $this->set_mapping(
                    'quizgeist_misconception',
                    (int) $pending['oldid'],
                    (int) $newitemid,
                    false
                );
            }
        }
        $this->pendingmisconceptions = [];
    }

    /**
     * Write curriculum anchors once every question root is final.
     *
     * The root uniqueness index is global to the question lineage. Existing
     * anchors are therefore mapped and retained; unresolved roots are dropped.
     *
     * @return void
     */
    private function restore_pending_curriculumrefs(): void {
        global $DB;

        if (!$this->pendingcurriculumrefs) {
            return;
        }
        $quizgeistid = (int) $this->get_new_parentid('quizgeist');
        $now = time();
        foreach ($this->pendingcurriculumrefs as $pending) {
            $newquestionid = (int) $this->get_mappingid(
                'quizgeist_question',
                $pending['oldrootid'],
                0
            );
            if ($newquestionid <= 0) {
                continue;
            }
            $question = $DB->get_record(
                'quizgeist_questions',
                ['id' => $newquestionid, 'quizgeistid' => $quizgeistid],
                'id, rootid'
            );
            if ($question === false) {
                continue;
            }
            $rootid = (int) $question->rootid > 0
                ? (int) $question->rootid
                : (int) $question->id;
            if (!$DB->record_exists('quizgeist_questions', [
                'id' => $rootid,
                'quizgeistid' => $quizgeistid,
            ])) {
                continue;
            }
            $existing = $DB->get_record(
                'quizgeist_curriculum_refs',
                ['rootid' => $rootid],
                'id'
            );
            if ($existing !== false) {
                if ((int) $pending['oldid'] > 0) {
                    $this->set_mapping(
                        'quizgeist_curriculumref',
                        (int) $pending['oldid'],
                        (int) $existing->id,
                        false
                    );
                }
                continue;
            }
            $newitemid = $DB->insert_record('quizgeist_curriculum_refs', (object)[
                'quizgeistid' => $quizgeistid,
                'rootid' => $rootid,
                'subject' => $pending['subject'],
                'grade' => $pending['grade'],
                'variant' => $pending['variant'] === '' ? null : $pending['variant'],
                'learningarea' => $pending['learningarea'] === ''
                    ? null
                    : $pending['learningarea'],
                'competency' => $pending['competency'] === ''
                    ? null
                    : $pending['competency'],
                'citationurl' => $pending['citationurl'] === ''
                    ? null
                    : $pending['citationurl'],
                'chunkid' => $pending['chunkid'],
                'timecreated' => $pending['timecreated'] ?: $now,
            ]);
            if ((int) $pending['oldid'] > 0) {
                $this->set_mapping(
                    'quizgeist_curriculumref',
                    (int) $pending['oldid'],
                    (int) $newitemid,
                    false
                );
            }
        }
        $this->pendingcurriculumrefs = [];
    }

    /**
     * Write clips after all answer paths have established their mappings.
     *
     * @return void
     */
    private function restore_pending_clips(): void {
        global $DB;

        if (!$this->pendingclips) {
            return;
        }
        $quizgeistid = (int) $this->get_new_parentid('quizgeist');
        foreach ($this->pendingclips as $pending) {
            $answerid = null;
            if ($pending['oldanswerid'] !== null) {
                $answerid = (int) $this->get_mappingid(
                    'quizgeist_answer',
                    $pending['oldanswerid'],
                    0
                );
                if ($answerid <= 0) {
                    $answerid = (int) $this->get_mappingid(
                        'quizgeist_liveanswer',
                        $pending['oldanswerid'],
                        0
                    );
                }
                if ($answerid <= 0) {
                    $answerid = (int) $this->get_mappingid(
                        'quizgeist_soloanswer',
                        $pending['oldanswerid'],
                        0
                    );
                }
                if ($answerid <= 0 || !$DB->record_exists('quizgeist_answers', [
                    'id' => $answerid,
                ])) {
                    $answerid = null;
                } else if ($DB->record_exists('quizgeist_clips', [
                    'answerid' => $answerid,
                ])) {
                    // The source index permits one clip per answer. A
                    // duplicate hand-authored row remains an unbound clip.
                    $answerid = null;
                }
            }
            $newitemid = $DB->insert_record('quizgeist_clips', (object)[
                'quizgeistid' => $quizgeistid,
                'userid' => $pending['userid'],
                'answerid' => $answerid,
                'purpose' => $pending['purpose'],
                'itemid' => 0,
                'durationms' => $pending['durationms'],
                'bytes' => $pending['bytes'],
                'language' => $pending['language'],
                'transcript' => $pending['transcript'],
                'transcriptstate' => $pending['transcriptstate'],
                'transcriptcode' => $pending['transcriptcode'],
                'audiodeleted' => $pending['audiodeleted'],
                'timecreated' => $pending['timecreated'],
                'timemodified' => $pending['timemodified'],
            ]);
            // clip_service keeps itemid equal to the bookkeeping ID; related
            // files are copied under this mapped ID in after_execute().
            $DB->set_field('quizgeist_clips', 'itemid', $newitemid, [
                'id' => $newitemid,
            ]);
            $this->set_mapping(
                'quizgeist_clip',
                (int) $pending['oldid'],
                (int) $newitemid,
                true
            );
        }
        $this->pendingclips = [];
    }

    /**
     * Write workshop submissions once every question root is final.
     *
     * The unique identity is root_uix (rootid). Every old workshop ID is
     * mapped, including a duplicate that resolves to an existing row, so its
     * pending ratings can safely follow the destination ID.
     *
     * @return void
     */
    private function restore_pending_workshops(): void {
        global $DB;

        if (!$this->pendingworkshops) {
            return;
        }
        $quizgeistid = (int) $this->get_new_parentid('quizgeist');
        $now = time();
        foreach ($this->pendingworkshops as $pending) {
            $newrootquestionid = (int) $this->get_mappingid(
                'quizgeist_question',
                $pending['oldrootid'],
                0
            );
            $newquestionid = (int) $this->get_mappingid(
                'quizgeist_question',
                $pending['oldquestionid'],
                0
            );
            if ($newrootquestionid <= 0 || $newquestionid <= 0) {
                continue;
            }
            $question = $DB->get_record(
                'quizgeist_questions',
                ['id' => $newrootquestionid, 'quizgeistid' => $quizgeistid],
                'id, rootid'
            );
            if ($question === false) {
                continue;
            }
            $rootid = (int) $question->rootid > 0
                ? (int) $question->rootid
                : (int) $question->id;
            $existing = $DB->get_record(
                'quizgeist_workshop',
                ['rootid' => $rootid],
                'id'
            );
            if ($existing !== false) {
                $newitemid = (int) $existing->id;
                $this->restoredworkshopmappings[(int) $pending['oldid']] = $newitemid;
                $this->set_mapping(
                    'quizgeist_workshop',
                    (int) $pending['oldid'],
                    $newitemid,
                    false
                );
                continue;
            }
            $newitemid = (int) $DB->insert_record('quizgeist_workshop', (object)[
                'quizgeistid' => $quizgeistid,
                'questionid' => $newquestionid,
                'rootid' => $rootid,
                'authorid' => $pending['authorid'],
                'state' => $pending['state'],
                'curatorid' => $pending['curatorid'],
                'curatornote' => $pending['curatornote'] === ''
                    ? null
                    : $pending['curatornote'],
                'aicheckjson' => $pending['aicheckjson'],
                'timesubmitted' => $pending['timesubmitted'] ?: $now,
                'timedecided' => $pending['timedecided'],
                'timemodified' => $pending['timemodified'] ?: $now,
            ]);
            $this->restoredworkshopmappings[(int) $pending['oldid']] = $newitemid;
            $this->set_mapping(
                'quizgeist_workshop',
                (int) $pending['oldid'],
                $newitemid,
                false
            );
        }
        $this->pendingworkshops = [];
    }

    /**
     * Write peer ratings after all workshop IDs have been mapped.
     *
     * The unique identity is workshop_user_uix (workshopid, userid);
     * duplicate ratings are skipped rather than copied twice.
     *
     * @return void
     */
    private function restore_pending_ratings(): void {
        global $DB;

        if (!$this->pendingratings) {
            return;
        }
        $now = time();
        foreach ($this->pendingratings as $pending) {
            $workshopid = (int) ($this->restoredworkshopmappings[
                (int) $pending['oldworkshopid']
            ] ?? 0);
            if ($workshopid <= 0 || !$DB->record_exists('quizgeist_workshop', [
                'id' => $workshopid,
            ])) {
                continue;
            }
            if ($DB->record_exists('quizgeist_workshop_ratings', [
                'workshopid' => $workshopid,
                'userid' => $pending['userid'],
            ])) {
                continue;
            }
            $DB->insert_record('quizgeist_workshop_ratings', (object)[
                'workshopid' => $workshopid,
                'userid' => $pending['userid'],
                'quality' => $pending['quality'],
                'difficulty' => $pending['difficulty'],
                'comment' => $pending['comment'] === ''
                    ? null
                    : $pending['comment'],
                'timecreated' => $pending['timecreated'] ?: $now,
                'timemodified' => $pending['timemodified'] ?: $now,
            ]);
        }
        $this->pendingratings = [];
        $this->restoredworkshopmappings = [];
    }

    /**
     * Write the held-back tag assignments once every root is final.
     *
     * An assignment is bound to quizgeist_questions.rootid, never to a
     * version ID. The destination root is therefore read back from the
     * restored question instead of being guessed from the mapping.
     *
     * @return void
     */
    private function restore_pending_question_tags(): void {
        global $DB;

        if (!$this->pendingquestiontags) {
            return;
        }
        $quizgeistid = (int) $this->get_new_parentid('quizgeist');
        $seen = [];
        foreach ($this->pendingquestiontags as $pending) {
            $newquestionid = (int) $this->get_mappingid(
                'quizgeist_question',
                $pending['oldrootid'],
                0
            );
            $newtagid = (int) $this->get_mappingid(
                'quizgeist_tag',
                $pending['oldtagid'],
                0
            );
            if ($newquestionid <= 0 || $newtagid <= 0) {
                continue;
            }
            $question = $DB->get_record(
                'quizgeist_questions',
                ['id' => $newquestionid, 'quizgeistid' => $quizgeistid],
                'id, rootid'
            );
            if ($question === false) {
                continue;
            }
            $rootid = (int) $question->rootid > 0
                ? (int) $question->rootid
                : (int) $question->id;
            $identity = $rootid . ':' . $newtagid;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            if ($DB->record_exists('quizgeist_question_tags', [
                'rootid' => $rootid,
                'tagid' => $newtagid,
            ])) {
                continue;
            }
            $DB->insert_record('quizgeist_question_tags', (object) [
                'quizgeistid' => $quizgeistid,
                'rootid' => $rootid,
                'tagid' => $newtagid,
                'weight' => $pending['weight'],
                'status' => $pending['status'],
                'createdby' => $pending['createdby'],
                'timecreated' => $pending['timecreated'] ?: time(),
            ]);
        }
        $this->pendingquestiontags = [];
    }

    /**
     * Write the held-back repetition rows once every root is final.
     *
     * The unique identity is (activity, learner, root). A row whose root did
     * not survive the restore is dropped rather than attached to a stranger.
     *
     * @return void
     */
    private function restore_pending_schedules(): void {
        global $DB;

        if (!$this->pendingschedules) {
            return;
        }
        $quizgeistid = (int) $this->get_new_parentid('quizgeist');
        $now = time();
        foreach ($this->pendingschedules as $pending) {
            $newquestionid = (int) $this->get_mappingid(
                'quizgeist_question',
                $pending['oldrootid'],
                0
            );
            if ($newquestionid <= 0) {
                continue;
            }
            $question = $DB->get_record(
                'quizgeist_questions',
                ['id' => $newquestionid, 'quizgeistid' => $quizgeistid],
                'id, rootid'
            );
            if ($question === false) {
                continue;
            }
            $rootid = (int) $question->rootid > 0
                ? (int) $question->rootid
                : (int) $question->id;
            if ($DB->record_exists('quizgeist_schedule', [
                'quizgeistid' => $quizgeistid,
                'userid' => $pending['userid'],
                'rootid' => $rootid,
            ])) {
                continue;
            }
            unset($pending['oldrootid']);
            $pending['timecreated'] = $pending['timecreated'] ?: $now;
            $pending['timemodified'] = $pending['timemodified'] ?: $now;
            $DB->insert_record('quizgeist_schedule', (object) ([
                'quizgeistid' => $quizgeistid,
                'rootid' => $rootid,
            ] + $pending));
        }
        $this->pendingschedules = [];
    }

    /**
     * Complete root mappings after all questions in this activity exist.
     *
     * A lineage root from another activity or a missing/malformed root is not
     * accepted; the restored row starts a fresh version-one lineage instead.
     *
     * @return void
     */
    private function remap_question_roots(): void {
        global $DB;

        foreach ($this->restoredquestionlineages as $newquestionid => $lineage) {
            $newrootid = (int) $this->get_mappingid(
                'quizgeist_question',
                $lineage['oldrootid'],
                0
            );
            $rootlineage = $this->restoredquestionlineages[$newrootid] ?? null;
            $validroot = $rootlineage !== null
                && $rootlineage['oldid'] === $lineage['oldrootid']
                && $rootlineage['oldrootid'] === $lineage['oldrootid'];
            if (!$validroot) {
                $newrootid = $newquestionid;
            }
            if ($newrootid === $newquestionid) {
                $DB->set_field(
                    'quizgeist_questions',
                    'version',
                    1,
                    ['id' => $newquestionid]
                );
            }
            $DB->set_field(
                'quizgeist_questions',
                'rootid',
                $newrootid,
                ['id' => $newquestionid]
            );
        }
    }

    /**
     * Remap every concrete question ID in a P3 live-session snapshot.
     *
     * Restored sessions are deliberately ended and have no join code, but their
     * immutable played sequence remains report data. If a foreign/legacy state
     * cannot be validated, it is discarded rather than retaining stale source
     * site IDs.
     *
     * @return void
     */
    private function remap_session_states(): void {
        global $DB;

        foreach ($this->restoredsessionstates as $sessionid => $pending) {
            $oldjson = $pending['json'];
            $remapped = \mod_quizgeist\local\live\session_state::remap_question_ids(
                $oldjson,
                fn(int $oldid): int => (int)$this->get_mappingid(
                    'quizgeist_question',
                    $oldid,
                    0
                )
            );
            // Fail closed: source-site IDs from an unknown or malformed state
            // document must never survive in the destination database.
            $currentquestionid = null;
            $statejson = null;
            if ($remapped !== null) {
                $state = \mod_quizgeist\local\live\session_state::decode($remapped);
                $currentquestionid =
                    \mod_quizgeist\local\live\session_state::current_question_id($state);
                $statejson = $remapped;
                $this->relationalise_legacy_snapshot($sessionid, $state);
            }
            $DB->update_record('quizgeist_sessions', (object)[
                'id' => $sessionid,
                'currentquestionid' => $currentquestionid,
                'statejson' => $statejson,
            ]);
        }
    }

    /**
     * Create relational snapshot rows when restoring a pre-REVIEWFIX3 backup.
     *
     * @param int $sessionid Restored session ID.
     * @param array $state Remapped canonical P3 state.
     * @return void
     */
    private function relationalise_legacy_snapshot(
        int $sessionid,
        array $state
    ): void {
        global $DB;

        if ($DB->record_exists('quizgeist_session_questions', [
            'sessionid' => $sessionid,
        ])) {
            return;
        }
        $questionids = array_values(array_map(
            'intval',
            $state['questionIds'] ?? []
        ));
        if (!$questionids) {
            return;
        }

        $answervisits = [];
        $voidedvisits = [];
        $answers = $DB->get_records_select(
            'quizgeist_answers',
            'sessionid = :sessionid AND visit IS NOT NULL',
            ['sessionid' => $sessionid],
            'id ASC',
            'id,questionid,answertype,visit'
        );
        foreach ($answers as $answer) {
            $visit = self::visit_token($answer->visit ?? null);
            if ($visit === null) {
                continue;
            }
            $questionid = (int)$answer->questionid;
            if ($answer->answertype === 'answer') {
                $answervisits[$questionid][$visit] = (int)$answer->id;
            } else if ($answer->answertype === 'scorevoid') {
                $voidedvisits[$questionid][$visit] = true;
            }
        }

        $currentindex = max(-1, min(
            count($questionids) - 1,
            (int)($state['currentIndex'] ?? -1)
        ));
        $currentvisit = self::visit_token($state['questionToken'] ?? null);
        $currentrevealed = \mod_quizgeist\local\live\session_state::
            current_question_was_revealed($state);

        foreach ($questionids as $sortindex => $questionid) {
            $latestvisit = null;
            $resolvedvisit = null;
            $questionvisits = $answervisits[$questionid] ?? [];
            if ($questionvisits) {
                asort($questionvisits, SORT_NUMERIC);
                $latestvisit = (string)array_key_last($questionvisits);
                foreach (array_keys($questionvisits) as $candidate) {
                    if (empty($voidedvisits[$questionid][$candidate])) {
                        $resolvedvisit = (string)$candidate;
                        break;
                    }
                }
            }

            $visit = null;
            $visitstate = 'pending';
            if ($sortindex < $currentindex) {
                $visit = $latestvisit;
                $visitstate = $latestvisit !== null
                        && !empty($voidedvisits[$questionid][$latestvisit])
                    ? 'skipped'
                    : ($resolvedvisit === null ? 'skipped' : 'revealed');
            } else if ($sortindex === $currentindex) {
                $visit = $currentvisit ?? $latestvisit;
                if ($currentrevealed && $visit !== null) {
                    $visitstate = 'revealed';
                    $resolvedvisit = $resolvedvisit ?? $visit;
                } else {
                    // Keep the legacy unresolved visit active until the common
                    // visit ledger compensates it immediately after remapping.
                    $visitstate = 'active';
                }
            }

            $DB->insert_record('quizgeist_session_questions', (object)[
                'sessionid' => $sessionid,
                'sortindex' => $sortindex,
                'questionid' => $questionid,
                'visit' => $visit,
                'visitstate' => $visitstate,
                'resolvedvisit' => $resolvedvisit,
                'stage' => 'answer',
            ]);
        }
    }

    /**
     * Remove files that bypassed the editor upload allowlists through restore.
     *
     * @return void
     */
    private function remove_unsafe_restored_media(): void {
        global $DB;

        $contextid = (int) $this->get_task()->get_contextid();
        $quizgeistid = (int)$this->get_new_parentid('quizgeist');
        $fs = get_file_storage();
        foreach (['background', 'logo', 'questionmedia', 'importmedia'] as $area) {
            $files = $fs->get_area_files(
                $contextid,
                'mod_quizgeist',
                $area,
                false,
                'itemid ASC, filepath ASC, filename ASC',
                false
            );
            foreach ($files as $file) {
                $canonical = $area === 'questionmedia'
                    ? (
                        isset($this->restoredquestionlineages[(int) $file->get_itemid()])
                        && (
                            $file->get_filepath() === '/question/'
                            || (bool) preg_match(
                                '/^\/answers\/[a-z][a-z0-9_-]{0,31}\/$/D',
                                $file->get_filepath()
                            )
                        )
                    )
                    : ($area === 'importmedia'
                        ? (
                            $file->get_filepath() === '/source/'
                            && $DB->record_exists('quizgeist_imports', [
                                'id' => (int)$file->get_itemid(),
                                'quizgeistid' => $quizgeistid,
                            ])
                        )
                        : (
                            (int) $file->get_itemid() === 0
                            && $file->get_filepath() === '/'
                        ));
                $safe = $canonical
                    && !$file->is_external_file()
                    && \mod_quizgeist\local\editor\media_service::is_safe_for_delivery($file);
                if (!$safe) {
                    $file->delete();
                    $this->log(
                        "Removed disallowed {$area} file from restored Quizgeist backup.",
                        backup::LOG_WARNING
                    );
                }
            }
        }
    }
}
