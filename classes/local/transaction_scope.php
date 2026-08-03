<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ownership-aware delegated database transaction scope.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Starts a delegated transaction only when the caller does not already own one.
 *
 * Moodle's delegated transactions do not provide savepoints: rolling back any
 * nested level makes every outer level uncommittable. Plugin service boundaries
 * therefore participate in an ambient transaction instead of nesting another
 * delegated level. Only the scope that opened the transaction may finish it.
 */
final class transaction_scope {

    /** @var \moodle_transaction|null Transaction owned by this scope. */
    private ?\moodle_transaction $transaction;

    /**
     * Build a scope for the current transaction context.
     *
     * @param \moodle_transaction|null $transaction Owned transaction, if any.
     */
    private function __construct(?\moodle_transaction $transaction) {
        $this->transaction = $transaction;
    }

    /**
     * Join an ambient transaction or start the outermost delegated transaction.
     */
    public static function begin(): self {
        global $DB;

        return new self(
            $DB->is_transaction_started()
                ? null
                : $DB->start_delegated_transaction()
        );
    }

    /**
     * Commit only a transaction opened by this scope.
     */
    public function allow_commit(): void {
        if ($this->transaction === null) {
            return;
        }

        // Clear ownership before Moodle commits. commit_delegated_transaction()
        // disposes its object before it can throw, so a surrounding catch must
        // never try to roll that same object back a second time.
        $transaction = $this->transaction;
        $this->transaction = null;
        $transaction->allow_commit();
    }

    /**
     * Roll back an owned, live transaction and always rethrow the real cause.
     *
     * @param \Throwable $exception Original failure.
     * @return never
     */
    public function rollback(\Throwable $exception): never {
        $transaction = $this->transaction;
        $this->transaction = null;
        if ($transaction === null || $transaction->is_disposed()) {
            throw $exception;
        }

        // Moodle rollback() rethrows the supplied exception. The final throw
        // keeps that contract explicit if a database driver ever returns.
        $transaction->rollback($exception);
        throw $exception;
    }
}
