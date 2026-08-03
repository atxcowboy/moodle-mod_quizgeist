<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Tests for the Bühnen-Check no-media boundary.
 *
 * @package    mod_quizgeist
 * @category   test
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist;

use mod_quizgeist\local\ajax\action_registry;

defined('MOODLE_INTERNAL') || die();

/**
 * The camera is analysed locally; the addon accepts numbers, never media.
 */
final class stage_no_media_test extends \advanced_testcase {

    /**
     * Install the addon for registry discovery.
     *
     * @return void
     */
    private function install_buehne(): void {
        set_config('version', 2026073000, 'quizgeistaddon_buehne');
    }

    /**
     * Return only the actions announced by the Bühnen-Check addon.
     *
     * @return array<string,array>
     */
    private function buehne_actions(): array {
        $actions = action_registry::actions();
        $buehne = [];
        foreach ($actions as $name => $definition) {
            if (($definition['feature'] ?? null) === 'buehne') {
                $buehne[$name] = $definition;
            }
        }
        return $buehne;
    }

    /**
     * Remove comments before checking executable handler source. The finish
     * handler documents forbidden upload APIs in its docblock; comments are
     * not an input path and must not make this boundary test a false positive.
     *
     * @param string $source PHP source.
     * @return string Source with comments removed.
     */
    private function without_php_comments(string $source): string {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)
                    && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }

    /**
     * Registered stage actions do not declare file or multipart inputs.
     */
    public function test_stage_actions_have_no_file_or_multipart_input(): void {
        $this->resetAfterTest(true);
        $this->install_buehne();

        $actions = $this->buehne_actions();
        $this->assertCount(2, $actions);
        foreach ($actions as $name => $definition) {
            foreach (array_keys($definition) as $key) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(?:file|multipart|upload)/i',
                    $key,
                    "Bühnen-Aktion {$name} deklariert einen Medien-Eingang."
                );
            }
            $encoded = json_encode($definition);
            $this->assertIsString($encoded);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:file|multipart|upload)/i',
                $encoded,
                "Bühnen-Aktion {$name} deklariert einen Medien-Eingang."
            );
        }
    }

    /**
     * The two handlers contain no executable upload or binary-stream reads.
     */
    public function test_stage_handlers_do_not_read_binary_uploads(): void {
        $this->resetAfterTest(true);

        foreach (['stage_start_handler.php', 'stage_finish_handler.php'] as $file) {
            $path = __DIR__ . '/../addon/buehne/classes/local/ajax/' . $file;
            $source = file_get_contents($path);
            $this->assertIsString($source, "Handler {$file} fehlt.");
            $source = $this->without_php_comments($source);

            $this->assertStringNotContainsString('$_FILES', $source, $file);
            $this->assertStringNotContainsString('is_uploaded_file', $source, $file);
            $this->assertDoesNotMatchRegularExpression(
                '/file_get_contents\s*\(\s*[\'\"]php:\/\/input[\'\"]\s*\)/',
                $source,
                $file
            );
        }
    }

    /**
     * Base and addon privacy strings make the no-video/no-raw-landmarks
     * promise in both languages.
     */
    public function test_privacy_strings_promise_no_video_or_raw_landmarks(): void {
        $this->resetAfterTest(true);

        $germanpaths = [
            __DIR__ . '/../lang/de/quizgeist.php',
            __DIR__ . '/../addon/buehne/lang/de/quizgeistaddon_buehne.php',
        ];
        $englishpaths = [
            __DIR__ . '/../lang/en/quizgeist.php',
            __DIR__ . '/../addon/buehne/lang/en/quizgeistaddon_buehne.php',
        ];
        foreach ($germanpaths as $path) {
            $german = file_get_contents($path);
            $this->assertIsString($german);
            // The shipped sentence continues with a colon/dash; assert the
            // exact promise without inventing a different closing mark.
            $this->assertStringContainsString(
                'Video und rohe Körper-Landmarks werden nicht gespeichert',
                $german
            );
        }
        foreach ($englishpaths as $path) {
            $english = file_get_contents($path);
            $this->assertIsString($english);
            $this->assertStringContainsString(
                'Video and raw body landmarks are not stored',
                $english
            );
        }
    }
}
