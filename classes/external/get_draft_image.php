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
 * Admin tool "Image Picker" - External function to get the current image of a draft file area.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use tool_imagepicker\local\fileserving;
use tool_imagepicker\local\originals;
use tool_imagepicker\local\sizelimit;

/**
 * External function to get the (full resolution) URL of the single image in a draft file area.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_draft_image extends external_api {
    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'The draft area item id'),
        ]);
    }

    /**
     * Get the URL and file name of the single image in the given draft file area.
     *
     * @param int $draftitemid The draft area item id.
     * @return array The image URL and file name (both empty if the draft area contains no file).
     */
    public static function execute(int $draftitemid): array {
        global $USER;

        // Validate parameters.
        [
            'draftitemid' => $draftitemid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'draftitemid' => $draftitemid,
        ]);

        // Draft file areas always live in the user context of the current user, and that is all this function touches. The
        // draft area is only ever the current user's own, no matter what is requested, so nothing else has to be validated:
        // which form the draft area belongs to (and whether the user may edit it) was decided when that form was opened and
        // the draft area was prepared.
        $usercontext = \context_user::instance($USER->id);
        self::validate_context($usercontext);

        // Get the currently displayed image in the root of the draft area.
        $current = originals::get_root_file($usercontext->id, 'user', 'draft', $draftitemid);
        if (!$current) {
            return ['url' => '', 'filename' => ''];
        }

        // The file name of the displayed file is used to name the cropped result later on.
        $filename = $current->get_filename();

        // Where the image lives is read from the draft file itself, see originals::resolve_place().
        $place = originals::resolve_place($current);

        // Default: crop from the displayed file itself. It is served through this plugin rather than through draftfile.php,
        // so that the browser may cache it (see fileserving).
        //
        // The size limit is handed over so that the client can tell the user before it stores a cropped image which is too
        // large to be stored, and can offer to scale it down. This is the very same limit which replace_draft_image enforces,
        // so the client knows in advance what the server will accept.
        $result = [
            'url' => fileserving::get_draft_url($current)->out(false),
            'filename' => $filename,
            'maxbytes' => sizelimit::resolve($place, $usercontext),
        ];

        // If original preservation is enabled and an original is preserved for the displayed file, crop from the original
        // instead (so cropping stays non-destructive) and return the last crop region so it can be restored.
        if (originals::is_enabled() && ($record = originals::get_record_for($current, $place))) {
            // Make sure the original sits with the image it backs before it is served from there.
            $record = originals::adopt_place($record, $place);

            // If we have an original.
            if ($original = originals::get_file($record)) {
                $result['url'] = originals::get_url($original)->out(false);

                // The image which is loaded into the cropper is the original, not the displayed file, so its file name is
                // what decides whether cropping changes the file format. These two differ exactly when a previous crop
                // already changed the format (a GIF original which is displayed as a PNG, for example), which is the case
                // where the notice has to keep being shown. As long as they are the same, the displayed file name says it
                // all and nothing extra is reported.
                if ($original->get_filename() !== $filename) {
                    $result['sourcefilename'] = $original->get_filename();
                }
            }

            // If a crop position was stored.
            if ($record->cropwidth !== null && $record->cropheight !== null) {
                $result['crop'] = [
                    'x' => (float) $record->cropx,
                    'y' => (float) $record->cropy,
                    'width' => (float) $record->cropwidth,
                    'height' => (float) $record->cropheight,
                ];
            }
        }

        return $result;
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL, 'The full URL of the image, or empty if there is none'),
            'filename' => new external_value(PARAM_FILE, 'The file name, or empty if there is none'),
            'maxbytes' => new external_value(
                PARAM_INT,
                'The maximum size in bytes which a cropped image may have, or 0 if it is not limited',
                VALUE_OPTIONAL
            ),
            'sourcefilename' => new external_value(
                PARAM_FILE,
                'The file name of the image which is served as the crop source, only if it differs from the displayed file',
                VALUE_OPTIONAL
            ),
            'crop' => new external_single_structure([
                'x' => new external_value(PARAM_FLOAT, 'The x position, as a fraction (0..1) of the image width'),
                'y' => new external_value(PARAM_FLOAT, 'The y position, as a fraction (0..1) of the image height'),
                'width' => new external_value(PARAM_FLOAT, 'The width, as a fraction (0..1) of the image width'),
                'height' => new external_value(PARAM_FLOAT, 'The height, as a fraction (0..1) of the image height'),
            ], 'The last crop region, if one is stored', VALUE_OPTIONAL),
        ]);
    }
}
