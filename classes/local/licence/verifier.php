<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Offline Ed25519 licence verification.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates the shared panomity-license-v1 contract without network access.
 *
 * The injected keyring is the trust root. Values may be raw 32-byte Ed25519
 * public keys or their canonical unpadded Base64url representation. A public
 * key found in a licence file is never accepted.
 */
final class verifier {

    /** Maximum byte length of one downloaded licence file. */
    public const MAX_FILE_BYTES = 131072;

    /** Envelope format identifier. */
    public const FORMAT = 'panomity-license-v1';

    /** Signed payload schema identifier. */
    public const SCHEMA = 'panomity.quizgeist.license.v1';

    /** Signed product identifier. */
    public const PRODUCT = 'quizgeist';

    /** Signature domain-separation prefix. */
    private const SIGNATURE_DOMAIN = "PANOMITY-LICENSE-V1\0";

    /** Known entitlement keys that can grant rights in contract version 1. */
    public const KNOWN_ENTITLEMENTS = [
        'quizgeistaddon_qtypes',
        'quizgeistaddon_modes',
        'quizgeistaddon_selfstudy',
        'quizgeistaddon_reports',
        'quizgeistaddon_ai',
        // P11/C6 (F13). Der Eintrag ist eine reine VERSCHAERFUNG: eine bereits
        // signierte Datei, die diesen Namen nicht kennt, bleibt gueltig, und
        // das Addon faellt ueber die Schleife unten sauber auf `read_only`.
        // Deshalb muss keine Bestandslizenz neu signiert werden.
        'quizgeistaddon_buehne',
    ];

    /** @var array<string, string> Trusted key IDs to raw 32-byte public keys. */
    private array $keyring = [];

