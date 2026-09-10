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
 * Admin tool "Image Picker" - External function to replace the image in a draft file area.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_form\filetypes_util;
use tool_imagepicker\local\originals;
use tool_imagepicker\local\sizelimit;

/**
 * External function to replace the single image in a draft file area with a (cropped) version.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class replace_draft_image extends external_api {
    /**
     * @var string[][] The file extensions of each image type which a browser canvas is able to encode.
     *
     * This is not a policy but a fact about the feature: a cropped image is encoded in the browser with canvas.toBlob(),
     * which supports these image types and nothing else. As the stored file is named after the type which the image content
     * actually has (see build_filename()), this list is also the hard limit of what this function can ever write - no matter
     * what the caller sends. Which of these types are acceptable in a given form is a separate question, which the accepted
     * types of the form element answer.
     *
     * The first extension of each type is the one which a file gets if it has to be renamed; the others are alternative
     * spellings which are kept if the file has one of them already.
     */
    protected const ENCODABLE_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * Describes the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'The draft area item id'),
            'filecontent' => new external_value(PARAM_RAW, 'Base64 encoded image content'),
            'acceptedtypes' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'A file type, given as an extension, a group or a mime type'),
                'The file types which the form element accepts',
                VALUE_DEFAULT,
                []
            ),
            'cropx' => new external_value(
                PARAM_FLOAT,
                'Crop x position as a fraction (0..1) of the original width',
                VALUE_DEFAULT,
                null
            ),
            'cropy' => new external_value(
                PARAM_FLOAT,
                'Crop y position as a fraction (0..1) of the original height',
                VALUE_DEFAULT,
                null
            ),
            'cropwidth' => new external_value(
                PARAM_FLOAT,
                'Crop width as a fraction (0..1) of the original width',
                VALUE_DEFAULT,
                null
            ),
            'cropheight' => new external_value(
                PARAM_FLOAT,
                'Crop height as a fraction (0..1) of the original height',
                VALUE_DEFAULT,
                null
            ),
        ]);
    }

    /**
     * Replace the single image in the given draft file area with the given (cropped) image content.
     *
     * @param int $draftitemid The draft area item id.
     * @param string $filecontent The base64 encoded image content.
     * @param array $acceptedtypes The file types which the form element accepts.
     * @param float|null $cropx The crop x position as a fraction (0..1) of the original width.
     * @param float|null $cropy The crop y position as a fraction (0..1) of the original height.
     * @param float|null $cropwidth The crop width as a fraction (0..1) of the original width.
     * @param float|null $cropheight The crop height as a fraction (0..1) of the original height.
     * @return array The URL and file name of the stored image.
     */
    public static function execute(
        int $draftitemid,
        string $filecontent,
        array $acceptedtypes = [],
        ?float $cropx = null,
        ?float $cropy = null,
        ?float $cropwidth = null,
        ?float $cropheight = null
    ): array {
        global $USER;

        // Validate the parameters.
        [
            'draftitemid' => $draftitemid,
            'filecontent' => $filecontent,
            'acceptedtypes' => $acceptedtypes,
            'cropx' => $cropx,
            'cropy' => $cropy,
            'cropwidth' => $cropwidth,
            'cropheight' => $cropheight,
        ] = self::validate_parameters(self::execute_parameters(), [
            'draftitemid' => $draftitemid,
            'filecontent' => $filecontent,
            'acceptedtypes' => $acceptedtypes,
            'cropx' => $cropx,
            'cropy' => $cropy,
            'cropwidth' => $cropwidth,
            'cropheight' => $cropheight,
        ]);

        // Draft file areas always live in the user context of the current user, and that is all this function writes to. The
        // draft area is only ever the current user's own, no matter what is requested, so nothing else has to be validated:
        // which form the draft area belongs to (and whether the user may edit it) was decided when that form was opened and
        // the draft area was prepared.
        $context = \context_user::instance($USER->id);
        self::validate_context($context);

        // The crop region is optional, but if it is given, it has to be complete and it has to lie within the image.
        $crop = self::validate_crop_region($cropx, $cropy, $cropwidth, $cropheight);

        // Get the image which is being cropped. Without one there is nothing to replace.
        $current = originals::get_root_file($context->id, 'user', 'draft', $draftitemid);
        if (!$current) {
            throw new \moodle_exception('error_noimagetocrop', 'tool_imagepicker');
        }

        // Where the image lives is read from the draft file itself, see originals::resolve_place(). For an image which has
        // been saved somewhere before, this names its real file area. For a fresh upload, it is the draft area.
        $place = originals::resolve_place($current);

        // Decode the image content and read the image type from the content itself. Content which is not an image at all
        // makes getimagesizefromstring() return false, which is all that is needed here - the notice which it raises on top
        // of that for unreadable content is suppressed, as such content is refused below anyway.
        $content = base64_decode($filecontent, true);
        $imageinfo = ($content === false || $content === '') ? false : @getimagesizefromstring($content);
        if ($imageinfo === false) {
            throw new \moodle_exception('error_invalidimagecontent', 'tool_imagepicker');
        }

        // The cropped image must not be bigger than what this user may upload here. Note that cropping does not necessarily
        // make an image smaller: a crop covers fewer pixels than the image it came from, but it is re-encoded by the browser,
        // and that can make it considerably bigger than what it was cropped from (an indexed PNG, a GIF or an AVIF photo, for
        // example, all come back as a plain PNG). So this is a limit which a cropped image can really run into.
        //
        // This check sits before anything is written or deleted below, so that a rejected image leaves the field exactly as
        // it was rather than destroying the image which is currently in it.
        $sizelimit = sizelimit::resolve($place, $context);
        if ($sizelimit > 0 && strlen($content) > $sizelimit) {
            throw new \moodle_exception('error_imagetoolarge', 'tool_imagepicker', '', display_size($sizelimit));
        }

        // Name the cropped image after the image which it was cropped from, giving it the extension of the image type which
        // the content actually has. Both halves come from data which we hold ourselves, so no file name has to be taken from
        // the caller (which could otherwise pick an arbitrary one).
        $filename = self::build_filename($current->get_filename(), $imageinfo['mime']);

        // The cropped image must be a file type which the form element accepts. Note that the accepted types are reported by
        // the client, so a forged request could claim to accept anything - but as the file name is derived from the actual
        // image content above, all this could achieve is to store, say, a PNG in a field which only asked for JPEGs.
        $typesutil = new filetypes_util();
        if (!$typesutil->is_allowed_file_type($filename, $acceptedtypes)) {
            throw new \moodle_exception('error_invalidimagetype', 'tool_imagepicker');
        }

        // If original preservation is enabled, make sure the currently displayed image is preserved as the original before
        // we replace it. This has to happen before the file is deleted below. The original is stored in the context of the
        // place which the image lives in (and not in the user context of whoever is cropping, unless the image is nothing
        // but a draft yet), so that other users can crop from it as well later on.
        $originalrecord = null;
        if (originals::is_enabled()) {
            $originalrecord = originals::ensure_original($current, $place);
        }

        // Remove the existing files in the root of the draft area (the image picker is a single-file field).
        foreach (originals::get_root_files($context->id, 'user', 'draft', $draftitemid) as $file) {
            $file->delete();
        }

        // Store the cropped image.
        //
        // The cropped image takes over everything from the image it replaces which describes where that image came from and
        // whose work it is. The source is what matters most: for a draft file which was prepared from an existing file area,
        // it names that file area (see originals::resolve_place()), and it is the only link between the draft file and the
        // place the image lives in. Without it, the cropped draft file would look like a fresh upload from here on - so a
        // second crop within the same form session could not find the original which was preserved on the first one, and
        // reading that original would be refused (see originals::can_access()).
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $current->get_userid(),
            'source' => $current->get_source(),
            'author' => $current->get_author(),
            'license' => $current->get_license(),
        ];
        $newfile = $fs->create_file_from_string($filerecord, $content);

        // Point the preserved original at the new displayed (cropped) file (so it can be found again when re-cropping) and
        // store the crop region so it can be restored the next time the image is cropped.
        if ($originalrecord) {
            originals::set_derived($originalrecord->id, $newfile->get_contenthash(), $crop);
        }

        // Return the draft image.
        return [
            'url' => \moodle_url::make_draftfile_url($draftitemid, '/', $filename)->out(false),
            'filename' => $filename,
        ];
    }

    /**
     * Validate the crop region which the caller has given.
     *
     * The region is given as fractions of the image: an x and y position and a width and a height, each between 0 and 1. The
     * parameter validation only makes sure that these are floats, so this is where their meaning is checked: either no
     * region is given at all, or all four values are given, each lies between 0 and 1, the width and the height are not
     * zero, and the region does not reach beyond the image.
     *
     * The last check allows for a tiny tolerance, as the client computes the fractions from element positions and a region
     * which ends exactly at the edge of the image can come out a hair beyond it. Such a region is trimmed to the image.
     *
     * @param float|null $x The x position of the region.
     * @param float|null $y The y position of the region.
     * @param float|null $width The width of the region.
     * @param float|null $height The height of the region.
     * @return array|null The region with the keys 'x', 'y', 'width' and 'height', or null if no region was given.
     *
     * A region which is refused is reported with a message of its own rather than as an invalid parameter. The latter would
     * only tell the user that 'an invalid parameter value was detected' unless debugging is on, whereas what is wrong here
     * can be said in plain words. What exactly is wrong with the region is added as debug information.
     * @throws \moodle_exception If the region is incomplete or does not lie within the image.
     */
    protected static function validate_crop_region(?float $x, ?float $y, ?float $width, ?float $height): ?array {
        $values = ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];

        // No region at all is fine.
        if ($values === array_fill_keys(array_keys($values), null)) {
            return null;
        }

        // Find out what is wrong with the region, if anything: half a region is not acceptable, neither is a value which is
        // not a fraction, nor an empty region, nor one which reaches beyond the image (give or take a rounding error).
        $tolerance = 0.000001;
        $problem = null;
        foreach ($values as $name => $value) {
            if ($value === null) {
                $problem = "Crop region is incomplete: crop{$name} is missing";
                break;
            }
            if ($value < 0 || $value > 1) {
                $problem = "Crop region is invalid: crop{$name} must be between 0 and 1";
                break;
            }
        }
        if ($problem === null && ($width == 0 || $height == 0)) {
            $problem = 'Crop region is invalid: it must not be empty';
        }
        if ($problem === null && ($x + $width > 1 + $tolerance || $y + $height > 1 + $tolerance)) {
            $problem = 'Crop region is invalid: it reaches beyond the image';
        }

        // The message tells the user in plain words that the region is not acceptable, the debug information tells the
        // developer why.
        if ($problem !== null) {
            throw new \moodle_exception('error_invalidcropregion', 'tool_imagepicker', '', null, $problem);
        }

        return [
            'x' => $x,
            'y' => $y,
            'width' => min($width, 1 - $x),
            'height' => min($height, 1 - $y),
        ];
    }

    /**
     * Build the file name under which a cropped image is stored.
     *
     * The cropped image keeps the name of the image which it was cropped from, but it gets the file extension of the image
     * type which it really is. That is not necessarily the type of the source image: a browser canvas cannot encode every
     * image type, so cropping a GIF, for example, yields a PNG.
     *
     * If the source image has an extension of the right type already, the name is kept exactly as it is: 'photo.jpeg' is not
     * renamed to 'photo.jpg', and 'PHOTO.JPG' is not renamed to 'PHOTO.jpg'. The file is only ever renamed if its type has
     * actually changed.
     *
     * @param string $sourcefilename The file name of the image which was cropped.
     * @param string $mimetype The mime type of the cropped image content.
     * @return string The file name.
     */
    protected static function build_filename(string $sourcefilename, string $mimetype): string {
        // Refuse anything which a browser canvas cannot have produced.
        if (!isset(self::ENCODABLE_TYPES[$mimetype])) {
            throw new \moodle_exception('error_invalidimagetype', 'tool_imagepicker');
        }
        $extensions = self::ENCODABLE_TYPES[$mimetype];

        // Split the source file name into its base name and its extension.
        $sourcepathinfo = pathinfo($sourcefilename);
        $basename = $sourcepathinfo['filename'];
        $sourceextension = $sourcepathinfo['extension'] ?? '';

        // If the source image has an extension of the right type already (in whichever spelling and case), keep it.
        // Otherwise, the type has changed and the file gets the extension of its new type.
        if (in_array(strtolower($sourceextension), $extensions, true)) {
            $extension = $sourceextension;
        } else {
            $extension = reset($extensions);
        }

        // The base name comes from a file which is already stored in Moodle, so it is clean. Sanitize it all the same, as it
        // is used to write a file.
        return clean_param($basename . '.' . $extension, PARAM_FILE);
    }

    /**
     * Describes the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'url' => new external_value(PARAM_URL, 'The full URL of the stored image'),
            'filename' => new external_value(PARAM_FILE, 'The stored file name'),
        ]);
    }
}
