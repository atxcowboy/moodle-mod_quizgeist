<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Atomic local storage for the accepted Quizgeist licence file.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\licence;

defined('MOODLE_INTERNAL') || die();

/**
 * Stores a validated file behind an atomic active-item pointer.
 */
final class storage {

    /** Private system-context file area. */
    public const FILEAREA = 'licence';

    /** Fixed file name; the item id supplies immutable generations. */
    private const FILENAME = 'quizgeist.licence.json';

    /** Component config holding the active immutable file generation. */
    private const CONFIG_ACTIVE_ITEM = 'licence_active_itemid';

    /** Component config holding accepted anti-rollback observations. */
    private const CONFIG_LEDGER = 'licence_revision_ledger';

    /** One lock namespace/key serialises licence installations. */
    private const LOCK_TYPE = 'mod_quizgeist_licence';
    private const LOCK_KEY = 'install';

    /**
     * Read the active file, or null when none was installed.
     */
    public function read(): ?string {
        try {
            $itemid = (int)self::config_value(
                self::CONFIG_ACTIVE_ITEM
            );
            if ($itemid <= 0) {
                return null;
            }
            $file = get_file_storage()->get_file(
                \context_system::instance()->id,
                'mod_quizgeist',
                self::FILEAREA,
                $itemid,
                '/',
                self::FILENAME
            );
            if (!$file || $file->is_directory()) {
                throw new licence_exception('licence_storage_unreadable');
            }
            $content = $file->get_content();
            if (!is_string($content)) {
                throw new licence_exception('licence_storage_unreadable');
            }
            return $content;
        } catch (licence_exception $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new licence_exception(
                'licence_storage_unreadable',
                '',
                $exception
            );
        }
    }

