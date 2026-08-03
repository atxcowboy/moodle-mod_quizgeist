<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Stable card-mode error with a translated message of its own (F11a).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries a client-stable code AND resolves its own readable sentence.
 *
 * Verbatim the clip_exception pattern (U3): a new rejection reason cannot be
 * invented without also getting a sentence, so a raw machine code can never
 * be the only thing a teacher sees (F16).
 */
final class card_exception extends \moodle_exception {

    /** @var array<string,string> code => mod_quizgeist language key. */
    private const MESSAGES = [
        'cardset_not_found' => 'cards:error:setnotfound',
        'cardset_layout_invalid' => 'cards:error:layoutinvalid',
        'cardset_name_invalid' => 'cards:error:nameinvalid',
        'cardset_too_many' => 'cards:error:toomanycards',
        'cardset_empty' => 'cards:error:emptyset',
        'card_scan_not_found' => 'cards:error:scannotfound',
        'card_scan_not_allowed' => 'cards:error:scannotallowed',
        'card_scan_state_invalid' => 'cards:error:scanstateinvalid',
        'card_scan_question_invalid' => 'cards:error:scanquestioninvalid',
        'card_scan_upload_failed' => 'cards:error:scanuploadfailed',
        'card_scan_storage_failed' => 'cards:error:scanuploadfailed',
        'card_scan_empty' => 'cards:error:scanempty',
        'card_scan_too_large' => 'cards:error:scantoolarge',
        'card_scan_type_invalid' => 'cards:error:scantypeinvalid',
        'card_scan_pixels_invalid' => 'cards:error:scanpixelsinvalid',
        'card_scan_image_deleted' => 'cards:error:scanimagedeleted',
        'card_scan_rate_limited' => 'cards:error:scanratelimited',
        'card_scan_not_recognised' => 'cards:error:scannotrecognised',
    ];

    /** @var array<string,int> Codes whose HTTP status is not 400. */
    private const STATUSES = [
        'cardset_not_found' => 404,
        'card_scan_not_found' => 404,
        'card_scan_not_allowed' => 403,
        'card_scan_too_large' => 413,
        'card_scan_rate_limited' => 429,
    ];

    /**
     * The property is NOT called `$code` — see clip_exception for why.
     *
     * @param string $cardcode Stable snake-case card error code.
     */
    public function __construct(private readonly string $cardcode) {
        if (!array_key_exists($cardcode, self::MESSAGES)) {
            throw new \coding_exception('An unknown card error code was raised.');
        }
        parent::__construct(self::MESSAGES[$cardcode], 'mod_quizgeist');
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
