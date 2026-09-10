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
 * Admin tool "Image Picker" - Tests for the external function which replaces the image in a draft area.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\external;

use tool_imagepicker_generator as generator;
use core_external\external_api;
use tool_imagepicker\local\originals;

/**
 * Tests for the external function which replaces the image in a draft area.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\external\replace_draft_image
 */
final class replace_draft_image_test extends \advanced_testcase {
    /** @var generator The plugin generator. */
    private generator $generator;

    /**
     * Set up the plugin generator before each test.
     *
     * Loading it here rather than on first use also makes its class constants available throughout the test, as the
     * generator class is not autoloaded.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('tool_imagepicker');
    }

    /**
     * Store an image in a file area of a new course and prepare a draft area from it, as an edit form would.
     *
     * @param string $content The image content.
     * @param \context|null $context The context of the file area, or null for a new course.
     * @return int The draft item id.
     */
    private function prepare_draft_from_area(string $content, ?\context $context = null): int {
        $context = $context ?? \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', $content);

        return $this->generator->prepare_draft_area($context);
    }

    /**
     * Call the external function with the given image content.
     *
     * @param int $draftitemid The draft item id.
     * @param string $content The image content.
     * @param array $acceptedtypes The accepted file types.
     * @return array The cleaned return value.
     */
    private function replace(int $draftitemid, string $content, array $acceptedtypes = ['.png']): array {
        $result = replace_draft_image::execute($draftitemid, base64_encode($content), $acceptedtypes, 0.1, 0.1, 0.5, 0.5);

        return external_api::clean_returnvalue(replace_draft_image::execute_returns(), $result);
    }

    /**
     * Call the external function and assert that it refuses the request with the given error.
     *
     * @param int $draftitemid The draft item id.
     * @param string $filecontent The (already encoded) file content parameter.
     * @param string $errorcode The expected error code.
     * @param array $acceptedtypes The accepted file types.
     * @return \moodle_exception The exception, for further inspection.
     */
    private function assert_refused(
        int $draftitemid,
        string $filecontent,
        string $errorcode,
        array $acceptedtypes = ['.png']
    ): \moodle_exception {
        try {
            replace_draft_image::execute($draftitemid, $filecontent, $acceptedtypes, 0.1, 0.1, 0.5, 0.5);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertSame('tool_imagepicker', $e->module);

            return $e;
        }

        $this->fail('The request was not refused.');
    }

    /**
     * Assert that the draft area of the current user still holds exactly the given image, and that no original was preserved.
     *
     * @param int $draftitemid The draft item id.
     * @param string $content The image content which the draft area is expected to hold.
     */
    private function assert_untouched(int $draftitemid, string $content): void {
        global $DB, $USER;

        $files = originals::get_root_files(\context_user::instance($USER->id)->id, 'user', 'draft', $draftitemid);
        $this->assertCount(1, $files);
        $this->assertSame(sha1($content), reset($files)->get_contenthash());
        $this->assertEquals(0, $DB->count_records('tool_imagepicker_original'));
    }

    /**
     * Cropping an image a second time within the same form session keeps cropping from the original which was preserved
     * on the first crop, rather than treating the first crop as a new original.
     */
    public function test_a_second_crop_in_the_same_session_keeps_the_original(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $original = $this->generator->build_image(red: 10);
        $firstcrop = $this->generator->build_image(red: 20);
        $secondcrop = $this->generator->build_image(red: 30);

        $draftitemid = $this->prepare_draft_from_area($original);

        // First crop: the image is preserved as the original.
        $this->replace($draftitemid, $firstcrop);

        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
        $record = $DB->get_record('tool_imagepicker_original', []);
        $this->assertSame(sha1($original), originals::get_file($record)->get_contenthash());
        $this->assertSame(sha1($firstcrop), $record->derivedhash);

        // The draft file still knows where it came from, which is what ties it to its original.
        $draftfile = $this->generator->get_draft_file($draftitemid);
        $this->assertSame(sha1($firstcrop), $draftfile->get_contenthash());
        $place = originals::resolve_place($draftfile);
        $this->assertSame(generator::AREA_COMPONENT, $place['component']);

        // Second crop, without the form having been saved in between.
        $this->replace($draftitemid, $secondcrop);

        // There is still exactly one original, it is still the uncropped image, and it now backs the second crop.
        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
        $record = $DB->get_record('tool_imagepicker_original', []);
        $this->assertSame(sha1($original), originals::get_file($record)->get_contenthash());
        $this->assertSame(sha1($secondcrop), $record->derivedhash);

        // The draft file which is left in the draft area is the second crop, and it still names its place.
        $draftfile = $this->generator->get_draft_file($draftitemid);
        $this->assertSame(sha1($secondcrop), $draftfile->get_contenthash());
        $this->assertSame(generator::AREA_COMPONENT, originals::resolve_place($draftfile)['component']);
    }

