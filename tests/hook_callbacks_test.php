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

namespace tool_imagepicker;

use tool_imagepicker\local\originals;

/**
 * Tests for the hook callbacks.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(hook_callbacks::class)]
#[\PHPUnit\Framework\Attributes\CoversMethod(originals::class, 'adopt_saved_file')]
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Get the plugin generator.
     *
     * @return \tool_imagepicker_generator
     */
    private function generator(): \tool_imagepicker_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_imagepicker');
    }

    /**
     * Crop a fresh upload as the current user, so that its original is preserved with the draft area as its place.
     *
     * The draft area then holds the cropped image (which is what a form submits), and the original is preserved in the user
     * context of the current user.
     *
     * @param string $original The content of the uploaded image.
     * @param string $cropped The content of the cropped image.
     * @return array The draft item id and the preserved original record.
     */
    private function crop_fresh_upload(string $original, string $cropped): array {
        $freshfile = $this->generator()->create_draft_file('image.png', $original);
        $draftitemid = $freshfile->get_itemid();
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));
        $freshfile->delete();
        $croppedfile = $this->generator()->create_draft_file('image.png', $cropped, ['itemid' => $draftitemid]);
        originals::set_derived($record->id, $croppedfile->get_contenthash());

        return [$draftitemid, $record];
    }

    /**
     * Assert that the given record is draft-placed in the given user context.
     *
     * @param \stdClass $record The record, as it was returned when it was created.
     * @param \context_user $usercontext The user context.
     */
    private function assert_still_with_the_draft(\stdClass $record, \context_user $usercontext): void {
        global $DB;

        $record = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);
        $this->assertTrue(originals::is_draft_place((array) $record));
        $this->assertEquals($usercontext->id, $record->contextid);
        $this->assertEquals($usercontext->id, originals::get_file($record)->get_contextid());
    }

    /**
     * Saving the form which holds a cropped fresh upload settles its original into the place the image is saved to, right
     * away and without anybody having to edit the image again.
     */
    public function test_a_draft_placed_original_is_adopted_when_the_form_is_saved(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        [$draftitemid, $record] = $this->crop_fresh_upload('original-content', 'cropped-content');
        $this->assertTrue(originals::is_draft_place((array) $record));

        // The form is submitted: core copies the draft area into the file area of a course.
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        file_save_draft_area_files($draftitemid, $context->id, 'theme_boost_union', 'courseheaderimage', 0);

        $record = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);
        $this->assertFalse(originals::is_draft_place((array) $record));
        $this->assertEquals($context->id, $record->contextid);
        $this->assertSame('theme_boost_union', $record->component);
        $this->assertSame('courseheaderimage', $record->filearea);
        $this->assertEquals(0, $record->itemid);
        $this->assertSame(sha1('cropped-content'), $record->derivedhash);

        // The original file has moved along into the context of the image.
        $original = originals::get_file($record);
        $this->assertNotNull($original);
        $this->assertEquals($context->id, $original->get_contextid());
        $this->assertSame(sha1('original-content'), $original->get_contenthash());
        $this->assertEquals(1, $DB->count_records_select(
            'files',
            "component = 'tool_imagepicker' AND filearea = 'original' AND filename <> '.'"
        ));
        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));

        // A user who edits the image from the course from now on reads the original from there.
        $this->setUser($this->getDataGenerator()->create_user());
        $this->generator()->prepare_draft_file($context, 'theme_boost_union', 'courseheaderimage', 0);
        $this->assertTrue(originals::can_access($record));
    }

    /**
     * Another draft file with the same content is no place: an original stays with its draft until the image is saved.
     */
    public function test_a_draft_file_adopts_nothing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        [, $record] = $this->crop_fresh_upload('original-content', 'cropped-content');

        // The same content turns up in another draft area (the user uploads the cropped image again elsewhere).
        $this->generator()->create_draft_file('copy.png', 'cropped-content');

        $this->assert_still_with_the_draft($record, \context_user::instance($user->id));
    }

    /**
     * A file which does not hold the cropped image is not the image the original backs, so nothing is adopted.
     */
    public function test_an_unrelated_file_adopts_nothing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        [, $record] = $this->crop_fresh_upload('original-content', 'cropped-content');

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator()->create_area_file($context, 'other.png', 'other-content');

        // Not even the original content itself: the record backs the cropped image, not the original.
        $this->generator()->create_area_file($context, 'original.png', 'original-content');

        $this->assert_still_with_the_draft($record, \context_user::instance($user->id));
    }

    /**
     * An image picker image sits in the root of its file area, so the cropped image turning up in a subdirectory of some area
     * is not an image picker image and adopts nothing.
     */
    public function test_a_file_in_a_subdirectory_adopts_nothing(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        [, $record] = $this->crop_fresh_upload('original-content', 'cropped-content');

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator()->create_area_file($context, 'image.png', 'cropped-content', ['filepath' => '/sub/']);

        $this->assert_still_with_the_draft($record, \context_user::instance($user->id));
    }

    /**
     * An original which knows its real place already is never moved when its image turns up somewhere else (which is what
     * happens when a course is duplicated or restored). The copy is another image, which gets an original of its own once it
     * is cropped.
     */
    public function test_a_placed_original_is_never_moved(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->generator()->preserve_cropped($context, 'image.png', 'original-content', 'image.png', 'cropped-content');
        $this->assertFalse(originals::is_draft_place((array) $record));

        $othercontext = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $this->generator()->create_area_file($othercontext, 'image.png', 'cropped-content');

        $record = $DB->get_record('tool_imagepicker_original', ['id' => $record->id]);
        $this->assertEquals($context->id, $record->contextid);
        $this->assertEquals($context->id, originals::get_file($record)->get_contextid());
        $this->assertEquals(1, $DB->count_records('tool_imagepicker_original'));
    }

    /**
     * If two cropped fresh uploads hold the same content, saving one of them adopts the most recently touched original, and
     * the other one stays with its draft.
     */
    public function test_the_most_recently_touched_original_is_adopted(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        [$draftone, $recordone] = $this->crop_fresh_upload('original-one', 'the-very-same-crop');
        [, $recordtwo] = $this->crop_fresh_upload('original-two', 'the-very-same-crop');
        $DB->set_field('tool_imagepicker_original', 'timemodified', 2000, ['id' => $recordone->id]);
        $DB->set_field('tool_imagepicker_original', 'timemodified', 1000, ['id' => $recordtwo->id]);

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        file_save_draft_area_files($draftone, $context->id, 'tool_imagepicker', 'demo', 1);

        $recordone = $DB->get_record('tool_imagepicker_original', ['id' => $recordone->id]);
        $this->assertEquals($context->id, $recordone->contextid);
        $this->assertSame('demo', $recordone->filearea);
        $this->assert_still_with_the_draft($recordtwo, \context_user::instance($user->id));
    }

    /**
     * The hook does nothing before the plugin is installed, as its table does not exist yet then.
     */
    public function test_nothing_happens_before_the_plugin_is_installed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        [$draftitemid, $record] = $this->crop_fresh_upload('original-content', 'cropped-content');

        // Pretend that the plugin is not installed. The table is still there, so all this proves is that it is not touched.
        unset_config('version', 'tool_imagepicker');
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        file_save_draft_area_files($draftitemid, $context->id, 'tool_imagepicker', 'demo', 1);

        $this->assertTrue(originals::is_draft_place((array) $DB->get_record('tool_imagepicker_original', ['id' => $record->id])));
    }
}
