<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Bounded JSON decoder with duplicate-key detection.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Parses the deliberately small numeric subset used by licence format v1.
 *
 * Native json_decode() silently keeps the last duplicate object member, which
 * is unsuitable at a signature and authorisation boundary. Objects are
 * represented as stdClass instances so an empty object remains distinct from
 * an empty array.
 */
final class strict_json {

    /** Largest interoperable integer admitted by the v1 contract. */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /** @var string JSON bytes being parsed. */
    private string $json;

    /** @var int Byte length of the input. */
    private int $length;

    /** @var int Current byte offset. */
    private int $offset = 0;

    /** @var int Maximum object/array nesting. */
    private int $maxdepth;

    /**
     * @param string $json Valid UTF-8 JSON bytes.
     * @param int $maxdepth Maximum object/array nesting.
     */
    private function __construct(string $json, int $maxdepth) {
        $this->json = $json;
        $this->length = strlen($json);
        $this->maxdepth = $maxdepth;
    }

    /**
     * Decode bounded, duplicate-free JSON.
     *
     * Licence format v1 permits only non-negative safe integers. Fractions,
     * exponent notation and negative numbers are rejected even if they would
     * otherwise be valid JSON.
     *
     * @param string $json Raw JSON bytes.
     * @param int $maxbytes Maximum accepted input length.
     * @param int $maxdepth Maximum object/array nesting.
     * @return mixed
     * @throws licence_exception
     */
    public static function decode(
        string $json,
        int $maxbytes = 131072,
        int $maxdepth = 32
    ): mixed {
        if ($maxbytes < 1 || $maxdepth < 1) {
            throw new \InvalidArgumentException('JSON bounds must be positive.');
        }
        if (strlen($json) > $maxbytes) {
            throw new licence_exception('file_too_large');
        }
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            throw new licence_exception('invalid_json');
        }
        if (preg_match('//u', $json) !== 1) {
            throw new licence_exception('invalid_json');
        }

        $parser = new self($json, $maxdepth);
        $parser->skip_whitespace();
        if ($parser->offset >= $parser->length) {
            throw new licence_exception('invalid_json');
        }
        $value = $parser->parse_value(0);
        $parser->skip_whitespace();
        if ($parser->offset !== $parser->length) {
            throw new licence_exception('invalid_json');
        }