    /**
     * The cropped image takes over the author and the license of the image it was cropped from, and it is reported under a
     * draft file URL.
     */
    public function test_the_cropped_image_keeps_author_and_license(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $draftitemid = $this->generator->create_draft_file('fresh.png', $this->generator->build_image(red: 10), [
            'author' => 'Jane Doe',
            'license' => 'cc-4.0',
        ])->get_itemid();
        $usercontextid = \context_user::instance($USER->id)->id;

        $result = $this->replace($draftitemid, $this->generator->build_image(red: 20));

        $this->assertSame('fresh.png', $result['filename']);
        $this->assertStringEndsWith("/draftfile.php/{$usercontextid}/user/draft/{$draftitemid}/fresh.png", $result['url']);
        $draftfile = $this->generator->get_draft_file($draftitemid);
        $this->assertSame('Jane Doe', $draftfile->get_author());
        $this->assertSame('cc-4.0', $draftfile->get_license());
        $this->assertEquals($USER->id, $draftfile->get_userid());
    }

    /**
     * Cropping a fresh upload with original preservation enabled keeps the original with the user, tied to the draft area.
     */
    public function test_a_fresh_upload_is_preserved_with_the_draft(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $original = $this->generator->build_image(red: 10);
        $draftitemid = $this->generator->create_draft_file('fresh.png', $original)->get_itemid();
        $usercontextid = \context_user::instance($USER->id)->id;

        $this->replace($draftitemid, $this->generator->build_image(red: 20));
        $this->replace($draftitemid, $this->generator->build_image(red: 30));

        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
        $record = $DB->get_record('tool_imagepicker_original', []);
        $this->assertEquals($usercontextid, $record->contextid);
        $this->assertSame('user', $record->component);
        $this->assertSame('draft', $record->filearea);
        $this->assertEquals($draftitemid, $record->itemid);
        $this->assertSame(sha1($original), originals::get_file($record)->get_contenthash());
        $this->assertSame(sha1($this->generator->build_image(red: 30)), $record->derivedhash);
    }

    /**
     * Without original preservation, the image is simply replaced: nothing is preserved and no crop region is stored, and
     * the region which the client sends is accepted all the same.
     */
    public function test_nothing_is_preserved_while_the_feature_is_disabled(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $draftitemid = $this->prepare_draft_from_area($this->generator->build_image(red: 10));

        $this->replace($draftitemid, $this->generator->build_image(red: 20));

        $this->assertEquals(0, $DB->count_records('tool_imagepicker_original'));
        $this->assertEquals(0, $DB->count_records('files', ['component' => 'tool_imagepicker', 'filearea' => 'original']));
        $this->assertSame(
            sha1($this->generator->build_image(red: 20)),
            $this->generator->get_draft_file($draftitemid)->get_contenthash()
        );
    }

