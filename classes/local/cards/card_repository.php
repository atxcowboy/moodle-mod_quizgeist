<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Persistence reads of the card mode (F11a).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads only. Every write lives in cardset_service or card_scan_service.
 *
 * Modelled on report_repository: plain SQL, no policy, no validation. The
 * split matters here more than usual, because the recognition boundary asks
 * this class "is this a code of that set" and must get an answer that no
 * business rule can have softened on the way.
 */
final class card_repository {

    /**
     * Load one card set of one activity.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $cardsetid Card set.
     * @return \stdClass|null
     */
    public static function cardset(int $quizgeistid, int $cardsetid): ?\stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $cardsetid <= 0) {
            return null;
        }
        $set = $DB->get_record('quizgeist_cardsets', [
            'id' => $cardsetid,
            'quizgeistid' => $quizgeistid,
        ]);
        return $set ?: null;
    }

    /**
     * List every card set of one activity, newest first.
     *
     * @param int $quizgeistid Owning activity.
     * @return \stdClass[]
     */
    public static function cardsets(int $quizgeistid): array {
        global $DB;

        if ($quizgeistid <= 0) {
            return [];
        }
        return array_values($DB->get_records_sql(
            "SELECT cs.id, cs.quizgeistid, cs.name, cs.layout, cs.seed,
                    cs.createdby, cs.timecreated, cs.timemodified,
                    (SELECT COUNT(1)
                       FROM {quizgeist_cards} c
                      WHERE c.cardsetid = cs.id) AS cardcount
               FROM {quizgeist_cardsets} cs
              WHERE cs.quizgeistid = :quizgeistid
           ORDER BY cs.timecreated DESC, cs.id DESC",
            ['quizgeistid' => $quizgeistid]
        ));
    }

    /**
     * List the cards of one set in print order.
     *
     * @param int $cardsetid Card set.
     * @return \stdClass[]
     */
    public static function cards(int $cardsetid): array {
        global $DB;

        if ($cardsetid <= 0) {
            return [];
        }
        return array_values($DB->get_records(
            'quizgeist_cards',
            ['cardsetid' => $cardsetid],
            'cardindex ASC, id ASC'
        ));
    }

    /**
     * Map every code of one set to its card row.
     *
     * The recognition boundary uses exactly this map: a code the model
     * produced is looked up here and discarded when it is absent. No fuzzy
     * matching, no nearest neighbour — an unknown code is unknown.
     *
     * @param int $cardsetid Card set.
     * @return array<string,\stdClass> code => card row
     */
    public static function code_map(int $cardsetid): array {
        $map = [];
        foreach (self::cards($cardsetid) as $card) {
            $map[(string)$card->cardcode] = $card;
        }
        return $map;
    }

    /**
     * Count the cards of one set.
     *
     * @param int $cardsetid Card set.
     * @return int
     */
    public static function card_count(int $cardsetid): int {
        global $DB;

        return $cardsetid <= 0
            ? 0
            : (int)$DB->count_records('quizgeist_cards', ['cardsetid' => $cardsetid]);
    }

    /**
     * Resolve the display names of the card holders of one set.
     *
     * @param int $cardsetid Card set.
     * @return array<int,string> userid => full name
     */
    public static function holder_names(int $cardsetid): array {
        global $DB;

        $userids = [];
        foreach (self::cards($cardsetid) as $card) {
            if ($card->userid !== null && (int)$card->userid > 0) {
                $userids[(int)$card->userid] = (int)$card->userid;
            }
        }
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($userids), SQL_PARAMS_NAMED, 'u');
        $users = $DB->get_records_select(
            'user',
            "id {$insql}",
            $params,
            'id ASC',
            'id, ' . implode(', ', \core_user\fields::get_name_fields())
        );
        $names = [];
        foreach ($users as $user) {
            $names[(int)$user->id] = fullname($user);
        }
        return $names;
    }

    /**
     * Load one scan of one activity.
     *
     * @param int $quizgeistid Owning activity.
     * @param int $scanid Scan.
     * @return \stdClass|null
     */
    public static function scan(int $quizgeistid, int $scanid): ?\stdClass {
        global $DB;

        if ($quizgeistid <= 0 || $scanid <= 0) {
            return null;
        }
        $scan = $DB->get_record('quizgeist_card_scans', [
            'id' => $scanid,
            'quizgeistid' => $quizgeistid,
        ]);
        return $scan ?: null;
    }

    /**
     * Map the live players of one session by user.
     *
     * @param int $sessionid Live session.
     * @return array<int,\stdClass> userid => player row
     */
    public static function session_players(int $sessionid): array {
        global $DB;

        if ($sessionid <= 0) {
            return [];
        }
        $players = $DB->get_records('quizgeist_players', ['sessionid' => $sessionid], 'id ASC');
        $map = [];
        foreach ($players as $player) {
            if ((int)$player->userid > 0) {
                $map[(int)$player->userid] = $player;
            }
        }
        return $map;
    }
}