        return $value;
    }

    /**
     * Parse one JSON value.
     *
     * @param int $depth Current container nesting.
     * @return mixed
     */
    private function parse_value(int $depth): mixed {
        if ($this->offset >= $this->length) {
            throw new licence_exception('invalid_json');
        }

        $character = $this->json[$this->offset];
        if ($character === '{') {
            return $this->parse_object($depth + 1);
        }
        if ($character === '[') {
            return $this->parse_array($depth + 1);
        }
        if ($character === '"') {
            return $this->parse_string();
        }
        if ($character === 't') {
            $this->consume_literal('true');
            return true;
        }
        if ($character === 'f') {
            $this->consume_literal('false');
            return false;
        }
        if ($character === 'n') {
            $this->consume_literal('null');
            return null;
        }
        if ($character === '-' || ($character >= '0' && $character <= '9')) {
            return $this->parse_number();
        }

        throw new licence_exception('invalid_json');
    }

    /**
     * Parse an object and reject duplicate decoded member names.
     *
     * @param int $depth Container nesting.
     * @return \stdClass
     */
    private function parse_object(int $depth): \stdClass {
        $this->assert_depth($depth);
        $this->offset++;
        $this->skip_whitespace();

        $object = new \stdClass();
        $seen = [];
        if ($this->consume_if('}')) {
            return $object;
        }

        while (true) {
            if ($this->offset >= $this->length || $this->json[$this->offset] !== '"') {
                throw new licence_exception('invalid_json');
            }
            $name = $this->parse_string();
            if (array_key_exists($name, $seen)) {
                throw new licence_exception('invalid_json');
            }
            $seen[$name] = true;

            $this->skip_whitespace();
            $this->expect(':');
            $this->skip_whitespace();
            $object->{$name} = $this->parse_value($depth);
            $this->skip_whitespace();

            if ($this->consume_if('}')) {
                break;
            }
            $this->expect(',');
            $this->skip_whitespace();
        }

        return $object;
    }

    /**
     * Parse an array.
     *
     * @param int $depth Container nesting.
     * @return array
     */
    private function parse_array(int $depth): array {
        $this->assert_depth($depth);
        $this->offset++;
        $this->skip_whitespace();

        $values = [];
        if ($this->consume_if(']')) {
            return $values;
        }

        while (true) {
            $values[] = $this->parse_value($depth);
            $this->skip_whitespace();
            if ($this->consume_if(']')) {
                break;
            }
            $this->expect(',');
            $this->skip_whitespace();
        }

        return $values;
    }

    /**
     * Parse and decode one JSON string token.
     *
     * @return string
     */
    private function parse_string(): string {
        $start = $this->offset;
        $this->offset++;

        while ($this->offset < $this->length) {
            $byte = ord($this->json[$this->offset]);
            if ($byte < 0x20) {
                throw new licence_exception('invalid_json');
            }
            if ($this->json[$this->offset] === '"') {
                $this->offset++;
                $token = substr($this->json, $start, $this->offset - $start);
                try {
                    $decoded = json_decode($token, false, 2, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new licence_exception('invalid_json', '', $exception);
                }
                if (!is_string($decoded)) {
                    throw new licence_exception('invalid_json');
                }
                return $decoded;
            }
            if ($this->json[$this->offset] === '\\') {
                $this->offset++;
                if ($this->offset >= $this->length
                        || strpos('"\\/bfnrtu', $this->json[$this->offset]) === false) {
                    throw new licence_exception('invalid_json');
                }
                if ($this->json[$this->offset] === 'u') {
                    if ($this->offset + 4 >= $this->length
                            || preg_match(
                                '/^[0-9A-Fa-f]{4}$/D',
                                substr($this->json, $this->offset + 1, 4)
                            ) !== 1) {
                        throw new licence_exception('invalid_json');
                    }
                    $this->offset += 4;
                }
            }
            $this->offset++;
        }

        throw new licence_exception('invalid_json');
    }

    /**
     * Parse a JSON number and enforce the v1 integer subset.
     *
     * @return int
     */
    private function parse_number(): int {
        $start = $this->offset;
        $negative = $this->consume_if('-');

        if ($this->offset >= $this->length) {
            throw new licence_exception('invalid_json');
        }
        if ($this->json[$this->offset] === '0') {
            $this->offset++;
            if ($this->offset < $this->length
                    && $this->json[$this->offset] >= '0'
                    && $this->json[$this->offset] <= '9') {
                throw new licence_exception('invalid_json');
            }
        } else {
            if ($this->json[$this->offset] < '1' || $this->json[$this->offset] > '9') {
                throw new licence_exception('invalid_json');
            }
            while ($this->offset < $this->length
                    && $this->json[$this->offset] >= '0'
                    && $this->json[$this->offset] <= '9') {
                $this->offset++;
            }
        }

        $fractionorexponent = false;
        if ($this->consume_if('.')) {
            $fractionorexponent = true;
            $digits = $this->consume_digits();
            if ($digits === 0) {
                throw new licence_exception('invalid_json');
            }
        }
        if ($this->offset < $this->length
                && ($this->json[$this->offset] === 'e' || $this->json[$this->offset] === 'E')) {
            $fractionorexponent = true;
            $this->offset++;
            if ($this->offset < $this->length
                    && ($this->json[$this->offset] === '+' || $this->json[$this->offset] === '-')) {
                $this->offset++;
            }
            $digits = $this->consume_digits();
            if ($digits === 0) {
                throw new licence_exception('invalid_json');
            }
        }

        if ($negative || $fractionorexponent) {
            throw new licence_exception('invalid_json');
        }

        $digits = substr($this->json, $start, $this->offset - $start);
        $maximum = (string)self::MAX_SAFE_INTEGER;
        if (strlen($digits) > strlen($maximum)
                || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new licence_exception('invalid_json');
        }

        return (int)$digits;
    }

    /**
     * Consume consecutive decimal digits.
     *
     * @return int Number of consumed bytes.
     */
    private function consume_digits(): int {
        $start = $this->offset;
        while ($this->offset < $this->length
                && $this->json[$this->offset] >= '0'
                && $this->json[$this->offset] <= '9') {
            $this->offset++;
        }
        return $this->offset - $start;
    }

    /**
     * Consume an exact JSON literal.
     *
     * @param string $literal Literal text.
     * @return void
     */
    private function consume_literal(string $literal): void {
        if (substr($this->json, $this->offset, strlen($literal)) !== $literal) {
            throw new licence_exception('invalid_json');
        }
        $this->offset += strlen($literal);
    }

    /**
     * Require and consume one punctuation byte.
     *
     * @param string $character Required character.
     * @return void
     */
    private function expect(string $character): void {
        if (!$this->consume_if($character)) {
            throw new licence_exception('invalid_json');
        }
    }

    /**
     * Consume a punctuation byte if present.
     *
     * @param string $character Character to inspect.
     * @return bool
     */
    private function consume_if(string $character): bool {
        if ($this->offset < $this->length && $this->json[$this->offset] === $character) {
            $this->offset++;
            return true;
        }
        return false;
    }

    /**
     * Skip the four JSON whitespace bytes.
     *
     * @return void
     */
    private function skip_whitespace(): void {
        while ($this->offset < $this->length
                && strpos(" \t\r\n", $this->json[$this->offset]) !== false) {
            $this->offset++;
        }
    }

    /**
     * Enforce the configured nesting bound.
     *
     * @param int $depth Current depth.
     * @return void
     */
    private function assert_depth(int $depth): void {
        if ($depth > $this->maxdepth) {
            throw new licence_exception('invalid_json');
        }
    }
}
