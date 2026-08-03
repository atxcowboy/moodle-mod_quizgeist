<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Geometry of the printable card sheet (F11a).
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://moodle.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\local\cards;

defined('MOODLE_INTERNAL') || die();

/**
 * Turns a card model into drawing instructions — pure arithmetic, no TCPDF.
 *
 * Modelled on points_formula: the calculation lives where it can be proved,
 * and `cards_pdf.php` stays a file that only executes instructions. Without
 * this split the sheet geometry would only ever be testable by looking at a
 * printout, and "the letters are rotated correctly" is exactly the kind of
 * promise nobody re-checks after the first time.
 *
 * The rotational principle: option *i* sits at angle `i * 360/n` measured
 * clockwise from the top edge and is drawn rotated by that same angle. Turning
 * the card until option *i* is at the top therefore also makes it the only
 * upright letter — which is what a learner sees and what the vision model
 * reads back.
 */
final class card_sheet {

    /** Card edge length in millimetres. */
    public const CARD_SIZE = 88.0;

    /** Left/right page margin in millimetres. */
    public const MARGIN_X = 12.0;

    /** Top page margin in millimetres. */
    public const MARGIN_Y = 14.0;

    /** Gap between two cards in millimetres. */
    public const GAP = 6.0;

    /** Cards per row and per column of an A4 portrait page. */
    public const COLUMNS = 2;
    public const ROWS = 3;

    /** Distance of the letter ring from the card edge, in millimetres. */
    public const LETTER_INSET = 9.0;

    /** Cards on one page. */
    public static function per_page(): int {
        return self::COLUMNS * self::ROWS;
    }

    /**
     * Top-left corner of one card slot on the page.
     *
     * @param int $slot Zero-based slot inside the page.
     * @return array{0:float,1:float} x and y in millimetres.
     */
    public static function slot_origin(int $slot): array {
        $slot = max(0, min(self::per_page() - 1, $slot));
        $column = $slot % self::COLUMNS;
        $row = intdiv($slot, self::COLUMNS);
        return [
            self::MARGIN_X + ($column * (self::CARD_SIZE + self::GAP)),
            self::MARGIN_Y + ($row * (self::CARD_SIZE + self::GAP)),
        ];
    }

    /**
     * Drawing instructions of one card.
     *
     * @param float $x Left edge in millimetres.
     * @param float $y Top edge in millimetres.
     * @param array{code:string,label:string,reserve:bool} $card Card model.
     * @param string[] $letters Answer letters in print order.
     * @param float $size Card edge length in millimetres.
     * @return array<int,array<string,mixed>> Ordered operations.
     */
    public static function card_operations(
        float $x,
        float $y,
        array $card,
        array $letters,
        float $size = self::CARD_SIZE
    ): array {
        $letters = array_values($letters);
        $count = max(1, count($letters));
        $centrex = $x + ($size / 2);
        $centrey = $y + ($size / 2);
        $radius = ($size / 2) - self::LETTER_INSET;

        $operations = [['op' => 'rect', 'x' => $x, 'y' => $y, 'w' => $size, 'h' => $size]];

        foreach ($letters as $index => $letter) {
            $angle = 360.0 * $index / $count;
            $radians = deg2rad($angle);
            $operations[] = [
                'op' => 'text',
                'text' => (string)$letter,
                'font' => 'freesansb',
                'size' => 30.0,
                // The anchor is the centre of the letter cell; the renderer
                // offsets by half the cell so the letter is centred on it.
                'x' => round($centrex + ($radius * sin($radians)), 4),
                'y' => round($centrey - ($radius * cos($radians)), 4),
                'w' => 18.0,
                'h' => 12.0,
                'angle' => round($angle, 4),
            ];
        }

        // The code is printed twice, upright and upside down, so that at least
        // one readable code faces the camera in every orientation of the card.
        // Bold monospace, because this is the one string the model has to read
        // reliably from a phone photograph across a classroom.
        foreach ([0.0, 180.0] as $offset) {
            $radians = deg2rad($offset);
            $operations[] = [
                'op' => 'text',
                'text' => (string)$card['code'],
                'font' => 'freemonob',
                'size' => 15.0,
                'x' => round($centrex + (7.0 * sin($radians)), 4),
                'y' => round($centrey - (7.0 * cos($radians)), 4),
                'w' => $size - 26.0,
                'h' => 9.0,
                'angle' => $offset,
            ];
        }

        // The name is for the learner and is printed once, small, between the
        // codes and the lower letter — never at the card edge, where it would
        // collide with a rotated letter. It is deliberately NOT part of the
        // recognition: a scan identifies a card by its code, never by reading
        // a name off a photograph of a classroom.
        $operations[] = [
            'op' => 'text',
            'text' => (string)$card['label'],
            'font' => 'freesans',
            'size' => 8.0,
            'x' => $centrex,
            'y' => round($centrey + 20.0, 4),
            'w' => $size - 26.0,
            'h' => 5.0,
            'angle' => 0.0,
        ];

        return $operations;
    }

    /**
     * The angle at which one option has to be at the top of the card.
     *
     * The inverse of the drawing rule, kept beside it so the two can never
     * drift apart: a test that reads this must fail when the drawing changes.
     *
     * @param int $index Zero-based option index.
     * @param int $count Number of options.
     * @return float Degrees clockwise.
     */
    public static function option_angle(int $index, int $count): float {
        $count = max(1, $count);
        return round(360.0 * (($index % $count + $count) % $count) / $count, 4);
    }
}
