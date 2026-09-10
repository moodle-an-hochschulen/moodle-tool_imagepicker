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
 * Admin tool "Image Picker" - External services definition.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'tool_imagepicker_get_draft_image' => [
        'classname' => 'tool_imagepicker\external\get_draft_image',
        'methodname' => 'execute',
        'description' => 'Get the URL of the single image in a draft file area.',
        // Although this function only reads the draft area, it is declared as a write function: it settles the record of
        // a preserved original which was written before the real place of its image was known (see
        // \tool_imagepicker\local\originals::adopt_place()), which updates that record and moves the original file along.
        'type' => 'write',
        'ajax' => true,
    ],
    'tool_imagepicker_replace_draft_image' => [
        'classname' => 'tool_imagepicker\external\replace_draft_image',
        'methodname' => 'execute',
        'description' => 'Replace the single image in a draft file area with a cropped version.',
        'type' => 'write',
        'ajax' => true,
    ],
];
