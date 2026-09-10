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
 * Admin tool "Image Picker" - Tests for the external function which gets the image in a draft area.
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
 * Tests for the external function which gets the image in a draft area.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\external\get_draft_image
 */
final class get_draft_image_test extends \advanced_testcase {
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
     * Call the external function.
     *
     * @param int $draftitemid The draft item id.
     * @return array The cleaned return value.
     */
    private function get(int $draftitemid): array {
        $result = get_draft_image::execute($draftitemid);

        return external_api::clean_returnvalue(get_draft_image::execute_returns(), $result);
    }

    /**
     * Store an image in a file area of a new course, prepare a draft area from it and crop it once, so that an original is
     * preserved (if the preservation of originals is enabled).
     *
     * @param string $filename The file name of the image.
     * @param string $content The content of the image.
     * @param array|null $crop The crop region to store (x, y, width, height), or null to store none.
     * @return int The draft item id.
     */
    private function crop_once(string $filename, string $content, ?array $crop = [0.25, 0.25, 0.5, 0.5]): int {
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, $filename, $content);
        $draftitemid = $this->generator->prepare_draft_area($context);

        // The cropped image is always a PNG here, so cropping a GIF changes the type of the file.
        $cropped = base64_encode($this->generator->build_image(4, 4, 'png', 20));
        replace_draft_image::execute($draftitemid, $cropped, ['web_image'], ...($crop ?? []));

