<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Seating card holders without a device in a lobby (F11a, rc.6).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

use mod_quizgeist\local\live\session_service;
use mod_quizgeist\local\live\state_projector;

defined('MOODLE_INTERNAL') || die();

/**
 * "Ein Gerät, eine Klasse": the roll call before a card round.
 *
 * The scan only books for players, and a player used to exist
 * only after a device join. This class lets the teacher seat the holders of
 * a printed set instead — from a name list in the lobby, where absent
 * learners are simply unticked. The holder list is always read from the
 * set on the server; the client only says which of THOSE are present.
 */
final class card_seat_service {

    /** Roster state: no player row for this holder. */
    public const STATE_NONE = 'none';

    /** Roster state: seated by the teacher, plays by card. */
    public const STATE_CARD = 'card';

    /** Roster state: joined with their own device. */
    public const STATE_DEVICE = 'device';

    /**
     * Build the lobby name list of one card set for one session.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param int $userid Acting host.
     * @param int $sessionid Live session.
     * @param int $cardsetid Card set.
     * @return array<string,mixed>
     */
    public static function roster(
        \stdClass $quizgeist,
        \context_module $context,
        int $userid,
        int $sessionid,
        int $cardsetid
    ): array {
        $session = session_service::hosted_session($quizgeist, $context, $userid, $sessionid);
        $set = card_repository::cardset((int)$quizgeist->id, $cardsetid);
        if ($set === null) {
            throw new card_exception('cardset_not_found');
        }
        $players = card_repository::session_players($sessionid);
        $names = card_repository::holder_names($cardsetid);
        $enrolled = self::playable_userids($context);

        $holders = [];
        $reserves = 0;
        foreach (card_repository::cards($cardsetid) as $card) {
            $holderid = $card->userid === null ? 0 : (int)$card->userid;
            if ($holderid <= 0) {
                $reserves++;
                continue;
            }
            $player = $players[$holderid] ?? null;
            $state = self::STATE_NONE;
            if ($player !== null) {
                $state = (string)$player->status === session_service::PLAYER_STATUS_CARD
                    ? self::STATE_CARD
                    : self::STATE_DEVICE;
            }
            $holders[] = [
                'userId' => $holderid,
                'name' => $names[$holderid] ?? get_string('cards:print:unknownholder', 'mod_quizgeist'),
                // The site shows first names only. The code printed on the
                // card tells two learners with the same name apart.
                'cardCode' => (string)$card->cardcode,
                'state' => $state,
                // A holder who left the course since printing is shown, but
                // cannot be seated — the sheet is older than the enrolment.
                'available' => isset($enrolled[$holderid]),
            ];
        }

        $blocked = 'none';
        if ((string)$session->status !== 'lobby') {
            $blocked = 'started';
        } else if (session_service::card_seating_blocked(state_projector::settings($session))) {
            $blocked = 'freeteams';
        }

        return [
            'sessionId' => (int)$session->id,
            'cardSetId' => (int)$set->id,
            'blocked' => $blocked,
            'holders' => $holders,
            'reserveCount' => $reserves,
        ];
    }

    /**
     * Seat the ticked holders of one set and withdraw unticked card seats.
     *
     * @param \stdClass $quizgeist Activity.
     * @param \context_module $context Module context.
     * @param \stdClass $host Acting host.
     * @param int $sessionid Live session.
     * @param int $cardsetid Card set.
     * @param int[] $presentids Holders the teacher marked as present.
     * @return array{seated:int,removed:int,refused:array,roster:array}
     */
    public static function seat(
        \stdClass $quizgeist,
        \context_module $context,
        \stdClass $host,
        int $sessionid,
        int $cardsetid,
        array $presentids
    ): array {
        $set = card_repository::cardset((int)$quizgeist->id, $cardsetid);
        if ($set === null) {
            throw new card_exception('cardset_not_found');
        }
        $holderids = [];
        foreach (card_repository::cards($cardsetid) as $card) {
            if ($card->userid !== null && (int)$card->userid > 0) {
                $holderids[] = (int)$card->userid;
            }
        }
        $outcome = session_service::seat_card_players(
            $quizgeist,
            $context,
            $host,
            $sessionid,
            $holderids,
            array_map('intval', $presentids)
        );
        $outcome['roster'] = self::roster(
            $quizgeist,
            $context,
            (int)$host->id,
            $sessionid,
            $cardsetid
        );
        return $outcome;
    }

    /**
     * Users who may currently play this activity, by ID.
     *
     * @param \context_module $context Module context.
     * @return array<int,true>
     */
    private static function playable_userids(\context_module $context): array {
        $users = get_enrolled_users(
            $context,
            'mod/quizgeist:play',
            0,
            'u.id',
            null,
            0,
            0,
            true
        );
        $ids = [];
        foreach ($users as $user) {
            $ids[(int)$user->id] = true;
        }
        return $ids;
    }
}
