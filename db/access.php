<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Capability definitions for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'mod/quizgeist:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    'mod/quizgeist:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],

    'mod/quizgeist:manage' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quiz:manage',
    ],

    'mod/quizgeist:publishtemplate' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:manage',
    ],

    'mod/quizgeist:managealltemplates' => [
        'riskbitmask' => RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    'mod/quizgeist:host' => [
        'riskbitmask' => RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quiz:preview',
    ],

    'mod/quizgeist:viewreports' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quiz:viewreports',
    ],

    'mod/quizgeist:play' => [
        'riskbitmask' => RISK_SPAM,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quiz:attempt',
    ],

    // Tagging selbst gehoert zu :manage. Nur der Schueler-Vorschlagsweg aus
    // der Fragenwerkstatt braucht eine eigene, abschaltbare Erlaubnis
    // (P11_PLAN.md, Entscheidung E-4). Ein Vorschlag wird nie wirksam, bevor
    // eine Lehrkraft ihn freischaltet.
    'mod/quizgeist:suggesttag' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:play',
    ],

    // F7 Fragenwerkstatt. Ein Schuelertext ist Fremdinhalt in einer Lehrer-
    // und Beamer-Oberflaeche; deshalb tragen Einreichen und Bewerten
    // RISK_SPAM|RISK_XSS. Wirksam wird eine Einreichung erst durch die
    // Kuratierung, und spielbar erst durch :manage (P11_PLAN.md 4.0/4/F7).
    'mod/quizgeist:submitquestion' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:play',
    ],

    'mod/quizgeist:ratequestion' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:play',
    ],

    'mod/quizgeist:curatequestions' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:manage',
    ],

    // F3: das Faelligkeits-Dashboard zeigt, wer welchem Thema hinterherlaeuft.
    // Das sind personenbezogene Lernstaende, also dieselbe Vertrauensstufe wie
    // die Berichte — bewusst nicht an :manage gekoppelt, damit eine Schule
    // Beobachtung und Bearbeitung trennen kann.
    'mod/quizgeist:viewschedule' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:viewreports',
    ],

    // U3 Kurzclip-Kanal. Eine Stimmaufnahme ist ein personenbezogenes
    // Rohdatum eigener Qualitaet — deshalb RISK_PERSONAL und eine EIGENE
    // Capability neben :play. Eine Schule, die Sprachaufnahmen nicht
    // wuenscht, entzieht genau dieses Recht und behaelt alles andere
    // (P11_PLAN.md 3/U3 und 4.0).
    'mod/quizgeist:recordaudio' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:play',
    ],
    // F11a Karten-Modus. Eine Aufnahme der ganzen Klasse ist ein besonders
    // schuetzenswertes personenbezogenes Rohdatum — deshalb RISK_PERSONAL und
    // eine EIGENE Capability neben :host. Eine Schule, die keine Klassenfotos
    // wuenscht, entzieht genau dieses Recht und behaelt alles andere.
    'mod/quizgeist:scancards' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:host',
    ],
    // F13 Buehnen-Check. Die Kamera geht an, auch wenn kein Bild das Geraet
    // verlaesst — das allein rechtfertigt RISK_PERSONAL und eine EIGENE
    // Capability neben :play. Eine Schule, die keine Kameranutzung wuenscht,
    // entzieht genau dieses Recht; die Aufgabe selbst bleibt les- und
    // bearbeitbar.
    'mod/quizgeist:presentstage' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'mod/quizgeist:play',
    ],
];
