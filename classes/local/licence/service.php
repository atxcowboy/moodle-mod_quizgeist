<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cached local licence evaluation.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Produces one privacy-conscious status snapshot for all feature gates.
 */
final class service {

    /** Known v1 entitlements, mapped to public feature keys. */
    public const COMPONENTS = [
        'qtypes' => 'quizgeistaddon_qtypes',
        'modes' => 'quizgeistaddon_modes',
        'selfstudy' => 'quizgeistaddon_selfstudy',
        'reports' => 'quizgeistaddon_reports',
        'ai' => 'quizgeistaddon_ai',
        // P11/C6 (F13) Buehnen-Check. E-9: im Schulpaket fuer DACH enthalten,
        // international ein Einzeladdon. Plugin-seitig ist das genau EIN
        // eigener Entitlement-Schluessel — der Paketschnitt gehoert dem Handel.
        'buehne' => 'quizgeistaddon_buehne',
    ];

    /**
     * Evaluate the installed file.
     *
     * Passing a time bypasses MUC for exact deterministic boundary tests.
     *
     * @param int|null $now Unix timestamp.
     * @return array{
     *   valid:bool,diagnosis:string,fingerprint:?string,license:?array,
     *   entitlements:array<string,string>,valid_until:int
     * }
     */
    public static function snapshot(?int $now = null): array {
        global $CFG;

        $evaluationtime = $now ?? time();
        try {
            $rawfile = (new storage())->read();
        } catch (licence_exception $exception) {
            $cachekey = hash(
                'sha256',
                "storage-error\0"
                    . $exception->diagnosis()
                    . "\0"
                    . (string)$CFG->wwwroot
            );
            if ($now === null) {
                $cached = self::cache()->get($cachekey);
                if (is_array($cached)
                        && (int)($cached['valid_until'] ?? 0)
                            > $evaluationtime) {
                    return $cached;
                }
            }
            $snapshot = self::failed(
                $exception->diagnosis(),
                $evaluationtime
            );
            if ($now === null) {
                self::cache()->set($cachekey, $snapshot);
            }
            return $snapshot;
        }
        if ($rawfile === null) {
            $cachekey = hash(
                'sha256',
                "missing\0" . (string)$CFG->wwwroot
            );
            if ($now === null) {
                $cached = self::cache()->get($cachekey);
                if (is_array($cached)
                        && (int)($cached['valid_until'] ?? 0)
                            > $evaluationtime) {
                    return $cached;
                }
            }
            $snapshot = self::failed('missing_licence', $evaluationtime);
            if ($now === null) {
                self::cache()->set($cachekey, $snapshot);
            }
            return $snapshot;
        }
        $fingerprint = hash('sha256', $rawfile);
        $cachekey = hash(
            'sha256',
            $fingerprint . "\0" . (string)$CFG->wwwroot
        );
        if ($now === null) {
            $cached = self::cache()->get($cachekey);
            if (is_array($cached)
                    && (int)($cached['valid_until'] ?? 0) > $evaluationtime) {
                return $cached;
            }
        }

        $trustedkeys = keyring::production();
        if (!$trustedkeys) {
            $snapshot = self::failed(
                'production_keyring_missing',
                $evaluationtime,
                $fingerprint
            );
        } else {
            try {
                $result = (new verifier($trustedkeys))->verify(
                    $rawfile,
                    (string)$CFG->wwwroot,
                    $evaluationtime
                );
                $payload = $result->payload();
                $entitlements = [];
                foreach (self::COMPONENTS as $component) {
                    $entitlements[$component] =
                        $result->effective_status($component);
                }
                $snapshot = [
                    'valid' => true,
                    'diagnosis' => 'ok',
                    'fingerprint' => $fingerprint,
                    'license' => [
                        'license_id' => (string)$payload['license_id'],
                        'license_kind' => (string)$payload['license_kind'],
                        'customer' => (string)$payload['customer']['display_name'],
                        'activation_id' =>
                            (string)$payload['instance']['activation_id'],
                        'instance_limit' => (int)$payload['instance_limit'],
                        'revision' => (int)$payload['revision'],
                        'issued_at' => (string)$payload['issued_at'],
                        'refresh_after' => $payload['refresh_after'],
                        'revoked' => (bool)$payload['instance']['revoked'],
                        'entitlements' => $payload['entitlements'],
                    ],
                    'entitlements' => $entitlements,
                    'valid_until' => self::next_transition(
                        $payload,
                        $evaluationtime
                    ),
                ];
            } catch (licence_exception $exception) {
                $snapshot = self::failed(
                    $exception->diagnosis(),
                    $evaluationtime,
                    $fingerprint
                );
            } catch (\Throwable $exception) {
                debugging($exception->getMessage(), DEBUG_DEVELOPER);
                $snapshot = self::failed(
                    'licence_verification_failed',
                    $evaluationtime,
                    $fingerprint
                );
            }
        }

        if ($now === null) {
            self::cache()->set($cachekey, $snapshot);
        }
        return $snapshot;
    }

    /**
     * Purge all cached licence evaluations after an atomic replacement.
     */
    public static function purge_cache(): void {
        self::cache()->purge();
    }

    /**
     * Return the component cache.
     */
    private static function cache(): \cache {
        return \cache::make('mod_quizgeist', 'licence');
    }

    /**
     * Fail closed for new content while retaining a stable diagnosis.
     */
    private static function failed(
        string $diagnosis,
        int $now,
        ?string $fingerprint = null
    ): array {
        return [
            'valid' => false,
            'diagnosis' => $diagnosis,
            'fingerprint' => $fingerprint,
            'license' => null,
            'entitlements' => array_fill_keys(
                array_values(self::COMPONENTS),
                'read_only'
            ),
            'valid_until' => $now + 300,
        ];
    }

    /**
     * Bound a cached snapshot by the next signed status transition.
     */
    private static function next_transition(array $payload, int $now): int {
        $next = $now + 3600;
        foreach ((array)$payload['entitlements'] as $entitlement) {
            if (!is_array($entitlement)) {
                continue;
            }
            foreach (['starts_at', 'expires_at', 'grace_until'] as $field) {
                $value = $entitlement[$field] ?? null;
                if (!is_string($value)) {
                    continue;
                }
                $timestamp = strtotime($value);
                if ($timestamp !== false && $timestamp > $now) {
                    $next = min($next, $timestamp);
                }
            }
        }
        return $next;
    }
}
