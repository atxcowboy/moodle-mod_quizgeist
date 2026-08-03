<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Fire-and-forget notifications for the live-session relay.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\live;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends state-version hints without making the relay part of the data path.
 */
final class relay {

    /** @var array<int,int> Highest pending state version per session. */
    private static array $pending = [];

    /** @var bool Whether the pending-flush callback is registered. */
    private static bool $flushregistered = false;

    /**
     * Notify the relay about a committed session state change.
     *
     * Every transport failure is deliberately invisible to the live session.
     *
     * @param int $sessionid Session ID.
     * @param int $stateversion New state version.
     */
    public static function notify(int $sessionid, int $stateversion): void {
        try {
            $configuration = self::configuration();
            if ($configuration === null || $sessionid <= 0 || $stateversion < 0) {
                return;
            }
            if (self::transaction_active()) {
                self::queue($sessionid, $stateversion);
                return;
            }
            self::send_now($sessionid, $stateversion, $configuration);
        } catch (\Throwable $exception) {
            self::log_failure();
        }
    }

    /**
     * Determine whether the current request participates in a DB transaction.
     *
     * @return bool Whether a transaction is active.
     */
    private static function transaction_active(): bool {
        try {
            global $DB;
            if (!isset($DB) || !is_object($DB)) {
                return false;
            }
            return (bool)$DB->is_transaction_started();
        } catch (\Throwable $exception) {
            // Do not signal when transaction state cannot be established.
            return true;
        }
    }

    /**
     * Coalesce a state bump until the surrounding transaction has settled.
     *
     * @param int $sessionid Session ID.
     * @param int $stateversion Requested state version.
     */
    private static function queue(int $sessionid, int $stateversion): void {
        if ($stateversion > (self::$pending[$sessionid] ?? -1)) {
            self::$pending[$sessionid] = $stateversion;
        }
        if (self::$flushregistered) {
            return;
        }
        \core\shutdown_manager::register_function(
            static function (): void {
                self::flush_pending();
            }
        );
        self::$flushregistered = true;
    }

    /**
     * Flush queued bumps after a committed request, never during rollback.
     */
    private static function flush_pending(): void {
        $pending = self::$pending;
        self::$pending = [];
        self::$flushregistered = false;
        if ($pending === [] || self::transaction_active()) {
            return;
        }

        try {
            global $DB;
            if (!isset($DB) || !is_object($DB)) {
                return;
            }
            foreach ($pending as $sessionid => $requestedversion) {
                try {
                    $persistedversion = $DB->get_field(
                        'quizgeist_sessions',
                        'stateversion',
                        ['id' => (int)$sessionid]
                    );
                    if ($persistedversion === false || $persistedversion === null) {
                        continue;
                    }
                    $persistedversion = (int)$persistedversion;
                    if ($persistedversion >= (int)$requestedversion) {
                        self::send_now((int)$sessionid, $persistedversion);
                    }
                } catch (\Throwable $exception) {
                    self::log_failure();
                }
            }
        } catch (\Throwable $exception) {
            self::log_failure();
        }
    }

