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
 * Admin tool "Image Picker" - Demo page.
 *
 * Shows the ways in which the image picker can be put onto a form, side by side, and stores the images in file areas of the
 * plugin so that the whole life cycle of an image can be tried out. This page is for admins only.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_imagepicker\form\demo_form;
use tool_imagepicker\local\adminpage;

// Include config.php.
require(__DIR__ . '/../../../config.php');

// Globals.
global $CFG, $OUTPUT, $PAGE;

// Include adminlib.php.
require_once($CFG->libdir . '/adminlib.php');

// Set up the page (which also checks that the user is allowed to see it).
admin_externalpage_setup('tool_imagepicker_demo');
adminpage::add_settings_page_to_breadcrumb('tool_imagepicker_demo');
$context = context_system::instance();
$pageurl = new core\url('/admin/tool/imagepicker/demo.php');

// Prepare the form.
$form = new demo_form($pageurl);

// Handle the form submission.
if ($data = $form->get_data()) {
    // Store the images of all fields in their file areas.
    foreach (demo_form::FIELDS as $field => $itemid) {
        file_save_draft_area_files(
            $data->$field,
            $context->id,
            'tool_imagepicker',
            demo_form::FILEAREA,
            $itemid,
            demo_form::get_field_options($field)
        );
    }

    // Redirect to the page itself, so that the form is shown with the stored images again.
    redirect($pageurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Fill the form with the stored images.
$defaults = new stdClass();
foreach (demo_form::FIELDS as $field => $itemid) {
    $draftitemid = file_get_submitted_draft_itemid($field);
    file_prepare_draft_area(
        $draftitemid,
        $context->id,
        'tool_imagepicker',
        demo_form::FILEAREA,
        $itemid,
        demo_form::get_field_options($field)
    );
    $defaults->$field = $draftitemid;
}
$form->set_data($defaults);

// Start page output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('demo_pagetitle', 'tool_imagepicker'));
echo html_writer::tag('p', get_string('demo_pageintro', 'tool_imagepicker'));

// Show the form.
$form->display();

// Finish page output.
echo $OUTPUT->footer();
