<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * JSON Canonicalization Scheme encoder for licence payloads.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Encodes the JSON subset used by panomity.quizgeist.license.v1.
 *
 * RFC 8785 sorts object member names by UTF-16 code units. Licence v1 has no
 * floating-point values, so the substantially more complex ECMAScript number
 * serialisation is intentionally outside this encoder.
 */
final class jcs {

    /**
     * Return canonical UTF-8 JSON bytes.
     *
     * @param mixed $value JSON-compatible value.
     * @return string
     * @throws licence_exception
     */
    public static function encode(mixed $value): string {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            if ($value < 0 || $value > strict_json::MAX_SAFE_INTEGER) {
                throw new licence_exception('json_number_invalid');
            }
            return (string)$value;
        }
        if (is_float($value)) {
            throw new licence_exception('json_number_invalid');
        }
        if (is_string($value)) {
            return self::encode_string($value);
        }
        if ($value instanceof \stdClass) {
            return self::encode_std_object($value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                $encoded = [];
                foreach ($value as $entry) {
                    $encoded[] = self::encode($entry);
                }
                return '[' . implode(',', $encoded) . ']';
            }
            return self::encode_object($value);
        }

        throw new licence_exception('invalid_json_value');
    }

    /**
     * Encode a decoded JSON object without PHP array-key coercion.
     *
     * Iterating stdClass directly preserves member names such as "0" as
     * strings. get_object_vars() would turn that name into integer key 0 and
     * incorrectly reject an otherwise valid RFC 8785 object.
     *
     * @param \stdClass $object Decoded JSON object.
     * @return string
     */
    private static function encode_std_object(\stdClass $object): string {
        $properties = [];
        foreach ($object as $name => $value) {
            if (!is_string($name)) {
                throw new licence_exception('invalid_json_value');
            }
            $properties[] = [
                'name' => $name,
                'value' => $value,
            ];
        }
        usort(
            $properties,
            static fn(array $first, array $second): int =>
                self::compare_property_names(
                    $first['name'],
                    $second['name']
                )
        );
        $encoded = [];
        foreach ($properties as $property) {
            $encoded[] = self::encode_string($property['name'])
                . ':'
                . self::encode($property['value']);
        }
        return '{' . implode(',', $encoded) . '}';
    }

    /**
     * Encode an object after RFC 8785 property sorting.
     *
     * @param array $properties Object properties.
     * @return string
     */
    private static function encode_object(array $properties): string {
        foreach (array_keys($properties) as $name) {
            if (!is_string($name)) {
                throw new licence_exception('invalid_json_value');
            }
        }

        uksort($properties, [self::class, 'compare_property_names']);
        $encoded = [];
        foreach ($properties as $name => $value) {
            $encoded[] = self::encode_string($name) . ':' . self::encode($value);
        }
        return '{' . implode(',', $encoded) . '}';
    }

    /**
     * Compare names by unsigned UTF-16 code units as required by RFC 8785.
     *
     * @param string $first First UTF-8 name.
     * @param string $second Second UTF-8 name.
     * @return int
     */
    private static function compare_property_names(string $first, string $second): int {
        $firstutf16 = self::utf16be($first);
        $secondutf16 = self::utf16be($second);
        return strcmp($firstutf16, $secondutf16);
    }

    /**
     * Convert a valid UTF-8 string for bytewise UTF-16 code-unit comparison.
     *
     * @param string $value UTF-8 string.
     * @return string
     */
    private static function utf16be(string $value): string {
        if (preg_match('//u', $value) !== 1) {
            throw new licence_exception('invalid_utf8');
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
        }
        $converted = iconv('UTF-8', 'UTF-16BE', $value);
        if (!is_string($converted)) {
            throw new licence_exception('invalid_utf8');
        }
        return $converted;
    }

    /**
     * Encode a string with the RFC 8785 JSON escaping rules.
     *
     * @param string $value UTF-8 value.
     * @return string
     */
    private static function encode_string(string $value): string {
        if (preg_match('//u', $value) !== 1) {
            throw new licence_exception('invalid_utf8');
        }
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_LINE_TERMINATORS
                    | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new licence_exception('invalid_json_value', '', $exception);
        }
    }
}
