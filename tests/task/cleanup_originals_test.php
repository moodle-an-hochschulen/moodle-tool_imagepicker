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
 * Admin tool "Image Picker" - Tests for the scheduled clean up of orphaned originals.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\task;

use tool_imagepicker_generator as generator;
use tool_imagepicker\local\originals;

/**
 * Tests for the scheduled clean up of orphaned originals.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\task\cleanup_originals
 */
final class cleanup_originals_test extends \advanced_testcase {
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
     * Run the task and return what it wrote to the cron log.
     *
     * @return string
     */
    private function run_task(): string {
        ob_start();
        (new cleanup_originals())->execute();

        return (string) ob_get_clean();
    }

    /**
     * Preserve an original for an image which is stored in the given context.
     *
     * @param \context $context The context to store both the image and its original in.
     * @param string $content The image content.
     * @return \stdClass The preserved original record.
     */
    private function preserve_original(\context $context, string $content): \stdClass {
        $file = $this->generator->create_area_file($context, 'image.png', $content);

        return originals::ensure_original($file, $this->generator->build_place($context));
    }

    /**
     * An original whose image still exists somewhere is kept.
     */
    public function test_an_original_which_is_still_in_use_is_kept(): void {
        global $DB;

        $this->resetAfterTest();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->preserve_original($context, 'image-content');

        $this->run_task();

        $this->assertTrue($DB->record_exists('tool_imagepicker_original', ['id' => $record->id]));
        $this->assertNotNull(originals::get_file($record));
    }

    /**
     * An original which still names its own content as the displayed image (which is the state between stashing it and
     * pointing its record at the cropped file) does not keep itself alive: once the image is gone, it is orphaned like any
     * other original, although the preserved original file itself holds the very content which the record names.
     */
    public function test_an_original_does_not_keep_itself_alive(): void {
        global $DB;

        $this->resetAfterTest();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->preserve_original($context, 'image-content');
        $this->assertSame(sha1('image-content'), $record->derivedhash);

        // Remove the image from its file area, so the only file left with its content is the preserved original itself.
        $this->generator->get_area_file($context)->delete();

        $this->run_task();

        $this->assertFalse($DB->record_exists('tool_imagepicker_original', ['id' => $record->id]));
        $this->assertNull(originals::get_file($record));
    }

    /**
     * An original whose image no longer exists anywhere is removed, record and file alike.
     */
    public function test_an_orphaned_original_is_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->preserve_original($context, 'image-content');

        // Point the record at a file which does not exist anywhere, which is the state it ends up in once the image it backs
        // has been deleted.
        originals::set_derived($record->id, sha1('a content which is not stored anywhere'));

        $output = $this->run_task();

        $this->assertFalse($DB->record_exists('tool_imagepicker_original', ['id' => $record->id]));
        $this->assertNull(originals::get_file($record));
        $this->assertStringContainsString('cleaned up 1', $output);
    }

    /**
     * One run removes every orphaned original there is, and only those: originals which are still in use stay.
     */
    public function test_all_orphaned_originals_are_removed_at_once(): void {
        global $DB;

        $this->resetAfterTest();

        $contexts = [];
        $records = [];
        foreach (['one', 'two', 'three'] as $name) {
            $contexts[$name] = \context_course::instance($this->getDataGenerator()->create_course()->id);
            $records[$name] = $this->preserve_original($contexts[$name], $name . '-content');
        }
        $this->generator->get_area_file($contexts['one'])->delete();
        $this->generator->get_area_file($contexts['three'])->delete();

        $output = $this->run_task();

        $this->assertStringContainsString('cleaned up 2', $output);
        $this->assertFalse($DB->record_exists('tool_imagepicker_original', ['id' => $records['one']->id]));
        $this->assertTrue($DB->record_exists('tool_imagepicker_original', ['id' => $records['two']->id]));
        $this->assertFalse($DB->record_exists('tool_imagepicker_original', ['id' => $records['three']->id]));
        $this->assertNull(originals::get_file($records['one']));
        $this->assertNotNull(originals::get_file($records['two']));
        $this->assertNull(originals::get_file($records['three']));
    }

    /**
     * An original whose context has been deleted (which takes the image and the original file with it) is orphaned as well,
     * and its record is removed without complaint although there is no file left to delete.
     */
    public function test_an_original_of_a_deleted_context_is_removed(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $record = $this->preserve_original(\context_course::instance($course->id), 'image-content');
        delete_course($course, false);
        $this->assertNull(originals::get_file($record));

        $output = $this->run_task();

        $this->assertStringContainsString('cleaned up 1', $output);
        $this->assertFalse($DB->record_exists('tool_imagepicker_original', ['id' => $record->id]));
    }

    /**
     * With nothing to clean up, the task says so rather than staying silent.
     */
    public function test_the_task_reports_when_there_is_nothing_to_do(): void {
        $this->resetAfterTest();

        $output = $this->run_task();

        $this->assertStringContainsString('no orphaned original images', $output);
    }
}