        return $draftitemid;
    }

    /**
     * An empty draft area yields no image and no size limit.
     */
    public function test_an_empty_draft_area_yields_nothing(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = $this->get(file_get_unused_draft_itemid());

        $this->assertSame('', $result['url']);
        $this->assertSame('', $result['filename']);
        $this->assertArrayNotHasKey('maxbytes', $result);
        $this->assertArrayNotHasKey('crop', $result);
    }

    /**
     * A draft area which holds files in subdirectories only (which an image picker field never produces itself) holds no
     * image as far as the image picker is concerned.
     */
    public function test_a_draft_area_with_subdirectories_only_yields_nothing(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $draftfile = $this->generator->create_draft_file('nested.png', 'nested-content', ['filepath' => '/sub/']);

        $result = $this->get($draftfile->get_itemid());

        $this->assertSame('', $result['url']);
        $this->assertSame('', $result['filename']);
    }

    /**
     * The size limit which is reported for a draft image is the upload limit of the site for the current user, which is
     * what the replace_draft_image function enforces later on.
     */
    public function test_the_size_limit_is_reported(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        // A limit which is well below what PHP allows, so that it is the one which applies.
        $CFG->maxbytes = 12345;

        $result = $this->get($this->generator->create_draft_file('image.png', 'image-content')->get_itemid());

        $this->assertSame('image.png', $result['filename']);
        $this->assertSame(12345, $result['maxbytes']);
    }

    /**
     * For an image which lives in a course, the limit of that course is reported as well (whichever is smaller), as that is
     * what the replace_draft_image function enforces for it.
     */
    public function test_the_size_limit_of_the_course_is_reported(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->maxbytes = 100000;

        $course = $this->getDataGenerator()->create_course(['maxbytes' => 12345]);
        $context = \context_course::instance($course->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');

        $result = $this->get($this->generator->prepare_draft_area($context));
        $this->assertSame(12345, $result['maxbytes']);

        // A fresh upload is not tied to the course yet, so only the site limit applies to it.
        $result = $this->get($this->generator->create_draft_file('fresh.png', 'fresh-content')->get_itemid());
        $this->assertSame(100000, $result['maxbytes']);
    }

    /**
     * The image is served through the plugin (so that the browser may cache it) under a URL which names its content.
     */
    public function test_the_image_url_names_the_content(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $draftitemid = $this->generator->create_draft_file('image.png', 'image-content')->get_itemid();
        $usercontextid = \context_user::instance($USER->id)->id;
        $contenthash = sha1('image-content');

        $result = $this->get($draftitemid);

        $this->assertStringEndsWith(
            "/pluginfile.php/{$usercontextid}/tool_imagepicker/draft/{$draftitemid}/{$contenthash}/image.png",
            $result['url']
        );
    }

    /**
     * A user who may ignore file size limits (such as an admin) is reported no limit at all.
     */
    public function test_no_size_limit_is_reported_if_the_user_may_ignore_it(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setAdminUser();

        $CFG->maxbytes = 12345;

        $result = $this->get($this->generator->create_draft_file('image.png', 'image-content')->get_itemid());

        $this->assertSame(0, $result['maxbytes']);
    }

    /**
     * Once an original is preserved, the crop source is the original (served from its own file area) and the last crop
     * region is reported. The name of the crop source is only reported if it differs from the name of the displayed file.
     */
    public function test_the_original_is_served_as_the_crop_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        // A crop which kept the type of the image: the source has the same name as the displayed file.
        $result = $this->get($this->crop_once('header.png', $this->generator->build_image()));

        $this->assertSame('header.png', $result['filename']);
        $this->assertStringContainsString('/pluginfile.php/', $result['url']);
        $this->assertStringContainsString('/tool_imagepicker/original/', $result['url']);
        $this->assertStringEndsWith('/header.png', $result['url']);
        $this->assertArrayNotHasKey('sourcefilename', $result);
        $this->assertEqualsWithDelta(0.25, $result['crop']['x'], 0.000001);
        $this->assertEqualsWithDelta(0.25, $result['crop']['y'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $result['crop']['width'], 0.000001);
        $this->assertEqualsWithDelta(0.5, $result['crop']['height'], 0.000001);

        // A crop which changed the type of the image: the source is named differently from the displayed file.
        $result = $this->get($this->crop_once('animated.gif', $this->generator->build_image(4, 4, 'gif')));

        $this->assertSame('animated.png', $result['filename']);
        $this->assertStringEndsWith('/animated.gif', $result['url']);
        $this->assertSame('animated.gif', $result['sourcefilename']);
    }

    /**
     * An original whose crop was stored without a region has no region to report.
     */
    public function test_no_crop_region_is_reported_if_none_is_stored(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $result = $this->get($this->crop_once('header.png', $this->generator->build_image(), null));

        $this->assertStringContainsString('/tool_imagepicker/original/', $result['url']);
        $this->assertArrayNotHasKey('crop', $result);
    }

    /**
     * If the preserved original file has gone missing, the displayed file is the crop source again - but the crop region is
     * still known and is reported.
     */
    public function test_the_displayed_file_is_the_crop_source_if_the_original_is_missing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $draftitemid = $this->crop_once('animated.gif', $this->generator->build_image(4, 4, 'gif'));
        originals::get_file($DB->get_record('tool_imagepicker_original', []))->delete();

        $result = $this->get($draftitemid);

        $this->assertSame('animated.png', $result['filename']);
        $this->assertStringContainsString('/tool_imagepicker/draft/', $result['url']);
        $this->assertStringEndsWith('/animated.png', $result['url']);
        $this->assertArrayNotHasKey('sourcefilename', $result);
        $this->assertEqualsWithDelta(0.5, $result['crop']['width'], 0.000001);
    }

    /**
     * While the preservation of originals is disabled, an original which was preserved earlier is not used: the displayed
     * file is the crop source, and no crop region is reported.
     */
    public function test_a_preserved_original_is_not_used_while_the_feature_is_disabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $draftitemid = $this->crop_once('header.png', $this->generator->build_image());
        set_config('preserveoriginals', 0, 'tool_imagepicker');

        $result = $this->get($draftitemid);

        $this->assertStringContainsString('/tool_imagepicker/draft/', $result['url']);
        $this->assertArrayNotHasKey('sourcefilename', $result);
        $this->assertArrayNotHasKey('crop', $result);
    }

    /**
     * Asking for the crop source settles an original which was preserved while its image was nothing but a draft: as soon
     * as the image is edited from the place it has been saved to, the original is adopted into that place - which moves the
     * original file into the context of the image, and is why the function is a write function.
     */
    public function test_a_draft_placed_original_is_adopted_into_the_place_of_its_image(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $uploader = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();
        $cropped = $this->generator->build_image(4, 4, 'png', 20);

        // The uploader crops a fresh upload, so its original is preserved with their draft.
        $this->setUser($uploader);
        $draftitemid = $this->generator->create_draft_file('image.png', $this->generator->build_image())->get_itemid();
        replace_draft_image::execute($draftitemid, base64_encode($cropped), ['web_image']);
        $record = $DB->get_record('tool_imagepicker_original', []);
        $this->assertTrue(originals::is_draft_place((array) $record));

        // The form was saved, so the cropped image now lives in a course, and another user opens the form there.
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', $cropped);
        $this->setUser($editor);

        $result = $this->get($this->generator->prepare_draft_area($context));

        $record = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);
        $this->assertEquals($context->id, $record->contextid);
        $this->assertSame(generator::AREA_COMPONENT, $record->component);
        $this->assertEquals($context->id, originals::get_file($record)->get_contextid());
        $this->assertStringContainsString(
            "/pluginfile.php/{$context->id}/tool_imagepicker/original/{$record->id}/",
            $result['url']
        );
    }
}
