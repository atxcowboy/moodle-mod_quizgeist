<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * AJAX request dispatcher.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\ajax;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates, authorises and dispatches one JSON AJAX request.
 */
final class dispatcher {

    /**
     * Process the current request and emit its JSON response.
     *
     * @return never
     */
    public function run(): never {
        global $DB, $USER;

        $modulecontext = null;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::respond([
                'ok' => false,
                'error' => 'method_not_allowed',
                'message' => get_string('ajax:methodnotallowed', 'mod_quizgeist'),
            ], 405);
        }

        $payload = $this->decode_payload();
        [$action, $cmid, $requestsesskey] = $this->validate_envelope($payload);

        try {
            $cm = get_coursemodule_from_id('quizgeist', $cmid, 0, false, MUST_EXIST);
            $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
            $quizgeist = $DB->get_record('quizgeist', ['id' => $cm->instance], '*', MUST_EXIST);
            $modulecontext = \context_module::instance($cm->id);

            // Authentication deliberately precedes sesskey validation. Otherwise an
            // expired login is misreported as CSRF and the polling client cannot recover.
            require_login($course, false, $cm);

            if (!confirm_sesskey($requestsesskey)) {
                self::respond([
                    'ok' => false,
                    'error' => 'invalid_sesskey',
                    'message' => get_string('ajax:invalidrequest', 'mod_quizgeist'),
                ], 403);
            }

            $definition = action_registry::get($action);
            if ($definition === null) {
                self::respond([
                    'ok' => false,
                    'error' => 'unknown_action',
                    'message' => get_string('ajax:unknownaction', 'mod_quizgeist'),
                ], 404);
            }

            $capabilities = (array)$definition['capability'];
            if (!$capabilities) {
                throw new \coding_exception('AJAX action has no capability requirement.');
            }
            $authorised = false;
            foreach ($capabilities as $capability) {
                if (has_capability($capability, $modulecontext)) {
                    $authorised = true;
                    break;
                }
            }
            if (!$authorised) {
                // Produce Moodle's canonical required-capability exception so
                // the common 403 response remains unchanged.
                require_capability($capabilities[0], $modulecontext);
            }

            if (isset($definition['feature'])) {
                \mod_quizgeist\local\licence\feature_gate::require(
                    (string)$definition['feature'],
                    (string)$definition['operation']
                );
            }

            if (!$definition['writes']) {
                \core\session\manager::write_close();
            }

            $handlerclass = $definition['handler'];
            $handler = new $handlerclass();
            if (!$handler instanceof action_handler) {
                throw new \coding_exception('Invalid mod_quizgeist AJAX handler registration.');
            }

            $context = new action_context(
                $action,
                $cm,
                $quizgeist,
                $modulecontext,
                $USER,
                $payload,
            );
            self::respond([
                'ok' => true,
                'action' => $action,
                'data' => $handler->execute($context),
            ]);
        } catch (\require_login_exception | \require_login_session_timeout_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => 'session_expired',
                'message' => get_string('sessionerroruser', 'error'),
            ], 401);
        } catch (\required_capability_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => 'forbidden',
                'message' => get_string('nopermissions', 'error'),
            ], 403);
        } catch (\mod_quizgeist\local\licence\feature_locked_exception $exception) {
            $staff = $modulecontext instanceof \context_module
                && (
                    has_capability(
                        'mod/quizgeist:manage',
                        $modulecontext
                    )
                    || has_capability(
                        'mod/quizgeist:host',
                        $modulecontext
                    )
                    || has_capability(
                        'mod/quizgeist:viewreports',
                        $modulecontext
                    )
                );
            self::respond([
                'ok' => false,
                'error' => 'feature_locked',
                'message' => get_string(
                    $staff
                        ? 'licence:featurelocked'
                        : 'feature:unavailable',
                    'mod_quizgeist'
                ),
                'data' => [
                    'feature' => $exception->feature(),
                ],
            ], 403);
        } catch (\dml_missing_record_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => 'not_found',
                'message' => get_string('ajax:invalidrequest', 'mod_quizgeist'),
            ], 404);
        } catch (\mod_quizgeist\local\editor\edit_conflict_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => 'conflict',
                'message' => get_string('ajax:conflict', 'mod_quizgeist'),
                'data' => [
                    $exception->get_entity() => $exception->get_current(),
                ],
            ], 409);
        } catch (\mod_quizgeist\local\live\live_conflict_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => 'conflict',
                'message' => get_string('live:error:conflict', 'mod_quizgeist'),
                'data' => [
                    'state' => $exception->get_state(),
                ],
            ], 409);
        } catch (\mod_quizgeist\local\live\live_domain_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => $exception->get_error_code(),
                'message' => get_string(
                    $exception->get_string_key(),
                    'mod_quizgeist'
                ),
            ], $exception->get_http_status());
        } catch (\quizgeistaddon_ai\local\ai\document_import_exception $exception) {
            // The AI addon rejects a source document with a stable machine reason.
            // The teacher needs to learn why, so the reason is translated through
            // its family here instead of being flattened into "invalid request".
            // catch does not autoload, so this stays harmless without the addon.
            self::respond([
                'ok' => false,
                'error' => 'document_rejected',
                'message' => get_string(
                    $exception->user_string_key(),
                    'mod_quizgeist'
                ),
                'data' => [
                    'reason' => $exception->reason(),
                ],
            ], 400);
        } catch (\invalid_parameter_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => 'invalid_request',
                'message' => get_string('ajax:invalidrequest', 'mod_quizgeist'),
            ], 400);
        } catch (\moodle_exception $exception) {
            self::respond([
                'ok' => false,
                'error' => 'moodle_error',
                'message' => get_string('ajax:servererror', 'mod_quizgeist'),
            ], 400);
        } catch (\Throwable $exception) {
            debugging($exception->getMessage(), DEBUG_DEVELOPER);
            self::respond([
                'ok' => false,
                'error' => 'server_error',
                'message' => get_string('ajax:servererror', 'mod_quizgeist'),
            ], 500);
        }
    }

    /**
     * Decode the JSON request body.
     *
     * @return array
     */
    private function decode_payload(): array {
        $rawbody = file_get_contents('php://input');
        $payload = json_decode($rawbody === false ? '' : $rawbody, true);
        if (!is_array($payload)) {
            self::respond([
                'ok' => false,
                'error' => 'invalid_json',
                'message' => get_string('ajax:invalidjson', 'mod_quizgeist'),
            ], 400);
        }

        return $payload;
    }

    /**
     * Validate the common request fields.
     *
     * @param array $payload Decoded request payload.
     * @return array{0: string, 1: int, 2: string}
     */
    private function validate_envelope(array $payload): array {
        $rawaction = $payload['action'] ?? null;
        $rawcmid = $payload['cmid'] ?? null;
        $rawsesskey = $payload['sesskey'] ?? null;
        if (!is_string($rawaction)
                || (!is_int($rawcmid) && !is_string($rawcmid))
                || !is_string($rawsesskey)) {
            self::invalid_request();
        }

        $action = clean_param($rawaction, PARAM_ALPHANUMEXT);
        $cmid = clean_param($rawcmid, PARAM_INT);
        if ($action === ''
                || $action !== $rawaction
                || $cmid <= 0
                || (is_string($rawcmid) && !preg_match('/^[1-9][0-9]*$/D', $rawcmid))
                || $rawsesskey === '') {
            self::invalid_request();
        }

        return [$action, $cmid, $rawsesskey];
    }

    /**
     * Emit a common invalid-request response.
     *
     * @return never
     */
    private static function invalid_request(): never {
        self::respond([
            'ok' => false,
            'error' => 'invalid_request',
            'message' => get_string('ajax:invalidrequest', 'mod_quizgeist'),
        ], 400);
    }

    /**
     * Emit a JSON response and stop.
     *
     * @param array $data Response payload.
     * @param int $status HTTP status.
     * @return never
     */
    private static function respond(array $data, int $status = 200): never {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
