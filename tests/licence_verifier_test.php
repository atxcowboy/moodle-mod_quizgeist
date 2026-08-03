<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for the fail-closed offline licence verifier.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\licence\base64url;
use mod_quizgeist\local\licence\entitlement_status;
use mod_quizgeist\local\licence\jcs;
use mod_quizgeist\local\licence\licence_exception;
use mod_quizgeist\local\licence\verifier;
use mod_quizgeist\local\licence\wwwroot;

defined('MOODLE_INTERNAL') || die();

/**
 * Exercises signature, schema, binding and exact temporal boundaries.
 */
final class licence_verifier_test extends \advanced_testcase {

    /** Contract-only key ID. It must never enter the production keyring. */
    private const TEST_KEY_ID = 'qg-test-2026-01';

    /** Contract test seed, used only to produce isolated test fixtures. */
    private const TEST_SEED_HEX =
        '000102030405060708090a0b0c0d0e0f'
        . '101112131415161718191a1b1c1d1e1f';

    /** Contract test public key in canonical unpadded Base64url. */
    private const TEST_PUBLIC_KEY =
        'A6EHv_POEL4dcN0Y50vAmWfk1jCbpQ1fHdyGZBJVMbg';

    /** Contract test-vector detached signature. */
    private const VECTOR_SIGNATURE =
        '0mRiDOHtPF-hy7F6fP5kKzEmqdoGMEub3bVeztPN18bYpESUzBUt5nu1zQCx'
        . 'Po2J6fLCIU5YAgm8we1XCuf7Cw';

    /** Exact 977 canonical payload bytes from contract section 2.4. */
    private const VECTOR_PAYLOAD =
        '{"customer":{"display_name":"Beispielschule","reference":"cus_demo_001"},'
        . '"entitlements":{"quizgeistaddon_modes":{"expires_at":"2026-07-01T00:00:00Z",'
        . '"grace_until":"2026-07-31T00:00:00Z","starts_at":"2025-07-01T00:00:00Z",'
        . '"status":"grace","support_until":"2026-07-31T00:00:00Z",'
        . '"updates_until":"2026-07-31T00:00:00Z"},"quizgeistaddon_qtypes":{'
        . '"expires_at":"2027-07-01T00:00:00Z","grace_until":"2027-07-31T00:00:00Z",'
        . '"starts_at":"2026-07-01T00:00:00Z","status":"active",'
        . '"support_until":"2027-07-31T00:00:00Z","updates_until":"2027-07-31T00:00:00Z"}},'
        . '"instance":{"activation_id":"act_demo_001","bound_at":"2026-06-01T10:00:00Z",'
        . '"revoked":false,"wwwroot_hash":'
        . '"sha256:b6573cd54d9c3f440cb4c354c77b15706d1403d645df4d8d59ff2382ecc386a1"},'
        . '"instance_limit":1,"issued_at":"2026-07-30T10:00:00Z",'
        . '"key_id":"qg-test-2026-01","license_id":"lic_demo_001","license_kind":"paid",'
        . '"product":"quizgeist","refresh_after":"2026-08-06T10:00:00Z","revision":7,'
        . '"schema":"panomity.quizgeist.license.v1"}';

    /**
     * The normative external vector fixes payload bytes, hash and signature.
     *
     * @return void
     */
    public function test_contract_vector_verifies_unchanged_bytes(): void {
        $this->assertSame(977, strlen(self::VECTOR_PAYLOAD));
        $this->assertSame(
            'cb360136f9cf211ec281dff6567c2950506daee6fd8857d0c8b69afec4a669ef',
            hash('sha256', self::VECTOR_PAYLOAD)
        );

        $result = $this->verifier()->verify(
            self::vector_envelope(),
            'HTTPS://Moodle.Beispielschule.de/',
            strtotime('2026-07-30T12:00:00Z')
        );

        $this->assertSame(self::VECTOR_PAYLOAD, $result->payload_bytes());
        $this->assertSame(
            base64url::decode(self::VECTOR_SIGNATURE),
            $result->signature_bytes()
        );
        $this->assertSame(
            entitlement_status::ACTIVE,
            $result->effective_status('quizgeistaddon_qtypes')
        );
        $this->assertSame(
            entitlement_status::GRACE,
            $result->effective_status('quizgeistaddon_modes')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $result->effective_status('quizgeistaddon_reports')
        );
        $this->assertSame(
            'sha256:b6573cd54d9c3f440cb4c354c77b15706d1403d645df4d8d59ff2382ecc386a1',
            $result->wwwroot_hash()
        );
    }

