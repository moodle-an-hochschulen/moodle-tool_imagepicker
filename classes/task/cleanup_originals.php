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
 * Admin tool "Image Picker" - Scheduled task to clean up orphaned original images.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\task;

use core\task\scheduled_task;
use tool_imagepicker\local\originals;

/**
 * Scheduled task to clean up orphaned original images.
 *
 * A preserved original is orphaned once the displayed (cropped) file it backs no longer exists anywhere (identified by its
 * content hash), for example after the image was removed from the field or the surrounding activity / course was deleted.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_originals extends scheduled_task {
    /**
     * Get the name of the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanuporiginals', 'tool_imagepicker');
    }

    /**
     * Execute the task.
     */
    public function execute(): void {
        // Find preserved originals whose derived (displayed) file no longer exists anywhere.
        $orphans = originals::get_orphaned_records();

        // If no orphans exist, say so and return directly.
        if (!$orphans) {
            mtrace('tool_imagepicker: no orphaned original images to clean up.');
            return;
        }

        // Delete the orphaned original files and their database entry in the originals table.
        foreach ($orphans as $orphan) {
            originals::delete($orphan);
        }

        // Trace.
        mtrace('tool_imagepicker: cleaned up ' . count($orphans) . ' orphaned original image(s).');
    }
}
