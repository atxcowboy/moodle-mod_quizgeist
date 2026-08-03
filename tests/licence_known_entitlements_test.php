<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the known licence entitlement contract.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\licence\base64url;
use mod_quizgeist\local\licence\entitlement_status;
use mod_quizgeist\local\licence\jcs;
use mod_quizgeist\local\licence\service;
use mod_quizgeist\local\licence\verifier;
use mod_quizgeist\local\licence\wwwroot;

defined('MOODLE_INTERNAL') || die();

/**
 * Unknown future entitlement names never grant rights.
 */
final class licence_known_entitlements_test extends \advanced_testcase {

    public function test_known_entitlements_match_service_components(): void {
        $this->resetAfterTest(true);
        $this->assertCount(6, verifier::KNOWN_ENTITLEMENTS);
        $this->assertSame(
            array_values(service::COMPONENTS),
            verifier::KNOWN_ENTITLEMENTS
        );
    }

    public function test_unknown_signed_entitlement_grants_no_rights(): void {
        $this->resetAfterTest(true);

        $keypair = sodium_crypto_sign_keypair();
        $publickey = sodium_crypto_sign_publickey($keypair);
        $secretkey = sodium_crypto_sign_secretkey($keypair);
        $keyid = 'test-key-2026';
        $wwwroot = 'https://school.example/moodle';
        $entitlement = [
            'status' => entitlement_status::ACTIVE,
            'starts_at' => '2026-01-01T00:00:00Z',
            'expires_at' => '2026-02-01T00:00:00Z',
            'grace_until' => '2026-03-01T00:00:00Z',
            'updates_until' => '2026-02-15T00:00:00Z',
            'support_until' => '2026-03-15T00:00:00Z',
        ];
        $payload = [
            'schema' => verifier::SCHEMA,
            'revision' => 1,
            'key_id' => $keyid,
            'license_id' => 'lic_test_001',
            'license_kind' => 'paid',
            'product' => verifier::PRODUCT,
            'customer' => [
                'reference' => 'cus_test_001',
                'display_name' => 'Testschule',
            ],
            'instance_limit' => 1,
            'instance' => [
                'activation_id' => 'act_test_001',
                'wwwroot_hash' => wwwroot::hash($wwwroot),
                'bound_at' => '2025-12-01T00:00:00Z',
                'revoked' => false,
            ],
            'entitlements' => [
                'quizgeistaddon_qtypes' => $entitlement,
                'quizgeistaddon_modes' => $entitlement,
                'quizgeistaddon_selfstudy' => $entitlement,
                'quizgeistaddon_reports' => $entitlement,
                'quizgeistaddon_ai' => $entitlement,
                'quizgeistaddon_buehne' => $entitlement,
                'quizgeistaddon_unknown' => $entitlement,
            ],
            'issued_at' => '2025-12-01T00:00:00Z',
            'refresh_after' => null,
        ];
        $payloadbytes = jcs::encode($payload);
        $signature = sodium_crypto_sign_detached(
            "PANOMITY-LICENSE-V1\0" . $payloadbytes,
            $secretkey
        );
        $envelope = json_encode([
            'format' => verifier::FORMAT,
            'key_id' => $keyid,
            'payload' => base64url::encode($payloadbytes),
            'signature' => base64url::encode($signature),
        ], JSON_THROW_ON_ERROR);

        $result = (new verifier([$keyid => $publickey]))->verify(
            $envelope,
            $wwwroot,
            strtotime('2026-01-15T00:00:00Z')
        );

        $this->assertSame(
            entitlement_status::ACTIVE,
            $result->effective_status('quizgeistaddon_qtypes')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $result->effective_status('quizgeistaddon_unknown')
        );
        $this->assertArrayNotHasKey(
            'quizgeistaddon_unknown',
            $result->effective_statuses()
        );
    }
}