    /**
     * A one-byte semantic change cannot reuse the vector signature.
     *
     * @return void
     */
    public function test_tampered_payload_is_rejected_before_semantic_use(): void {
        $tampered = str_replace(
            '"status":"grace"',
            '"status":"active"',
            self::VECTOR_PAYLOAD
        );
        $envelope = self::envelope($tampered, self::VECTOR_SIGNATURE);

        $this->assert_diagnosis('invalid_signature', function() use ($envelope): void {
            $this->verifier()->verify(
                $envelope,
                'https://moodle.beispielschule.de'
            );
        });
    }

    /**
     * A signature over noncanonical JSON is valid cryptographically but not a v1 licence.
     *
     * @return void
     */
    public function test_signed_noncanonical_payload_is_rejected(): void {
        $payload = self::base_payload();
        $prettybytes = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $this->assertIsString($prettybytes);
        $envelope = self::signed_envelope_bytes($prettybytes);

        $this->assert_diagnosis('noncanonical_payload', function() use ($envelope): void {
            $this->verifier()->verify($envelope, 'https://school.example/moodle');
        });
    }

    /**
     * Duplicate fields are rejected in both unsigned and signed JSON layers.
     *
     * @return void
     */
    public function test_duplicate_object_members_are_rejected(): void {
        $duplicateenvelope = '{"format":"panomity-license-v1",'
            . '"format":"panomity-license-v1","key_id":"' . self::TEST_KEY_ID . '",'
            . '"payload":"' . base64url::encode(self::VECTOR_PAYLOAD) . '",'
            . '"signature":"' . self::VECTOR_SIGNATURE . '"}';
        $this->assert_diagnosis('invalid_json', function() use ($duplicateenvelope): void {
            $this->verifier()->verify(
                $duplicateenvelope,
                'https://moodle.beispielschule.de'
            );
        });

        $payloadbytes = jcs::encode(self::base_payload());
        $duplicatepayload = str_replace(
            '"revision":1',
            '"revision":1,"revision":1',
            $payloadbytes
        );
        $signedduplicate = self::signed_envelope_bytes($duplicatepayload);
        $this->assert_diagnosis('invalid_json', function() use ($signedduplicate): void {
            $this->verifier()->verify(
                $signedduplicate,
                'https://school.example/moodle'
            );
        });
    }

    /**
     * Exact exclusive end instants produce grace and then read-only.
     *
     * @return void
     */
    public function test_expiry_and_grace_boundaries_are_exclusive(): void {
        $envelope = self::signed_envelope(self::base_payload());

        $atexpiry = $this->verifier()->verify(
            $envelope,
            'https://school.example/moodle',
            strtotime('2026-02-01T00:00:00Z')
        );
        $this->assertSame(
            entitlement_status::GRACE,
            $atexpiry->effective_status('quizgeistaddon_qtypes')
        );

        $atgraceend = $this->verifier()->verify(
            $envelope,
            'https://school.example/moodle',
            strtotime('2026-03-01T00:00:00Z')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $atgraceend->effective_status('quizgeistaddon_qtypes')
        );
    }

    /**
     * Signed restrictions are never relaxed by the local time calculation.
     *
     * @return void
     */
    public function test_signed_status_is_a_minimum_restriction(): void {
        $payload = self::base_payload();
        $payload['entitlements']['quizgeistaddon_qtypes']['status'] =
            entitlement_status::GRACE;
        $envelope = self::signed_envelope($payload);

        $result = $this->verifier()->verify(
            $envelope,
            'https://school.example/moodle',
            strtotime('2026-01-15T00:00:00Z')
        );
        $this->assertSame(
            entitlement_status::GRACE,
            $result->effective_status('quizgeistaddon_qtypes')
        );
    }

