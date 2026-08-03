<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\report;

use mod_quizgeist\local\addon\registry as addon_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves report sources, module access and Moodle group scope.
 *
 * Keeping this boundary separate prevents a source rejected for one role or
 * grouping from influencing the projection role of another source.
 */
final class report_catalogue {

    /**
     * Source catalogue and viewer defaults.
     */
    public static function bootstrap(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $viewer
    ): array {
        $teacher = has_capability(
            'mod/quizgeist:viewreports',
            $context,
            (int)$viewer->id
        );
        $groupid = self::default_group_id($cm, $context, $viewer);
        $access = report_access::for_module(
            $cm,
            $context,
            $viewer,
            $groupid
        );
        $reportsinstalled = addon_registry::is_installed(
            'quizgeistaddon_reports'
        );
        $sources = self::filter_addon_sources(self::normalise_instance_sources(
            report_repository::instance_sources((int)$quizgeist->id),
            $cm,
            $quizgeist
        ));
        $participation = $teacher
            ? ['sessions' => [], 'assignments' => []]
            : report_repository::viewer_participation($sources, (int)$viewer->id);
        $sources = array_values(array_filter(
            $sources,
            static fn(array $source): bool => self::source_visible_to_viewer(
                $source,
                $access,
                $participation
            )
        ));
        $firstkey = $sources ? (string)$sources[0]['key'] : null;
        $coursegroups = $teacher && $reportsinstalled
            ? self::course_group_options((int)$cm->course, $viewer, true)
            : ['groups' => [], 'defaultGroupId' => 0, 'canSelectAll' => false];
        return [
            'viewer' => [
                'kind' => $teacher ? 'teacher' : 'student',
                'canViewOthers' => $teacher,
                'canExport' => true,
                'canProfileLinks' => $teacher,
            ],
            'selection' => [
                'scope' => $firstkey === null && $reportsinstalled
                    ? 'course'
                    : 'session',
                'sourceKeys' => $firstkey === null ? [] : [$firstkey],
                'groupId' => $groupid,
            ],
            'groups' => $access->groups(),
            'courseGroups' => $coursegroups['groups'],
            'courseDefaultGroupId' => $coursegroups['defaultGroupId'],
            'courseCanSelectAllGroups' => $coursegroups['canSelectAll'],
            'reportsAddonInstalled' => $reportsinstalled,
            'sources' => $sources,
        ];
    }

