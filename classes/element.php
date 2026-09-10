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
 * Admin tool "Image Picker" - Element registration helper.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker;

use MoodleQuickForm;

/**
 * Admin tool "Image Picker" - Element registration helper.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element {
    /**
     * The name under which the image picker element is registered with QuickForm.
     *
     * Consuming plugins use this name with $mform->createElement().
     */
    const TYPE = 'toolimagepicker';

    /**
     * Register the image picker element type with QuickForm.
     *
     * This is safe to call multiple times and should be called in a form's definition (or a form-related hook callback)
     * before the element is created with $mform->createElement(self::TYPE, ...).
     */
    public static function register(): void {
        global $CFG;

        $classfile = $CFG->dirroot . '/admin/tool/imagepicker/classes/formelement/imagepicker.php';
        require_once($classfile);

        MoodleQuickForm::registerElementType(
            self::TYPE,
            $classfile,
            '\tool_imagepicker\formelement\imagepicker'
        );
    }
}
