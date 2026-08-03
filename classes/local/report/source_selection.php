<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\report;

defined('MOODLE_INTERNAL') || die();

/**
 * Strict report-source selection shared by AJAX and downloads.
 */
final class source_selection {

    /** Maximum explicitly combined sources in one interactive report. */
    private const MAX_SOURCES = 100;

    /**
     * @param string $scope session, combined or course.
     * @param array $sourcekeys Public source keys.
     * @param int $groupid Moodle activity group, or zero.
     */
    private function __construct(
        public readonly string $scope,
        public readonly array $sourcekeys,
        public readonly int $groupid
    ) {
    }

    /**
     * Validate a public report selection.
     *
     * A "session" scope represents one concrete source and deliberately also
     * accepts an assignment key. This mirrors the single-source report tab.
     *
     * @param mixed $scope
     * @param mixed $sourcekeys
     * @param mixed $groupid
     */
    public static function from_values(
        $scope,
        $sourcekeys,
        $groupid
    ): self {
        if (!is_string($scope)
                || !in_array($scope, ['session', 'combined', 'course'], true)
                || !is_array($sourcekeys)
                || !array_is_list($sourcekeys)
                || (!is_int($groupid) && !is_string($groupid))) {
            throw new \invalid_parameter_exception(
                'Report selection is invalid.'
            );
        }
        if (is_string($groupid)
                && !preg_match('/^(0|[1-9][0-9]*)$/D', $groupid)) {
            throw new \invalid_parameter_exception(
                'Report group is invalid.'
            );
        }
        $canonicalgroupid = (int)$groupid;
        if ($canonicalgroupid < 0) {
            throw new \invalid_parameter_exception(
                'Report group is invalid.'
            );
        }

        $canonicalkeys = [];
        foreach ($sourcekeys as $sourcekey) {
            if (!is_string($sourcekey)
                    || !preg_match(
                        '/^(session|assignment):([1-9][0-9]*)$/D',
                        $sourcekey
                    )) {
                throw new \invalid_parameter_exception(
                    'Report source key is invalid.'
                );
            }
            $canonicalkeys[$sourcekey] = true;
        }
        $canonicalkeys = array_keys($canonicalkeys);
        if (count($canonicalkeys) > self::MAX_SOURCES) {
            throw new \invalid_parameter_exception(
                'Too many report sources were selected.'
            );
        }
        if ($scope === 'session' && count($canonicalkeys) !== 1) {
            throw new \invalid_parameter_exception(
                'A single-source report needs exactly one source.'
            );
        }
        if ($scope === 'combined' && count($canonicalkeys) < 2) {
            throw new \invalid_parameter_exception(
                'A combined report needs at least two sources.'
            );
        }
        if ($scope === 'course' && $canonicalkeys) {
            throw new \invalid_parameter_exception(
                'A course report resolves its sources server-side.'
            );
        }

        return new self($scope, $canonicalkeys, $canonicalgroupid);
    }

    /**
     * Parse the action-specific part of an AJAX payload.
     */
    public static function from_payload(array $payload): self {
        return self::from_values(
            $payload['scope'] ?? null,
            $payload['sourceKeys'] ?? null,
            $payload['groupId'] ?? null
        );
    }

    /**
     * Return the stable public DTO.
     */
    public function dto(): array {
        return [
            'scope' => $this->scope,
            'sourceKeys' => array_values($this->sourcekeys),
            'groupId' => $this->groupid,
        ];
    }

    /**
     * Split keys by source kind.
     *
     * @return array{session:int[],assignment:int[]}
     */
    public function ids(): array {
        $ids = ['session' => [], 'assignment' => []];
        foreach ($this->sourcekeys as $sourcekey) {
            [$kind, $id] = explode(':', $sourcekey, 2);
            $ids[$kind][] = (int)$id;
        }
        return $ids;
    }
}
