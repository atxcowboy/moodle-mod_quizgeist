<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Creation and maintenance of printable card sets (F11a).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

use mod_quizgeist\local\transaction_scope;

defined('MOODLE_INTERNAL') || die();

/**
 * Validates, then writes — the template_service split.
 *
 * A card set is COURSE CONTENT. The printed sheets are in a school bag; a
 * course reset must not silently invalidate them. Only the column that says
 * WHO holds a card is participant data, and only that column is released.
 */
final class cardset_service {

    /** Reserve cards a set gets on top of its named holders. */
    public const DEFAULT_RESERVES = 4;

    /**
     * Create one card set with one card per user plus reserves.
     *
     * @param \stdClass $quizgeist Activity.
     * @param int $createdby Acting teacher.
     * @param string $name Set name.
     * @param string $layout One of card_limits::LAYOUTS.
     * @param int[] $userids Card holders, in print order.
     * @param int $reserves Additional cards without a holder.
     * @return \stdClass The created set, with its cards attached as `cards`.
     */
    public static function create(
        \stdClass $quizgeist,
        int $createdby,
        string $name,
        string $layout,
        array $userids,
        int $reserves = self::DEFAULT_RESERVES
    ): \stdClass {
        global $DB;

        $name = trim(clean_param($name, PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new card_exception('cardset_name_invalid');
        }
        if (!card_limits::is_layout($layout)) {
            throw new card_exception('cardset_layout_invalid');
        }

        // De-duplicate while preserving the order the teacher chose: two cards
        // for one learner would make every scan of that learner ambiguous.
        $holders = [];
        foreach ($userids as $userid) {
            $userid = (int)$userid;
            if ($userid > 0) {
                $holders[$userid] = $userid;
            }
        }
        $holders = array_values($holders);
        $reserves = max(0, min(50, $reserves));
        $total = count($holders) + $reserves;
        if ($total <= 0) {
            throw new card_exception('cardset_empty');
        }
        if ($total > card_limits::MAX_CARDS) {
            throw new card_exception('cardset_too_many');
        }

        $now = time();
        $set = (object)[
            'quizgeistid' => (int)$quizgeist->id,
            'name' => $name,
            'layout' => $layout,
            'seed' => card_code::seed(),
            'createdby' => $createdby > 0 ? $createdby : null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $transaction = transaction_scope::begin();
        try {
            $set->id = (int)$DB->insert_record('quizgeist_cardsets', $set);
            $rows = [];
            for ($index = 0; $index < $total; $index++) {
                $rows[] = (object)[
                    'cardsetid' => $set->id,
                    'cardindex' => $index,
                    'userid' => $holders[$index] ?? null,
                    'cardcode' => card_code::code($set->id, $index, $set->seed),
                    'timecreated' => $now,
                ];
            }
            $DB->insert_records('quizgeist_cards', $rows);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }

        $set->cards = card_repository::cards($set->id);
        return $set;
    }

    /**
     * Delete one card set and its cards.
     *
     * A set that has already been scanned keeps its scans' bookkeeping rows,
     * but those rows lose their lookup table. That is deliberate: deleting a
     * set is a teacher's decision about COURSE CONTENT, and it must not
     * retroactively delete answers that were booked from it.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $cardsetid Card set.
     * @return void
     */
    public static function delete(int $quizgeistid, int $cardsetid): void {
        global $DB;

        $set = card_repository::cardset($quizgeistid, $cardsetid);
        if ($set === null) {
            throw new card_exception('cardset_not_found');
        }
        $transaction = transaction_scope::begin();
        try {
            $DB->delete_records('quizgeist_cards', ['cardsetid' => (int)$set->id]);
            $DB->delete_records('quizgeist_cardsets', ['id' => (int)$set->id]);
            $transaction->allow_commit();
        } catch (\Throwable $exception) {
            $transaction->rollback($exception);
        }
    }

    /**
     * Build the print model of one set.
     *
     * Pure assembly, so cards_pdf.php stays a rendering file and this stays
     * testable: names are resolved once, reserve cards get a numbered label,
     * and the answer letters come from the layout, never from a request.
     *
     * @param \stdClass $set Card set row.
     * @return array{
     *     name:string,layout:string,letters:string[],
     *     cards:array<int,array{code:string,label:string,reserve:bool}>
     * }
     */
    public static function print_model(\stdClass $set): array {
        $cards = card_repository::cards((int)$set->id);
        $names = card_repository::holder_names((int)$set->id);
        $letters = card_limits::letters((string)$set->layout);

        $model = [];
        $reserve = 0;
        foreach ($cards as $card) {
            $userid = $card->userid === null ? 0 : (int)$card->userid;
            if ($userid > 0) {
                $label = $names[$userid] ?? get_string('cards:print:unknownholder', 'mod_quizgeist');
                $isreserve = false;
            } else {
                $reserve++;
                $label = get_string('cards:print:reserve', 'mod_quizgeist', $reserve);
                $isreserve = true;
            }
            $model[] = [
                'code' => (string)$card->cardcode,
                'label' => $label,
                'reserve' => $isreserve,
            ];
        }
        return [
            'name' => (string)$set->name,
            'layout' => (string)$set->layout,
            'letters' => $letters,
            'cards' => $model,
        ];
    }

    /**
     * Project one card set for the client.
     *
     * Codes are teacher-facing on purpose (they are printed), but the SEED is
     * never projected: with the seed every future code of the set could be
     * computed, and a card code is the identity of a learner in a scan.
     *
     * @param \stdClass $set Card set row.
     * @return array<string,mixed>
     */
    public static function project(\stdClass $set): array {
        return [
            'id' => (int)$set->id,
            'name' => (string)$set->name,
            'layout' => (string)$set->layout,
            'cardCount' => isset($set->cardcount)
                ? (int)$set->cardcount
                : card_repository::card_count((int)$set->id),
            'timeCreated' => (int)$set->timecreated,
        ];
    }
}
