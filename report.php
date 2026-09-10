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
 * Admin tool "Image Picker" - Preserved originals report.
 *
 * Lists the original images which the plugin preserves on the site and lets an admin view and delete them. This page is for
 * admins only.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use tool_imagepicker\local\adminpage;
use tool_imagepicker\local\originals;
use tool_imagepicker\table\originals_overview;

// Include config.php.
require(__DIR__ . '/../../../config.php');

// Globals.
global $CFG, $DB, $OUTPUT, $PAGE;

// Get parameters.
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);

// Include adminlib.php.
require_once($CFG->libdir . '/adminlib.php');

// Set up the page (which also checks that the user is allowed to see it).
admin_externalpage_setup('tool_imagepicker_report');
adminpage::add_settings_page_to_breadcrumb('tool_imagepicker_report');
$pageurl = new core\url('/admin/tool/imagepicker/report.php');

// Process actions.
if ($action === 'delete') {
    require_sesskey();

    // Remove the original, file and record alike. A record which is gone already is nothing to complain about, the outcome
    // is the same.
    if ($record = $DB->get_record('tool_imagepicker_original', ['id' => $id])) {
        originals::delete($record);
    }

    redirect($pageurl, get_string('report_deleted', 'tool_imagepicker'), null, notification::NOTIFY_SUCCESS);
} else if ($action === 'deleteall') {
    require_sesskey();

    $count = originals::delete_all();

    redirect($pageurl, get_string('report_deletedall', 'tool_imagepicker', $count), null, notification::NOTIFY_SUCCESS);
}

// Build the table.
$table = new originals_overview($pageurl);
$table->define_baseurl($pageurl);

// Start page output.
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report_pagetitle', 'tool_imagepicker'));
echo html_writer::tag('p', get_string('report_pageintro', 'tool_imagepicker'));
if (originals::is_enabled()) {
    echo html_writer::tag('p', get_string('report_enabled', 'tool_imagepicker'));
} else {
    echo html_writer::tag('p', get_string('report_disabled', 'tool_imagepicker'));
}

// Show the table.
$table->out(50, false);

// Offer to delete all originals at once, as long as there are any. This is what an admin needs after the preservation of
// originals has been disabled: the originals which were preserved before stay in place otherwise, as nothing removes them
// until the images they back are cropped again or deleted.
if ($DB->count_records('tool_imagepicker_original') > 0) {
    $deletealllink = new core\url($pageurl, ['action' => 'deleteall', 'sesskey' => sesskey()]);
    echo html_writer::div(
        html_writer::link(
            '#',
            get_string('report_deleteall', 'tool_imagepicker'),
            [
                'class' => 'btn btn-secondary',
                'role' => 'button',
                'id' => 'tool_imagepicker_deleteall',
                'data-modal' => 'confirmation',
                'data-modal-title-str' => json_encode(['report_deleteall', 'tool_imagepicker']),
                'data-modal-content-str' => json_encode(['report_deleteallconfirm', 'tool_imagepicker']),
                'data-modal-yes-button-str' => json_encode(['delete', 'core']),
                'data-modal-destination' => $deletealllink->out(false),
            ]
        ),
        'mt-3'
    );
}

// Finish page output.
echo $OUTPUT->footer();
