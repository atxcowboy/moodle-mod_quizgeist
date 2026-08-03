<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Immutable result of a successful offline licence verification.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Contains only data from a fully validated and correctly bound file.
 */
final class verification_result {

    /** @var array Fully validated payload. */
    private array $payload;

    /** @var string Exact signed payload bytes. */
    private string $payloadbytes;

    /** @var string Decoded 64-byte signature. */
    private string $signaturebytes;

    /** @var array<string, string> Effective states of known entitlements. */
    private array $statuses;

    /** @var string Hash calculated from the local wwwroot. */
    private string $wwwroothash;

    /**
     * @param array $payload Fully validated payload.
     * @param string $payloadbytes Exact signed payload bytes.
     * @param string $signaturebytes Decoded signature bytes.
     * @param array<string, string> $statuses Effective known states.
     * @param string $wwwroothash Locally calculated binding hash.
     */
    public function __construct(
        array $payload,
        string $payloadbytes,
        string $signaturebytes,
        array $statuses,
        string $wwwroothash
    ) {
        $this->payload = $payload;
        $this->payloadbytes = $payloadbytes;
        $this->signaturebytes = $signaturebytes;
        $this->statuses = $statuses;
        $this->wwwroothash = $wwwroothash;
    }

    /**
     * Return the validated payload.
     *
     * @return array
     */
    public function payload(): array {
        return $this->payload;
    }

    /**
     * Return the exact bytes covered by the signature.
     *
     * @return string
     */
    public function payload_bytes(): string {
        return $this->payloadbytes;
    }

    /**
     * Return the decoded detached signature.
     *
     * @return string
     */
    public function signature_bytes(): string {
        return $this->signaturebytes;
    }

    /**
     * Return the effective state of a known entitlement.
     *
     * Missing and future entitlement keys are deliberately fail-closed.
     *
     * @param string $component Entitlement key.
     * @return string
     */
    public function effective_status(string $component): string {
        return $this->statuses[$component] ?? entitlement_status::READ_ONLY;
    }

    /**
     * Return effective states for all known entitlement keys.
     *
     * @return array<string, string>
     */
    public function effective_statuses(): array {
        return $this->statuses;
    }

    /**
     * Return the binding hash calculated from the supplied local wwwroot.
     *
     * @return string
     */
    public function wwwroot_hash(): string {
        return $this->wwwroothash;
    }
}