    /**
     * @param array<string, string> $keyring Raw or canonical Base64url public keys.
     * @throws licence_exception If the local keyring itself is malformed.
     */
    public function __construct(array $keyring) {
        if (!function_exists('sodium_crypto_sign_verify_detached')
                || !defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
                || !defined('SODIUM_CRYPTO_SIGN_BYTES')) {
            throw new licence_exception('sodium_unavailable');
        }

        foreach ($keyring as $keyid => $publickey) {
            if (!is_string($keyid)
                    || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D', $keyid) !== 1
                    || !is_string($publickey)) {
                throw new licence_exception('invalid_keyring');
            }

            if (strlen($publickey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                try {
                    $publickey = base64url::decode(
                        $publickey,
                        SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                    );
                } catch (licence_exception $exception) {
                    throw new licence_exception('invalid_keyring', '', $exception);
                }
            }
            $this->keyring[$keyid] = $publickey;
        }
    }

    /**
     * Verify licence file bytes and bind them to one local Moodle wwwroot.
     *
     * @param string $file Complete downloaded licence file contents.
     * @param string $wwwroot Local configured Moodle wwwroot.
     * @param int|null $now Unix timestamp used for status calculation.
     * @return verification_result
     * @throws licence_exception
     */
    public function verify(
        string $file,
        string $wwwroot,
        ?int $now = null
    ): verification_result {
        if ($now === null) {
            $now = time();
        }
        if ($now < 0 || $now > strict_json::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('Verification time is outside the safe integer range.');
        }

        $envelope = strict_json::decode($file, self::MAX_FILE_BYTES, 8);
        self::require_exact_object(
            $envelope,
            ['format', 'key_id', 'payload', 'signature'],
            'invalid_envelope'
        );
        $envelopefields = get_object_vars($envelope);

        if (!is_string($envelopefields['format'])
                || !hash_equals(self::FORMAT, $envelopefields['format'])) {
            throw new licence_exception('unsupported_format');
        }
        if (!is_string($envelopefields['key_id'])
                || preg_match(
                    '/^[a-z0-9][a-z0-9._-]{2,63}$/D',
                    $envelopefields['key_id']
                ) !== 1) {
            throw new licence_exception('invalid_envelope');
        }
        if (!is_string($envelopefields['payload'])
                || !is_string($envelopefields['signature'])) {
            throw new licence_exception('invalid_envelope');
        }

        $payloadbytes = base64url::decode($envelopefields['payload']);
        $signaturebytes = base64url::decode(
            $envelopefields['signature'],
            SODIUM_CRYPTO_SIGN_BYTES
        );
        $keyid = $envelopefields['key_id'];
        if (!array_key_exists($keyid, $this->keyring)) {
            throw new licence_exception('unknown_key_id');
        }

        try {
            $signaturevalid = sodium_crypto_sign_verify_detached(
                $signaturebytes,
                self::SIGNATURE_DOMAIN . $payloadbytes,
                $this->keyring[$keyid]
            );
        } catch (\SodiumException $exception) {
            throw new licence_exception('invalid_signature', '', $exception);
        }
        if (!$signaturevalid) {
            throw new licence_exception('invalid_signature');
        }

        $payloadobject = strict_json::decode($payloadbytes, self::MAX_FILE_BYTES, 16);
        if (!$payloadobject instanceof \stdClass) {
            throw new licence_exception('invalid_payload');
        }
        if (!hash_equals($payloadbytes, jcs::encode($payloadobject))) {
            throw new licence_exception('noncanonical_payload');
        }

        $validated = $this->validate_payload($payloadobject, $keyid);
        $localhash = wwwroot::hash($wwwroot);
        if (!hash_equals($validated['payload']['instance']['wwwroot_hash'], $localhash)) {
            throw new licence_exception('wwwroot_mismatch');
        }

        $statuses = [];
        foreach (self::KNOWN_ENTITLEMENTS as $component) {
            $statuses[$component] = entitlement_status::READ_ONLY;
            if ($validated['payload']['instance']['revoked']
                    || !array_key_exists($component, $validated['entitlement_times'])) {
                continue;
            }
            $entry = $validated['payload']['entitlements'][$component];
            $times = $validated['entitlement_times'][$component];
            if ($now < $times['starts_at']) {
                $temporal = entitlement_status::READ_ONLY;
            } else if ($times['expires_at'] === null) {
                $temporal = entitlement_status::ACTIVE;
            } else if ($now < $times['expires_at']) {
                $temporal = entitlement_status::ACTIVE;
            } else if ($now < $times['grace_until']) {
                $temporal = entitlement_status::GRACE;
            } else {
                $temporal = entitlement_status::READ_ONLY;
            }
            $statuses[$component] = entitlement_status::stricter($temporal, $entry['status']);
        }

        return new verification_result(
            $validated['payload'],
            $payloadbytes,
            $signaturebytes,
            $statuses,
            $localhash
        );
    }

    /**
     * Validate the exact payload schema and return convenient scalar data.
     *
     * @param \stdClass $payload Signed decoded object.
     * @param string $envelopekeyid Trusted envelope key ID.
     * @return array{
     *     payload:array,
     *     entitlement_times:array<string, array{
     *         starts_at:int,
     *         expires_at:?int,
     *         grace_until:?int,
     *         updates_until:?int,
     *         support_until:?int
     *     }>
     * }
     */
    private function validate_payload(\stdClass $payload, string $envelopekeyid): array {
        self::require_exact_object(
            $payload,
            [
                'schema',
                'revision',
                'key_id',
                'license_id',
                'license_kind',
                'product',
                'customer',
                'instance_limit',
                'instance',
                'entitlements',
                'issued_at',
                'refresh_after',
            ],
            'invalid_payload'
        );
        $fields = get_object_vars($payload);

        if (!is_string($fields['schema']) || !hash_equals(self::SCHEMA, $fields['schema'])) {
            throw new licence_exception('unsupported_schema');
        }
        if (!is_int($fields['revision']) || $fields['revision'] < 1) {
            throw new licence_exception('invalid_payload');
        }
        if (!is_string($fields['key_id']) || !hash_equals($envelopekeyid, $fields['key_id'])) {
            throw new licence_exception('key_id_mismatch');
        }
        self::require_pattern($fields['license_id'], '/^[A-Za-z0-9_-]{8,64}$/D');
        if (!is_string($fields['license_kind'])
                || !in_array($fields['license_kind'], ['paid', 'trial', 'legacy'], true)) {
            throw new licence_exception('invalid_payload');
        }
        if (!is_string($fields['product']) || !hash_equals(self::PRODUCT, $fields['product'])) {
            throw new licence_exception('wrong_product');
        }
        if (!is_int($fields['instance_limit'])
                || $fields['instance_limit'] < 1
                || $fields['instance_limit'] > 10000) {
            throw new licence_exception('invalid_payload');
        }

        $customer = $this->validate_customer($fields['customer']);
        $instance = $this->validate_instance($fields['instance']);
        self::timestamp($fields['issued_at']);
        self::nullable_timestamp($fields['refresh_after']);

        if (!$fields['entitlements'] instanceof \stdClass) {
            throw new licence_exception('invalid_payload');
        }
        $entitlements = [];
        $entitlementtimes = [];
        foreach (get_object_vars($fields['entitlements']) as $component => $entry) {
            // Every v1 entry has the same exact shape. Future addon names are
            // accepted only after that common shape validates, but old clients
            // still never derive rights from an unknown name.
            $validatedentry = $this->validate_entitlement($entry);
            if (!in_array($component, self::KNOWN_ENTITLEMENTS, true)) {
                continue;
            }
            $entitlements[$component] = $validatedentry['entry'];
            $entitlementtimes[$component] = $validatedentry['times'];
        }

        $payloadarray = self::to_array($payload);
        $payloadarray['customer'] = $customer;
        $payloadarray['instance'] = $instance;
        foreach ($entitlements as $component => $entry) {
            $payloadarray['entitlements'][$component] = $entry;
        }

        return [
            'payload' => $payloadarray,
            'entitlement_times' => $entitlementtimes,
        ];
    }

    /**
     * Validate the customer object and human-readable NFC name.
     *
     * @param mixed $value Decoded value.
     * @return array{reference:string,display_name:string}
     */
    private function validate_customer(mixed $value): array {
        self::require_exact_object($value, ['reference', 'display_name'], 'invalid_payload');
        $fields = get_object_vars($value);
        self::require_pattern($fields['reference'], '/^[A-Za-z0-9_-]{1,64}$/D');
        if (str_contains($fields['reference'], '@')
                || !is_string($fields['display_name'])
                || mb_strlen($fields['display_name'], 'UTF-8') < 1
                || mb_strlen($fields['display_name'], 'UTF-8') > 160
                || preg_match('/\p{Cc}/u', $fields['display_name']) === 1) {
            throw new licence_exception('invalid_payload');
        }
        if (!class_exists(\Normalizer::class)) {
            throw new licence_exception('environment_unsupported');
        }
        if (!\Normalizer::isNormalized($fields['display_name'], \Normalizer::FORM_C)) {
            throw new licence_exception('invalid_payload');
        }

        return [
            'reference' => $fields['reference'],
            'display_name' => $fields['display_name'],
        ];
    }

    /**
     * Validate the one-instance binding object.
     *
     * @param mixed $value Decoded value.
     * @return array{activation_id:string,wwwroot_hash:string,bound_at:string,revoked:bool}
     */
    private function validate_instance(mixed $value): array {
        self::require_exact_object(
            $value,
            ['activation_id', 'wwwroot_hash', 'bound_at', 'revoked'],
            'invalid_payload'
        );
        $fields = get_object_vars($value);
        self::require_pattern($fields['activation_id'], '/^[A-Za-z0-9_-]{8,64}$/D');
        self::require_pattern($fields['wwwroot_hash'], '/^sha256:[0-9a-f]{64}$/D');
        self::timestamp($fields['bound_at']);
        if (!is_bool($fields['revoked'])) {
            throw new licence_exception('invalid_payload');
        }

        return [
            'activation_id' => $fields['activation_id'],
            'wwwroot_hash' => $fields['wwwroot_hash'],
            'bound_at' => $fields['bound_at'],
            'revoked' => $fields['revoked'],
        ];
    }

    /**
     * Validate one known entitlement and its time relationships.
     *
     * @param mixed $value Decoded value.
     * @return array{
     *     entry:array{
     *         status:string,
     *         starts_at:string,
     *         expires_at:?string,
     *         grace_until:?string,
     *         updates_until:?string,
     *         support_until:?string
     *     },
     *     times:array{
     *         starts_at:int,
     *         expires_at:?int,
     *         grace_until:?int,
     *         updates_until:?int,
     *         support_until:?int
     *     }
     * }
     */
    private function validate_entitlement(mixed $value): array {
        self::require_exact_object(
            $value,
            [
                'status',
                'starts_at',
                'expires_at',
                'grace_until',
                'updates_until',
                'support_until',
            ],
            'invalid_payload'
        );
        $fields = get_object_vars($value);
        if (!is_string($fields['status']) || !entitlement_status::is_known($fields['status'])) {
            throw new licence_exception('invalid_payload');
        }

        $starts = self::timestamp($fields['starts_at']);
        $expires = self::nullable_timestamp($fields['expires_at']);
        $grace = self::nullable_timestamp($fields['grace_until']);
        $updates = self::nullable_timestamp($fields['updates_until']);
        $support = self::nullable_timestamp($fields['support_until']);

        if ($expires === null) {
            if ($grace !== null) {
                throw new licence_exception('invalid_payload');
            }
        } else if ($grace === null || $starts > $expires || $expires > $grace) {
            throw new licence_exception('invalid_payload');
        }

        return [
            'entry' => [
                'status' => $fields['status'],
                'starts_at' => $fields['starts_at'],
                'expires_at' => $fields['expires_at'],
                'grace_until' => $fields['grace_until'],
                'updates_until' => $fields['updates_until'],
                'support_until' => $fields['support_until'],
            ],
            'times' => [
                'starts_at' => $starts,
                'expires_at' => $expires,
                'grace_until' => $grace,
                'updates_until' => $updates,
                'support_until' => $support,
            ],
        ];
    }

    /**
     * Require an object with exactly the named members.
     *
     * @param mixed $value Value to inspect.
     * @param array<int, string> $required Required member names.
     * @param string $diagnosis Diagnosis to expose on mismatch.
     * @return void
     */
    private static function require_exact_object(
        mixed $value,
        array $required,
        string $diagnosis
    ): void {
        if (!$value instanceof \stdClass) {
            throw new licence_exception($diagnosis);
        }
        $actual = array_keys(get_object_vars($value));
        sort($actual, SORT_STRING);
        sort($required, SORT_STRING);
        if ($actual !== $required) {
            throw new licence_exception($diagnosis);
        }
    }

    /**
     * Require a string matching a full anchored pattern.
     *
     * @param mixed $value Value to inspect.
     * @param string $pattern PCRE pattern.
     * @return void
     */
    private static function require_pattern(mixed $value, string $pattern): void {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new licence_exception('invalid_payload');
        }
    }

    /**
     * Validate and convert an exact UTC timestamp.
     *
     * @param mixed $value Decoded value.
     * @return int Unix timestamp.
     */
    private static function timestamp(mixed $value): int {
        if (!is_string($value)
                || preg_match(
                    '/^[0-9]{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])'
                        . 'T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]Z$/D',
                    $value
                ) !== 1) {
            throw new licence_exception('invalid_payload');
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC')
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
                || ($errors !== false
                    && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
                || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new licence_exception('invalid_payload');
        }
        return $date->getTimestamp();
    }

    /**
     * Validate an optional exact UTC timestamp.
     *
     * @param mixed $value Decoded value.
     * @return int|null
     */
    private static function nullable_timestamp(mixed $value): ?int {
        return $value === null ? null : self::timestamp($value);
    }

    /**
     * Convert strict decoder objects recursively to arrays for consumers.
     *
     * @param mixed $value Decoded JSON value.
     * @return mixed
     */
    private static function to_array(mixed $value): mixed {
        if ($value instanceof \stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $name => $entry) {
                $result[$name] = self::to_array($entry);
            }
            return $result;
        }
        if (is_array($value)) {
            return array_map([self::class, 'to_array'], $value);
        }
        return $value;
    }
}
