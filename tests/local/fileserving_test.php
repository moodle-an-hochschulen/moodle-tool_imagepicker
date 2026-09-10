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
 * Admin tool "Image Picker" - Tests for the serving of the files of the plugin.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\local;

use tool_imagepicker_generator as generator;

/**
 * Tests for the serving of the files of the plugin.
 *
 * Sending a file ends the request, so what is tested here is the decision which file a request gets, if any. That is
 * everything tool_imagepicker_pluginfile() does apart from the sending itself.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\local\fileserving
 */
final class fileserving_test extends \advanced_testcase {
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
     * Preserve an original for an image in a new course, as the current user, and return its record.
     *
     * @param \context $context The context of the course.
     * @return \stdClass The preserved original record.
     */
    private function preserve_original(\context $context): \stdClass {
        $this->generator->create_area_file($context, 'image.png', 'image-content');
        $draftfile = $this->generator->prepare_draft_file($context);

        return originals::ensure_original($draftfile, originals::resolve_place($draftfile));
    }

    /**
     * Assert that a request resolves to the given file.
     *
     * @param \stored_file $expected The expected file.
     * @param bool $cacheable Whether the file is expected to be cacheable.
     * @param array|null $resolved The result of fileserving::resolve().
     */
    private function assert_resolves_to(\stored_file $expected, bool $cacheable, ?array $resolved): void {
        $this->assertNotNull($resolved);
        $this->assertEquals($expected->get_id(), $resolved['file']->get_id());
        $this->assertSame($cacheable, $resolved['cacheable']);
    }

    /**
     * The URL of a draft file is served by the plugin and names the content of the file.
     */
    public function test_the_draft_url_names_the_content(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $draftfile = $this->generator->create_draft_file('image.png', 'image-content');

        $url = fileserving::get_draft_url($draftfile)->out(false);

        $this->assertStringEndsWith(
            '/pluginfile.php/' . $draftfile->get_contextid() . '/tool_imagepicker/draft/' . $draftfile->get_itemid() .
                '/' . sha1('image-content') . '/image.png',
            $url
        );
    }

    /**
     * A draft file is served to its owner, cacheable, as long as the URL names its current content.
     */
    public function test_a_draft_file_is_served_to_its_owner(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $usercontext = \context_user::instance($owner->id);

        $draftfile = $this->generator->create_draft_file('image.png', 'image-content');
        $itemid = $draftfile->get_itemid();
        $hash = $draftfile->get_contenthash();

        $this->assert_resolves_to($draftfile, true, fileserving::resolve($usercontext, 'draft', [$itemid, $hash, 'image.png']));

        // Another content hash names another content, which this file is not (any more).
        $this->assertNull(fileserving::resolve($usercontext, 'draft', [$itemid, sha1('other-content'), 'image.png']));

        // A file which does not exist, and the directory entry of the area.
        $this->assertNull(fileserving::resolve($usercontext, 'draft', [$itemid, $hash, 'other.png']));
        $this->assertNull(fileserving::resolve($usercontext, 'draft', [$itemid, sha1(''), '.']));
        $this->assertNull(fileserving::resolve($usercontext, 'draft', [file_get_unused_draft_itemid(), $hash, 'image.png']));
    }

    /**
     * A draft file is served to nobody but its owner: not from another user's context, and not to another user.
     */
    public function test_a_draft_file_is_served_to_nobody_else(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $ownercontext = \context_user::instance($owner->id);

        $this->setUser($owner);
        $draftfile = $this->generator->create_draft_file('image.png', 'image-content');
        $args = [$draftfile->get_itemid(), $draftfile->get_contenthash(), 'image.png'];

        // The request has to name the owner's user context, no other context will do.
        $coursecontext = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->assertNull(fileserving::resolve($coursecontext, 'draft', $args));
        $this->assertNull(fileserving::resolve(\context_system::instance(), 'draft', $args));

        // Another user gets nothing from the owner's context, not even an admin.
        $this->setUser($other);
        $this->assertNull(fileserving::resolve($ownercontext, 'draft', $args));
        $this->setAdminUser();
        $this->assertNull(fileserving::resolve($ownercontext, 'draft', $args));
    }