    /**
     * Revocation, missing entries and unknown future keys all fail closed.
     *
     * @return void
     */
    public function test_revoked_missing_and_unknown_entitlements_are_read_only(): void {
        $payload = self::base_payload();
        $payload['instance']['revoked'] = true;
        $payload['entitlements']['quizgeistaddon_future'] =
            $payload['entitlements']['quizgeistaddon_qtypes'];

        $result = $this->verifier()->verify(
            self::signed_envelope($payload),
            'https://school.example/moodle',
            strtotime('2026-01-15T00:00:00Z')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $result->effective_status('quizgeistaddon_qtypes')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $result->effective_status('quizgeistaddon_modes')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $result->effective_status('quizgeistaddon_future')
        );
    }

    /**
     * Even syntactically invalid future addon names remain ignorable.
     *
     * Numeric JSON member names must survive JCS without PHP coercing them to
     * integer array keys and invalidating the complete signed file.
     *
     * @return void
     */
    public function test_numeric_unknown_entitlement_key_is_ignored(): void {
        $decoded = \mod_quizgeist\local\licence\strict_json::decode(
            '{"0":1,"01":2}'
        );
        $this->assertSame('{"0":1,"01":2}', jcs::encode($decoded));

        $payloadbytes = jcs::encode(self::base_payload());
        $entry = jcs::encode(
            (object)self::base_payload()['entitlements']
                ['quizgeistaddon_qtypes']
        );
        $payloadbytes = str_replace(
            '"entitlements":{',
            '"entitlements":{"0":' . $entry . ',',
            $payloadbytes
        );
        $result = $this->verifier()->verify(
            self::signed_envelope_bytes($payloadbytes),
            'https://school.example/moodle',
            strtotime('2026-01-15T00:00:00Z')
        );
        $this->assertSame(
            entitlement_status::ACTIVE,
            $result->effective_status('quizgeistaddon_qtypes')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $result->effective_status('0')
        );
    }

    /**
     * Perpetual entries have no grace end and become active at starts_at.
     *
     * @return void
     */
    public function test_perpetual_entitlement_has_no_implicit_expiry(): void {
        $payload = self::base_payload();
        $entry = &$payload['entitlements']['quizgeistaddon_qtypes'];
        $entry['expires_at'] = null;
        $entry['grace_until'] = null;
        $entry['updates_until'] = null;
        unset($entry);

        $before = $this->verifier()->verify(
            self::signed_envelope($payload),
            'https://school.example/moodle',
            strtotime('2025-12-31T23:59:59Z')
        );
        $after = $this->verifier()->verify(
            self::signed_envelope($payload),
            'https://school.example/moodle',
            strtotime('2040-01-01T00:00:00Z')
        );
        $this->assertSame(
            entitlement_status::READ_ONLY,
            $before->effective_status('quizgeistaddon_qtypes')
        );
        $this->assertSame(
            entitlement_status::ACTIVE,
            $after->effective_status('quizgeistaddon_qtypes')
        );
    }

    /**
     * A correctly signed file for another installation cannot be activated.
     *
     * @return void
     */
    public function test_wrong_wwwroot_binding_is_rejected(): void {
        $this->assert_diagnosis('wwwroot_mismatch', function(): void {
            $this->verifier()->verify(
                self::signed_envelope(self::base_payload()),
                'https://other.example/moodle'
            );
        });
    }

