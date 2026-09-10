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
 * Admin tool "Image Picker" - File size limit for cropped images.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\local;

/**
 * Admin tool "Image Picker" - File size limit for cropped images.
 *
 * A cropped image is re-encoded by the browser, and that does not necessarily make it smaller than the image it was cropped
 * from (an indexed GIF which comes back as a plain PNG, for example). So a cropped image can run into the upload limit of
 * the site, and both sides need to know that limit: the server, to refuse a cropped image which exceeds it, and the client,
 * to tell the user beforehand and to offer to scale the image down. Both take it from here, so that they always agree.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sizelimit {
    /**
     * Resolve the maximum size which a cropped image may have, in bytes.
     *
     * This is the size limit which the site imposes on an upload by the current user into the place which the image lives
     * in, so it answers exactly the question which matters here: may this user put a file of this size in this place at all?
     *
     * It is deliberately computed on the server and never taken from a request. A limit which the caller hands in is a limit
     * which the caller can raise, which would make the whole check pointless.
     *
     * The limit is made up of the site limit and, if the image lives in a course (or in an activity of a course), the upload
     * limit of that course, whichever is smaller. Both are known on the server from the place alone.
     *
     * The limit of the form element itself, on the other hand, is not applied here: the web services have no way of knowing
     * which of the (arbitrarily many) forms and elements the image picker is used on they are currently serving, and asking
     * the client would lead straight back to a forgeable value. A field with a tighter limit of its own can therefore end up
     * with a cropped image which is larger than a fresh upload into the same field would have been allowed to be - but never
     * larger than the site and the course permit.
     *
     * @param array $place The place which the image lives in, as returned by originals::resolve_place().
     * @param \context $usercontext The user context of the current user.
     * @return int The maximum size in bytes, or 0 if the upload is not limited in size.
     */
    public static function resolve(array $place, \context $usercontext): int {
        global $CFG, $DB;

        // The context decides two things here: whether the user may ignore file size limits (a capability which is usually
        // granted in a course, so it is checked in the context of the place the image lives in) and whether a course limit
        // applies on top of the site limit. Both need a real place (which still exists). For an image which is nothing but a
        // draft yet, the user context has to do, and no course limit applies.
        $limitcontext = null;
        if (!originals::is_draft_place($place)) {
            $limitcontext = \context::instance_by_id($place['contextid'], IGNORE_MISSING);
        }
        if (!$limitcontext) {
            $limitcontext = $usercontext;
        }

        // If the place lies within a course, the upload limit of that course applies as well. A course limit of 0 means that
        // the course does not restrict uploads beyond the site limit.
        $coursebytes = 0;
        if ($coursecontext = $limitcontext->get_course_context(false)) {
            $coursebytes = (int) $DB->get_field('course', 'maxbytes', ['id' => $coursecontext->instanceid]);
        }

        // A user who is allowed to ignore file size limits gets USER_CAN_IGNORE_FILE_SIZE_LIMITS (-1) here, which is not a
        // size but the statement that no limit applies. It is reported as such.
        $limit = get_user_max_upload_file_size($limitcontext, $CFG->maxbytes, $coursebytes);

        return $limit > 0 ? (int) $limit : 0;
    }
}
