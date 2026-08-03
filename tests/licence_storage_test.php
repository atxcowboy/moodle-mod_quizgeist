<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for atomic licence generations and anti-rollback state.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\licence\licence_exception;
use mod_quizgeist\local\licence\service;
use mod_quizgeist\local\licence\storage;
use mod_quizgeist\local\licence\verification_result;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers the stateful part deliberately kept outside the pure verifier.
 */
final class licence_storage_test extends \advanced_testcase {

    public function test_failed_and_recovery_installs_preserve_highest_revision(): void {
        $this->resetAfterTest(true);
        $storage = new storage();

        $storage->install('revision-10', $this->result(10, 'a'));
        $this->assertSame('revision-10', $storage->read());

        try {
            $storage->install('revision-9', $this->result(9, 'a'));
            $this->fail('An unconfirmed rollback was accepted.');
        } catch (licence_exception $exception) {
            $this->assertSame('revision_rollback', $exception->diagnosis());
        }
        $this->assertSame('revision-10', $storage->read());

        $storage->install(
            'revision-9-recovery',
            $this->result(9, 'a'),
            true
        );
        $this->assertSame('revision-9-recovery', $storage->read());

        // Recovery never lowers the remembered high-water mark.
        try {
            $storage->install('revision-8', $this->result(8, 'a'));
            $this->fail('Recovery lowered the anti-rollback high-water mark.');
        } catch (licence_exception $exception) {
            $this->assertSame('revision_rollback', $exception->diagnosis());
        }
        $this->assertSame('revision-9-recovery', $storage->read());

        // Explicit recovery cannot make one revision mean two signed files.
        try {
            $storage->install(
                'revision-9-different',
                $this->result(9, 'b'),
                true
            );
            $this->fail('Inconsistent same-revision bytes were accepted.');
        } catch (licence_exception $exception) {
            $this->assertSame(
                'revision_inconsistent',
                $exception->diagnosis()
            );
        }
        $this->assertSame('revision-9-recovery', $storage->read());
    }

    public function test_active_file_without_valid_ledger_fails_closed(): void {
        $this->resetAfterTest(true);
        $storage = new storage();

        set_config(
            'licence_active_itemid',
            12345,
            'mod_quizgeist'
        );
        $snapshot = service::snapshot(1700000000);
        $this->assertFalse($snapshot['valid']);
        $this->assertSame(
            'licence_storage_unreadable',
            $snapshot['diagnosis']
        );
        try {
            $storage->install('revision-1', $this->result(1, 'a'));
            $this->fail('An active pointer without a ledger was accepted.');
        } catch (licence_exception $exception) {
            $this->assertSame(
                'revision_ledger_invalid',
                $exception->diagnosis()
            );
        }

        $ledgerkey = hash(
            'sha256',
            "lic_storage_001\0act_storage_001"
        );
        set_config(
            'licence_revision_ledger',
            json_encode([
                $ledgerkey => [
                    'license_id' => 'lic_storage_001',
                    'activation_id' => 'act_storage_001',
                    'highest_revision' => 10,
                    'observed_revisions' => [
                        '9' => [
                            'payload_hash' => str_repeat('a', 64),
                            'signature_hash' => str_repeat('b', 64),
                        ],
                    ],
                    'active_revision' => 9,
                    'accepted_at' => time(),
                    'recovery' => true,
                ],
            ], JSON_THROW_ON_ERROR),
            'mod_quizgeist'
        );
        try {
            $storage->install('revision-10', $this->result(10, 'a'));
            $this->fail('A high-water mark without its observation was accepted.');
        } catch (licence_exception $exception) {
            $this->assertSame(
                'revision_ledger_invalid',
                $exception->diagnosis()
            );
        }
    }

    private function result(int $revision, string $variant): verification_result {
        return new verification_result(
            [
                'revision' => $revision,
                'license_id' => 'lic_storage_001',
                'instance' => [
                    'activation_id' => 'act_storage_001',
                ],
            ],
            "payload-{$revision}-{$variant}",
            str_repeat($variant, 64),
            [],
            'sha256:' . str_repeat('0', 64)
        );
    }
}
