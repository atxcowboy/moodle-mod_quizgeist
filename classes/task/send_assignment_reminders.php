<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Send deadline reminders for open self-study assignments.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\task;

use mod_quizgeist\local\selfstudy\assignment_settings;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends one Moodle notification per assignment and eligible learner.
 */
final class send_assignment_reminders extends \core\task\scheduled_task {

    /**
     * Maximum number of message delivery attempts per assignment and learner.
     */
    public const MAX_DELIVERY_ATTEMPTS = 3;

    /**
     * Localised task name assembled from existing plugin strings.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:sendassignmentreminders', 'mod_quizgeist');
    }

    /**
     * Find deadlines in the next 24 hours and send idempotent messages.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        if (!\mod_quizgeist\local\addon\registry::is_installed(
            'quizgeistaddon_selfstudy'
        )) {
            return;
        }
        $now = time();
        $recipienteligibility = [];
        $assignments = $DB->get_records_sql(
            'SELECT z.*, m.course, m.name AS quizgeistname
               FROM {quizgeist_assignments} z
               JOIN {quizgeist} m ON m.id = z.quizgeistid
              WHERE z.status = :open
                AND z.timedue > :now
                AND z.timedue <= :cutoff
                AND (z.timeopen = 0 OR z.timeopen <= :opened)
           ORDER BY z.timedue ASC, z.id ASC',
            [
                'open' => 'open',
                'now' => $now,
                'cutoff' => $now + DAYSECS,
                'opened' => $now,
            ]
        );
        foreach ($assignments as $assignment) {
            $settings = assignment_settings::decode(
                $assignment->settingsjson ?? null,
                (int)$assignment->timedue
            );
            if (!$settings['reminderEnabled']) {
                continue;
            }
            $cm = get_coursemodule_from_instance(
                'quizgeist',
                (int)$assignment->quizgeistid,
                (int)$assignment->course,
                false,
                IGNORE_MISSING
            );
            if (!$cm) {
                continue;
            }
            $context = \context_module::instance(
                (int)$cm->id,
                IGNORE_MISSING
            );
            if (!$context) {
                continue;
            }
            $terminalledgers = [];
            foreach ($DB->get_records(
                'quizgeist_assignment_reminders',
                ['assignmentid' => (int)$assignment->id]
            ) as $ledger) {
                if ((int)$ledger->timesent > 0
                        || (int)$ledger->attemptcount
                            >= self::MAX_DELIVERY_ATTEMPTS) {
                    $terminalledgers[(int)$ledger->userid] = true;
                }
            }
            $users = get_enrolled_users(
                $context,
                'mod/quizgeist:play',
                0,
                'u.*',
                'u.id ASC',
                0,
                0,
                true
            );
            foreach ($users as $user) {
                if (isset($terminalledgers[(int)$user->id])) {
                    continue;
                }
                $eligibilitykey = (int)$cm->id . ':' . (int)$user->id;
                if (!array_key_exists($eligibilitykey, $recipienteligibility)) {
                    $recipienteligibility[$eligibilitykey] =
                        $this->can_receive_reminder($cm, $context, $user);
                }
                if (!$recipienteligibility[$eligibilitykey]) {
                    continue;
                }
                try {
                    $this->send_one(
                        $assignment,
                        $cm,
                        $context,
                        $user
                    );
                } catch (\Throwable $exception) {
                    mtrace(
                        'mod_quizgeist: deadline reminder for assignment '
                        . (int)$assignment->id
                        . ' and user '
                        . (int)$user->id
                        . ' failed: '
                        . $exception->getMessage()
                    );
                }
            }
        }
    }

    /**
     * Recheck and send one reminder under a recipient-specific lock.
     *
     * @param \stdClass $assignment Assignment.
     * @param \stdClass $cm Course module.
     * @param \context_module $context Context.
     * @param \stdClass $user Recipient.
     * @return void
     */
    private function send_one(
        \stdClass $assignment,
        \stdClass $cm,
        \context_module $context,
        \stdClass $user
    ): void {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory(
            'mod_quizgeist_assignment_reminders'
        );
        $lock = $factory->get_lock(
            'deadline:' . (int)$assignment->id . ':' . (int)$user->id,
            10
        );
        if (!$lock) {
            return;
        }
        try {
            $current = $DB->get_record(
                'quizgeist_assignments',
                ['id' => (int)$assignment->id],
                '*',
                IGNORE_MISSING
            );
            $now = time();
            if (!$current
                    || (string)$current->status !== 'open'
                    || (int)$current->timedue <= $now
                    || (int)$current->timedue > $now + DAYSECS
                    || ((int)$current->timeopen > 0
                        && (int)$current->timeopen > $now)
                    || !assignment_settings::decode(
                        $current->settingsjson ?? null,
                        (int)$current->timedue
                    )['reminderEnabled']) {
                return;
            }
            // Recheck access under the recipient lock. Visibility, availability,
            // or enrolment can change after enumeration.
            if (!$this->can_receive_reminder($cm, $context, $user)) {
                return;
            }
            foreach ([
                'name',
                'timedue',
                'timeopen',
                'settingsjson',
            ] as $field) {
                $assignment->{$field} = $current->{$field};
            }
            $ledger = $DB->get_record(
                'quizgeist_assignment_reminders',
                [
                    'assignmentid' => (int)$assignment->id,
                    'userid' => (int)$user->id,
                ],
                '*',
                IGNORE_MISSING
            );
            if (($ledger
                    && ((int)$ledger->timesent > 0
                        || (int)$ledger->attemptcount
                            >= self::MAX_DELIVERY_ATTEMPTS))
                    || $DB->record_exists('quizgeist_attempts', [
                        'assignmentid' => (int)$assignment->id,
                        'userid' => (int)$user->id,
                        'status' => 'completed',
                    ])) {
                return;
            }
            $assignmentname = format_string(
                (string)$assignment->name,
                true,
                ['context' => $context]
            );
            $subject = get_string('tab:assignments', 'mod_quizgeist')
                . ': '
                . $assignmentname;
            $duedate = userdate(
                (int)$assignment->timedue,
                get_string('strftimedatetimeshort', 'langconfig'),
                (string)($user->timezone ?? 99)
            );
            $url = new \moodle_url('/mod/quizgeist/view.php', [
                'id' => (int)$cm->id,
                'view' => 'overview',
                'assignmentid' => (int)$assignment->id,
            ]);
            $plain = $subject . "\n" . $duedate . "\n" . $url->out(false);

            $message = new \core\message\message();
            $message->component = 'mod_quizgeist';
            $message->name = 'deadline_reminder';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $plain;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = \html_writer::tag(
                'p',
                s($subject)
            ) . \html_writer::tag('p', s($duedate))
                . \html_writer::link($url, s($assignmentname));
            $message->smallmessage = $subject . ' — ' . $duedate;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = $assignmentname;
            $message->courseid = (int)$assignment->course;
            $message->customdata = [
                'cmid' => (int)$cm->id,
                'quizgeistid' => (int)$assignment->quizgeistid,
                'assignmentid' => (int)$assignment->id,
            ];

            // Persist the attempt before invoking Moodle's message processors.
            // A processor failure or exception therefore consumes one of the
            // bounded attempts instead of causing an endless hourly retry.
            $reservation = $this->reserve_delivery_attempt(
                (int)$assignment->id,
                (int)$user->id,
                $ledger ?: null
            );
            if ($reservation === null) {
                return;
            }
            $ledgerid = (int)$reservation->id;
            $attemptcount = (int)$reservation->attemptcount;
            $messageid = message_send($message);
            if (!$messageid) {
                throw new \coding_exception(
                    'Moodle message delivery failed on attempt '
                    . $attemptcount
                    . ' of '
                    . self::MAX_DELIVERY_ATTEMPTS
                    . '.'
                );
            }
            $sentat = time();
            $DB->set_field(
                'quizgeist_assignment_reminders',
                'timesent',
                $sentat,
                ['id' => $ledgerid]
            );
            $DB->set_field(
                'quizgeist_attempts',
                'remindedat',
                $sentat,
                [
                    'assignmentid' => (int)$assignment->id,
                    'userid' => (int)$user->id,
                    'status' => 'inprogress',
                ]
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Persist one delivery budget slot before calling any message processor.
     *
     * Returning null is a terminal no-send decision. Keeping this mutation in
     * one method makes the failure budget independently regression-testable.
     */
    private function reserve_delivery_attempt(
        int $assignmentid,
        int $userid,
        ?\stdClass $ledger
    ): ?\stdClass {
        global $DB;

        if ($ledger && ((int)$ledger->timesent > 0
                || (int)$ledger->attemptcount
                    >= self::MAX_DELIVERY_ATTEMPTS)) {
            return null;
        }
        $attemptcount = min(
            self::MAX_DELIVERY_ATTEMPTS,
            max(0, (int)($ledger->attemptcount ?? 0)) + 1
        );
        if ($ledger) {
            $DB->update_record(
                'quizgeist_assignment_reminders',
                (object)[
                    'id' => (int)$ledger->id,
                    'attemptcount' => $attemptcount,
                    'timesent' => 0,
                ]
            );
        } else {
            $ledger = (object)[
                'id' => (int)$DB->insert_record(
                    'quizgeist_assignment_reminders',
                    (object)[
                        'assignmentid' => $assignmentid,
                        'userid' => $userid,
                        'attemptcount' => $attemptcount,
                        'timesent' => 0,
                    ]
                ),
                'assignmentid' => $assignmentid,
                'userid' => $userid,
            ];
        }
        $ledger->attemptcount = $attemptcount;
        $ledger->timesent = 0;
        return $ledger;
    }

    /**
     * Check activity access for a recipient.
     *
     * cm_info::uservisible applies module and section visibility plus Moodle
     * availability conditions for the target user.
     *
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $user Candidate recipient.
     * @return bool
     */
    private function can_receive_reminder(
        \stdClass $cm,
        \context_module $context,
        \stdClass $user
    ): bool {
        $userid = (int)$user->id;
        if (!empty($user->deleted)
                || !empty($user->suspended)
                || empty($user->confirmed)
                || !is_enrolled(
            $context,
            $userid,
            'mod/quizgeist:play',
            true
                )) {
            return false;
        }

        try {
            if (!can_access_course(
                get_course((int)$cm->course),
                $userid,
                '',
                true
            )) {
                return false;
            }
            $usercm = get_fast_modinfo((int)$cm->course, $userid)->get_cm(
                (int)$cm->id
            );
            if (!$usercm->uservisible) {
                return false;
            }
            return true;
        } catch (\moodle_exception $exception) {
            return false;
        }
    }
}
