<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the server-side Bühnen-Check metric boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\stage\stage_exception;
use quizgeistaddon_buehne\local\stage_metrics;

defined('MOODLE_INTERNAL') || die();

/**
 * Every client metric is bounded, rebuilt and validated before storage.
 */
final class stage_metrics_test extends \advanced_testcase {

    /**
     * Build a complete valid metric document.
     *
     * @param array<string,mixed> $overrides Values to replace.
     * @return array<string,mixed>
     */
    private function metrics(array $overrides = []): array {
        $metrics = [
            'mode' => stage_metrics::PRESENTATION_MODES[0],
            'movementclass' => stage_metrics::MOVEMENT_CLASSES[0],
            'timeline' => [],
            'events' => [],
        ];
        foreach (stage_metrics::RANGES as $field => [$minimum, $unusedmaximum]) {
            $metrics[$field] = $minimum;
        }
        return array_replace($metrics, $overrides);
    }

    /**
     * Assert one operation fails with a stable stage error code.
     *
     * @param callable():mixed $operation Operation expected to fail.
     * @param string $code Expected stage error code.
     * @return void
     */
    private function assertStageException(callable $operation, string $code): void {
        try {
            $operation();
            $this->fail("Expected stage exception {$code}.");
        } catch (stage_exception $exception) {
            $this->assertSame($code, $exception->get_error_code());
        }
    }

    /**
     * Every declared numeric range accepts both inclusive boundaries and
     * rejects the immediately adjacent values on either side.
     */
    public function test_every_metric_range_is_inclusive_and_strict(): void {
        $this->resetAfterTest(true);

        foreach (stage_metrics::RANGES as $field => [$minimum, $maximum]) {
            $atminimum = stage_metrics::validate($this->metrics([
                $field => $minimum,
            ]));
            $this->assertSame($minimum, $atminimum[$field], "{$field} minimum");

            $atmaximum = stage_metrics::validate($this->metrics([
                $field => $maximum,
            ]));
            $this->assertSame($maximum, $atmaximum[$field], "{$field} maximum");

            $this->assertStageException(
                fn() => stage_metrics::validate($this->metrics([
                    $field => $minimum - 0.01,
                ])),
                'stage_metrics_out_of_range'
            );
            $this->assertStageException(
                fn() => stage_metrics::validate($this->metrics([
                    $field => $maximum + 0.01,
                ])),
                'stage_metrics_out_of_range'
            );
        }
    }

    /**
     * Presentation duration has an inclusive zero-to-one-hour boundary.
     */
    public function test_duration_boundaries_are_inclusive_and_strict(): void {
        $this->resetAfterTest(true);

        $this->assertSame(0, stage_metrics::duration(0));
        $this->assertSame(
            stage_metrics::MAX_DURATION_SECS,
            stage_metrics::duration(stage_metrics::MAX_DURATION_SECS)
        );

        foreach ([-1, stage_metrics::MAX_DURATION_SECS + 1] as $duration) {
            $this->assertStageException(
                fn() => stage_metrics::duration($duration),
                'stage_duration_invalid'
            );
        }
    }

    /**
     * The timeline count ceiling is inclusive, with one additional point
     * rejected before any point-level validation occurs.
     */
    public function test_timeline_point_limit_is_inclusive(): void {
        $this->resetAfterTest(true);

        $point = ['t' => 0, 'eye' => 0, 'gest' => 0, 'sway' => 0];
        $valid = $this->metrics([
            'timeline' => array_fill(0, stage_metrics::MAX_TIMELINE_POINTS, $point),
        ]);
        $validated = stage_metrics::validate($valid);
        $this->assertCount(stage_metrics::MAX_TIMELINE_POINTS, $validated['timeline']);

        $too_many = $this->metrics([
            'timeline' => array_fill(0, stage_metrics::MAX_TIMELINE_POINTS + 1, $point),
        ]);
        $this->assertStageException(
            fn() => stage_metrics::validate($too_many),
            'stage_metrics_too_many_points'
        );
    }

    /**
     * The event count ceiling follows the same inclusive boundary contract.
     */
    public function test_event_limit_is_inclusive(): void {
        $this->resetAfterTest(true);

        $event = ['t' => 0, 'dur' => 0, 'type' => stage_metrics::EVENT_TYPES[0]];
        $valid = $this->metrics([
            'events' => array_fill(0, stage_metrics::MAX_EVENTS, $event),
        ]);
        $validated = stage_metrics::validate($valid);
        $this->assertCount(stage_metrics::MAX_EVENTS, $validated['events']);

        $too_many = $this->metrics([
            'events' => array_fill(0, stage_metrics::MAX_EVENTS + 1, $event),
        ]);
        $this->assertStageException(
            fn() => stage_metrics::validate($too_many),
            'stage_metrics_too_many_points'
        );
    }

    /**
     * The raw JSON size guard runs before decoding.
     */
    public function test_json_size_limit_rejects_one_byte_over_maximum(): void {
        $this->resetAfterTest(true);

        $json = json_encode(
            str_repeat('x', stage_metrics::MAX_JSON_BYTES - 1),
            JSON_UNESCAPED_SLASHES
        );
        $this->assertIsString($json);
        $this->assertSame(stage_metrics::MAX_JSON_BYTES + 1, strlen($json));
        $this->assertStageException(
            fn() => stage_metrics::validate($json),
            'stage_metrics_too_large'
        );
    }

    /**
     * Validation rebuilds the document and never passes unknown client keys
     * through to storage.
     */
    public function test_unknown_keys_are_not_retained(): void {
        $this->resetAfterTest(true);

        $validated = stage_metrics::validate($this->metrics([
            'unknownClientField' => 'must not survive',
        ]));

        $this->assertArrayNotHasKey('unknownClientField', $validated);
    }

    /**
     * Movement classes and presentation modes accept exactly their enums.
     */
    public function test_movement_class_and_mode_use_only_declared_enums(): void {
        $this->resetAfterTest(true);

        foreach (stage_metrics::MOVEMENT_CLASSES as $movementclass) {
            $validated = stage_metrics::validate($this->metrics([
                'movementclass' => $movementclass,
            ]));
            $this->assertSame($movementclass, $validated['movementclass']);
        }
        foreach (stage_metrics::PRESENTATION_MODES as $mode) {
            $validated = stage_metrics::validate($this->metrics([
                'mode' => $mode,
            ]));
            $this->assertSame($mode, $validated['mode']);
        }

        foreach (['', 'unbekannt', null, 1] as $movementclass) {
            $this->assertStageException(
                fn() => stage_metrics::validate($this->metrics([
                    'movementclass' => $movementclass,
                ])),
                'stage_metrics_out_of_range'
            );
        }
        foreach (['', 'standing', null, 1] as $mode) {
            $this->assertStageException(
                fn() => stage_metrics::validate($this->metrics([
                    'mode' => $mode,
                ])),
                'stage_metrics_out_of_range'
            );
        }
    }
}
