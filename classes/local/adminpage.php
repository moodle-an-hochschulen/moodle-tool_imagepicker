<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Admin tool "Image Picker" - Admin page helpers.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\local;

use navigation_node;

/**
 * Helpers for the admin pages of the plugin (the demo page and the report page).
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adminpage {
    /** @var string The name of the settings page of the plugin in the admin tree. */
    const SETTINGS_PAGE = 'tool_imagepicker';

    /**
     * Put the settings page of the plugin into the breadcrumb of one of the admin pages of the plugin.
     *
     * The admin pages of the plugin are registered as hidden pages directly below the 'Admin tools' category, because an
     * admin settings page cannot have children in the admin tree. They are reached through buttons on the settings page of
     * the plugin, though, so that is where the breadcrumb should lead back to. The navigation node of the page is therefore
     * moved below the node of the settings page here, which makes the breadcrumb read 'Admin tools / Image picker / ...'.
     *
     * This has to be called after admin_externalpage_setup(), which is what builds the navigation.
     *
     * @param string $pagename The name of the admin page in the admin tree.
     */
    public static function add_settings_page_to_breadcrumb(string $pagename): void {
        global $PAGE;

        $pagenode = $PAGE->settingsnav->find($pagename, navigation_node::TYPE_SETTING);
        $settingsnode = $PAGE->settingsnav->find(self::SETTINGS_PAGE, navigation_node::TYPE_SETTING);
        if (!$pagenode || !$settingsnode) {
            // The navigation does not hold the two nodes as expected. That is nothing to fail over, the breadcrumb is just
            // less helpful then.
            return;
        }

        $pagenode->remove();
        $settingsnode->add_node($pagenode);
        $pagenode->make_active();
    }
}
