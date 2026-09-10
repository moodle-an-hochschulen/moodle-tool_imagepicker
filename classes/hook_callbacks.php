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
 * Admin tool "Image Picker" - Hook callbacks.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker;

use core_files\hook\after_file_created;
use tool_imagepicker\local\originals;

/**
 * Hook callbacks.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Settle a preserved original into the place of its image as soon as that image is saved there.
     *
     * An image which is cropped while it is nothing but a fresh upload has no place yet but the draft area it sits in, so
     * its original is preserved with the draft area recorded as its place (see \tool_imagepicker\local\originals). The
     * real place becomes known the moment the form is submitted: core then copies the image from the draft area into the
     * file area it belongs to, which is a file creation and therefore fires this hook. So this is the earliest moment at
     * which the original can be settled - and the file which core has just created is what names the place, so nothing has
     * to be guessed.
     *
     * The web services settle a draft-placed original as well, the next time the image is edited. That remains as the
     * fallback for images which were saved without this hook (which should not happen as the hook is part of the first
     * release of the plugin).
     *
     * @param after_file_created $hook The hook.
     */
    public static function after_file_created(after_file_created $hook): void {
        global $CFG;

        // Files may be created while the site is being installed or upgraded, when the table of the plugin is not necessarily
        // there yet. There is nothing to settle at that point anyway.
        if (during_initial_install() || !empty($CFG->upgraderunning)) {
            return;
        }

        // The hook callbacks of a plugin are loaded from disk, so they are called before the plugin has been installed. Its
        // table does not exist until then.
        if (!get_config('tool_imagepicker', 'version')) {
            return;
        }

        originals::adopt_saved_file($hook->storedfile);
    }
}
