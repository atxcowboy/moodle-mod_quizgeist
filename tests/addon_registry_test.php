<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the addon declaration contract.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\addon\registry as addon_registry;
use mod_quizgeist\local\live\qtype\registry as qtype_registry;
use mod_quizgeist\local\live\session_settings;

defined('MOODLE_INTERNAL') || die();

/**
 * Declaring nothing is valid; declaring something malformed is not.
 */
final class addon_registry_test extends \advanced_testcase {

    /**
     * Every shipped addon component — READ, not written down.
     *
     * P11/C6: this used to be a hard-wired five-entry list. The sixth addon
     * would have left it silently wrong, and the test would have kept passing
     * while proving one addon less than exists. It now comes from the same
     * contract the licence gate uses (U5 decoupling).
     *
     * @return string[]
     */
    private function components(): array {
        return array_values(\mod_quizgeist\local\licence\service::COMPONENTS);
    }

    /** The seven optional question types, owned by the qtypes addon. */
    private const PREMIUM_QTYPES = [
        'puzzle',
        'scale',
        'slider',
        'pin',
        'reveal',
        'brainstorm',
        'open',
    ];

    /**
     * Mark every shipped addon as installed.
     *
     * @return void
     */
    private function install_all_addons(): void {
        foreach ($this->components() as $component) {
            set_config('version', 2026073000, $component);
        }
    }

    /**
     * Invoke one private merge helper of the addon registry.
     *
     * @param string $method Helper name.
     * @param array $combined Declarations accepted so far.
     * @param string $component Declaring component.
     * @param mixed $values Raw provider return value.
     * @return array
     */
    private function merge(
        string $method,
        array $combined,
        string $component,
        mixed $values
    ): array {
        $helper = new \ReflectionMethod(addon_registry::class, $method);
        return $helper->invoke(
            null,
            $combined,
            $component,
            'question type',
            $values
        );
    }

    // The defect: an addon without its own question types.

    public function test_addon_without_own_question_types_is_valid(): void {
        $this->resetAfterTest(true);
        $this->install_all_addons();

        // The regression: quizgeistaddon_ai is discovered first and declares no
        // question type at all. array_is_list([]) is true, so the former list
        // rejection treated that empty, entirely legitimate map as a defect and
        // aborted the whole runtime with a coding_exception.
        $strategies = addon_registry::question_type_strategies();

        $this->assertSame(
            self::PREMIUM_QTYPES,
            array_keys($strategies),
            'Only the qtypes addon owns question types; the other four declare none.'
        );
        foreach ($strategies as $type => $classname) {
            $this->assertTrue(
                is_a(
                    $classname,
                    \mod_quizgeist\local\live\qtype\live_question_type::class,
                    true
                ),
                "Announced strategy for {$type} is not a live question type."
            );
        }
    }

    public function test_shipped_addons_that_declare_nothing_pass_every_guard(): void {
        $this->resetAfterTest(true);
        $this->install_all_addons();

        // The same empty-map shape occurs on the AJAX seam: modes, qtypes and
        // reports declare no action. Guarding only the question-type call would
        // have moved the abort to the next stage instead of removing it.
        $actions = addon_registry::ajax_actions();
        $this->assertArrayHasKey('ai_generate', $actions);
        $this->assertArrayHasKey('selfstudy_start', $actions);

        // Lists were never affected, but they belong to the same contract.
        $this->assertSame(
            ['accuracy', 'team', 'security'],
            addon_registry::live_modes()
        );
        $this->assertSame(['jahreszeiten'], addon_registry::themes());

        // Explicit per-addon evidence: not one of the five declares a shape the
        // registry rejects, on any of the four declaration methods.
        $providers = addon_registry::providers();
        foreach ($providers as $component => $providerclass) {
            foreach (['ajax_actions', 'question_type_strategies'] as $method) {
                $this->merge('merge_map', [], $component, $providerclass::$method());
            }
            foreach (['live_modes', 'themes'] as $method) {
                $this->merge('merge_list', [], $component, $providerclass::$method());
            }
        }
        $this->assertCount(count($this->components()), $providers);
    }

    public function test_unlicensed_addons_expose_installed_but_not_creatable_types(): void {
        $this->resetAfterTest(true);
        $this->install_all_addons();

        // Stage addons-unlicensed: code installed, no licence file. Resolving
        // the installed catalogue must succeed; only creation stays closed.
        $this->assertSame(
            qtype_registry::known_types(),
            qtype_registry::installed_types()
        );
        $this->assertSame(
            qtype_registry::BASE_TYPES,
            qtype_registry::creatable_types()
        );
        $this->assertSame(
            ['classic', 'accuracy', 'team', 'security'],
            session_settings::installed_modes()
        );
        $this->assertSame(['classic'], session_settings::creatable_modes());
    }

    // Still rejected: a genuinely malformed declaration.

    /**
     * Malformed map declarations that must keep aborting.
     *
     * @return array<string,array{0:mixed,1:string}>
     */
    public static function broken_map_provider(): array {
        return [
            'non-empty list instead of a keyed map' => [
                ['puzzle', 'scale'],
                'returned an invalid question type map',
            ],
            'not an array at all' => [
                'puzzle',
                'returned an invalid question type map',
            ],
            'null instead of an empty map' => [
                null,
                'returned an invalid question type map',
            ],
            'integer key' => [
                [7 => \mod_quizgeist\local\live\qtype\puzzle::class],
                'registered an invalid or duplicate question type',
            ],
            'empty string key' => [
                ['' => \mod_quizgeist\local\live\qtype\puzzle::class],
                'registered an invalid or duplicate question type',
            ],
        ];
    }

    /**
     * A wrongly shaped declaration must keep failing loudly.
     *
     * @dataProvider broken_map_provider
     * @param mixed $values Raw provider return value.
     * @param string $expected Expected message fragment.
     * @return void
     */
    public function test_broken_map_declaration_is_still_rejected(
        mixed $values,
        string $expected
    ): void {
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote($expected, '/') . '/'
        );
        $this->merge('merge_map', [], 'quizgeistaddon_broken', $values);
    }

    public function test_duplicate_map_key_across_addons_is_still_rejected(): void {
        $combined = $this->merge(
            'merge_map',
            [],
            'quizgeistaddon_qtypes',
            ['puzzle' => \mod_quizgeist\local\live\qtype\puzzle::class]
        );
        $this->assertSame(['puzzle'], array_keys($combined));

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessageMatches(
            '/registered an invalid or duplicate question type/'
        );
        $this->merge(
            'merge_map',
            $combined,
            'quizgeistaddon_broken',
            ['puzzle' => \mod_quizgeist\local\live\qtype\scale::class]
        );
    }

    public function test_empty_map_contributes_nothing_and_keeps_earlier_entries(): void {
        $combined = $this->merge(
            'merge_map',
            ['puzzle' => \mod_quizgeist\local\live\qtype\puzzle::class],
            'quizgeistaddon_ai',
            []
        );
        $this->assertSame(
            ['puzzle' => \mod_quizgeist\local\live\qtype\puzzle::class],
            $combined
        );
    }

    public function test_broken_list_declaration_is_still_rejected(): void {
        // An empty list stays valid, a keyed map does not.
        $this->assertSame(
            [],
            $this->merge('merge_list', [], 'quizgeistaddon_ai', [])
        );

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessageMatches(
            '/returned an invalid question type list/'
        );
        $this->merge(
            'merge_list',
            [],
            'quizgeistaddon_broken',
            ['team' => true]
        );
    }
}
