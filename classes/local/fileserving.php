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
 * Admin tool "Image Picker" - Serving of the files of the plugin.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\local;

use context;
use context_system;
use moodle_url;
use stored_file;

/**
 * Admin tool "Image Picker" - Serving of the files of the plugin.
 *
 * These are the preserved original image files, the draft files which the crop modal loads, and the images of the demo page.
 * Which file a request is after and whether the current user may have it is decided by resolve(); sending it is left to
 * tool_imagepicker_pluginfile(), which is the only place which can (sending a file ends the request).
 *
 * The crop modal loads the image which is to be cropped several times over: once to learn its natural dimensions, once to
 * inspect its content for an animation, and once more when the cropper itself loads it. Core serves a draft file through
 * draftfile.php, which forbids the browser to cache it - so each of these is a full download, and each costs a round trip
 * to the server. That is what makes the crop modal slow to open.
 *
 * So the images are served through this plugin instead, with headers which let the browser cache them, and the first
 * download serves all further loads. Two things keep that safe:
 *
 * - The URL of a draft file carries the hash of the file content, so it changes whenever the content changes. A cropped
 *   image is a new file with a new hash and hence a new URL, so the browser can never mistake a cached image for it. The
 *   URL of a preserved original carries the id of its record, and the file behind a record never changes (an image which
 *   is replaced gets a record of its own), so that URL is stable for its content as well.
 * - The images are marked as privately cacheable, so that they may only ever be kept by the browser of the user who
 *   fetched them, and never by a shared cache in between.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fileserving {
    /**
     * The time (in seconds) for which the browser may keep a served image.
     *
     * The loads which are meant to share a download happen within seconds of each other, so a minute is plenty. It is kept
     * this short on purpose: the image only has to survive the opening of the crop modal, and a private draft image should
     * not sit in the browser cache any longer than it has to.
     */
    const CACHE_LIFETIME = 60;

    /**
     * Get the URL under which the given draft file is served to its owner.
     *
     * @param stored_file $draftfile The file in the draft area.
     * @return moodle_url
     */
    public static function get_draft_url(stored_file $draftfile): moodle_url {
        // The request is served (and access-checked) by tool_imagepicker_pluginfile(). The content hash is put into the path
        // so that the URL changes along with the content, see the class comment.
        return moodle_url::make_pluginfile_url(
            $draftfile->get_contextid(),
            'tool_imagepicker',
            'draft',
            $draftfile->get_itemid(),
            '/' . $draftfile->get_contenthash() . '/',
            $draftfile->get_filename()
        );
    }

    /**
     * Resolve a file request of the plugin: find the requested file and decide whether the current user may have it.
     *
     * A preserved original is stored in the same context as the image which it backs, so it can be served to anybody who
     * edits that image - and not just to the user who happened to crop it. Who that is, is decided by originals::can_access().
     * Admins may read every original, as that is what the preserved originals report lets them do.
     *
     * A draft file is served to its owner only, just as draftfile.php does it. It is looked up in the draft area of the user
     * (it is not stored in a file area of this plugin), and only if its content is still the one which the URL names.
     *
     * The images of the demo page are stored in the system context and are served to admins only, as the demo page itself
     * is. Unlike the other files, a demo image changes with every crop while its URL stays the same, so it must not be
     * cached.
     *
     * @param context $context The context of the request.
     * @param string $filearea The file area.
     * @param array $args The remaining bits of the file path (the item id first, the file name last).
     * @return array|null The file to serve ('file') and whether the browser may cache it ('cacheable'), or null if there is
     *                    nothing to serve (the file does not exist or the user may not have it).
     */
    public static function resolve(context $context, string $filearea, array $args): ?array {
        global $DB, $USER;

        // The item id is the first argument for all file areas.
        $itemid = (int) array_shift($args);

        switch ($filearea) {
            case 'draft':
                // A draft area lives in the user context of the user who prepared it, and its files are served to that user
                // only. This is the very check which draftfile.php makes.
                if ($context->contextlevel !== CONTEXT_USER || (int) $context->instanceid !== (int) $USER->id) {
                    return null;
                }

                // The remaining arguments are the content hash and the file name, see get_draft_url().
                $contenthash = (string) array_shift($args);
                $filename = (string) array_pop($args);
                $file = get_file_storage()->get_file($context->id, 'user', 'draft', $itemid, '/', $filename);

                // The URL names the content which it was made for. A file whose content has changed since is not the file
                // which was asked for (and its new URL is what the browser has to fetch instead).
                if (!$file || $file->is_directory() || $file->get_contenthash() !== $contenthash) {
                    return null;
                }

                return ['file' => $file, 'cacheable' => true];

            case 'original':
                // The item id of a preserved original is the id of its record.
                $record = $DB->get_record('tool_imagepicker_original', ['id' => $itemid]);

                // The record has to exist and it has to be the one which is stored in the requested context. The latter makes
                // sure that an original which was moved to another context in the meantime cannot be fetched from its former
                // place any more.
                if (!$record || (int) $record->contextid !== (int) $context->id) {
                    return null;
                }

                // Only users who are editing the image which this original backs may read it, plus admins.
                if (
                    !has_capability('moodle/site:config', context_system::instance()) &&
                    !originals::can_access($record)
                ) {
                    return null;
                }

                $cacheable = true;
                break;

            case 'demo':
                // The demo images live in the system context and are for admins only.
                if ($context->contextlevel !== CONTEXT_SYSTEM) {
                    return null;
                }
                require_capability('moodle/site:config', $context);

                $cacheable = false;
                break;

            default:
                // We do not serve anything else.
                return null;
        }

        // The remaining arguments are the path of the file, with the file name as their last element. The files are always
        // stored in the root of their file area, but the path is composed from the arguments anyway so that the file is only
        // ever served from the place which was actually requested.
        $filename = (string) array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

        // The file might be gone (or the path might point to a directory rather than to a file), in which case there is
        // nothing to serve.
        $file = get_file_storage()->get_file($context->id, 'tool_imagepicker', $filearea, $itemid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return null;
        }

        return ['file' => $file, 'cacheable' => $cacheable];
    }

    /**
     * Send the given file with headers which let the browser (and only the browser) cache it.
     *
     * This does not return, see send_stored_file().
     *
     * @param stored_file $file The file to send.
     * @param bool $forcedownload Whether the file should be downloaded instead of shown.
     * @param array $options Additional options affecting the file serving.
     */
    public static function send_cacheable(stored_file $file, bool $forcedownload, array $options): void {
        send_stored_file($file, self::CACHE_LIFETIME, 0, $forcedownload, ['cacheability' => 'private'] + $options);
    }
}