    /**
     * Resolve selected sources and enforce every owning module separately.
     *
     * @return array{
     *     sources:array<string,array>,
     *     accesses:array<int,report_access>,
     *     teacher:bool,
     *     omittedSources:array<int,array>
     * }
     */
    public static function resolve(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $viewer,
        source_selection $selection
    ): array {
        $currentteacher = has_capability(
            'mod/quizgeist:viewreports',
            $context,
            (int)$viewer->id
        );
        if ($selection->scope !== 'course') {
            $access = report_access::for_module(
                $cm,
                $context,
                $viewer,
                $selection->groupid
            );
            $available = self::filter_addon_sources(self::normalise_instance_sources(
                report_repository::instance_sources((int)$quizgeist->id),
                $cm,
                $quizgeist
            ));
            $available = array_column($available, null, 'key');
            $participation = $currentteacher
                ? ['sessions' => [], 'assignments' => []]
                : report_repository::viewer_participation(
                    array_values($available),
                    (int)$viewer->id
                );
            $selected = [];
            foreach ($selection->sourcekeys as $sourcekey) {
                $source = $available[$sourcekey] ?? null;
                if (!is_array($source)
                        || !self::source_visible_to_viewer(
                            $source,
                            $access,
                            $participation
                        )) {
                    throw new \dml_missing_record_exception(
                        'quizgeist report source'
                    );
                }
                $selected[$sourcekey] = $source;
            }
            return [
                'sources' => $selected,
                'accesses' => [(int)$cm->id => $access],
                'teacher' => $currentteacher,
                'omittedSources' => [],
            ];
        }

        $catalogue = report_repository::course_sources((int)$cm->course);
        $normalised = self::filter_addon_sources(self::normalise_course_sources(
            $catalogue,
            (int)$cm->course
        ));
        $participation = $currentteacher
            ? ['sessions' => [], 'assignments' => []]
            : report_repository::viewer_participation(
                $normalised,
                (int)$viewer->id
            );
        $sources = [];
        $accesses = [];
        $rejected = [];
        $omitted = [];
        $modinfo = get_fast_modinfo(
            (int)$cm->course,
            (int)$viewer->id
        );
        foreach ($normalised as $source) {
            $cmid = (int)$source['cmid'];
            if (isset($rejected[$cmid])) {
                continue;
            }
            if (!isset($accesses[$cmid])) {
                $sourcecontext = \context_module::instance($cmid);
                $needed = $currentteacher
                    ? 'mod/quizgeist:viewreports'
                    : 'mod/quizgeist:play';
                if (!has_capability($needed, $sourcecontext, (int)$viewer->id)) {
                    $rejected[$cmid] = true;
                    continue;
                }
                $sourcecm = $modinfo->get_cm($cmid);
                $sourcegroupid = 0;
                if ($currentteacher) {
                    $sourcegroupid = self::course_group_id(
                        $sourcecm,
                        $sourcecontext,
                        $viewer,
                        $selection->groupid
                    );
                    if ($sourcegroupid === null) {
                        $rejected[$cmid] = true;
                        $omitted[$cmid] = self::omitted_source(
                            $source,
                            'group_unavailable'
                        );
                        continue;
                    }
                }
                try {
                    $candidate = report_access::for_module(
                        $sourcecm,
                        $sourcecontext,
                        $viewer,
                        $sourcegroupid
                    );
                } catch (\required_capability_exception
                        | \invalid_parameter_exception $exception) {
                    // Group zero in separate-groups mode is a caller error,
                    // not a reason to silently narrow the course report.
                    if ($currentteacher
                            && $selection->groupid === 0
                            && (int)groups_get_activity_groupmode($sourcecm)
                                === SEPARATEGROUPS
                            && !has_capability(
                                'moodle/site:accessallgroups',
                                $sourcecontext,
                                (int)$viewer->id
                            )) {
                        throw $exception;
                    }
                    $rejected[$cmid] = true;
                    continue;
                }
                // Only a role consistent with the invoking module enters the
                // access map. This closes the mixed-role projection leak.
                if ($candidate->is_teacher() !== $currentteacher) {
                    $rejected[$cmid] = true;
                    continue;
                }
                $accesses[$cmid] = $candidate;
            }
            $access = $accesses[$cmid] ?? null;
            if (!$access instanceof report_access
                    || !self::source_visible_to_viewer(
                        $source,
                        $access,
                        $participation
                    )) {
                continue;
            }
            $sources[$source['key']] = $source;
        }
        return [
            'sources' => $sources,
            'accesses' => $accesses,
            'teacher' => $currentteacher,
            'omittedSources' => array_values($omitted),
        ];
    }

    /**
     * @return array<int,array>
     */
    private static function normalise_instance_sources(
        array $catalogue,
        \stdClass $cm,
        \stdClass $quizgeist
    ): array {
        $sources = [];
        foreach ($catalogue['sessions'] as $session) {
            $sources[] = self::session_source(
                $session,
                (int)$cm->id,
                (int)$cm->course,
                (string)$quizgeist->name
            );
        }
        foreach ($catalogue['assignments'] as $assignment) {
            $sources[] = self::assignment_source(
                $assignment,
                (int)$cm->id,
                (int)$cm->course,
                (string)$quizgeist->name
            );
        }
        return $sources;
    }

