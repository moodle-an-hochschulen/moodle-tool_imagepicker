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
 * Admin tool "Image Picker" - Test data generator.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_imagepicker\local\originals;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Test data generator.
 *
 * Builds the image content and the files which the tests of this plugin work with: images in the file area of some
 * component (as an image picker field holds them after its form was saved), draft files (as the field holds them while the
 * form is open), and preserved originals.
 *
 * The file area which the generator writes to by default is the demo area of the plugin itself, which is where the demo
 * page keeps its images. The plugin does not care which component an image belongs to (it only records where it lives), so
 * any area would do - but this one is a place where image picker images really live.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_imagepicker_generator extends component_generator_base {
    /** @var string The component which the file areas of the generator belong to by default. */
    public const AREA_COMPONENT = 'tool_imagepicker';

    /** @var string The file area which the generator writes to by default. */
    public const AREA_FILEAREA = 'demo';

    /**
     * Build a small image of the given size and type.
     *
     * The image is filled with a single colour, which can be varied to tell images of the same size apart (every colour
     * gives different content and hence a different content hash).
     *
     * @param int $width The width in pixels.
     * @param int $height The height in pixels.
     * @param string $type The image type: 'png', 'jpg', 'gif', 'bmp' or 'webp'.
     * @param int $red The red component of the fill colour.
     * @return string The image content.
     */
    public function build_image(int $width = 4, int $height = 4, string $type = 'png', int $red = 10): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 0, 0));

        return $this->encode_image($image, $type);
    }

    /**
     * Build an image which has at least the given file size.
     *
     * A single-coloured image compresses to next to nothing, so an image with a guaranteed file size is filled with random
     * noise instead (which is what the size limit tests need). The noise is seeded, so the same call gives the same content.
     *
     * @param int $minbytes The file size which the image has to reach at least.
     * @param string $type The image type: 'png' or 'jpg'.
     * @param int $seed The seed of the noise, to tell images of the same size apart.
     * @return string The image content.
     */
    public function build_noisy_image(int $minbytes, string $type = 'png', int $seed = 1): string {
        mt_srand($seed);

        // Grow the image until it is large enough. Noise does not compress, so a PNG comes out at roughly three bytes per
        // pixel and a JPEG at roughly one, which makes this converge within a few rounds.
        $size = 16;
        do {
            $image = imagecreatetruecolor($size, $size);
            for ($x = 0; $x < $size; $x++) {
                for ($y = 0; $y < $size; $y++) {
                    imagesetpixel($image, $x, $y, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
                }
            }
            $content = $this->encode_image($image, $type);
            $size *= 2;
        } while (strlen($content) < $minbytes);

        return $content;
    }

    /**
     * Encode the given GD image in the given type.
     *
     * @param \GdImage $image The image.
     * @param string $type The image type: 'png', 'jpg', 'gif', 'bmp' or 'webp'.
     * @return string The image content.
     */
    protected function encode_image(\GdImage $image, string $type): string {
        ob_start();
        switch ($type) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($image);
                break;
            case 'gif':
                imagegif($image);
                break;
            case 'bmp':
                imagebmp($image);
                break;
            case 'webp':
                imagewebp($image);
                break;
            default:
                imagepng($image);
        }
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * Build the place of an image which lives in the given context, as originals::resolve_place() would describe it.
     *
     * @param \context $context The context.
     * @param string $component The component.
     * @param string $filearea The file area.
     * @param int $itemid The item id.
     * @return array The place.
     */
    public function build_place(
        \context $context,
        string $component = self::AREA_COMPONENT,
        string $filearea = self::AREA_FILEAREA,
        int $itemid = 0
    ): array {
        return [
            'contextid' => $context->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
        ];
    }

    /**
     * Build the place of an image which is nothing but a draft yet, as originals::resolve_place() would describe it.
     *
     * @param \context_user|null $usercontext The user context of the uploading user, or null for the current user.
     * @param int|null $draftitemid The draft item id, or null for a fresh one.
     * @return array The place.
     */
    public function build_draft_place(?\context_user $usercontext = null, ?int $draftitemid = null): array {
        global $USER;

        return $this->build_place(
            $usercontext ?? \context_user::instance($USER->id),
            'user',
            'draft',
            $draftitemid ?? file_get_unused_draft_itemid()
        );
    }

    /**
     * Store a file in a (non draft) file area, as an image picker field holds it after its form was saved.
     *
     * @param \context $context The context of the file area.
     * @param string $filename The file name.
     * @param string $content The file content.
     * @param array $extra Further fields of the file record (such as 'itemid', 'filearea', 'author' or 'license').
     * @return \stored_file
     */
    public function create_area_file(\context $context, string $filename, string $content, array $extra = []): \stored_file {
        return get_file_storage()->create_file_from_string($extra + [
            'contextid' => $context->id,
            'component' => self::AREA_COMPONENT,
            'filearea' => self::AREA_FILEAREA,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Get the (single) file in the root of the default file area in the given context.
     *
     * @param \context $context The context of the file area.
     * @param int $itemid The item id.
     * @return \stored_file|null The file, or null if the area is empty.
     */
    public function get_area_file(\context $context, int $itemid = 0): ?\stored_file {
        return originals::get_root_file($context->id, self::AREA_COMPONENT, self::AREA_FILEAREA, $itemid);
    }

    /**
     * Store a file in a draft area of the current user which does not come from any file area, as a fresh upload would be.
     *
     * @param string $filename The file name.
     * @param string $content The file content.
     * @param array $extra Further fields of the file record (such as 'itemid' to put the file into an existing draft area,
     *                     'filepath', 'source', 'author' or 'license').
     * @return \stored_file The draft file.
     */
    public function create_draft_file(string $filename, string $content, array $extra = []): \stored_file {
        global $USER;

        return get_file_storage()->create_file_from_string($extra + [
            'contextid' => \context_user::instance($USER->id)->id,
            'userid' => $USER->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Prepare a draft area of the current user from a file area, as a form does when it is opened for editing.
     *
     * This is the round trip which makes core record the source reference in the draft files, from which the plugin reads
     * where an image lives.
     *
     * @param \context $context The context of the file area.
     * @param string $component The component.
     * @param string $filearea The file area.
     * @param int $itemid The item id.
     * @return int The draft item id.
     */
    public function prepare_draft_area(
        \context $context,
        string $component = self::AREA_COMPONENT,
        string $filearea = self::AREA_FILEAREA,
        int $itemid = 0
    ): int {
        $draftitemid = 0;
        file_prepare_draft_area($draftitemid, $context->id, $component, $filearea, $itemid);

        return $draftitemid;
    }

    /**
     * Get the (single) file in the root of a draft area of the current user.
     *
     * @param int $draftitemid The draft item id.
     * @return \stored_file|null The draft file, or null if the area is empty.
     */
    public function get_draft_file(int $draftitemid): ?\stored_file {
        global $USER;

        return originals::get_root_file(\context_user::instance($USER->id)->id, 'user', 'draft', $draftitemid);
    }

    /**
     * Prepare a draft area of the current user from a file area and return the file which ended up in it.
     *
     * @param \context $context The context of the file area.
     * @param string $component The component.
     * @param string $filearea The file area.
     * @param int $itemid The item id.
     * @return \stored_file The draft file.
     */
    public function prepare_draft_file(
        \context $context,
        string $component = self::AREA_COMPONENT,
        string $filearea = self::AREA_FILEAREA,
        int $itemid = 0
    ): \stored_file {
        return $this->get_draft_file($this->prepare_draft_area($context, $component, $filearea, $itemid));
    }

    /**
     * Preserve an original for an image in the given context, the way the first crop does it.
     *
     * The image is stored in its file area, edited through a draft area of the current user, preserved as the original, and
     * replaced by the cropped image (as saving the form does it). Afterwards, the file area holds the cropped image and the
     * record points at it.
     *
     * @param \context $context The context of the file area.
     * @param string $filename The file name of the original.
     * @param string $original The content of the original.
     * @param string $croppedfilename The file name of the cropped image.
     * @param string $cropped The content of the cropped image.
     * @param array|null $crop The crop region as fractions (keys 'x', 'y', 'width' and 'height'), or null for none.
     * @return stdClass The preserved original record.
     */
    public function preserve_cropped(
        \context $context,
        string $filename,
        string $original,
        string $croppedfilename,
        string $cropped,
        ?array $crop = null
    ): stdClass {
        $areafile = $this->create_area_file($context, $filename, $original);
        $draftfile = $this->prepare_draft_file($context);

        $record = originals::ensure_original($draftfile, originals::resolve_place($draftfile));

        // The cropped image takes the place of the original in the file area.
        $areafile->delete();
        $croppedfile = $this->create_area_file($context, $croppedfilename, $cropped);
        originals::set_derived($record->id, $croppedfile->get_contenthash(), $crop);

        return $record;
    }

    /**
     * Put a fixture image into the file area of a field of the demo page, as it sits there after the demo form was saved.
     *
     * This is what the Behat generator entity "tool_imagepicker > image" creates. Anything which sits in the root of
     * that file area already is removed first, as the field holds a single image.
     *
     * @param array $data The entity data: 'field' (a key of \tool_imagepicker\form\demo_form::FIELDS) and 'filepath' (the
     *                    image, relative to the Moodle root directory), optionally 'author' and 'license' (the license
     *                    shortname) of the image.
     * @return \stored_file The stored image.
     */
    public function create_image(array $data): \stored_file {
        global $CFG;

        $fields = \tool_imagepicker\form\demo_form::FIELDS;
        if (!isset($fields[$data['field']])) {
            throw new coding_exception('Unknown demo form field: ' . $data['field']);
        }
        $filepath = $CFG->dirroot . '/' . ltrim($data['filepath'], '/');
        if (!is_readable($filepath)) {
            throw new coding_exception('Fixture image not found: ' . $data['filepath']);
        }

        $context = \context_system::instance();
        $itemid = $fields[$data['field']];
        $filearea = \tool_imagepicker\form\demo_form::FILEAREA;
        foreach (originals::get_root_files($context->id, self::AREA_COMPONENT, $filearea, $itemid) as $file) {
            $file->delete();
        }

        $filerecord = [
            'contextid' => $context->id,
            'component' => self::AREA_COMPONENT,
            'filearea' => \tool_imagepicker\form\demo_form::FILEAREA,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => basename($filepath),
        ];

        // The author and the license are optional, as they are for an uploaded file.
        foreach (['author', 'license'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $filerecord[$field] = $data[$field];
            }
        }

        return get_file_storage()->create_file_from_pathname($filerecord, $filepath);
    }
}
