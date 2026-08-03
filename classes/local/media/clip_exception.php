<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Stable clip-channel error with a translated message of its own.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\media;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries a client-stable code AND resolves its own readable sentence.
 *
 * Modelled on live_domain_exception, with the one difference that matters for
 * F16: the language key is not passed in at every throw site. Each code maps to
 * exactly one key here, so a new rejection reason cannot be invented without
 * also getting a sentence — the failure mode that put raw codes into the DOM in
 * P10 was precisely a code without a message.
 */
final class clip_exception extends \moodle_exception {

    /** @var array<string,string> code => mod_quizgeist language key. */
    private const MESSAGES = [
        'clip_owner_invalid' => 'clip:error:notallowed',
        'clip_purpose_invalid' => 'clip:error:notallowed',
        'clip_not_found' => 'clip:error:notfound',
        'clip_binding_invalid' => 'clip:error:notfound',
        'clip_already_bound' => 'clip:error:alreadybound',
        'clip_upload_failed' => 'clip:error:uploadfailed',
        'clip_storage_failed' => 'clip:error:uploadfailed',
        'clip_empty' => 'clip:error:empty',
        'clip_too_large' => 'clip:error:toolarge',
        'clip_too_long' => 'clip:error:toolong',
        'clip_type_invalid' => 'clip:error:typeinvalid',
        'clip_type_mismatch' => 'clip:error:typeinvalid',
        'clip_signature_unknown' => 'clip:error:typeinvalid',
        'clip_duration_unreadable' => 'clip:error:durationunreadable',
        'clip_unreadable' => 'clip:error:uploadfailed',
        'clip_rate_limited' => 'clip:error:ratelimited',
        'clip_not_recording' => 'clip:error:notallowed',
    ];

    /** @var array<string,int> Codes whose HTTP status is not 400. */
    private const STATUSES = [
        'clip_not_found' => 404,
        'clip_owner_invalid' => 403,
        'clip_not_recording' => 403,
        'clip_too_large' => 413,
        'clip_rate_limited' => 429,
    ];

    /**
     * The property is NOT called `$code`.
     *
     * `Exception::$code` already exists and is not readonly, so a promoted
     * readonly `$code` is a hard fatal the moment this file is loaded — which
     * `php -l` cannot see, because it only parses. live_domain_exception names
     * its own field `$domaincode` for exactly this reason; `$errorcode` is taken too — moodle_exception itself uses it.
     *
     * @param string $clipcode Stable snake-case clip error code.
     */
    public function __construct(private readonly string $clipcode) {
        if (!array_key_exists($clipcode, self::MESSAGES)) {
            throw new \coding_exception('An unknown clip error code was raised.');
        }
        parent::__construct(self::MESSAGES[$clipcode], 'mod_quizgeist');
    }

    /**
     * Return the stable API error code.
     *
     * @return string
     */
    public function get_error_code(): string {
        return $this->errorcode;
    }

    /**
     * Return the mod_quizgeist language-string key of this code.
     *
     * @return string
     */
    public function get_string_key(): string {
        return self::MESSAGES[$this->errorcode];
    }

    /**
     * Return the HTTP client-error status.
     *
     * @return int
     */
    public function get_http_status(): int {
        return self::STATUSES[$this->errorcode] ?? 400;
    }

    /**
     * Return every code together with its language key, for static audits.
     *
     * @return array<string,string>
     */
    public static function all_messages(): array {
        return self::MESSAGES;
    }
}