    /**
     * Envelope boundaries and the compile-time trust root fail closed.
     *
     * @return void
     */
    public function test_envelope_and_keyring_fail_closed(): void {
        $extra = json_decode(self::vector_envelope(), true, 8, JSON_THROW_ON_ERROR);
        $extra['unexpected'] = true;
        $extraenvelope = json_encode($extra, JSON_THROW_ON_ERROR);
        $this->assert_diagnosis('invalid_envelope', function() use ($extraenvelope): void {
            $this->verifier()->verify(
                $extraenvelope,
                'https://moodle.beispielschule.de'
            );
        });

        $padded = $extra;
        unset($padded['unexpected']);
        $padded['signature'] = self::VECTOR_SIGNATURE . '=';
        $paddedenvelope = json_encode($padded, JSON_THROW_ON_ERROR);
        $this->assert_diagnosis('invalid_base64url', function() use ($paddedenvelope): void {
            $this->verifier()->verify(
                $paddedenvelope,
                'https://moodle.beispielschule.de'
            );
        });

        $unknown = $extra;
        unset($unknown['unexpected']);
        $unknown['key_id'] = 'qg-unknown-2026';
        $unknownenvelope = json_encode($unknown, JSON_THROW_ON_ERROR);
        $this->assert_diagnosis('unknown_key_id', function() use ($unknownenvelope): void {
            $this->verifier()->verify(
                $unknownenvelope,
                'https://moodle.beispielschule.de'
            );
        });
    }

    /**
     * Invalid UTF-8, BOM and maximum file size are checked before trust decisions.
     *
     * @return void
     */
    public function test_file_transport_boundaries_are_enforced(): void {
        $this->assert_diagnosis('invalid_json', function(): void {
            $this->verifier()->verify(
                "\xEF\xBB\xBF" . self::vector_envelope(),
                'https://moodle.beispielschule.de'
            );
        });
        $this->assert_diagnosis('invalid_json', function(): void {
            $this->verifier()->verify(
                "{\"format\":\"\xFF\"}",
                'https://moodle.beispielschule.de'
            );
        });
        $this->assert_diagnosis('file_too_large', function(): void {
            $this->verifier()->verify(
                str_repeat(' ', verifier::MAX_FILE_BYTES + 1),
                'https://moodle.beispielschule.de'
            );
        });
    }

    /**
     * Exact schema, timestamp, relationship and NFC constraints are enforced.
     *
     * @return void
     */
    public function test_payload_schema_constraints_are_enforced(): void {
        $fixtures = [];

        $fixtures['reversed_entitlement_dates'] = self::base_payload();
        $fixtures['reversed_entitlement_dates']['entitlements']
            ['quizgeistaddon_qtypes']['grace_until'] = '2026-01-31T23:59:59Z';

        $fixtures['fractional_timestamp'] = self::base_payload();
        $fixtures['fractional_timestamp']['issued_at'] = '2026-01-01T00:00:00.000Z';

        $fixtures['extra_payload_field'] = self::base_payload();
        $fixtures['extra_payload_field']['future'] = true;

        $fixtures['decomposed_human_text'] = self::base_payload();
        $fixtures['decomposed_human_text']['customer']['display_name'] = "Cafe\u{0301}";

        $fixtures['finite_without_grace'] = self::base_payload();
        $fixtures['finite_without_grace']['entitlements']
            ['quizgeistaddon_qtypes']['grace_until'] = null;

        $fixtures['malformed_future_entitlement'] = self::base_payload();
        $fixtures['malformed_future_entitlement']['entitlements']
            ['quizgeistaddon_future'] = ['status' => entitlement_status::ACTIVE];

        foreach ($fixtures as $name => $payload) {
            $envelope = self::signed_envelope($payload);
            try {
                $this->verifier()->verify($envelope, 'https://school.example/moodle');
                $this->fail("Invalid fixture {$name} was accepted.");
            } catch (licence_exception $exception) {
                $this->assertSame('invalid_payload', $exception->diagnosis(), $name);
            }
        }
    }

    /**
     * Administrators receive distinct diagnoses for three common wrong files.
     *
     * @return void
     */
    public function test_schema_product_and_inner_key_have_distinct_diagnoses(): void {
        $cases = [];

        $cases['unsupported_schema'] = self::base_payload();
        $cases['unsupported_schema']['schema'] = 'panomity.quizgeist.license.v2';

        $cases['wrong_product'] = self::base_payload();
        $cases['wrong_product']['product'] = 'another-product';

        $cases['key_id_mismatch'] = self::base_payload();
        $cases['key_id_mismatch']['key_id'] = 'qg-other-2026-01';

        foreach ($cases as $diagnosis => $payload) {
            $envelope = self::signed_envelope($payload);
            $this->assert_diagnosis($diagnosis, function() use ($envelope): void {
                $this->verifier()->verify(
                    $envelope,
                    'https://school.example/moodle'
                );
            });
        }
    }