    /**
     * Atomically activate a file that has already passed full verification.
     *
     * A lower revision is accepted only through the separately confirmed
     * recovery path. Even recovery does not permit a same-revision file with
     * different signed bytes.
     *
     * @param string $rawfile Original envelope bytes.
     * @param verification_result $result Verified, instance-bound result.
     * @param bool $confirmedrecovery Explicit administrator confirmation.
     */
    public function install(
        string $rawfile,
        verification_result $result,
        bool $confirmedrecovery = false
    ): void {
        $factory = \core\lock\lock_config::get_lock_factory(
            self::LOCK_TYPE
        );
        $lock = $factory->get_lock(self::LOCK_KEY, 30);
        if (!$lock) {
            throw new licence_exception('storage_busy');
        }
        try {
            $this->install_locked(
                $rawfile,
                $result,
                $confirmedrecovery
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Perform one serialised read/check/publish cycle.
     *
     * The caller holds the global licence-install lock. Keeping the ledger
     * read and pointer publication in the same critical section prevents two
     * uploads derived from the same old high-water mark from lowering it.
     *
     * @param string $rawfile Original envelope bytes.
     * @param verification_result $result Verified, instance-bound result.
     * @param bool $confirmedrecovery Explicit administrator confirmation.
     */
    private function install_locked(
        string $rawfile,
        verification_result $result,
        bool $confirmedrecovery
    ): void {
        global $DB;

        $payload = $result->payload();
        $licenseid = (string)($payload['license_id'] ?? '');
        $activationid = (string)($payload['instance']['activation_id'] ?? '');
        $revision = (int)($payload['revision'] ?? 0);
        if ($licenseid === '' || $activationid === '' || $revision < 1) {
            throw new \coding_exception(
                'A non-validated licence result reached the storage boundary.'
            );
        }

        $ledger = $this->ledger();
        $ledgerkey = hash('sha256', $licenseid . "\0" . $activationid);
        $payloadhash = hash('sha256', $result->payload_bytes());
        $signaturehash = hash('sha256', $result->signature_bytes());
        $observedrevisions = [];
        $highestrevision = 0;
        if (isset($ledger[$ledgerkey])) {
            $previous = $ledger[$ledgerkey];
            $highestrevision = (int)($previous['highest_revision']
                ?? $previous['revision']
                ?? 0);
            $observedrevisions = is_array(
                $previous['observed_revisions'] ?? null
            ) ? $previous['observed_revisions'] : [];
            // Migrate the initial P10 ledger shape without weakening its
            // already observed same-revision consistency guarantee.
            if (!$observedrevisions && $highestrevision > 0) {
                $observedrevisions[(string)$highestrevision] = [
                    'payload_hash' => (string)($previous['payload_hash'] ?? ''),
                    'signature_hash' =>
                        (string)($previous['signature_hash'] ?? ''),
                ];
            }
            if ($revision < $highestrevision && !$confirmedrecovery) {
                throw new licence_exception('revision_rollback');
            }
            $previousbytes = $observedrevisions[(string)$revision] ?? null;
            if (is_array($previousbytes)
                    && (!hash_equals(
                        (string)($previousbytes['payload_hash'] ?? ''),
                        $payloadhash
                    ) || !hash_equals(
                        (string)($previousbytes['signature_hash'] ?? ''),
                        $signaturehash
                    ))) {
                throw new licence_exception('revision_inconsistent');
            }
        }

        $olditemid = (int)self::config_value(
            self::CONFIG_ACTIVE_ITEM
        );
        $contextid = \context_system::instance()->id;
        $filestorage = get_file_storage();
        do {
            $newitemid = random_int(1, 2147483647);
        } while ($newitemid === $olditemid
            || $filestorage->file_exists(
                $contextid,
                'mod_quizgeist',
                self::FILEAREA,
                $newitemid,
                '/',
                self::FILENAME
            ));

        $filerecord = [
            'contextid' => $contextid,
            'component' => 'mod_quizgeist',
            'filearea' => self::FILEAREA,
            'itemid' => $newitemid,
            'filepath' => '/',
            'filename' => self::FILENAME,
        ];
        $newledger = $ledger;
        $observedrevisions[(string)$revision] = [
            'payload_hash' => $payloadhash,
            'signature_hash' => $signaturehash,
        ];
        $newledger[$ledgerkey] = [
            'license_id' => $licenseid,
            'activation_id' => $activationid,
            // Recovery changes the active file, never the highest revision
            // remembered for anti-rollback decisions.
            'highest_revision' => max($highestrevision, $revision),
            'observed_revisions' => $observedrevisions,
            'active_revision' => $revision,
            'accepted_at' => time(),
            'recovery' => $revision < $highestrevision,
        ];

        $transaction = $DB->start_delegated_transaction();
        try {
            $filestorage->create_file_from_string(
                $filerecord,
                $rawfile
            );
            set_config(
                self::CONFIG_LEDGER,
                json_encode(
                    $newledger,
                    JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                ),
                'mod_quizgeist'
            );
            set_config(
                self::CONFIG_ACTIVE_ITEM,
                $newitemid,
                'mod_quizgeist'
            );
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        // set_config() invalidates this cache while the transaction is still
        // open. Another request could repopulate the old committed values in
        // that narrow window, so invalidate once more after commit.
        \cache_helper::invalidate_by_definition(
            'core',
            'config',
            [],
            'mod_quizgeist'
        );

        // Keep prior immutable generations. A concurrent reader may already
        // hold the old pointer when this transaction commits; deleting that
        // generation here would turn an atomic old-or-new read into a transient
        // storage error. Licence files are bounded to 128 KiB and only site
        // administrators can add them, so safe reader semantics take priority
        // over eager reclamation.
        service::purge_cache();
    }

    /**
     * Decode the bounded local revision ledger.
     *
     * @return array<string,array>
     */
    private function ledger(): array {
        $raw = self::config_value(self::CONFIG_LEDGER);
        if (!is_string($raw) || $raw === '') {
            if ((int)self::config_value(
                self::CONFIG_ACTIVE_ITEM
            ) > 0) {
                throw new licence_exception('revision_ledger_invalid');
            }
            return [];
        }
        if (strlen($raw) > 1048576) {
            throw new licence_exception('revision_ledger_invalid');
        }
        try {
            $ledger = json_decode(
                $raw,
                true,
                32,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            throw new licence_exception(
                'revision_ledger_invalid',
                '',
                $exception
            );
        }
        if (!is_array($ledger) || array_is_list($ledger)) {
            throw new licence_exception('revision_ledger_invalid');
        }
        if ($ledger === [] && (int)self::config_value(
            self::CONFIG_ACTIVE_ITEM
        ) > 0) {
            throw new licence_exception('revision_ledger_invalid');
        }
        foreach ($ledger as $key => $entry) {
            $licenseid = is_array($entry)
                ? ($entry['license_id'] ?? null)
                : null;
            $activationid = is_array($entry)
                ? ($entry['activation_id'] ?? null)
                : null;
            if (!is_string($key)
                    || preg_match('/^[0-9a-f]{64}$/D', $key) !== 1
                    || !is_array($entry)
                    || array_is_list($entry)
                    || !is_string($licenseid)
                    || preg_match(
                        '/^[A-Za-z0-9_-]{8,64}$/D',
                        $licenseid
                    ) !== 1
                    || !is_string($activationid)
                    || preg_match(
                        '/^[A-Za-z0-9_-]{8,64}$/D',
                        $activationid
                    ) !== 1
                    || !hash_equals(
                        $key,
                        hash(
                            'sha256',
                            $licenseid . "\0" . $activationid
                        )
                    )
                    || !is_int($entry['accepted_at'] ?? null)
                    || ($entry['accepted_at'] ?? -1) < 0
                    || !is_bool($entry['recovery'] ?? null)) {
                throw new licence_exception('revision_ledger_invalid');
            }
            $highest = $entry['highest_revision']
                ?? $entry['revision']
                ?? null;
            if (!is_int($highest) || $highest < 1) {
                throw new licence_exception('revision_ledger_invalid');
            }
            $observed = $entry['observed_revisions'] ?? null;
            if ($observed === null) {
                if (!self::valid_hash($entry['payload_hash'] ?? null)
                        || !self::valid_hash(
                            $entry['signature_hash'] ?? null
                        )) {
                    throw new licence_exception(
                        'revision_ledger_invalid'
                    );
                }
                continue;
            }
            if (!is_array($observed)
                    || array_is_list($observed)
                    || $observed === []) {
                throw new licence_exception('revision_ledger_invalid');
            }
            foreach ($observed as $revision => $hashes) {
                $revision = (string)$revision;
                if (preg_match('/^[1-9][0-9]*$/D', $revision) !== 1
                        || (int)$revision > $highest
                        || !is_array($hashes)
                        || !self::valid_hash(
                            $hashes['payload_hash'] ?? null
                        )
                        || !self::valid_hash(
                            $hashes['signature_hash'] ?? null
                        )) {
                    throw new licence_exception(
                        'revision_ledger_invalid'
                    );
                }
            }
            if (!array_key_exists((string)$highest, $observed)) {
                throw new licence_exception('revision_ledger_invalid');
            }
            $active = $entry['active_revision'] ?? null;
            if (!is_int($active)
                    || $active < 1
                    || $active > $highest
                    || !array_key_exists((string)$active, $observed)) {
                throw new licence_exception('revision_ledger_invalid');
            }
        }
        return $ledger;
    }

    /**
     * Validate one stored SHA-256 observation.
     */
    private static function valid_hash(mixed $value): bool {
        return is_string($value)
            && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    /**
     * Read security state directly from the committed database row.
     *
     * Moodle's component-config cache is deliberately bypassed here. A
     * concurrent request can refill that cache from the pre-commit database
     * view after set_config() invalidated it, which would otherwise keep an
     * obsolete licence pointer or anti-rollback ledger alive.
     *
     * @return string|false Stored value, or false when absent.
     */
    private static function config_value(string $name): string|false {
        global $DB;

        $value = $DB->get_field('config_plugins', 'value', [
            'plugin' => 'mod_quizgeist',
            'name' => $name,
        ]);
        return is_string($value) ? $value : false;
    }
}
