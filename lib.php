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
 * Admin tool "Image Picker" - Library functions.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_imagepicker\local\fileserving;

/**
 * Serve the files of the plugin.
 *
 * These are the preserved original image files, the draft files which the crop modal loads, and the images of the demo page.
 * Which file is asked for and whether the current user may have it is decided by \tool_imagepicker\local\fileserving, this
 * function only sends what it is handed.
 *
 * @param stdClass $course The course object.
 * @param stdClass $cm The course module object.
 * @param context $context The context.
 * @param string $filearea The file area.
 * @param array $args The remaining bits of the file path.
 * @param bool $forcedownload Whether the file should be downloaded instead of shown.
 * @param array $options Additional options affecting the file serving.
 * @return bool False if the file was not found (the caller then sends a 404).
 */
function tool_imagepicker_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // Access check.
    require_login();

    // Find the requested file. If there is nothing to serve (because the file does not exist or because the user may not
    // have it), the caller sends a 404.
    $resolved = fileserving::resolve($context, $filearea, $args);
    if ($resolved === null) {
        return false;
    }

    // Serve the file. The browser may cache a draft file and a preserved original (see fileserving), but not a demo image.
    if ($resolved['cacheable']) {
        fileserving::send_cacheable($resolved['file'], $forcedownload, $options);
    }
    send_stored_file($resolved['file'], 0, 0, $forcedownload, $options);
}
