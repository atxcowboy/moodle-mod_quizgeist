<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\report;

defined('MOODLE_INTERNAL') || die();

/**
 * One module's capability, group and learner-visibility boundary.
 */
final class report_access {

    /** @var array<int, true>|null Null means all users are visible. */
    private ?array $visibleuserids;

    /** @var \cm_info Canonical course-module information. */
    private \cm_info $cminfo;

    /**
     * @param \stdClass|\cm_info $cm Course module.
     * @param \stdClass $viewer Authenticated viewer.
     * @param int $requestedgroupid Requested activity group.
     */
    private function __construct(
        \stdClass|\cm_info $cm,
        private readonly \stdClass $viewer,
        private readonly \context_module $context,
        private readonly bool $teacher,
        private readonly int $groupid
    ) {
        $this->cminfo = $cm instanceof \cm_info
            ? $cm
            : get_fast_modinfo((int)$cm->course, (int)$viewer->id)->get_cm(
                (int)$cm->id
            );
        if (!$teacher) {
            $this->visibleuserids = [(int)$viewer->id => true];
            return;
        }
        if ($groupid === 0) {
            $this->visibleuserids = null;
            return;
        }
        global $DB;
        $userids = $DB->get_fieldset_select(
            'groups_members',
            'userid',
            'groupid = :groupid',
            ['groupid' => $groupid]
        );
        $this->visibleuserids = array_fill_keys(
            array_map('intval', $userids),
            true
        );
    }

    /**
     * Resolve and enforce report access for one activity.
     */
    public static function for_module(
        \stdClass|\cm_info $cm,
        \context_module $context,
        \stdClass $viewer,
        int $requestedgroupid
    ): self {
        $cminfo = $cm instanceof \cm_info
            ? $cm
            : get_fast_modinfo((int)$cm->course, (int)$viewer->id)->get_cm(
                (int)$cm->id
            );
        $teacher = has_capability(
            'mod/quizgeist:viewreports',
            $context,
            (int)$viewer->id
        );
        // Reports must follow the same effective module/section visibility and
        // availability boundary as the activity itself. This is especially
        // important for the course scope: viewreports on another hidden module
        // does not, by itself, make that module visible to a report-only role.
        if (!$cminfo->uservisible) {
            throw new \required_capability_exception(
                $context,
                'moodle/course:viewhiddenactivities',
                'nopermissions',
                ''
            );
        }
        if (!$teacher) {
            require_capability(
                'mod/quizgeist:play',
                $context,
                (int)$viewer->id
            );
            // A learner's own-data scope is stronger than any requested group.
            return new self($cminfo, $viewer, $context, false, 0);
        }

        $groupmode = (int)groups_get_activity_groupmode($cminfo);
        if ($groupmode === NOGROUPS) {
            if ($requestedgroupid !== 0) {
                throw new \invalid_parameter_exception(
                    'This activity does not use groups.'
                );
            }
            return new self($cminfo, $viewer, $context, true, 0);
        }

        $allowedgroups = groups_get_activity_allowed_groups(
            $cminfo,
            (int)$viewer->id
        );
        $allowedgroups = is_array($allowedgroups) ? $allowedgroups : [];
        if ($requestedgroupid > 0
                && !array_key_exists($requestedgroupid, $allowedgroups)) {
            throw new \required_capability_exception(
                $context,
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
        $accessall = has_capability(
            'moodle/site:accessallgroups',
            $context,
            (int)$viewer->id
        );
        if ($requestedgroupid === 0
                && $groupmode === SEPARATEGROUPS
                && !$accessall) {
            throw new \required_capability_exception(
                $context,
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
        return new self(
            $cminfo,
            $viewer,
            $context,
            true,
            $requestedgroupid
        );
    }

    public function is_teacher(): bool {
        return $this->teacher;
    }

    public function can_view_user(int $userid): bool {
        return $userid > 0
            && ($this->visibleuserids === null
                || isset($this->visibleuserids[$userid]));
    }

    /**
     * The authenticated viewer this boundary was built for.
     *
     * @return int
     */
    public function viewer_id(): int {
        return (int)$this->viewer->id;
    }

    public function group_id(): int {
        return $this->groupid;
    }

    public function context(): \context_module {
        return $this->context;
    }

    public function cm(): \cm_info {
        return $this->cminfo;
    }

    /**
     * Active enrolled learners eligible to play in this activity/group.
     *
     * @return int[]
     */
    public function eligible_user_ids(): array {
        global $CFG;
        require_once($CFG->libdir . '/enrollib.php');

        if (!$this->teacher) {
            return is_enrolled(
                $this->context,
                (int)$this->viewer->id,
                'mod/quizgeist:play',
                true
            ) ? [(int)$this->viewer->id] : [];
        }
        $users = get_enrolled_users(
            $this->context,
            'mod/quizgeist:play',
            $this->groupid,
            'u.id',
            null,
            0,
            0,
            true
        );
        return array_values(array_map(
            static fn(\stdClass $user): int => (int)$user->id,
            $users
        ));
    }

    /**
     * Group selector DTOs which Moodle says this viewer may inspect.
     */
    public function groups(): array {
        if (!$this->teacher
                || (int)groups_get_activity_groupmode($this->cminfo)
                    === NOGROUPS) {
            return [];
        }
        $groups = groups_get_activity_allowed_groups(
            $this->cminfo,
            (int)$this->viewer->id
        );
        if (!is_array($groups)) {
            return [];
        }
        return array_values(array_map(
            static fn(\stdClass $group): array => [
                'id' => (int)$group->id,
                'name' => format_string((string)$group->name),
            ],
            $groups
        ));
    }
}
