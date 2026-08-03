<?php
// This file is part of Moodle - https://moodle.org/

namespace mod_quizgeist\local\report;

use mod_quizgeist\local\addon\registry as addon_registry;
use mod_quizgeist\local\licence\feature_gate;
use mod_quizgeist\local\transaction_scope;

defined('MOODLE_INTERNAL') || die();

/**
 * Thin report application service.
 *
 * Catalogue/access resolution, projection and export formatting deliberately
 * live in separate collaborators so each boundary can be tested in isolation.
 */
final class report_service {

    /**
     * Source catalogue and viewer defaults.
     */
    public static function bootstrap(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $viewer
    ): array {
        return report_catalogue::bootstrap(
            $cm,
            $quizgeist,
            $context,
            $viewer
        );
    }

    /**
     * Build one internally consistent report projection.
     *
     * @param bool $interactive Whether large review lists may be capped for UI.
     */
    public static function build(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $viewer,
        source_selection $selection,
        bool $interactive = true
    ): array {
        self::require_selection_feature($selection);
        $transaction = transaction_scope::begin();
        try {
            $resolved = report_catalogue::resolve(
                $cm,
                $quizgeist,
                $context,
                $viewer,
                $selection
            );
            $report = report_projector::project(
                $resolved['sources'],
                $resolved['accesses'],
                $selection,
                $resolved['teacher'],
                $resolved['omittedSources'],
                $interactive
            );
            $report = self::apply_feature_visibility($report);
            $transaction->allow_commit();
            return $report;
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Stable dataformat columns for every report row kind.
     */
    public static function export_columns(): array {
        return report_export::columns();
    }

    /**
     * Flatten the report DTO without retaining another row collection.
     *
     * @return \Generator<int,array>
     */
    public static function export_rows(array $report): \Generator {
        yield from report_export::rows($report);
    }

    /**
     * Prepare a bounded-memory export for one authorised selection.
     *
     * Full distribution DTOs are written to PHP's self-cleaning temporary
     * stream as they are projected. The returned generator then reads one
     * distribution at a time, while the smaller KPI/question/student model
     * remains in memory. Projection happens before response headers are sent
     * so an authorization or data error can still produce a normal Moodle
     * error page.
     *
     * @return \Generator<int,array>
     */
    public static function export_rows_for_selection(
        \stdClass $cm,
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $viewer,
        source_selection $selection
    ): \Generator {
        self::require_selection_feature($selection);
        $spool = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($spool === false) {
            throw new \coding_exception(
                'Report distribution spool is unavailable.'
            );
        }
        $distributionindex = [];
        $consumer = static function(
            string $rootkey,
            array $distribution
        ) use ($spool, &$distributionindex): void {
            $encoded = json_encode(
                $distribution,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
            );
            $offset = self::write_spool_record($spool, $encoded);
            $distributionindex[$rootkey][] = [
                'sourceKey' => (string)$distribution['sourceKey'],
                'questionId' => (int)$distribution['questionId'],
                'offset' => $offset,
                'length' => strlen($encoded),
            ];
        };

        $transaction = transaction_scope::begin();
        try {
            $resolved = report_catalogue::resolve(
                $cm,
                $quizgeist,
                $context,
                $viewer,
                $selection
            );
            $report = report_projector::project(
                $resolved['sources'],
                $resolved['accesses'],
                $selection,
                $resolved['teacher'],
                $resolved['omittedSources'],
                false,
                $consumer
            );
            $report = self::apply_feature_visibility($report);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            fclose($spool);
            $transaction->rollback($exception);
        }

        foreach ($distributionindex as &$entries) {
            usort(
                $entries,
                static fn(array $left, array $right): int =>
                    strcmp($left['sourceKey'], $right['sourceKey'])
                    ?: ($left['questionId'] <=> $right['questionId'])
                    ?: ($left['offset'] <=> $right['offset'])
            );
        }
        unset($entries);

        $provider = static function(array $question) use (
            $spool,
            $distributionindex
        ): \Generator {
            foreach (
                $distributionindex[(string)$question['rootKey']] ?? []
                as $entry
            ) {
                if (fseek($spool, (int)$entry['offset']) !== 0) {
                    throw new \coding_exception(
                        'Report distribution spool could not be positioned.'
                    );
                }
                $encoded = stream_get_contents(
                    $spool,
                    (int)$entry['length']
                );
                if (!is_string($encoded)
                        || strlen($encoded) !== (int)$entry['length']) {
                    throw new \coding_exception(
                        'Report distribution spool could not be read.'
                    );
                }
                $distribution = json_decode(
                    $encoded,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
                if (!is_array($distribution)
                        || array_is_list($distribution)) {
                    throw new \coding_exception(
                        'Report distribution spool contains invalid data.'
                    );
                }
                yield $distribution;
            }
        };

        return (static function() use (
            $spool,
            $report,
            $provider
        ): \Generator {
            try {
                yield from report_export::rows($report, $provider);
            } finally {
                fclose($spool);
            }
        })();
    }

    /**
     * Write one complete spool record and return its byte offset.
     *
     * @param resource $spool Temporary stream.
     */
    private static function write_spool_record(
        $spool,
        string $encoded
    ): int {
        $offset = ftell($spool);
        if ($offset === false) {
            throw new \coding_exception(
                'Report distribution spool position is unavailable.'
            );
        }
        $written = 0;
        $length = strlen($encoded);
        while ($written < $length) {
            $chunk = fwrite($spool, substr($encoded, $written));
            if ($chunk === false || $chunk === 0) {
                throw new \coding_exception(
                    'Report distribution spool could not be written.'
                );
            }
            $written += $chunk;
        }
        return $offset;
    }

    /**
     * Combined, course and assignment projections belong to Reports Pro.
     *
     * Read-only entitlement states deliberately use VIEW_EXISTING: these
     * reports only project already stored sessions/attempts and must remain
     * available for inspection and export.
     */
    private static function require_selection_feature(
        source_selection $selection
    ): void {
        $premium = $selection->scope !== 'session';
        foreach ($selection->sourcekeys as $sourcekey) {
            if (str_starts_with($sourcekey, 'assignment:')) {
                $premium = true;
                break;
            }
        }
        if ($premium) {
            feature_gate::require(
                'reports',
                feature_gate::VIEW_EXISTING
            );
        }
    }

    /**
     * Remove Reports-Pro-only analysis fields from the base session report.
     */
    private static function apply_feature_visibility(array $report): array {
        if (addon_registry::is_installed('quizgeistaddon_reports')) {
            return $report;
        }
        $report['moderationTrail'] = [];
        $report['moderationTrailTruncated'] = false;
        $report['timeline'] = [];
        if (isset($report['kpis']) && is_array($report['kpis'])) {
            $report['kpis']['hardestRootKey'] = null;
            $report['kpis']['hardestQuestion'] = null;
        }
        if (isset($report['questions']) && is_array($report['questions'])) {
            foreach ($report['questions'] as &$question) {
                if (is_array($question)) {
                    $question['difficult'] = false;
                }
            }
            unset($question);
        }
        return $report;
    }
}