    /**
     * Cropping an image whose original was preserved while the image was nothing but a draft settles that original into
     * the place the image has been saved to in the meantime, no matter who crops it there.
     */
    public function test_a_draft_placed_original_is_adopted_by_the_next_crop(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $uploader = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();
        $original = $this->generator->build_image(red: 10);
        $firstcrop = $this->generator->build_image(red: 20);

        // The uploader crops a fresh upload.
        $this->setUser($uploader);
        $draftitemid = $this->generator->create_draft_file('image.png', $original)->get_itemid();
        $this->replace($draftitemid, $firstcrop);
        $record = $DB->get_record('tool_imagepicker_original', []);
        $this->assertTrue(originals::is_draft_place((array) $record));

        // The form was saved, and the editor crops the image again from the course it now lives in.
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->setUser($editor);
        $draftitemid = $this->prepare_draft_from_area($firstcrop, $context);
        $this->replace($draftitemid, $this->generator->build_image(red: 30));

        // The same original now backs the second crop from the place of the image, and its file has moved along.
        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
        $record = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);
        $this->assertEquals($context->id, $record->contextid);
        $this->assertSame(generator::AREA_COMPONENT, $record->component);
        $this->assertSame(sha1($this->generator->build_image(red: 30)), $record->derivedhash);
        $original = originals::get_file($record);
        $this->assertEquals($context->id, $original->get_contextid());
        $this->assertSame(sha1($this->generator->build_image(red: 10)), $original->get_contenthash());
    }

    /**
     * Without an image in the draft area there is nothing to crop.
     */
    public function test_an_empty_draft_area_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cropped = base64_encode($this->generator->build_image(red: 20));
        $this->assert_refused(file_get_unused_draft_itemid(), $cropped, 'error_noimagetocrop');
    }

    /**
     * The draft area is always the current user's own: the draft area of another user cannot be reached, whatever item id is
     * requested, so the other user's image is neither read nor replaced.
     */
    public function test_the_draft_area_of_another_user_cannot_be_reached(): void {
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $original = $this->generator->build_image(red: 10);

        $this->setUser($owner);
        $draftitemid = $this->generator->create_draft_file('image.png', $original)->get_itemid();

        $this->setUser($other);
        $this->assert_refused($draftitemid, base64_encode($this->generator->build_image(red: 20)), 'error_noimagetocrop');

        $this->setUser($owner);
        $this->assert_untouched($draftitemid, $original);
    }

    /**
     * Data provider: file content parameters which do not hold an image.
     *
     * @return array
     */
    public static function invalid_content_provider(): array {
        return [
            'not base64' => ['this is not base64!!'],
            'empty' => [''],
            'base64 of text' => [base64_encode('hello world')],
            'base64 of an SVG' => [base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"></svg>')],
            'base64 of a truncated PNG' => [base64_encode("\x89PNG\r\n\x1a\n")],
        ];
    }

    /**
     * Content which is not a readable raster image is refused, and the image in the draft area is left untouched.
     *
     * @param string $filecontent The file content parameter.
     * @dataProvider invalid_content_provider
     */
    public function test_invalid_image_content_is_refused(string $filecontent): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $original = $this->generator->build_image(red: 10);
        $draftitemid = $this->generator->create_draft_file('image.png', $original)->get_itemid();

        $this->assert_refused($draftitemid, $filecontent, 'error_invalidimagecontent');
        $this->assert_untouched($draftitemid, $original);
    }

    /**
     * Data provider: image types which a browser canvas cannot have produced.
     *
     * @return array
     */
    public static function unencodable_type_provider(): array {
        return [
            'GIF' => ['gif'],
            'BMP' => ['bmp'],
        ];
    }

    /**
     * An image of a type which a browser canvas cannot encode is refused (it cannot have come from the cropper), and the
     * image in the draft area is left untouched.
     *
     * @param string $type The image type.
     * @dataProvider unencodable_type_provider
     */
    public function test_an_unencodable_image_type_is_refused(string $type): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $original = $this->generator->build_image(red: 10);
        $draftitemid = $this->generator->create_draft_file('image.png', $original)->get_itemid();
        $content = $this->generator->build_image(4, 4, $type);

        $this->assert_refused($draftitemid, base64_encode($content), 'error_invalidimagetype', ['web_image']);
        $this->assert_untouched($draftitemid, $original);
    }

    /**
     * Data provider: accepted file types of the form element, and whether a cropped PNG passes them.
     *
     * @return array
     */
    public static function accepted_types_provider(): array {
        return [
            'the extension' => [['.png'], true],
            'the extension among others' => [['.jpg', '.png'], true],
            'the mime type' => [['image/png'], true],
            'the web image group' => [['web_image'], true],
            'the image group' => [['image'], true],
            'everything' => [['*'], true],
            'nothing at all' => [[], true],
            'another extension' => [['.jpg'], false],
            'another mime type' => [['image/jpeg'], false],
            'another group' => [['document'], false],
        ];
    }

    /**
     * The cropped image has to be of a file type which the form element accepts. The accepted types are given as the form
     * element gives them (as extensions, mime types or groups), and an empty list means no restriction at all.
     *
     * Note that a forged request could claim to accept anything, but all that could achieve is to store, say, a PNG in a
     * field which only asked for JPEGs - the file is named after what the content really is, never after the claim.
     *
     * @param array $acceptedtypes The accepted file types.
     * @param bool $accepted Whether a PNG passes them.
     * @dataProvider accepted_types_provider
     */
    public function test_the_cropped_image_has_to_be_of_an_accepted_type(array $acceptedtypes, bool $accepted): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $original = $this->generator->build_image(red: 10);
        $cropped = $this->generator->build_image(red: 20);
        $draftitemid = $this->generator->create_draft_file('image.png', $original)->get_itemid();

        if ($accepted) {
            $result = $this->replace($draftitemid, $cropped, $acceptedtypes);
            $this->assertSame('image.png', $result['filename']);
            $this->assertSame(sha1($cropped), $this->generator->get_draft_file($draftitemid)->get_contenthash());
        } else {
            $this->assert_refused($draftitemid, base64_encode($cropped), 'error_invalidimagetype', $acceptedtypes);
            $this->assert_untouched($draftitemid, $original);
        }
    }

    /**
     * A cropped image which is larger than the user may upload is refused before anything is changed, no matter which
     * strategy the client is configured to reduce it with (the server does not know about strategies, it only knows the
     * limit). A user who may ignore file size limits is not limited.
     */
    public function test_a_cropped_image_which_exceeds_the_size_limit_is_refused(): void {
        global $CFG;

        $this->resetAfterTest();
        set_config('preserveoriginals', 1, 'tool_imagepicker');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $original = $this->generator->build_image(red: 10);
        $draftitemid = $this->generator->create_draft_file('image.png', $original)->get_itemid();
        $cropped = $this->generator->build_noisy_image(3000);

        // A limit below the size of the cropped image.
        $CFG->maxbytes = 2000;
        $exception = $this->assert_refused($draftitemid, base64_encode($cropped), 'error_imagetoolarge');
        $this->assertSame(display_size(2000), $exception->a);
        $this->assert_untouched($draftitemid, $original);

        // The same with the other reduction strategy configured: the server does not care.
        set_config('sizelimitstrategy', 'lowerquality', 'tool_imagepicker');
        $this->assert_refused($draftitemid, base64_encode($cropped), 'error_imagetoolarge');
        $this->assert_untouched($draftitemid, $original);

        // A limit one byte below the size of the cropped image.
        $CFG->maxbytes = strlen($cropped) - 1;
        $this->assert_refused($draftitemid, base64_encode($cropped), 'error_imagetoolarge');
        $this->assert_untouched($draftitemid, $original);

        // A limit of exactly the size of the cropped image.
        $CFG->maxbytes = strlen($cropped);
        $this->replace($draftitemid, $cropped);
        $this->assertSame(sha1($cropped), $this->generator->get_draft_file($draftitemid)->get_contenthash());

        // A user who may ignore file size limits (such as an admin) is not limited at all.
        $CFG->maxbytes = 2000;
        $this->setAdminUser();
        $draftitemid = $this->generator->create_draft_file('image.png', $original)->get_itemid();
        $this->replace($draftitemid, $cropped);
        $this->assertSame(sha1($cropped), $this->generator->get_draft_file($draftitemid)->get_contenthash());
    }

    /**
     * For an image which lives in a course, the upload limit of that course applies as well - while a fresh upload (which is
     * not tied to any course yet) is only subject to the site limit.
     */
    public function test_the_size_limit_of_the_course_applies_to_an_image_which_lives_there(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->maxbytes = 100000;

        $original = $this->generator->build_image(red: 10);
        $cropped = $this->generator->build_noisy_image(3000);
        $course = $this->getDataGenerator()->create_course(['maxbytes' => 2000]);
        $context = \context_course::instance($course->id);

        $draftitemid = $this->prepare_draft_from_area($original, $context);
        $this->assert_refused($draftitemid, base64_encode($cropped), 'error_imagetoolarge');
        $this->assert_untouched($draftitemid, $original);

        $draftitemid = $this->generator->create_draft_file('fresh.png', $original)->get_itemid();
        $this->replace($draftitemid, $cropped);
        $this->assertSame(sha1($cropped), $this->generator->get_draft_file($draftitemid)->get_contenthash());
    }

    /**
     * The image picker is a single-file field, so whatever else sits in the root of the draft area is replaced along with
     * the image - while files in subdirectories (which the field never produces) are left alone.
     */
    public function test_the_cropped_image_replaces_everything_in_the_root_of_the_draft_area(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $usercontextid = \context_user::instance($USER->id)->id;

        $draftitemid = $this->generator->create_draft_file('image.png', $this->generator->build_image(red: 10))->get_itemid();
        $this->generator->create_draft_file('other.png', $this->generator->build_image(red: 15), ['itemid' => $draftitemid]);
        $this->generator->create_draft_file('nested.png', $this->generator->build_image(red: 16), [
            'itemid' => $draftitemid,
            'filepath' => '/sub/',
        ]);

        $result = $this->replace($draftitemid, $this->generator->build_image(red: 20));

        // Which of the two root files is "the image" is decided by name order, so the first one gives the cropped image its
        // name.
        $this->assertSame('image.png', $result['filename']);
        $rootfiles = originals::get_root_files($usercontextid, 'user', 'draft', $draftitemid);
        $this->assertCount(1, $rootfiles);
        $this->assertSame(sha1($this->generator->build_image(red: 20)), reset($rootfiles)->get_contenthash());
        $nested = get_file_storage()->get_file($usercontextid, 'user', 'draft', $draftitemid, '/sub/', 'nested.png');
        $this->assertNotEmpty($nested);
    }

    /**
     * Data provider: crop regions which are not acceptable.
     *
     * @return array
     */
    public static function invalid_crop_region_provider(): array {
        return [
            'incomplete region' => [0.1, 0.1, 0.5, null],
            'position below zero' => [-0.1, 0.1, 0.5, 0.5],
            'position above one' => [0.1, 1.1, 0.5, 0.5],
            'width above one' => [0.1, 0.1, 1.5, 0.5],
            'empty region' => [0.1, 0.1, 0, 0.5],
            'reaching beyond the right edge' => [0.6, 0.1, 0.5, 0.5],
            'reaching beyond the bottom edge' => [0.1, 0.6, 0.5, 0.5],
        ];
    }

    /**
     * A crop region which is incomplete or which does not lie within the image is refused before anything is changed.
     *
     * @param float|null $x The x position of the region.
     * @param float|null $y The y position of the region.
     * @param float|null $width The width of the region.
     * @param float|null $height The height of the region.
     * @dataProvider invalid_crop_region_provider
     */
    public function test_an_invalid_crop_region_is_refused(?float $x, ?float $y, ?float $width, ?float $height): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $original = $this->generator->build_image(red: 10);
        $draftitemid = $this->prepare_draft_from_area($original);

        // The region is refused with a message of its own, which tells the user what is wrong even if debugging is off (an
        // invalid parameter exception would not), and the details are left to the debug information.
        try {
            $cropped = base64_encode($this->generator->build_image(red: 20));
            replace_draft_image::execute($draftitemid, $cropped, ['.png'], $x, $y, $width, $height);
            $this->fail('The crop region was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertNotInstanceOf(\invalid_parameter_exception::class, $e);
            $this->assertSame('error_invalidcropregion', $e->errorcode);
            $this->assertSame('tool_imagepicker', $e->module);
            $this->assertStringContainsString(get_string('error_invalidcropregion', 'tool_imagepicker'), $e->getMessage());
            $this->assertStringStartsWith('Crop region is ', $e->debuginfo);
        }
        $this->assert_untouched($draftitemid, $original);
    }

    /**
     * A crop region which reaches a hair beyond the image (which is what rounding on the client can produce for a region
     * that ends at the edge of the image) is accepted and trimmed to the image. A region can also be left out altogether.
     */
    public function test_a_crop_region_at_the_edge_is_trimmed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $draftitemid = $this->prepare_draft_from_area($this->generator->build_image(red: 10));

        $cropped = base64_encode($this->generator->build_image(red: 20));
        replace_draft_image::execute($draftitemid, $cropped, ['.png'], 0.3, 0.4, 0.7000000001, 0.6);

        $record = $DB->get_record('tool_imagepicker_original', []);
        $this->assertEqualsWithDelta(0.3, $record->cropx, 0.000001);
        $this->assertEqualsWithDelta(0.4, $record->cropy, 0.000001);
        $this->assertEqualsWithDelta(0.7, $record->cropwidth, 0.000001);
        $this->assertEqualsWithDelta(0.6, $record->cropheight, 0.000001);

        // Without a region, the stored region is left as it is.
        replace_draft_image::execute($draftitemid, base64_encode($this->generator->build_image(red: 30)), ['.png']);

        $record = $DB->get_record('tool_imagepicker_original', []);
        $this->assertEqualsWithDelta(0.3, $record->cropx, 0.000001);
        $this->assertEqualsWithDelta(0.7, $record->cropwidth, 0.000001);
    }

    /**
     * Data provider: source file names, the type of the cropped image, and the resulting file name.
     *
     * @return array
     */
    public static function cropped_filename_provider(): array {
        return [
            'same type' => ['photo.png', 'png', 'photo.png'],
            'same type in upper case' => ['PHOTO.PNG', 'png', 'PHOTO.PNG'],
            'same type in mixed case' => ['Photo.Png', 'png', 'Photo.Png'],
            'jpeg spelled jpg' => ['photo.jpg', 'jpg', 'photo.jpg'],
            'jpeg spelled jpeg' => ['photo.jpeg', 'jpg', 'photo.jpeg'],
            'jpeg spelled jpe' => ['photo.jpe', 'jpg', 'photo.jpe'],
            'jpeg spelled in upper case' => ['PHOTO.JPG', 'jpg', 'PHOTO.JPG'],
            'type changed' => ['photo.gif', 'png', 'photo.png'],
            'type changed from upper case' => ['PHOTO.GIF', 'png', 'PHOTO.png'],
            'type changed to jpeg' => ['photo.png', 'jpg', 'photo.jpg'],
            'several dots' => ['my.holiday.photo.gif', 'png', 'my.holiday.photo.png'],
            'no extension' => ['photo', 'png', 'photo.png'],
        ];
    }

    /**
     * The cropped image keeps the name of the image it was cropped from, and it is only ever renamed if its type has
     * changed - a spelling or a case of the extension which fits the type already is left alone.
     *
     * @param string $sourcefilename The file name of the image in the draft area.
     * @param string $type The type of the cropped image which the client sends ('png' or 'jpg').
     * @param string $expected The file name under which the cropped image is stored.
     * @dataProvider cropped_filename_provider
     */
    public function test_the_cropped_image_is_named_after_its_source(string $sourcefilename, string $type, string $expected): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $draftitemid = $this->generator->create_draft_file($sourcefilename, $this->generator->build_image(red: 10))->get_itemid();
        $usercontextid = \context_user::instance($USER->id)->id;

        $content = $this->generator->build_image(4, 4, $type, 20);
        $result = $this->replace($draftitemid, $content, ['web_image']);

        $this->assertSame($expected, $result['filename']);
        $this->assertStringEndsWith('/' . rawurlencode($expected), $result['url']);

        // The cropped image is the only file left in the draft area, under the new name.
        $files = originals::get_root_files($usercontextid, 'user', 'draft', $draftitemid);
        $this->assertCount(1, $files);
        $this->assertSame($expected, reset($files)->get_filename());
        $this->assertSame(sha1($content), reset($files)->get_contenthash());
    }
}
