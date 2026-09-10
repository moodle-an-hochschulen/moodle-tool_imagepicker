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
 * Admin tool "Image Picker" - Demo page form.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\form;

use moodleform;
use tool_imagepicker\element;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Demo page form.
 *
 * The form shows the ways in which the image picker can be put onto a form, side by side, and stores the images in file
 * areas of the plugin (see FIELDS) so that the whole life cycle of an image - upload, crop, save, edit again - can be tried
 * out and tested.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class demo_form extends moodleform {
    /** @var string The file area (of the tool_imagepicker component, in the system context) which the demo images live in. */
    const FILEAREA = 'demo';

    /**
     * @var int[] The form fields and the item ids under which their images are stored in the demo file area.
     *
     * The item ids are what keeps the images of the fields apart from each other.
     */
    const FIELDS = [
        'imagepicker' => 1,
        'imagepickerratio' => 2,
        'imagepickerjpeg' => 3,
        'imagepickernocrop' => 4,
    ];

    /**
     * Get the file manager options for the given field.
     *
     * @param string $field The field name (a key of FIELDS).
     * @return array The options.
     */
    public static function get_field_options(string $field): array {
        global $CFG;

        switch ($field) {
            case 'imagepickerratio':
                // The image picker element, with a fixed crop aspect ratio.
                return [
                    'maxbytes' => $CFG->maxbytes,
                    'accepted_types' => 'web_image',
                    'cropaspectratio' => 16 / 9,
                ];
            case 'imagepickerjpeg':
                // The image picker element, restricted to JPEG images. The list deliberately names a file type which is not
                // a web image as well, as the element drops such types on its own.
                return [
                    'maxbytes' => $CFG->maxbytes,
                    'accepted_types' => ['.jpg', '.jpeg', '.jpe', '.pdf'],
                ];
            case 'imagepickernocrop':
                // The image picker element without the crop button, which makes it a plain file manager for a single web
                // image.
                return [
                    'maxbytes' => $CFG->maxbytes,
                    'accepted_types' => 'web_image',
                    'enablecrop' => false,
                ];
            default:
                // The image picker element, with free cropping. The element reduces the accepted types to web images anyway,
                // so asking for all file types here is what shows that.
                return [
                    'maxbytes' => $CFG->maxbytes,
                    'accepted_types' => '*',
                ];
        }
    }

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        // First, register the element type.
        element::register();

        // Section 1: The image picker element.
        $mform->addElement('header', 'imagepickerheader', get_string('demo_imagepicker', 'tool_imagepicker'));
        $mform->setExpanded('imagepickerheader');
        $mform->addElement('static', 'imagepickerdesc', '', get_string('demo_imagepicker_desc', 'tool_imagepicker'));
        $mform->addElement(
            element::TYPE,
            'imagepicker',
            get_string('demo_image', 'tool_imagepicker'),
            null,
            self::get_field_options('imagepicker')
        );

        // Section 2: The image picker element with a fixed crop aspect ratio.
        $mform->addElement('header', 'imagepickerratioheader', get_string('demo_imagepickerratio', 'tool_imagepicker'));
        $mform->setExpanded('imagepickerratioheader');
        $mform->addElement('static', 'imagepickerratiodesc', '', get_string('demo_imagepickerratio_desc', 'tool_imagepicker'));
        $mform->addElement(
            element::TYPE,
            'imagepickerratio',
            get_string('demo_imageratio', 'tool_imagepicker'),
            null,
            self::get_field_options('imagepickerratio')
        );

        // Section 3: The image picker element restricted to JPEG images.
        $mform->addElement('header', 'imagepickerjpegheader', get_string('demo_imagepickerjpeg', 'tool_imagepicker'));
        $mform->setExpanded('imagepickerjpegheader');
        $mform->addElement('static', 'imagepickerjpegdesc', '', get_string('demo_imagepickerjpeg_desc', 'tool_imagepicker'));
        $mform->addElement(
            element::TYPE,
            'imagepickerjpeg',
            get_string('demo_imagejpeg', 'tool_imagepicker'),
            null,
            self::get_field_options('imagepickerjpeg')
        );

        // Section 4: The image picker element without cropping.
        $mform->addElement('header', 'imagepickernocropheader', get_string('demo_imagepickernocrop', 'tool_imagepicker'));
        $mform->setExpanded('imagepickernocropheader');
        $mform->addElement('static', 'imagepickernocropdesc', '', get_string('demo_imagepickernocrop_desc', 'tool_imagepicker'));
        $mform->addElement(
            element::TYPE,
            'imagepickernocrop',
            get_string('demo_imagenocrop', 'tool_imagepicker'),
            null,
            self::get_field_options('imagepickernocrop')
        );

        // Action buttons.
        $this->add_action_buttons(false);
    }
}
