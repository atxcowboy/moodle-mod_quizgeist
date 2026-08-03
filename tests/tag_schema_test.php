<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the canonical tagging schema.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\tagging\tag_schema;

defined('MOODLE_INTERNAL') || die();

/**
 * Normalisation, key form, duplicates and weight bounds.
 */
final class tag_schema_test extends \advanced_testcase {

    public function test_normalisation_canonicalises_key_and_label(): void {
        $this->resetAfterTest(true);

        $result = tag_schema::normalise([
            'scope' => 'activity',
            'scopeid' => 7,
            'kind' => 'competence',
            'tagkey' => '  Analyse-1  ',
            'label' => '  Analysieren  ',
            'colorkey' => 'BLAU',
            'sortorder' => 3,
        ]);

        $this->assertSame([], $result['validationErrors']);
        $this->assertSame('analyse-1', $result['tag']['tagkey']);
        $this->assertSame('Analysieren', $result['tag']['label']);
        $this->assertSame('blau', $result['tag']['colorkey']);
        $this->assertSame('competence', $result['tag']['kind']);
    }

    public function test_invalid_key_form_is_field_addressable(): void {
        $this->resetAfterTest(true);

        $result = tag_schema::normalise([
            'scope' => 'activity',
            'scopeid' => 7,
            'kind' => 'topic',
            'tagkey' => 'nicht erlaubt!',
            'label' => 'Test',
        ]);

        $this->assertNotSame([], $result['validationErrors']);
        $this->assertContains(
            ['field' => 'tagkey', 'code' => 'invalid'],
            $result['validationErrors']
        );
    }

    public function test_required_label_and_unknown_kind_are_reported(): void {
        $this->resetAfterTest(true);

        $result = tag_schema::normalise([
            'scope' => 'activity',
            'scopeid' => 7,
            'kind' => 'gibtesnicht',
            'tagkey' => 'thema',
            'label' => '',
        ]);

        $codes = array_map(
            static fn(array $error): string => $error['field'] . ':' . $error['code'],
            $result['validationErrors']
        );
        $this->assertContains('kind:invalid', $codes);
        $this->assertContains('label:required', $codes);
        // A complaint never destroys the rest of the document.
        $this->assertSame('topic', $result['tag']['kind']);
    }

    public function test_site_scope_refuses_an_owner(): void {
        $this->resetAfterTest(true);

        $result = tag_schema::normalise([
            'scope' => 'site',
            'scopeid' => 42,
            'kind' => 'topic',
            'tagkey' => 'schulweit',
            'label' => 'Schulweit',
        ]);

        $this->assertContains(
            ['field' => 'scopeid', 'code' => 'invalid'],
            $result['validationErrors']
        );
        $this->assertSame(0, $result['tag']['scopeid']);
    }

    public function test_assignment_duplicates_and_weight_bounds(): void {
        $this->resetAfterTest(true);

        $result = tag_schema::normalise_assignments([
            ['tagId' => 5, 'weight' => 100],
            ['tagId' => 5, 'weight' => 50],
            ['tagId' => 6, 'weight' => 300],
        ]);

        $codes = array_map(
            static fn(array $error): string => $error['field'] . ':' . $error['code'],
            $result['validationErrors']
        );
        $this->assertContains('assignments.1.tagId:duplicate', $codes);
        $this->assertContains('assignments.2.weight:out_of_range', $codes);
        $this->assertCount(2, $result['assignments']);
        $this->assertSame(100, $result['assignments'][1]['weight']);
    }

    public function test_too_many_assignments_are_bounded(): void {
        $this->resetAfterTest(true);

        $input = [];
        for ($index = 1; $index <= tag_schema::MAX_ASSIGNMENTS + 5; $index++) {
            $input[] = ['tagId' => $index];
        }
        $result = tag_schema::normalise_assignments($input);

        $this->assertContains(
            ['field' => 'assignments', 'code' => 'too_many'],
            $result['validationErrors']
        );
        $this->assertCount(tag_schema::MAX_ASSIGNMENTS, $result['assignments']);
    }

    public function test_reserved_system_tag_is_recognised(): void {
        $this->resetAfterTest(true);

        $this->assertTrue(tag_schema::is_reserved_new('topic', 'neu'));
        $this->assertFalse(tag_schema::is_reserved_new('competence', 'neu'));
        $this->assertFalse(tag_schema::is_reserved_new('topic', 'alt'));
    }

    public function test_identity_is_the_merge_key(): void {
        $this->resetAfterTest(true);

        $left = tag_schema::normalise([
            'scope' => 'course', 'scopeid' => 3, 'kind' => 'topic',
            'tagkey' => 'bruch', 'label' => 'Brüche',
        ])['tag'];
        $right = tag_schema::normalise([
            'scope' => 'course', 'scopeid' => 3, 'kind' => 'topic',
            'tagkey' => 'bruch', 'label' => 'Bruchrechnung',
        ])['tag'];

        $this->assertSame(
            tag_schema::identity($left),
            tag_schema::identity($right)
        );
    }
}
