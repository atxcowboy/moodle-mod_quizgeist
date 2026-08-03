<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Context for one live interaction submission.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries server-owned visit and stage data into a question strategy.
 */
final class submission_context {

    public function __construct(
        public readonly string $stage = 'answer',
        public readonly string $kind = 'answer',
        public readonly string $role = 'player',
        public readonly int $playerid = 0,
        public readonly string $visit = '',
        public readonly ?string $submissionkey = null,
        public readonly ?array $allowedreferenceids = null,
        public readonly ?array $referenceanswers = null,
        public readonly bool $trusted = false
    ) {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $stage)
                || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $kind)
                || !in_array($role, ['host', 'player'], true)
                || $playerid < 0
                || ($visit === '' && !$trusted)
                || ($visit !== '' && !preg_match('/^[a-f0-9]{32}$/D', $visit))
                || !self::valid_reference_ids($allowedreferenceids)
                || !self::valid_reference_answers($referenceanswers)) {
            throw new \invalid_parameter_exception('Live submission context is invalid.');
        }
    }

    /**
     * Make an explicit server-internal context for persisted canonical data.
     *
     * Empty visits allow canonical IDs and must never be constructed from a
     * client request. Keeping this exception named makes that trust boundary
     * reviewable instead of encoding it as a magic empty string.
     */
    public static function internal(
        string $stage,
        string $kind,
        string $role = 'player'
    ): self {
        return new self(
            $stage,
            $kind,
            $role,
            0,
            '',
            null,
            null,
            null,
            true
        );
    }

    /**
     * Validate an authoritative, visit-scoped reference allow-list.
     */
    private static function valid_reference_ids(?array $ids): bool {
        if ($ids === null || array_is_list($ids) === false) {
            return $ids === null;
        }
        $seen = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id <= 0 || isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
        }
        return true;
    }

    /**
     * Validate authoritative, visit-scoped reference answer payloads.
     */
    private static function valid_reference_answers(?array $answers): bool {
        if ($answers === null || array_is_list($answers) === false) {
            return $answers === null;
        }
        $seen = [];
        foreach ($answers as $answer) {
            if (!is_array($answer)
                    || !is_int($answer['id'] ?? null)
                    || $answer['id'] <= 0
                    || isset($seen[$answer['id']])
                    || !is_string($answer['answerType'] ?? null)
                    || !preg_match(
                        '/^[a-z][a-z0-9_-]{0,31}$/D',
                        $answer['answerType']
                    )
                    || !is_array($answer['payload'] ?? null)
                    || array_is_list($answer['payload'])) {
                return false;
            }
            $seen[$answer['id']] = true;
        }
        return true;
    }
}