    /**
     * @return array<int,array>
     */
    private static function normalise_course_sources(
        array $catalogue,
        int $courseid
    ): array {
        $sources = [];
        foreach ($catalogue['sessions'] as $session) {
            $sources[] = self::session_source(
                $session,
                (int)$session->cmid,
                $courseid,
                (string)$session->quizgeistname
            );
        }
        foreach ($catalogue['assignments'] as $assignment) {
            $sources[] = self::assignment_source(
                $assignment,
                (int)$assignment->cmid,
                $courseid,
                (string)$assignment->quizgeistname
            );
        }
        return $sources;
    }

    private static function session_source(
        \stdClass $session,
        int $cmid,
        int $courseid,
        string $quizgeistname
    ): array {
        $timestamp = (int)$session->timeended
            ?: ((int)$session->timestarted ?: (int)$session->timecreated);
        return [
            'key' => 'session:' . (int)$session->id,
            'kind' => 'session',
            'id' => (int)$session->id,
            'quizgeistId' => (int)$session->quizgeistid,
            'cmid' => $cmid,
            'courseid' => $courseid,
            'name' => format_string($quizgeistname) . ' · ' . userdate($timestamp),
            'instanceName' => format_string($quizgeistname),
            'status' => (string)$session->status,
            'mode' => (string)$session->mode,
            'startedAt' => (int)$session->timestarted,
            'endedAt' => (int)$session->timeended,
        ];
    }

    private static function assignment_source(
        \stdClass $assignment,
        int $cmid,
        int $courseid,
        string $quizgeistname
    ): array {
        return [
            'key' => 'assignment:' . (int)$assignment->id,
            'kind' => 'assignment',
            'id' => (int)$assignment->id,
            'quizgeistId' => (int)$assignment->quizgeistid,
            'cmid' => $cmid,
            'courseid' => $courseid,
            'name' => format_string((string)$assignment->name),
            'instanceName' => format_string($quizgeistname),
            'status' => (string)$assignment->status,
            'mode' => (string)$assignment->mode,
            'startedAt' => (int)$assignment->timeopen
                ?: (int)$assignment->timecreated,
            'endedAt' => (int)$assignment->timedue,
        ];
    }

    private static function source_visible_to_viewer(
        array $source,
        report_access $access,
        array $participation
    ): bool {
        if ($access->is_teacher()) {
            return true;
        }
        $bucket = $source['kind'] === 'session'
            ? 'sessions'
            : 'assignments';
        return isset($participation[$bucket][(int)$source['id']]);
    }

    /**
     * Keep addon-owned source kinds out of base-only catalogues.
     *
     * The records deliberately remain in the parent tables for backup,
     * privacy and later addon reinstallation; only their presentation and
     * execution surface is removed here.
     *
     * @param array<int,array> $sources
     * @return array<int,array>
     */
    private static function filter_addon_sources(array $sources): array {
        $reportsinstalled = addon_registry::is_installed(
            'quizgeistaddon_reports'
        );
        $selfstudyinstalled = addon_registry::is_installed(
            'quizgeistaddon_selfstudy'
        );
        return array_values(array_filter(
            $sources,
            static function(array $source) use (
                $reportsinstalled,
                $selfstudyinstalled
            ): bool {
                $kind = (string)($source['kind'] ?? '');
                if ($kind === 'assignment' && !$selfstudyinstalled) {
                    return false;
                }
                return $reportsinstalled || $kind === 'session';
            }
        ));
    }

