<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Activity settings form for mod_quizgeist.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Quizgeist activity form.
 */
class mod_quizgeist_mod_form extends moodleform_mod {
    /**
     * Define activity settings.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('quizgeistname', 'mod_quizgeist'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'quizgeistname', 'mod_quizgeist');

        $this->standard_intro_elements();

        $mform->addElement('header', 'appearanceheader', get_string('appearance', 'mod_quizgeist'));
        $themes = [
            'hell' => get_string('theme:hell', 'mod_quizgeist'),
            'dunkel' => get_string('theme:dunkel', 'mod_quizgeist'),
            'weltraum' => get_string('theme:weltraum', 'mod_quizgeist'),
            'ozean' => get_string('theme:ozean', 'mod_quizgeist'),
            'retro-arcade' => get_string('theme:retroarcade', 'mod_quizgeist'),
        ];
        $currenttheme = (string)($this->current->theme ?? '');
        if (\mod_quizgeist\local\licence\feature_gate::can_create('modes')
                || $currenttheme === 'jahreszeiten') {
            $themes['jahreszeiten'] = get_string(
                'theme:jahreszeiten',
                'mod_quizgeist'
            );
        }
        $mform->addElement('select', 'theme', get_string('theme', 'mod_quizgeist'), $themes);
        $mform->setDefault('theme', 'hell');

        $seasons = [
            'herbst' => get_string('season:herbst', 'mod_quizgeist'),
            'winter' => get_string('season:winter', 'mod_quizgeist'),
            'fruehling' => get_string('season:fruehling', 'mod_quizgeist'),
            'sommer' => get_string('season:sommer', 'mod_quizgeist'),
        ];
        $mform->addElement('select', 'season', get_string('season', 'mod_quizgeist'), $seasons);
        $mform->setDefault('season', 'herbst');
        $mform->hideIf('season', 'theme', 'neq', 'jahreszeiten');

        $mform->addElement(
            'advcheckbox',
            'allowbacktrack',
            get_string('allowbacktrack', 'mod_quizgeist'),
            '',
            null,
            [0, 1]
        );
        $mform->setDefault('allowbacktrack', 1);

        $modes = [];
        foreach (\mod_quizgeist\local\live\session_settings::creatable_modes()
                as $mode) {
            $modes[$mode] = get_string(
                'mode:' . $mode,
                'mod_quizgeist'
            );
        }
        $currentmode = (string)($this->current->defaultmode ?? '');
        if ($currentmode !== ''
                && in_array(
                    $currentmode,
                    \mod_quizgeist\local\live\session_settings::known_modes(),
                    true
                )
                && !isset($modes[$currentmode])) {
            $modes[$currentmode] = get_string(
                'mode:' . $currentmode,
                'mod_quizgeist'
            );
        }
        $mform->addElement('select', 'defaultmode', get_string('defaultmode', 'mod_quizgeist'), $modes);
        $mform->setDefault('defaultmode', 'classic');

        // F1 Stressarm-Standard. Bewusst ohne jedes Feature-Tor: das ist das
        // stärkste Argument des Basispakets und darf an keinem Addon hängen.
        $mform->addElement(
            'header',
            'stressfreeheader',
            get_string('stressfree', 'mod_quizgeist')
        );
        $mform->addElement(
            'static',
            'stressfreeintro',
            '',
            get_string('stressfree:description', 'mod_quizgeist')
        );
        $paces = [];
        foreach (\mod_quizgeist\local\live\scoring_context::PACES as $pace) {
            $paces[$pace] = get_string('pacemode:' . $pace, 'mod_quizgeist');
        }
        $mform->addElement(
            'select',
            'pacemode',
            get_string('pacemode', 'mod_quizgeist'),
            $paces
        );
        $mform->addHelpButton('pacemode', 'pacemode', 'mod_quizgeist');
        $mform->setDefault(
            'pacemode',
            \mod_quizgeist\local\live\scoring_context::DEFAULT_PACE
        );

        $leaderboards = [];
        foreach (
            \mod_quizgeist\local\live\leaderboard_policy::VISIBILITIES
                as $visibility
        ) {
            $leaderboards[$visibility] = get_string(
                'leaderboard:' . $visibility,
                'mod_quizgeist'
            );
        }
        $mform->addElement(
            'select',
            'leaderboard',
            get_string('leaderboard', 'mod_quizgeist'),
            $leaderboards
        );
        $mform->addHelpButton('leaderboard', 'leaderboard', 'mod_quizgeist');
        $mform->setDefault(
            'leaderboard',
            \mod_quizgeist\local\live\leaderboard_policy::DEFAULT_VISIBILITY
        );

        foreach ([
            'timervisible' => 1,
            'soundenabled' => 1,
            'friendlynew' => 1,
            'reasonstep' => 0,
        ] as $switch => $default) {
            $mform->addElement(
                'advcheckbox',
                $switch,
                get_string($switch, 'mod_quizgeist'),
                '',
                null,
                [0, 1]
            );
            $mform->addHelpButton($switch, $switch, 'mod_quizgeist');
            $mform->setDefault($switch, $default);
        }

        // F8 Erklär-Geist. Die Anzeige gehört der Basis; nur das Erzeugen
        // eines Lösungswegs braucht das KI-Abo.
        $policies = [];
        foreach (
            \mod_quizgeist\local\live\explanation_policy::POLICIES as $policy
        ) {
            $policies[$policy] = get_string(
                'explanationpolicy:' . $policy,
                'mod_quizgeist'
            );
        }
        $mform->addElement(
            'select',
            'explanationpolicy',
            get_string('explanationpolicy', 'mod_quizgeist'),
            $policies
        );
        $mform->addHelpButton('explanationpolicy', 'explanationpolicy', 'mod_quizgeist');
        $mform->setDefault(
            'explanationpolicy',
            \mod_quizgeist\local\live\explanation_policy::DEFAULT_POLICY
        );

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 0);

        $methods = [
            'best' => get_string('grademethod:best', 'mod_quizgeist'),
            'last' => get_string('grademethod:last', 'mod_quizgeist'),
            'average' => get_string('grademethod:average', 'mod_quizgeist'),
        ];
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'mod_quizgeist'), $methods);
        $mform->addHelpButton('grademethod', 'grademethod', 'mod_quizgeist');
        $mform->setDefault('grademethod', 'best');
        $mform->hideIf('grademethod', 'grade[modgrade_type]', 'eq', 'none');

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Add module-specific completion rules.
     *
     * @return string[] Element names.
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $participate = 'completionparticipate' . $suffix;
        $percent = 'completionpercent' . $suffix;

        $mform->addElement(
            'checkbox',
            $participate,
            '',
            get_string('completionparticipate', 'mod_quizgeist')
        );
        $mform->addHelpButton($participate, 'completionparticipate', 'mod_quizgeist');

        $percentages = [0 => get_string('completionpercentdisabled', 'mod_quizgeist')];
        foreach (range(10, 100, 10) as $value) {
            $percentages[$value] = $value . ' %';
        }
        $mform->addElement(
            'select',
            $percent,
            get_string('completionpercent', 'mod_quizgeist'),
            $percentages
        );

        return [$participate, $percent];
    }

    /**
     * Whether at least one module-specific completion rule is enabled.
     *
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        $suffix = $this->get_suffix();
        return !empty($data['completionparticipate' . $suffix])
            || !empty($data['completionpercent' . $suffix]);
    }

}
