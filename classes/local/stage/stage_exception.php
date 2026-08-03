<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Stable stage-check error with a translated message of its own (F13).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\stage;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries a client-stable code AND resolves its own readable sentence.
 *
 * Verbatim the clip_exception/card_exception pattern: a new rejection reason
 * cannot be invented without also getting a sentence, so a raw machine code
 * can never be the only thing a learner sees (F16).
 *
 * The class lives in the BASIS, not in the addon, for the same reason the
 * table does: its language keys are shipped with the basis and stay
 * translatable even when the addon code package is absent.
 */
final class stage_exception extends \moodle_exception {

    /** @var array<string,string> code => mod_quizgeist language key. */
    private const MESSAGES = [
        'stage_not_available' => 'stage:error:notavailable',
        'stage_not_allowed' => 'stage:error:notallowed',
        'stage_question_invalid' => 'stage:error:questioninvalid',
        'stage_answer_invalid' => 'stage:error:answerinvalid',
        'stage_metrics_invalid' => 'stage:error:metricsinvalid',
        'stage_metrics_too_large' => 'stage:error:metricstoolarge',
        'stage_metrics_out_of_range' => 'stage:error:metricsoutofrange',
        'stage_metrics_too_many_points' => 'stage:error:metricstoomanypoints',
        'stage_duration_invalid' => 'stage:error:durationinvalid',
        'stage_report_not_found' => 'stage:error:reportnotfound',
    ];

    /** @var array<string,int> Codes whose HTTP status is not 400. */
    private const STATUSES = [
        'stage_not_available' => 404,
        'stage_not_allowed' => 403,
        'stage_report_not_found' => 404,
        'stage_metrics_too_large' => 413,
    ];

    /**
     * The property is NOT called `$code` — see clip_exception for why.
     *
     * @param string $stagecode Stable snake-case stage error code.
     */
    public function __construct(private readonly string $stagecode) {
        if (!array_key_exists($stagecode, self::MESSAGES)) {
            throw new \coding_exception('An unknown stage error code was raised.');
        }
        parent::__construct(self::MESSAGES[$stagecode], 'mod_quizgeist');
    }

    /**
     * Return the stable API error code.
     *
     * Deliberately `$this->stagecode` and NOT `$this->errorcode`: moodle_exception
     * stores the LANGUAGE KEY in `$errorcode`, so the sibling families
     * `clip_exception` and `card_exception` hand out their language key as the
     * machine code and their `get_string_key()` looks a value up as a key. The
     * shape that works is `live_domain_exception`'s, and this class follows it.
     * The observation about the two siblings is recorded in REVIEWS/P11_FUNDE.md
     * rather than fixed here — it is not this cluster's surface.
     *
     * @return string
     */
    public function get_error_code(): string {
        return $this->stagecode;
    }

    /**
     * Return the mod_quizgeist language-string key of this code.
     *
     * @return string
     */
    public function get_string_key(): string {
        return self::MESSAGES[$this->stagecode];
    }

    /**
     * Return the HTTP client-error status.
     *
     * @return int
     */
    public function get_http_status(): int {
        return self::STATUSES[$this->stagecode] ?? 400;
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
