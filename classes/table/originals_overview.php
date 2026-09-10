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
 * Admin tool "Image Picker" - Preserved originals report table.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\table;

use core\output\html_writer;
use core\url;
use tool_imagepicker\local\originals;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * List of the preserved originals on the site.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class originals_overview extends \core_table\sql_table {
    /**
     * Set up the table.
     *
     * @param url $reporturl The URL of the report page, which the actions lead back to.
     */
    public function __construct(
        /** @var url The URL of the report page. */
        protected url $reporturl
    ) {
        parent::__construct('tool_imagepicker_originals');
        $this->set_attribute('id', 'tool_imagepicker_originals');

        // Define the headers and columns.
        $headers = [
            get_string('report_column_id', 'tool_imagepicker'),
            get_string('report_column_place', 'tool_imagepicker'),
            get_string('report_column_original', 'tool_imagepicker'),
            get_string('report_column_cropped', 'tool_imagepicker'),
            get_string('report_column_crop', 'tool_imagepicker'),
            get_string('report_column_timemodified', 'tool_imagepicker'),
            get_string('actions'),
        ];
        $columns = ['id', 'place', 'original', 'cropped', 'crop', 'timemodified', 'actions'];
        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_header_column('id');
        $this->sortable(true, 'id', SORT_ASC);
        $this->no_sorting('place');
        $this->no_sorting('original');
        $this->no_sorting('cropped');
        $this->no_sorting('crop');
        $this->no_sorting('actions');
        $this->collapsible(false);
        $this->column_class('actions', 'text-nowrap');

        // The derived file count tells whether the displayed file which an original backs still exists anywhere, which is
        // the same question the clean up task asks (see originals::get_orphaned_records()).
        $this->set_sql(
            'o.*, ' . originals::get_derived_count_sql('o') . ' AS derivedcount',
            '{tool_imagepicker_original} o',
            '1 = 1'
        );
    }

    /**
     * Place column.
     *
     * @param \stdClass $data The record.
     * @return string
     */
    public function col_place($data): string {
        $place = s($data->component . ' / ' . $data->filearea . ' / ' . $data->itemid);

        // Name the context which the original is stored in. It might be gone in the meantime, in which case its id is all
        // that can be said about it.
        $context = \context::instance_by_id($data->contextid, IGNORE_MISSING);
        if ($context) {
            $contextname = $context->get_context_name(true, true);
        } else {
            $contextname = get_string('report_contextmissing', 'tool_imagepicker', $data->contextid);
        }
        $place .= html_writer::empty_tag('br') . html_writer::span($contextname, 'text-muted small');

        // An original which still names a draft area belongs to an image which has not been saved anywhere yet (or which
        // has not been edited again since).
        if (originals::is_draft_place((array) $data)) {
            $place .= html_writer::empty_tag('br') .
                html_writer::span(get_string('report_draftplace', 'tool_imagepicker'), 'badge bg-secondary text-white');
        }

        return $place;
    }

    /**
     * Original image status column.
     *
     * Tells whether the preserved original file itself still exists. It is gone, for example, when the context which it was
     * stored in has been deleted, which takes every file in that context with it.
     *
     * @param \stdClass $data The record.
     * @return string
     */
    public function col_original($data): string {
        $file = originals::get_file($data);
        if (!$file) {
            return $this->render_status(get_string('report_original_missing', 'tool_imagepicker'), 'bg-danger text-white');
        }

        return $this->render_status(get_string('report_original_inplace', 'tool_imagepicker'), 'bg-success text-white', $file);
    }

    /**
     * Cropped image status column.
     *
     * Tells whether the cropped image which the original backs still exists anywhere. If it does not, the original is
     * orphaned and will be removed by the clean up task (see \tool_imagepicker\task\cleanup_originals), which asks the
     * very same question.
     *
     * @param \stdClass $data The record.
     * @return string
     */
    public function col_cropped($data): string {
        global $DB;

        if ($data->derivedcount < 1) {
            return $this->render_status(get_string('report_cropped_orphaned', 'tool_imagepicker'), 'bg-warning text-dark');
        }

        // The cropped image can exist in several places at once (in its file area and in the draft areas of the users who
        // are editing it, for example). Its file area is the place worth naming, so a file outside of a draft area is
        // preferred; any of the files will do to name the file and its size otherwise.
        $file = null;
        $filerecords = $DB->get_records_select(
            'files',
            "contenthash = :contenthash AND filename <> '.'",
            ['contenthash' => $data->derivedhash],
            'id ASC'
        );
        foreach ($filerecords as $filerecord) {
            $isdraft = $filerecord->component === 'user' && $filerecord->filearea === 'draft';
            if ($file === null || !$isdraft) {
                $file = get_file_storage()->get_file_instance($filerecord);
            }
            if (!$isdraft) {
                break;
            }
        }

        return $this->render_status(get_string('report_cropped_inuse', 'tool_imagepicker'), 'bg-success text-white', $file);
    }

    /**
     * Render a status cell: a status badge, followed by a badge with the file extension and the size of the file if there
     * is one.
     *
     * The file name itself is deliberately not shown. The same image content can sit in several places at once (see
     * col_cropped()), so a name would only ever be the name of one of them, whereas the type and the size are the same for
     * all of them.
     *
     * @param string $label The status badge label.
     * @param string $badgeclasses The colour classes of the status badge.
     * @param \stored_file|null $file The file to describe, if any.
     * @return string
     */
    protected function render_status(string $label, string $badgeclasses, ?\stored_file $file = null): string {
        $html = html_writer::span($label, 'badge ' . $badgeclasses);
        if ($file) {
            $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if ($extension !== '') {
                $html .= ' ' . html_writer::span(s('.' . $extension), 'badge bg-light text-dark border');
            }
            $html .= html_writer::empty_tag('br') .
                html_writer::span(display_size($file->get_filesize()), 'text-muted small');
        }

        return $html;
    }

    /**
     * Crop region column.
     *
     * The crop region is stored as fractions of the original image (so that it does not depend on the size at which the
     * image happens to be displayed in the crop modal). For the report, it is translated into pixels of the original image,
     * which is what a reader expects. If the original is gone, its size is unknown and the fractions are shown as
     * percentages instead.
     *
     * @param \stdClass $data The record.
     * @return string
     */
    public function col_crop($data): string {
        if ($data->cropwidth === null || $data->cropheight === null) {
            return '';
        }

        $file = originals::get_file($data);
        $imageinfo = $file ? $file->get_imageinfo() : false;
        if ($imageinfo && !empty($imageinfo['width']) && !empty($imageinfo['height'])) {
            $size = get_string('report_cropsize_px', 'tool_imagepicker', [
                'width' => round($data->cropwidth * $imageinfo['width']),
                'height' => round($data->cropheight * $imageinfo['height']),
            ]);
            $position = get_string('report_cropposition_px', 'tool_imagepicker', [
                'x' => round($data->cropx * $imageinfo['width']),
                'y' => round($data->cropy * $imageinfo['height']),
            ]);
        } else {
            $size = get_string('report_cropsize_percent', 'tool_imagepicker', [
                'width' => round($data->cropwidth * 100),
                'height' => round($data->cropheight * 100),
            ]);
            $position = get_string('report_cropposition_percent', 'tool_imagepicker', [
                'x' => round($data->cropx * 100),
                'y' => round($data->cropy * 100),
            ]);
        }

        return $size . html_writer::empty_tag('br') . html_writer::span($position, 'text-muted small');
    }

    /**
     * Time modified column.
     *
     * @param \stdClass $data The record.
     * @return string
     */
    public function col_timemodified($data): string {
        return userdate($data->timemodified, get_string('strftimedatetimeshort', 'langconfig'));
    }

    /**
     * Actions column.
     *
     * @param \stdClass $data The record.
     * @return string
     */
    public function col_actions($data): string {
        global $OUTPUT;

        $actions = [];

        // View. The original is served by tool_imagepicker_pluginfile(), which lets admins read every original.
        $file = originals::get_file($data);
        if ($file) {
            $actions[] = html_writer::link(
                originals::get_url($file),
                $OUTPUT->pix_icon('i/search', get_string('report_view', 'tool_imagepicker')),
                ['class' => 'action-view', 'role' => 'button']
            );
        }

        // Delete, with a confirmation modal in front of it.
        $deleteurl = new url($this->reporturl, ['action' => 'delete', 'id' => $data->id, 'sesskey' => sesskey()]);
        $actions[] = html_writer::link(
            '#',
            $OUTPUT->pix_icon('t/delete', get_string('report_delete', 'tool_imagepicker')),
            [
                'class' => 'action-delete',
                'role' => 'button',
                'data-modal' => 'confirmation',
                'data-modal-title-str' => json_encode(['report_delete', 'tool_imagepicker']),
                'data-modal-content-str' => json_encode(['report_deleteconfirm', 'tool_imagepicker', $data->id]),
                'data-modal-yes-button-str' => json_encode(['delete', 'core']),
                'data-modal-destination' => $deleteurl->out(false),
            ]
        );

        return html_writer::span(join(' ', $actions), 'tool-imagepicker-originals-actions');
    }

    /**
     * Override the message if the table contains no entries.
     */
    public function print_nothing_to_display() {
        global $OUTPUT;

        echo $OUTPUT->render(new \core\output\notification(
            get_string('report_nothingtodisplay', 'tool_imagepicker'),
            \core\output\notification::NOTIFY_INFO,
            false
        ));
    }
}