    private static function default_group_id(
        \stdClass $cm,
        \context_module $context,
        \stdClass $viewer
    ): int {
        if (!has_capability(
            'mod/quizgeist:viewreports',
            $context,
            (int)$viewer->id
        )) {
            return 0;
        }
        $cminfo = get_fast_modinfo(
            (int)$cm->course,
            (int)$viewer->id
        )->get_cm((int)$cm->id);
        if ((int)groups_get_activity_groupmode($cminfo) !== SEPARATEGROUPS
                || has_capability(
                    'moodle/site:accessallgroups',
                    $context,
                    (int)$viewer->id
                )) {
            return 0;
        }
        $groups = groups_get_activity_allowed_groups(
            $cminfo,
            (int)$viewer->id
        );
        if (!is_array($groups) || !$groups) {
            throw new \required_capability_exception(
                $context,
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
        return (int)array_key_first($groups);
    }

    /**
     * Effective course-wide group against one activity.
     *
     * @return int|null Null means an explicit group is not in this grouping.
     */
    private static function course_group_id(
        \cm_info $cm,
        \context_module $context,
        \stdClass $viewer,
        int $requestedgroupid
    ): ?int {
        $groupmode = (int)groups_get_activity_groupmode($cm);
        if ($groupmode === NOGROUPS) {
            return 0;
        }
        $allowedgroups = groups_get_activity_allowed_groups(
            $cm,
            (int)$viewer->id
        );
        $allowedgroups = is_array($allowedgroups) ? $allowedgroups : [];
        if ($requestedgroupid > 0) {
            return isset($allowedgroups[$requestedgroupid])
                ? $requestedgroupid
                : null;
        }
        if ($groupmode === SEPARATEGROUPS
                && !has_capability(
                    'moodle/site:accessallgroups',
                    $context,
                    (int)$viewer->id
                )) {
            throw new \required_capability_exception(
                $context,
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
        return 0;
    }

    /**
     * Union of groups offered by reportable, visible Quizgeist modules.
     *
     * @return array{groups:array,defaultGroupId:int,canSelectAll:bool}
     */
    private static function course_group_options(
        int $courseid,
        \stdClass $viewer,
        bool $teacher
    ): array {
        if (!$teacher) {
            return ['groups' => [], 'defaultGroupId' => 0, 'canSelectAll' => false];
        }
        $catalogue = self::filter_addon_sources(self::normalise_course_sources(
            report_repository::course_sources($courseid),
            $courseid
        ));
        $modinfo = get_fast_modinfo($courseid, (int)$viewer->id);
        $groups = [];
        $mustchoose = false;
        foreach (array_unique(array_map(
            static fn(array $source): int => (int)$source['cmid'],
            $catalogue
        )) as $cmid) {
            $context = \context_module::instance($cmid);
            if (!has_capability(
                'mod/quizgeist:viewreports',
                $context,
                (int)$viewer->id
            )) {
                continue;
            }
            $cm = $modinfo->get_cm($cmid);
            if (!$cm->uservisible
                    || (int)groups_get_activity_groupmode($cm) === NOGROUPS) {
                continue;
            }
            $allowed = groups_get_activity_allowed_groups(
                $cm,
                (int)$viewer->id
            );
            foreach (is_array($allowed) ? $allowed : [] as $group) {
                $groups[(int)$group->id] = [
                    'id' => (int)$group->id,
                    'name' => format_string((string)$group->name),
                ];
            }
            if ((int)groups_get_activity_groupmode($cm) === SEPARATEGROUPS
                    && !has_capability(
                        'moodle/site:accessallgroups',
                        $context,
                        (int)$viewer->id
                    )) {
                $mustchoose = true;
            }
        }
        uasort(
            $groups,
            static fn(array $left, array $right): int =>
                strnatcasecmp($left['name'], $right['name'])
                    ?: ($left['id'] <=> $right['id'])
        );
        return [
            'groups' => array_values($groups),
            'defaultGroupId' => $mustchoose && $groups
                ? (int)array_key_first($groups)
                : 0,
            'canSelectAll' => !$mustchoose,
        ];
    }

    private static function omitted_source(array $source, string $reason): array {
        return [
            'cmid' => (int)$source['cmid'],
            'instanceName' => (string)$source['instanceName'],
            'reason' => $reason,
        ];
    }
}