    /**
     * Send one relay notification without consulting transaction state.
     *
     * @param int $sessionid Session ID.
     * @param int $stateversion State version.
     * @param array{url:string,notifyurl:string,secret:string}|null $configuration
     *     Optional validated configuration.
     */
    private static function send_now(
        int $sessionid,
        int $stateversion,
        ?array $configuration = null
    ): void {
        $handle = null;
        try {
            $configuration ??= self::configuration();
            if ($configuration === null || $sessionid <= 0 || $stateversion < 0) {
                return;
            }
            $channel = self::channel_from_configuration(
                $sessionid,
                $configuration
            );
            if ($channel === '') {
                return;
            }
            $body = json_encode(
                [
                    'kanal' => $channel,
                    'stateversion' => $stateversion,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $handle = curl_init();
            if ($handle === false) {
                self::log_failure();
                return;
            }
            $configured = curl_setopt_array($handle, [
                CURLOPT_URL => $configuration['notifyurl'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Relay-Secret: ' . $configuration['secret'],
                ],
                CURLOPT_CONNECTTIMEOUT_MS => 250,
                CURLOPT_TIMEOUT_MS => 250,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            if (!$configured) {
                self::log_failure();
                return;
            }
            $response = curl_exec($handle);
            $errno = curl_errno($handle);
            if ($response === false || $errno !== 0) {
                self::log_failure();
                return;
            }
            $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                self::log_failure();
            }
        } catch (\Throwable $exception) {
            self::log_failure();
        } finally {
            if ($handle !== null && $handle !== false) {
                try {
                    curl_close($handle);
                } catch (\Throwable $exception) {
                    // The relay is best effort, including cleanup.
                }
            }
        }
    }

    /**
     * Return the opaque channel key for a session, or an empty string.
     *
     * @param int $sessionid Session ID.
     * @return string Channel key.
     */
    public static function channel_for(int $sessionid): string {
        try {
            if ($sessionid <= 0) {
                return '';
            }
            $configuration = self::configuration();
            if ($configuration === null) {
                return '';
            }
            return self::channel_from_configuration($sessionid, $configuration);
        } catch (\Throwable $exception) {
            return '';
        }
    }

    /**
     * Return the browser relay connection DTO, or null when not configured.
     *
     * @param int $sessionid Session ID.
     * @return array{url:string,channel:string}|null Connection DTO.
     */
    public static function connection_for(int $sessionid): ?array {
        try {
            if ($sessionid <= 0) {
                return null;
            }
            $configuration = self::configuration();
            if ($configuration === null) {
                return null;
            }
            $channel = self::channel_from_configuration(
                $sessionid,
                $configuration
            );
            return $channel === ''
                ? null
                : [
                    'url' => $configuration['url'],
                    'channel' => $channel,
                ];
        } catch (\Throwable $exception) {
            return null;
        }
    }

    /**
     * Return the bootstrap relay DTO, or null when unavailable.
     *
     * @param int $sessionid Session ID.
     * @return array{url:string,channel:string}|null Bootstrap DTO.
     */
    public static function bootstrap_for(int $sessionid): ?array {
        return self::connection_for($sessionid);
    }

    /**
     * Read the complete relay configuration.
     *
     * @return array{url:string,notifyurl:string,secret:string}|null Configuration.
     */
    private static function configuration(): ?array {
        $config = get_config('mod_quizgeist');
        $transport = (string)($config->livetransport ?? '');
        $url = trim((string)($config->relayurl ?? ''));
        $notifyurl = trim((string)($config->relaynotifyurl ?? ''));
        $secret = (string)($config->relaysecret ?? '');
        if ($transport !== 'websocket' || $url === '' || $notifyurl === '' || $secret === '') {
            return null;
        }
        $relayparts = parse_url($url);
        $relayscheme = is_array($relayparts)
            ? strtolower((string)($relayparts['scheme'] ?? ''))
            : '';
        $notifyparts = parse_url($notifyurl);
        $notifyscheme = is_array($notifyparts)
            ? strtolower((string)($notifyparts['scheme'] ?? ''))
            : '';
        if (!in_array($relayscheme, ['ws', 'wss'], true)
                || empty($relayparts['host'] ?? null)
                || !in_array($notifyscheme, ['http', 'https'], true)
                || empty($notifyparts['host'] ?? null)) {
            return null;
        }
        return [
            'url' => $url,
            'notifyurl' => $notifyurl,
            'secret' => $secret,
        ];
    }

    /**
     * Derive the channel key from one already validated configuration.
     *
     * @param int $sessionid Session ID.
     * @param array{secret:string} $configuration Configuration.
     * @return string Channel key.
     */
    private static function channel_from_configuration(
        int $sessionid,
        array $configuration
    ): string {
        if ($sessionid <= 0 || $configuration['secret'] === '') {
            return '';
        }
        return substr(
            hash_hmac('sha256', (string)$sessionid, $configuration['secret']),
            0,
            32
        );
    }

    /**
     * Write only a generic developer diagnostic; never include relay data.
     */
    private static function log_failure(): void {
        try {
            global $CFG;
            if (!empty($CFG->debug)) {
                error_log('Quizgeist relay notification failed.');
            }
        } catch (\Throwable $exception) {
            // Diagnostics must not turn a best-effort signal into an error.
        }
    }
}
