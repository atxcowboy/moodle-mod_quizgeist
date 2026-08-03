<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Fail-closed exception raised while reading an offline licence.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Carries a stable, non-sensitive diagnosis for the administration UI.
 */
final class licence_exception extends \RuntimeException {

    /** @var string Stable machine-readable diagnosis. */
    private string $diagnosis;

    /**
     * @param string $diagnosis Stable machine-readable diagnosis.
     * @param string $message Internal message without licence data or secrets.
     * @param \Throwable|null $previous Original implementation error, if any.
     */
    public function __construct(
        string $diagnosis,
        string $message = '',
        ?\Throwable $previous = null
    ) {
        $this->diagnosis = $diagnosis;
        parent::__construct($message === '' ? $diagnosis : $message, 0, $previous);
    }

    /**
     * Return the stable diagnosis suitable for mapping to a localised message.
     *
     * @return string
     */
    public function diagnosis(): string {
        return $this->diagnosis;
    }
}
