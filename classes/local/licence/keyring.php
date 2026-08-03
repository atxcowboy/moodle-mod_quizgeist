<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Compile-time trust anchors for Quizgeist licence files.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Returns only public keys shipped as part of a reviewed plugin release.
 *
 * Keys received from a licence file, HTTP response or an administrator are
 * deliberately never accepted here. The contract's qg-test key belongs in
 * automated tests only and must not become a production trust anchor.
 */
final class keyring {

    /**
     * Current and immediately previous production Ed25519 public keys.
     *
     * Keyed by key_id, values are strict unpadded base64url public keys.
     * The private counterpart lives ONLY on the licensing server and is never
     * part of this repository or of any shipped plugin.
     *
     * Rotation: add the new key here and ship the plugin release BEFORE the
     * licensing server activates the new key_id. Keep the previous key until
     * every installation has received that release.
     *
     * @return array<string,string>
     */
    public static function production(): array {
        return [
            // Erzeugt 2026-07-30 auf dem Panomity-Lizenzserver.
            '4ac3bc6c389134af' => 'VfLn460-MlsmuIdQReM7VRmGN0cbYc5Xu07nqqJeGh8',
        ];
    }
}
