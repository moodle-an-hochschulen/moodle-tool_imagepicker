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
 * Admin tool "Image Picker" - Behat data generator.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for tool_imagepicker.
 *
 * Entities:
 * - "tool_imagepicker > image": puts a fixture image into the file area of a field of the demo page, as it sits there
 *   after the demo form was saved with that image. Fields: 'field' (the demo form field, a key of
 *   \tool_imagepicker\form\demo_form::FIELDS) and 'filepath' (the image, relative to the Moodle root directory), optionally
 *   'author' and 'license' (the license shortname) of the image.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_imagepicker_generator extends behat_generator_base {
    /**
     * Get a list of the entities that Behat can create using the generator step.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'images' => [
                'singular' => 'image',
                'datagenerator' => 'image',
                'required' => ['field', 'filepath'],
            ],
        ];
    }
}