    /**
     * A raw 32-byte public key is accepted as the documented constructor form.
     *
     * @return void
     */
    public function test_raw_public_key_constructor_form_is_supported(): void {
        $keypair = sodium_crypto_sign_seed_keypair(
            hex2bin(self::TEST_SEED_HEX)
        );
        $rawpublickey = sodium_crypto_sign_publickey($keypair);
        $verifier = new verifier([self::TEST_KEY_ID => $rawpublickey]);

        $result = $verifier->verify(
            self::signed_envelope(self::base_payload()),
            'https://school.example/moodle',
            strtotime('2026-01-15T00:00:00Z')
        );
        $this->assertSame(
            entitlement_status::ACTIVE,
            $result->effective_status('quizgeistaddon_qtypes')
        );
    }

    /**
     * Build the verifier with only the contract test trust anchor.
     *
     * @return verifier
     */
    private function verifier(): verifier {
        return new verifier([self::TEST_KEY_ID => self::TEST_PUBLIC_KEY]);
    }

    /**
     * Build the exact external test-vector envelope.
     *
     * @return string
     */
    private static function vector_envelope(): string {
        return self::envelope(self::VECTOR_PAYLOAD, self::VECTOR_SIGNATURE);
    }

    /**
     * Sign the canonical representation of a fixture payload.
     *
     * @param array $payload Payload fixture.
     * @return string
     */
    private static function signed_envelope(array $payload): string {
        return self::signed_envelope_bytes(jcs::encode($payload));
    }

    /**
     * Sign exact payload bytes with the contract test seed.
     *
     * @param string $payloadbytes Exact bytes to sign.
     * @return string
     */
    private static function signed_envelope_bytes(string $payloadbytes): string {
        $seed = hex2bin(self::TEST_SEED_HEX);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secretkey = sodium_crypto_sign_secretkey($keypair);
        $signature = sodium_crypto_sign_detached(
            "PANOMITY-LICENSE-V1\0" . $payloadbytes,
            $secretkey
        );
        return self::envelope($payloadbytes, base64url::encode($signature));
    }

    /**
     * Wrap exact payload bytes and a detached signature.
     *
     * @param string $payloadbytes Exact payload bytes.
     * @param string $signature Encoded detached signature.
     * @return string
     */
    private static function envelope(string $payloadbytes, string $signature): string {
        return json_encode([
            'format' => verifier::FORMAT,
            'key_id' => self::TEST_KEY_ID,
            'payload' => base64url::encode($payloadbytes),
            'signature' => $signature,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Return a compact valid payload for mutation tests.
     *
     * @return array
     */
    private static function base_payload(): array {
        return [
            'schema' => verifier::SCHEMA,
            'revision' => 1,
            'key_id' => self::TEST_KEY_ID,
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
                'wwwroot_hash' => wwwroot::hash('https://school.example/moodle'),
                'bound_at' => '2025-12-01T00:00:00Z',
                'revoked' => false,
            ],
            'entitlements' => [
                'quizgeistaddon_qtypes' => [
                    'status' => entitlement_status::ACTIVE,
                    'starts_at' => '2026-01-01T00:00:00Z',
                    'expires_at' => '2026-02-01T00:00:00Z',
                    'grace_until' => '2026-03-01T00:00:00Z',
                    'updates_until' => '2026-02-15T00:00:00Z',
                    'support_until' => '2026-03-15T00:00:00Z',
                ],
            ],
            'issued_at' => '2025-12-01T00:00:00Z',
            'refresh_after' => null,
        ];
    }

    /**
     * Assert a stable verifier diagnosis.
     *
     * @param string $expected Expected diagnosis.
     * @param callable():void $operation Operation that must fail.
     * @return void
     */
    private function assert_diagnosis(string $expected, callable $operation): void {
        try {
            $operation();
            $this->fail("Expected licence diagnosis {$expected}.");
        } catch (licence_exception $exception) {
            $this->assertSame($expected, $exception->diagnosis());
        }
    }
}
