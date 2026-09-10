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
 * Admin tool "Image Picker" - Tests for the preserved originals handling.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\local;

use tool_imagepicker_generator as generator;

/**
 * Tests for the preserved originals handling.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\local\originals
 */
final class originals_test extends \advanced_testcase {
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
     * A draft file which was prepared from a file area names that area as its place.
     */
    public function test_resolve_place_reads_the_source_reference(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content', ['itemid' => 7]);

        $draftfile = $this->generator->prepare_draft_file(
            $context,
            generator::AREA_COMPONENT,
            generator::AREA_FILEAREA,
            7
        );
        $place = originals::resolve_place($draftfile);

        $this->assertEquals($context->id, $place['contextid']);
        $this->assertSame(generator::AREA_COMPONENT, $place['component']);
        $this->assertSame(generator::AREA_FILEAREA, $place['filearea']);
        $this->assertSame(7, $place['itemid']);
        $this->assertFalse(originals::is_draft_place($place));
    }

    /**
     * An item id of 0 is a perfectly normal item id and must not be mistaken for a missing place.
     */
    public function test_resolve_place_accepts_item_id_zero(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');

        $place = originals::resolve_place($this->generator->prepare_draft_file($context));

        $this->assertSame(0, $place['itemid']);
        $this->assertFalse(originals::is_draft_place($place));
    }

    /**
     * A freshly uploaded image has no place but its draft area, so that is what is returned.
     */
    public function test_resolve_place_returns_the_draft_area_for_a_fresh_upload(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $freshfile = $this->generator->create_draft_file('fresh.png', 'fresh-content');
        $place = originals::resolve_place($freshfile);

        $this->assertEquals(\context_user::instance($USER->id)->id, $place['contextid']);
        $this->assertSame('user', $place['component']);
        $this->assertSame('draft', $place['filearea']);
        $this->assertSame((int) $freshfile->get_itemid(), $place['itemid']);
        $this->assertTrue(originals::is_draft_place($place));
    }

    /**
     * Data provider: source fields of a draft file which do not name a place, and whether reading them is worth a
     * developer debugging message.
     *
     * @return array
     */
    public static function unusable_source_provider(): array {
        return [
            'no source at all' => ['', false],
            'a plain URL, as the file picker records it for a downloaded file' => ['https://example.com/image.png', false],
            'a serialized object without a reference' => [serialize((object) ['source' => 'somewhere']), false],
            'a serialized array rather than an object' => [serialize(['original' => 'abc']), false],
            'a serialized object of a class which is not allowed' => ['O:3:"foo":1:{s:8:"original";s:3:"abc";}', false],
            'a reference which is not base64' => [serialize((object) ['original' => 'not base64!!']), true],
            'a reference which does not unserialize' => [serialize((object) ['original' => base64_encode('garbage')]), true],
            'a reference without a context' => [serialize((object) ['original' => \file_storage::pack_reference([
                'contextid' => 0,
                'component' => 'tool_imagepicker',
                'filearea' => 'demo',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => 'image.png',
            ])]), false],
        ];
    }

    /**
     * A draft file whose source does not name a place (because it was not prepared from a file area, or because whatever
     * sits in its source field is not what core writes there) is treated as a fresh upload: its draft area is its place.
     *
     * @param string $source The source field of the draft file.
     * @param bool $debugging Whether a debugging message is expected.
     * @dataProvider unusable_source_provider
     */
    public function test_resolve_place_falls_back_to_the_draft_area_for_an_unusable_source(string $source, bool $debugging): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $draftfile = $this->generator->create_draft_file('image.png', 'image-content', ['source' => $source]);
        $place = originals::resolve_place($draftfile);

