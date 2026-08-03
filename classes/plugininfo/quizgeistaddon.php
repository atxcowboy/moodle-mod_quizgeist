<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Plugin information for Quizgeist addons.
 *
 * @package    mod_quizgeist
 * @copyright  2026 Montessori Fachoberschule München
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgeist\plugininfo;

defined('MOODLE_INTERNAL') || die();

/**
 * Makes the custom quizgeistaddon type visible to Moodle's plugin manager.
 */
final class quizgeistaddon extends \core\plugininfo\base {

    /**
     * Addons contain no owning content tables, so Moodle may remove their code
     * without deleting activity data. The basis plugin remains responsible for
     * backup, restore and privacy handling of all shared records.
     *
     * @return bool
     */
    public function is_uninstall_allowed(): bool {
        return true;
    }

    /**
     * Load an addon's optional settings page below the activity settings.
     *
     * @param \part_of_admin_tree $adminroot Administration tree.
     * @param string $parentnodename Parent node.
     * @param bool $hassiteconfig Whether the viewer can configure the site.
     */
    public function load_settings(
        \part_of_admin_tree $adminroot,
        $parentnodename,
        $hassiteconfig
    ): void {
        if (!$this->is_installed_and_upgraded()
                || !$hassiteconfig
                || !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $ADMIN = $adminroot;
        $plugininfo = $this;
        $settings = new \admin_settingpage(
            $this->get_settings_section_name(),
            $this->displayname,
            'moodle/site:config',
            false
        );
        include($this->full_path('settings.php'));
        $ADMIN->add($parentnodename, $settings);
    }

    /**
     * Return the stable settings section name.
     *
     * @return string|null
     */
    public function get_settings_section_name(): ?string {
        return file_exists($this->full_path('settings.php'))
            ? 'quizgeistaddon_' . $this->name
            : null;
    }
}