    /**
     * A preserved original is served, cacheable, to a user who is editing the image it backs, and to an admin.
     */
    public function test_an_original_is_served_to_its_editors_and_to_admins(): void {
        $this->resetAfterTest();
        $editor = $this->getDataGenerator()->create_user();
        $coeditor = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($editor);
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->preserve_original($context);
        $original = originals::get_file($record);
        $args = [$record->id, 'image.png'];

        // The editor holds the image in a draft prepared from its place.
        $this->assert_resolves_to($original, true, fileserving::resolve($context, 'original', $args));

        // A co-editor gets the original as soon as they have opened the form as well.
        $this->setUser($coeditor);
        $this->assertNull(fileserving::resolve($context, 'original', $args));
        $this->generator->prepare_draft_file($context);
        $this->assert_resolves_to($original, true, fileserving::resolve($context, 'original', $args));

        // A stranger gets nothing, even if they hold the very same content in a draft of their own.
        $this->setUser($stranger);
        $this->generator->create_draft_file('image.png', 'image-content');
        $this->assertNull(fileserving::resolve($context, 'original', $args));

        // An admin gets every original, without holding any draft.
        $this->setAdminUser();
        $this->assert_resolves_to($original, true, fileserving::resolve($context, 'original', $args));
    }

    /**
     * A preserved original is only served from the context it is stored in, under its own name, from the root of its area.
     */
    public function test_an_original_is_only_served_from_where_it_is(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $othercontext = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->preserve_original($context);

        // A record which does not exist.
        $this->assertNull(fileserving::resolve($context, 'original', [$record->id + 1000, 'image.png']));

        // The record exists, but it is not stored in the requested context (which is what happens to a URL of an original
        // after the original has moved to the place of its image).
        $this->assertNull(fileserving::resolve($othercontext, 'original', [$record->id, 'image.png']));

        // A wrong file name, and a path below the root of the area.
        $this->assertNull(fileserving::resolve($context, 'original', [$record->id, 'other.png']));
        $this->assertNull(fileserving::resolve($context, 'original', [$record->id, 'sub', 'image.png']));
        $this->assertNull(fileserving::resolve($context, 'original', [$record->id]));

        // The record exists, but its file is gone.
        originals::get_file($record)->delete();
        $this->assertNull(fileserving::resolve($context, 'original', [$record->id, 'image.png']));
    }

    /**
     * A demo image is served to admins only, from the system context, and it is not cacheable.
     */
    public function test_a_demo_image_is_served_to_admins_only(): void {
        $this->resetAfterTest();
        $systemcontext = \context_system::instance();

        $demofile = get_file_storage()->create_file_from_string([
            'contextid' => $systemcontext->id,
            'component' => 'tool_imagepicker',
            'filearea' => 'demo',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => 'demo.png',
        ], 'demo-content');

        $this->setAdminUser();
        $this->assert_resolves_to($demofile, false, fileserving::resolve($systemcontext, 'demo', [1, 'demo.png']));
        $this->assertNull(fileserving::resolve($systemcontext, 'demo', [1, 'other.png']));
        $this->assertNull(fileserving::resolve($systemcontext, 'demo', [2, 'demo.png']));

        // Not from any other context.
        $coursecontext = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->assertNull(fileserving::resolve($coursecontext, 'demo', [1, 'demo.png']));

        // And not to anybody who is not an admin.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        fileserving::resolve($systemcontext, 'demo', [1, 'demo.png']);
    }

    /**
     * Nothing else is served.
     */
    public function test_nothing_else_is_served(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        get_file_storage()->create_file_from_string([
            'contextid' => \context_system::instance()->id,
            'component' => 'tool_imagepicker',
            'filearea' => 'somethingelse',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'file.png',
        ], 'content');

        $this->assertNull(fileserving::resolve(\context_system::instance(), 'somethingelse', [0, 'file.png']));
    }
}