        if ($debugging) {
            $this->assertDebuggingCalled();
        } else {
            $this->assertDebuggingNotCalled();
        }
        $this->assertTrue(originals::is_draft_place($place));
        $this->assertEquals($draftfile->get_contextid(), $place['contextid']);
        $this->assertSame((int) $draftfile->get_itemid(), $place['itemid']);
    }

    /**
     * The first crop of an image preserves that image as the original and records where it lives.
     */
    public function test_ensure_original_stashes_the_file_and_records_the_place(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');
        $draftfile = $this->generator->prepare_draft_file($context);

        $record = originals::ensure_original($draftfile, originals::resolve_place($draftfile));

        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
        $this->assertEquals($context->id, $record->contextid);
        $this->assertSame(generator::AREA_COMPONENT, $record->component);
        $this->assertSame(generator::AREA_FILEAREA, $record->filearea);
        $this->assertEquals(0, $record->itemid);
        $this->assertSame($draftfile->get_contenthash(), $record->derivedhash);

        // The original file itself has been copied into the plugin's own file area, under the id of its record.
        $original = originals::get_file($record);
        $this->assertNotNull($original);
        $this->assertEquals($context->id, $original->get_contextid());
        $this->assertSame($draftfile->get_contenthash(), $original->get_contenthash());
    }

    /**
     * The first crop of a freshly uploaded image preserves the original in the user's own context, tied to the draft area.
     */
    public function test_ensure_original_keeps_a_fresh_upload_with_the_draft(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $freshfile = $this->generator->create_draft_file('fresh.png', 'fresh-content');
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));

        $usercontextid = \context_user::instance($USER->id)->id;
        $this->assertEquals($usercontextid, $record->contextid);
        $this->assertTrue(originals::is_draft_place((array) $record));
        $this->assertEquals($freshfile->get_itemid(), $record->itemid);
        $this->assertEquals($usercontextid, originals::get_file($record)->get_contextid());
    }

    /**
     * Cropping the same image in the same place again reuses the original which is preserved for it.
     */
    public function test_ensure_original_reuses_the_record_of_the_same_place(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');
        $draftfile = $this->generator->prepare_draft_file($context);
        $place = originals::resolve_place($draftfile);

        $first = originals::ensure_original($draftfile, $place);
        $second = originals::ensure_original($draftfile, $place);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
    }

    /**
     * Cropping a freshly uploaded image again (in the same draft area) reuses the original which is preserved for it.
     */
    public function test_ensure_original_reuses_the_record_of_the_same_draft_area(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $freshfile = $this->generator->create_draft_file('fresh.png', 'fresh-content');
        $place = originals::resolve_place($freshfile);

        $first = originals::ensure_original($freshfile, $place);
        $second = originals::ensure_original($freshfile, $place);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
    }

    /**
     * Two images with identical content in two different places each get an original of their own.
     *
     * This is what recording the place is for. Identical content in two places is not exotic at all - duplicating or
     * restoring a course produces exactly that - and identifying an original by content alone made the second image take
     * over the original of the first one, moving it out of the context of the image it actually belonged to.
     */
    public function test_identical_content_in_two_places_keeps_the_originals_apart(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        // Two courses, each holding an image with byte for byte the same content.
        $contextone = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $contexttwo = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($contextone, 'image.png', 'the-very-same-content');
        $this->generator->create_area_file($contexttwo, 'image.png', 'the-very-same-content');

        $draftone = $this->generator->prepare_draft_file($contextone);
        $drafttwo = $this->generator->prepare_draft_file($contexttwo);
        $this->assertSame($draftone->get_contenthash(), $drafttwo->get_contenthash());

        $recordone = originals::ensure_original($draftone, originals::resolve_place($draftone));
        $recordtwo = originals::ensure_original($drafttwo, originals::resolve_place($drafttwo));

        // Each image got an original of its own ...
        $this->assertNotEquals($recordone->id, $recordtwo->id);
        $this->assertEquals(2, $DB->count_records('tool_imagepicker_original'));

        // ... and each original stayed with the image it belongs to.
        $this->assertNotNull(originals::get_file($recordone));
        $this->assertNotNull(originals::get_file($recordtwo));
        $this->assertEquals($contextone->id, originals::get_file($recordone)->get_contextid());
        $this->assertEquals($contexttwo->id, originals::get_file($recordtwo)->get_contextid());
    }

    /**
     * Two fresh uploads with identical content in two different draft areas each get an original of their own, even if they
     * belong to the same user. A draft which happens to hold the same content is not the same image.
     */
    public function test_identical_content_in_two_draft_areas_keeps_the_originals_apart(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $freshone = $this->generator->create_draft_file('one.png', 'the-very-same-content');
        $freshtwo = $this->generator->create_draft_file('two.png', 'the-very-same-content');

        $recordone = originals::ensure_original($freshone, originals::resolve_place($freshone));
        $recordtwo = originals::ensure_original($freshtwo, originals::resolve_place($freshtwo));

        $this->assertNotEquals($recordone->id, $recordtwo->id);
        $this->assertEquals(2, $DB->count_records('tool_imagepicker_original'));
    }

    /**
     * A record which was written while its image was nothing but a draft is adopted into the real place as soon as that is
     * known - by whoever edits the image from there, which need not be the user who uploaded it.
     */
    public function test_a_draft_placed_record_is_adopted_once_the_real_place_is_known(): void {
        global $DB;

        $this->resetAfterTest();

        $uploader = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();
        $content = 'image-content';

        // The image is cropped while it is freshly uploaded, so it lives nowhere but in the draft area yet. The original is
        // preserved in the user context of the uploader, tied to the draft area.
        $this->setUser($uploader);
        $freshfile = $this->generator->create_draft_file('image.png', $content);
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));

        $uploadercontextid = \context_user::instance($uploader->id)->id;
        $this->assertTrue(originals::is_draft_place((array) $record));
        $this->assertEquals($uploadercontextid, $record->contextid);

        // The form has been submitted in the meantime, so the image now lives in a file area of its own. It is edited again
        // from there by another user, which is the first moment at which its place is known.
        $this->setUser($editor);
        $targetcontext = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($targetcontext, 'image.png', $content);
        $draftfile = $this->generator->prepare_draft_file($targetcontext);

        $adopted = originals::ensure_original($draftfile, originals::resolve_place($draftfile));

        // The very same original was adopted rather than a second one being created ...
        $this->assertEquals($record->id, $adopted->id);
        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
        $this->assertSame(generator::AREA_COMPONENT, $adopted->component);
        $this->assertSame(generator::AREA_FILEAREA, $adopted->filearea);
        $this->assertFalse(originals::is_draft_place((array) $adopted));

        // ... and its file has moved over to the context of the image it backs.
        $original = originals::get_file($adopted);
        $this->assertNotNull($original);
        $this->assertEquals($targetcontext->id, $original->get_contextid());
        $this->assertEmpty(
            get_file_storage()->get_area_files(
                $uploadercontextid,
                'tool_imagepicker',
                'original',
                $record->id,
                'id',
                false
            )
        );
    }

    /**
     * An original which already knows its real place is never handed over to another one.
     */
    public function test_a_placed_record_is_never_moved_to_another_place(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');
        $draftfile = $this->generator->prepare_draft_file($context);
        $record = originals::ensure_original($draftfile, originals::resolve_place($draftfile));

        $othercontext = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $otherplace = $this->generator->build_place($othercontext, generator::AREA_COMPONENT, 'elsewhere', 42);
        $result = originals::adopt_place($record, $otherplace);

        $this->assertEquals($context->id, $result->contextid);
        $this->assertSame(generator::AREA_FILEAREA, $result->filearea);
        $this->assertEquals($context->id, originals::get_file($result)->get_contextid());
    }

    /**
     * An original which is tied to a draft area is never handed to another draft area, not even one which holds the very same
     * content. This is what keeps a copy of a cropped image from opening up the original of that image.
     */
    public function test_a_draft_placed_record_is_never_handed_to_another_draft(): void {
        global $DB;

        $this->resetAfterTest();

        $uploader = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($uploader);
        $freshfile = $this->generator->create_draft_file('image.png', 'image-content');
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));

        // Somebody else uploads the same content into a draft area of their own and looks for an original for it.
        $this->setUser($stranger);
        $copy = $this->generator->create_draft_file('image.png', 'image-content');
        $place = originals::resolve_place($copy);

        $this->assertNull(originals::get_record_for($copy, $place));

        // Cropping the copy starts an original of its own instead of taking over the uploader's one.
        $other = originals::ensure_original($copy, $place);
        $this->assertNotEquals($record->id, $other->id);
        $this->assertEquals(2, $DB->count_records('tool_imagepicker_original'));
        $this->assertEquals($uploader->id, \context::instance_by_id($DB->get_field(
            'tool_imagepicker_original',
            'contextid',
            ['id' => $record->id]
        ))->instanceid);
    }

    /**
     * A record whose original file has gone missing is still adopted into the real place: the record is settled, there is
     * just no file to move along.
     */
    public function test_a_draft_placed_record_is_adopted_even_without_its_file(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $freshfile = $this->generator->create_draft_file('image.png', 'image-content');
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));
        originals::get_file($record)->delete();

        $targetcontext = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $adopted = originals::adopt_place($record, $this->generator->build_place($targetcontext));

        $this->assertEquals($targetcontext->id, $adopted->contextid);
        $this->assertSame(generator::AREA_COMPONENT, $adopted->component);
        $this->assertEquals($targetcontext->id, $DB->get_field('tool_imagepicker_original', 'contextid', ['id' => $record->id]));
        $this->assertNull(originals::get_file($adopted));
    }

    /**
     * A record is adopted into a real place within the very context it is stored in already (an image which ends up in a file
     * area of the uploading user's own context, for example) without its file being touched.
     */
    public function test_a_draft_placed_record_is_adopted_within_the_same_context(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $usercontext = \context_user::instance($USER->id);

        $freshfile = $this->generator->create_draft_file('image.png', 'image-content');
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));
        $fileid = originals::get_file($record)->get_id();

        $adopted = originals::adopt_place($record, $this->generator->build_place($usercontext, 'user', 'icon', 0));

        $this->assertEquals($usercontext->id, $adopted->contextid);
        $this->assertSame('icon', $adopted->filearea);
        $this->assertFalse(originals::is_draft_place((array) $adopted));
        $this->assertEquals($fileid, originals::get_file($adopted)->get_id());
    }

    /**
     * If two draft-placed records hold the same content (two uploads of the same image which were both cropped before either
     * was saved), the one which was touched most recently is the one which a real place adopts. Once adopted, it names that
     * place and is found for it no matter what happens to the other record afterwards.
     *
     * The adoption happens the moment the image is saved into the place (see \tool_imagepicker\hook_callbacks), which is
     * what creating the area file below stands for.
     */
    public function test_the_most_recently_touched_draft_placed_record_is_adopted(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $freshone = $this->generator->create_draft_file('one.png', 'the-very-same-content');
        $freshtwo = $this->generator->create_draft_file('two.png', 'the-very-same-content');
        $recordone = originals::ensure_original($freshone, originals::resolve_place($freshone));
        $recordtwo = originals::ensure_original($freshtwo, originals::resolve_place($freshtwo));

        $DB->set_field('tool_imagepicker_original', 'timemodified', 1000, ['id' => $recordone->id]);
        $DB->set_field('tool_imagepicker_original', 'timemodified', 2000, ['id' => $recordtwo->id]);

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'the-very-same-content');
        $draftfile = $this->generator->prepare_draft_file($context);
        $place = originals::resolve_place($draftfile);

        $this->assertEquals($recordtwo->id, originals::get_record_for($draftfile, $place)->id);
        $adoptedtwo = $DB->get_record('tool_imagepicker_original', ['id' => $recordtwo->id]);
        $untouchedone = $DB->get_record('tool_imagepicker_original', ['id' => $recordone->id]);
        $this->assertFalse(originals::is_draft_place((array) $adoptedtwo));
        $this->assertTrue(originals::is_draft_place((array) $untouchedone));

        $DB->set_field('tool_imagepicker_original', 'timemodified', 3000, ['id' => $recordone->id]);

        $this->assertEquals($recordtwo->id, originals::get_record_for($draftfile, $place)->id);
    }

    /**
     * Only the file in the root of an area is considered, as an image picker field holds a single file without subdirs.
     */
    public function test_get_root_file_ignores_subdirectories(): void {
        $this->resetAfterTest();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'nested.png', 'nested', ['filepath' => '/sub/']);
        $this->generator->create_area_file($context, 'root.png', 'root');

        $place = $this->generator->build_place($context);
        $file = originals::get_root_file(...$place);

        $this->assertNotNull($file);
        $this->assertSame('root.png', $file->get_filename());
        $this->assertCount(1, originals::get_root_files(...$place));
    }

    /**
     * An empty file area has no root file.
     */
    public function test_get_root_file_returns_null_for_an_empty_area(): void {
        $this->resetAfterTest();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);

        $place = $this->generator->build_place($context);

        $this->assertNull(originals::get_root_file(...$place));
        $this->assertSame([], originals::get_root_files(...$place));
    }

    /**
     * Reading an original requires the user to hold the image it backs in a draft area which was prepared from its place.
     */
    public function test_can_access_requires_a_draft_prepared_from_the_place(): void {
        $this->resetAfterTest();

        $editor = $this->getDataGenerator()->create_user();
        $coeditor = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($editor);
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');
        $draftfile = $this->generator->prepare_draft_file($context);
        $record = originals::ensure_original($draftfile, originals::resolve_place($draftfile));

        // The user who is editing the image holds it in a draft area which was prepared from its place, so they may read its
        // original.
        $this->assertTrue(originals::can_access($record));

        // So does anybody else who opens the form which the image lives on: their draft area is prepared from the same place.
        $this->setUser($coeditor);
        $this->assertFalse(originals::can_access($record));
        $this->generator->prepare_draft_file($context);
        $this->assertTrue(originals::can_access($record));

        // Somebody who merely got hold of the image (which may well be on public display) and uploaded it into a draft area
        // of their own holds the very same content, but that draft does not come from the place of the image. It proves
        // nothing, so the original stays closed to them.
        $this->setUser($stranger);
        $this->generator->create_draft_file('image.png', 'image-content');
        $this->assertFalse(originals::can_access($record));

        // Guests and not logged in users never may.
        $this->setGuestUser();
        $this->assertFalse(originals::can_access($record));
        $this->setUser(null);
        $this->assertFalse(originals::can_access($record));
    }

    /**
     * Reading an original which is tied to a draft area is reserved to the user whose draft area that is.
     */
    public function test_can_access_reserves_a_draft_placed_original_to_its_uploader(): void {
        $this->resetAfterTest();

        $uploader = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($uploader);
        $freshfile = $this->generator->create_draft_file('image.png', 'image-content');
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));

        $this->assertTrue(originals::can_access($record));

        // Even holding the very same content in a draft area of their own does not open it up to anybody else.
        $this->setUser($stranger);
        $this->generator->create_draft_file('image.png', 'image-content');
        $this->assertFalse(originals::can_access($record));

        $this->setGuestUser();
        $this->assertFalse(originals::can_access($record));
    }

    /**
     * Holding the image in a draft prepared from its place is only good for the version of the image which the original
     * currently backs. A user who opened the form before the image was cropped (and therefore still holds the uncropped
     * version) is not editing the cropped image, so the original stays closed to them until they open the form again.
     */
    public function test_can_access_requires_the_current_version_of_the_image(): void {
        global $DB;

        $this->resetAfterTest();

        $editor = $this->getDataGenerator()->create_user();
        $coeditor = $this->getDataGenerator()->create_user();

        // Both users open the form which holds the image.
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $areafile = $this->generator->create_area_file($context, 'image.png', 'image-content');
        $this->setUser($coeditor);
        $this->generator->prepare_draft_file($context);
        $this->setUser($editor);
        $draftfile = $this->generator->prepare_draft_file($context);

        // The editor crops the image and saves the form, so the file area now holds the cropped image.
        $record = originals::ensure_original($draftfile, originals::resolve_place($draftfile));
        $areafile->delete();
        $cropped = $this->generator->create_area_file($context, 'image.png', 'cropped-content');
        originals::set_derived($record->id, $cropped->get_contenthash());
        $record = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);

        // The co-editor still holds the uncropped version, which is not the image the original backs any more.
        $this->setUser($coeditor);
        $this->assertFalse(originals::can_access($record));

        // Opening the form again gives them the cropped image, and with it the original.
        $this->generator->prepare_draft_file($context);
        $this->assertTrue(originals::can_access($record));
    }

    /**
     * Pointing a record at the cropped file keeps the link intact and stores the crop region.
     */
    public function test_set_derived_updates_the_hash_and_the_crop_region(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');
        $draftfile = $this->generator->prepare_draft_file($context);
        $record = originals::ensure_original($draftfile, originals::resolve_place($draftfile));

        originals::set_derived($record->id, 'abc123', ['x' => 0.1, 'y' => 0.2, 'width' => 0.5, 'height' => 0.25]);

        $updated = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);
        $this->assertSame('abc123', $updated->derivedhash);
        $this->assertEqualsWithDelta(0.1, (float) $updated->cropx, 0.000001);
        $this->assertEqualsWithDelta(0.2, (float) $updated->cropy, 0.000001);
        $this->assertEqualsWithDelta(0.5, (float) $updated->cropwidth, 0.000001);
        $this->assertEqualsWithDelta(0.25, (float) $updated->cropheight, 0.000001);

        // Without a crop region, the stored one is left as it is.
        originals::set_derived($record->id, 'def456');

        $updated = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);
        $this->assertSame('def456', $updated->derivedhash);
        $this->assertEqualsWithDelta(0.5, (float) $updated->cropwidth, 0.000001);
    }

    /**
     * Deleting an original removes its file and its record, and deleting all of them removes every one of them.
     */
    public function test_delete_and_delete_all_remove_files_and_records(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $records = [];
        foreach (['one', 'two', 'three'] as $name) {
            $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
            $this->generator->create_area_file($context, $name . '.png', $name . '-content');
            $draftfile = $this->generator->prepare_draft_file($context);
            $records[] = originals::ensure_original($draftfile, originals::resolve_place($draftfile));
        }
        $this->assertEquals(3, $DB->count_records('tool_imagepicker_original'));

        originals::delete($records[0]);

        $this->assertFalse($DB->record_exists('tool_imagepicker_original', ['id' => $records[0]->id]));
        $this->assertNull(originals::get_file($records[0]));
        $this->assertNotNull(originals::get_file($records[1]));
        $this->assertEquals(2, $DB->count_records('tool_imagepicker_original'));

        $this->assertSame(2, originals::delete_all());

        $this->assertEquals(0, $DB->count_records('tool_imagepicker_original'));
        $this->assertNull(originals::get_file($records[1]));
        $this->assertNull(originals::get_file($records[2]));
        $this->assertEmpty(get_file_storage()->get_area_files(
            $records[2]->contextid,
            'tool_imagepicker',
            'original',
            false,
            'id',
            false
        ));
        $this->assertSame(0, originals::delete_all());
    }

    /**
     * Deleting an original whose file is gone already (because its context was deleted, for example) removes the record
     * without complaint.
     */
    public function test_delete_copes_with_a_missing_file(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $freshfile = $this->generator->create_draft_file('image.png', 'image-content');
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));
        originals::get_file($record)->delete();

        originals::delete($record);

        $this->assertFalse($DB->record_exists('tool_imagepicker_original', ['id' => $record->id]));
    }

    /**
     * The feature is off unless the admin setting says otherwise.
     */
    public function test_is_enabled_follows_the_admin_setting(): void {
        $this->resetAfterTest();

        $this->assertFalse(originals::is_enabled());

        set_config('preserveoriginals', 1, 'tool_imagepicker');
        $this->assertTrue(originals::is_enabled());

        set_config('preserveoriginals', 0, 'tool_imagepicker');
        $this->assertFalse(originals::is_enabled());
    }

    /**
     * A preserved original carries nothing of the user who cropped the image: neither a user reference nor an author, a
     * license or the source reference of the draft file it was copied from.
     */
    public function test_a_preserved_original_carries_no_user_data(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        // The image was uploaded with an author and a license, as the file picker records them.
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content', [
            'userid' => $USER->id,
            'author' => 'Jane Doe',
            'license' => 'cc-4.0',
        ]);
        $draftfile = $this->generator->prepare_draft_file($context);

        // The draft file carries all of that, plus the source reference which names the place it came from.
        $this->assertSame('Jane Doe', $draftfile->get_author());
        $this->assertSame('cc-4.0', $draftfile->get_license());
        $this->assertNotEmpty($draftfile->get_source());
        $this->assertEquals($USER->id, $draftfile->get_userid());

        $record = originals::ensure_original($draftfile, originals::resolve_place($draftfile));
        $original = originals::get_file($record);

        $this->assertNull($original->get_userid());
        $this->assertNull($original->get_author());
        $this->assertNull($original->get_license());
        $this->assertNull($original->get_source());
        $this->assertSame('image.png', $original->get_filename());
        $this->assertSame($draftfile->get_contenthash(), $original->get_contenthash());
    }

    /**
     * Once an original has been adopted into the place of its image, it belongs to that image and not to the user who
     * uploaded it: deleting that user (and with them everything in their user context) leaves the original untouched.
     */
    public function test_an_adopted_original_survives_the_deletion_of_its_uploader(): void {
        $this->resetAfterTest();

        $uploader = $this->getDataGenerator()->create_user();
        $editor = $this->getDataGenerator()->create_user();

        $this->setUser($uploader);
        $freshfile = $this->generator->create_draft_file('image.png', 'image-content');
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));

        $this->setUser($editor);
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator->create_area_file($context, 'image.png', 'image-content');
        $draftfile = $this->generator->prepare_draft_file($context);
        $adopted = originals::ensure_original($draftfile, originals::resolve_place($draftfile));
        $this->assertEquals($record->id, $adopted->id);

        delete_user($uploader);

        $original = originals::get_file($adopted);
        $this->assertNotNull($original);
        $this->assertEquals($context->id, $original->get_contextid());
        $this->assertSame(sha1('image-content'), $original->get_contenthash());
    }
}
