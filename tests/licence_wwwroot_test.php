<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for version 1 of the licence wwwroot binding.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\licence\licence_exception;
use mod_quizgeist\local\licence\wwwroot;

defined('MOODLE_INTERNAL') || die();

/**
 * Covers normative examples and URL ambiguity boundaries.
 */
final class licence_wwwroot_test extends \advanced_testcase {

    /**
     * The three normative contract examples canonicalise byte-for-byte.
     *
     * @return void
     */
    public function test_contract_examples_are_canonicalised(): void {
        $examples = [
            'HTTPS://Moodle.Beispielschule.de/' =>
                'https://moodle.beispielschule.de',
            'https://schule.example:443/moodle/' =>
                'https://schule.example/moodle',
            'http://10.0.0.5:8080/moodle' =>
                'http://10.0.0.5:8080/moodle',
        ];

        foreach ($examples as $input => $expected) {
            $this->assertSame($expected, wwwroot::canonicalise($input));
        }
        $this->assertSame(
            'sha256:b6573cd54d9c3f440cb4c354c77b15706d1403d645df4d8d59ff2382ecc386a1',
            wwwroot::hash('HTTPS://Moodle.Beispielschule.de/')
        );
    }

    /**
     * DNS, IP and port normalisation removes only contract-defined variance.
     *
     * @return void
     */
    public function test_hosts_and_ports_are_normalised(): void {
        $examples = [
            " \thttps://SCHOOL.EXAMPLE.:443/\r\n" =>
                'https://school.example',
            'http://192.168.001.001:80/' => null,
            'http://192.168.1.1:80/' => 'http://192.168.1.1',
            'https://[2001:0db8:0000:0000:0000:0000:0000:0001]:443/' =>
                'https://[2001:db8::1]',
            'http://[2001:db8::2]:8080/moodle/' =>
                'http://[2001:db8::2]:8080/moodle',
            'https://xn--bcher-kva.example/' =>
                'https://xn--bcher-kva.example',
        ];

        foreach ($examples as $input => $expected) {
            if ($expected === null) {
                $this->assert_invalid($input);
            } else {
                $this->assertSame($expected, wwwroot::canonicalise($input));
            }
        }
    }

    /**
     * Raw path case and percent encoding stay binding material.
     *
     * @return void
     */
    public function test_path_is_preserved_except_for_trailing_slashes(): void {
        $this->assertSame(
            'https://school.example/Moodle/%7eUser',
            wwwroot::canonicalise(
                'https://school.example/Moodle/%7eUser///'
            )
        );
        $this->assertNotSame(
            wwwroot::hash('https://school.example/Moodle/%7eUser'),
            wwwroot::hash('https://school.example/moodle/%7EUser')
        );
        $this->assertSame(
            'https://school.example/München/~quiz!$&\'()+,;=:@',
            wwwroot::canonicalise(
                'https://school.example/München/~quiz!$&\'()+,;=:@'
            )
        );
    }

    /**
     * Credentials and request-specific URL components are not instance roots.
     *
     * @return void
     */
    public function test_ambiguous_or_non_http_urls_are_rejected(): void {
        $invalid = [
            '',
            'school.example/moodle',
            '/moodle',
            'ftp://school.example/moodle',
            'https://user@school.example/moodle',
            'https://user:pass@school.example/moodle',
            'https://school.example/moodle?tenant=1',
            'https://school.example/moodle?',
            'https://school.example/moodle#fragment',
            'https://school.example/moodle#',
            'https://bücher.example/moodle',
            'https://school_exam.example/moodle',
            'https://school..example/moodle',
            'https://-school.example/moodle',
            'https://school.example../moodle',
            "https://school.example/moo dle",
            "https://school.example/\0moodle",
            "https://school.example/\xFF",
            'https://school.example/moodle/%ZZ',
            'https://school.example/moodle/%2',
            'https://school.example/a\\b',
            'https://school.example/a|b',
            'https://school.example/a{b}',
            'https://school.example/a[b]',
        ];

        foreach ($invalid as $value) {
            $this->assert_invalid($value);
        }
    }

    /**
     * Assert the stable diagnosis for a malformed binding URL.
     *
     * @param string $value Invalid URL.
     * @return void
     */
    private function assert_invalid(string $value): void {
        try {
            wwwroot::canonicalise($value);
            $this->fail("Invalid wwwroot was accepted: {$value}");
        } catch (licence_exception $exception) {
            $this->assertSame('invalid_wwwroot', $exception->diagnosis());
        }
    }
}
