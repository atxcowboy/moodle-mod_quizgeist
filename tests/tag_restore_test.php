<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Integration tests for tagging across a genuine activity backup/restore.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\tagging\tag_repository;
use mod_quizgeist\local\tagging\tag_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Tags are merged on restore, never duplicated, and stay bound to the root.
 */
final class tag_restore_test extends \advanced_testcase {

    public function test_restore_merges_tags_and_keeps_the_root_binding(): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest(true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $module = $this->getDataGenerator()->create_module('quizgeist', [
            'course' => $course->id,
        ]);
        $module->course = (int)$course->id;
        $now = time();
        $questionid = (int)$DB->insert_record('quizgeist_questions', (object)[
            'quizgeistid' => (int)$module->id,
            'rootid' => 0,
            'version' => 1,
            'sortorder' => 0,
            'qtype' => 'truefalse',
            'questiontext' => 'Ist das gesichert?',
            'questionformat' => FORMAT_PLAIN,
            'optionsjson' => json_encode(['media' => null, 'correct' => true]),
            'timelimit' => 20,
            'pointmode' => 'standard',
            'status' => 'ready',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->set_field('quizgeist_questions', 'rootid', $questionid, [
            'id' => $questionid,
        ]);

        // One activity tag and one course-wide tag. The course tag must be
        // mapped on restore, not duplicated.
        $activitytag = tag_service::save_tag($module, [
            'scope' => 'activity', 'kind' => 'topic',
            'tagkey' => 'wurzeln', 'label' => 'Wurzeln',
        ])['tag']['id'];
        $coursetag = tag_service::save_tag($module, [
            'scope' => 'course', 'kind' => 'competence',
            'tagkey' => 'analysieren', 'label' => 'Analysieren',
        ])['tag']['id'];
        tag_service::save_question_tags(
            $module,
            $questionid,
            [['tagId' => $activitytag], ['tagId' => $coursetag]],
            (int)$USER->id
        );

        $coursetagsbefore = $DB->count_records('quizgeist_tags', [
            'scope' => 'course',
            'scopeid' => (int)$course->id,
        ]);
        $this->assertSame(1, $coursetagsbefore);

        $restoredcmid = $this->duplicate_activity((int)$module->cmid, (int)$course->id);
        $restoredinstance = (int)$DB->get_field(
            'course_modules',
            'instance',
            ['id' => $restoredcmid],
            MUST_EXIST
        );

        // The course-wide vocabulary must not multiply with every restore.
        $this->assertSame($coursetagsbefore, $DB->count_records('quizgeist_tags', [
            'scope' => 'course',
            'scopeid' => (int)$course->id,
        ]));
        // The activity-scoped tag belongs to the NEW activity.
        $this->assertTrue($DB->record_exists('quizgeist_tags', [
            'scope' => 'activity',
            'scopeid' => $restoredinstance,
            'tagkey' => 'wurzeln',
        ]));

        $restoredquestion = $DB->get_record_sql(
            'SELECT id, rootid
               FROM {quizgeist_questions}
              WHERE quizgeistid = :quizgeistid
           ORDER BY id ASC',
            ['quizgeistid' => $restoredinstance],
            MUST_EXIST
        );
        $restoredroot = (int)$restoredquestion->rootid > 0
            ? (int)$restoredquestion->rootid
            : (int)$restoredquestion->id;
        $assignments = tag_repository::assignments_for_root($restoredroot);

        $this->assertCount(2, $assignments);
        $keys = array_map(
            static fn(\stdClass $row): string => (string)$row->tagkey,
            $assignments
        );
        sort($keys);
        $this->assertSame(['analysieren', 'wurzeln'], $keys);
        // Every restored assignment hangs on the restored ROOT.
        foreach ($assignments as $assignment) {
            $this->assertSame($restoredroot, (int)$assignment->rootid);
            $this->assertSame($restoredinstance, (int)$assignment->quizgeistid);
        }
    }

    /**
     * Run one genuine Moodle backup/restore cycle for a single activity.
     *
     * @param int $sourcecmid Course-module to duplicate.
     * @param int $courseid Target course.
     * @return int Restored course-module ID.
     */
    private function duplicate_activity(int $sourcecmid, int $courseid): int {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $moduleid = (int)$DB->get_field(
            'modules',
            'id',
            ['name' => 'quizgeist'],
            MUST_EXIST
        );
        $before = array_map('intval', array_keys($DB->get_records(
            'course_modules',
            ['course' => $courseid, 'module' => $moduleid],
            '',
            'id'
        )));

        $CFG->keeptempdirectoriesonbackup = true;
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $backup = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $sourcecmid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            (int)$USER->id
        );
        $backupid = (string)$backup->get_backupid();
        $backup->execute_plan();
        $backup->destroy();

        $restore = new \restore_controller(
            $backupid,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            (int)$USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        if (!$restore->execute_precheck()) {
            $restore->destroy();
            $this->fail('Moodle restore precheck failed.');
        }
        $restore->execute_plan();
        $restore->destroy();

        $after = array_map('intval', array_keys($DB->get_records(
            'course_modules',
            ['course' => $courseid, 'module' => $moduleid],
            '',
            'id'
        )));
        $created = array_values(array_diff($after, $before));
        $this->assertCount(1, $created, 'Genau eine Kopie wird erwartet.');
        return (int)$created[0];
    }
}
