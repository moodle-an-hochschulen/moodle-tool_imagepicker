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
 * Admin tool "Image Picker" - Tests for the preserved originals report table.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\table;

use tool_imagepicker_generator as generator;
use tool_imagepicker\local\originals;

/**
 * Tests for the preserved originals report table.
 *
 * The report is rendered as the report page renders it, and the HTML is checked for what the report says about an original
 * in each of the states it can be in.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\table\originals_overview
 */
final class originals_overview_test extends \advanced_testcase {
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
     * Render the report, as the report page does it.
     *
     * @return string The HTML.
     */
    private function render_report(): string {
        $url = new \core\url('/admin/tool/imagepicker/report.php');
        $table = new originals_overview($url);
        $table->define_baseurl($url);

        ob_start();
        $table->out(50, false);

        return (string) ob_get_clean();
    }

    /**
     * Get the HTML of the table row of the given original.
     *
     * @param string $html The report HTML.
     * @param \stdClass $record The preserved original record.
     * @return string The row HTML.
     */
    private function get_row(string $html, \stdClass $record): string {
        // The rows are numbered by position, so the row is found by the id cell which carries the record id.
        $matches = [];
        $found = preg_match(
            '~<tr[^>]*>\s*<th[^>]*>' . $record->id . '</th>.*?</tr>~s',
            $html,
            $matches
        );
        $this->assertSame(1, $found, 'The report does not list the original with the id ' . $record->id . '.');

        return $matches[0];
    }

    /**
     * Get the badges of the given row, in order.
     *
     * @param string $row The row HTML.
     * @return string[] The badge labels.
     */
    private function get_badges(string $row): array {
        $matches = [];
        preg_match_all('~<span class="badge[^"]*">([^<]*)</span>~', $row, $matches);

        return $matches[1];
    }

    /**
     * An original which is in place and backs an image which is still in use is reported as such, with the type and the
     * size of both files, the place of the image, the crop region in pixels and both actions.
     */
    public function test_a_placed_original_in_use(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['shortname' => 'reportcourse']);
        $context = \context_course::instance($course->id);
        $original = $this->generator->build_image(200, 100);
        $cropped = $this->generator->build_image(100, 50, 'png', 20);
        $record = $this->generator->preserve_cropped(
            $context,
            'header.png',
            $original,
            'header.png',
            $cropped,
            ['x' => 0.25, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]
        );

        $row = $this->get_row($this->render_report(), $record);

        // The status cells: status badge and file type badge for both the original and the cropped image.
        $this->assertSame(['In place', '.png', 'In use', '.png'], $this->get_badges($row));
        $this->assertStringContainsString(display_size(strlen($original)), $row);
        $this->assertStringContainsString(display_size(strlen($cropped)), $row);
        // The file name is not shown as text (it only appears within the URL of the view action).
        $this->assertStringNotContainsString('>header.png<', $row);
        $this->assertStringNotContainsString('header.png<br', $row);

        // The place of the image.
        $this->assertStringContainsString('tool_imagepicker / demo / 0', $row);
        $this->assertStringContainsString('Course: reportcourse', $row);
        $this->assertStringNotContainsString('Draft, not saved yet', $row);

        // The crop region, in pixels of the 200 x 100 original.
        $this->assertStringContainsString('100 × 50 px', $row);
        $this->assertStringContainsString('at 50 / 10 px from top left', $row);

        // Both actions are offered.
        $this->assertStringContainsString('class="action-view"', $row);
        $this->assertStringContainsString('class="action-delete"', $row);
        $this->assertStringContainsString('action=delete&amp;id=' . $record->id, $row);
    }

    /**
     * A cropped image which exists in a draft area only (the form has not been saved since the crop) still counts as in use.
     */
    public function test_a_cropped_image_which_only_exists_as_a_draft_is_in_use(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->generator->preserve_cropped(
            $context,
            'image.png',
            $this->generator->build_image(20, 10),
            'image.png',
            $this->generator->build_image(10, 5, 'png', 20),
            ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 0.5]
        );

        // Move the cropped image from the file area into a draft area, which is what the field holds after a crop and
        // before the form is saved.
        $areafile = $this->generator->get_area_file($context);
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(),
        ], $areafile);
        $areafile->delete();

        $row = $this->get_row($this->render_report(), $record);

        $this->assertSame(['In place', '.png', 'In use', '.png'], $this->get_badges($row));
    }

    /**
     * If the cropped image exists in its file area and in a draft area at once, the copy in the file area is the one which
     * describes it (the draft copy may carry any name), even if the draft copy is the older of the two.
     */
    public function test_a_cropped_image_is_described_by_the_copy_in_its_file_area(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $cropped = $this->generator->build_image(10, 5, 'png', 20);

        // The draft copy is created first, so that it is the first file which holds the content.
        $this->generator->create_draft_file('copy.jpg', $cropped);

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->generator->preserve_cropped(
            $context,
            'header.png',
            $this->generator->build_image(20, 10),
            'header.png',
            $cropped,
            ['x' => 0, 'y' => 0, 'width' => 0.5, 'height' => 0.5]
        );

        $row = $this->get_row($this->render_report(), $record);

        $this->assertSame(['In place', '.png', 'In use', '.png'], $this->get_badges($row));
        $this->assertStringNotContainsString('.jpg', $row);
    }

    /**
     * An original whose image is nothing but a draft yet is marked as such and named after the user's draft area.
     */
    public function test_a_draft_placed_original(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $freshfile = $this->generator->create_draft_file('fresh.jpg', $this->generator->build_image(40, 40, 'jpg'));
        $record = originals::ensure_original($freshfile, originals::resolve_place($freshfile));

        $row = $this->get_row($this->render_report(), $record);

        $this->assertStringContainsString('user / draft / ' . $freshfile->get_itemid(), $row);
        $this->assertStringContainsString('Draft, not saved yet', $row);
        $this->assertStringContainsString(fullname($USER), $row);
        $this->assertSame(['Draft, not saved yet', 'In place', '.jpg', 'In use', '.jpg'], $this->get_badges($row));

        // No crop has been stored yet, so there is no crop region to report.
        $this->assertStringNotContainsString('from top left', $row);
    }

    /**
     * A crop which changed the file format shows the type of the original and the type of the cropped image side by side.
     */
    public function test_a_crop_which_changed_the_format(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->generator->preserve_cropped(
            $context,
            'animated.gif',
            $this->generator->build_image(30, 30, 'gif'),
            'animated.png',
            $this->generator->build_image(15, 15, 'png', 20),
            ['x' => 0.5, 'y' => 0.5, 'width' => 0.5, 'height' => 0.5]
        );

        $row = $this->get_row($this->render_report(), $record);

        $this->assertSame(['In place', '.gif', 'In use', '.png'], $this->get_badges($row));
        $this->assertStringContainsString('15 × 15 px', $row);
        $this->assertStringContainsString('at 15 / 15 px from top left', $row);
    }

    /**
     * An original whose image has been deleted is orphaned, and an original whose file is gone (because its context has
     * been deleted) is missing. Both happen at once when a course is deleted, and the crop region then falls back to
     * percentages as the size of the original is not known any more.
     */
    public function test_an_orphaned_original_whose_file_is_missing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $record = $this->generator->preserve_cropped(
            $context,
            'header.png',
            $this->generator->build_image(200, 100),
            'header.png',
            $this->generator->build_image(100, 50, 'png', 20),
            ['x' => 0.25, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]
        );

        // Deleting the course deletes its context and, with it, every file which is stored in it: the cropped image as well
        // as the preserved original. The record stays behind until the clean up task gets to it.
        delete_course($course, false);

        $row = $this->get_row($this->render_report(), $record);

        $this->assertSame(['Missing', 'Orphaned'], $this->get_badges($row));
        $this->assertStringContainsString('Context ' . $context->id . ' (deleted)', $row);
        $this->assertStringContainsString('50 % × 50 % of the original image', $row);
        $this->assertStringContainsString('at 25 % / 10 % from top left', $row);

        // Without a file, there is nothing to view - but the record can still be deleted.
        $this->assertStringNotContainsString('class="action-view"', $row);
        $this->assertStringContainsString('class="action-delete"', $row);
    }

    /**
     * An original which is in place but whose image has been removed from the field is orphaned, yet can still be viewed.
     */
    public function test_an_orphaned_original_which_is_still_in_place(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->generator->preserve_cropped(
            $context,
            'header.png',
            $this->generator->build_image(200, 100),
            'header.png',
            $this->generator->build_image(100, 50, 'png', 20),
            ['x' => 0.25, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]
        );

        // The image is removed from the field, so the cropped image does not exist anywhere any more.
        $this->generator->get_area_file($context)->delete();

        $row = $this->get_row($this->render_report(), $record);

        $this->assertSame(['In place', '.png', 'Orphaned'], $this->get_badges($row));
        $this->assertStringContainsString('100 × 50 px', $row);
        $this->assertStringContainsString('class="action-view"', $row);
        $this->assertStringContainsString('class="action-delete"', $row);
    }

    /**
     * If the original is in place but its dimensions cannot be read (it is not a readable image), the crop region is shown
     * in percentages rather than in pixels.
     */
    public function test_the_crop_region_falls_back_to_percentages_without_dimensions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        $record = $this->generator->preserve_cropped(
            $context,
            'header.png',
            'this is not an image',
            'header.png',
            $this->generator->build_image(100, 50, 'png', 20),
            ['x' => 0.25, 'y' => 0.1, 'width' => 0.5, 'height' => 0.5]
        );

        $row = $this->get_row($this->render_report(), $record);

        $this->assertSame(['In place', '.png', 'In use', '.png'], $this->get_badges($row));
        $this->assertStringContainsString('50 % × 50 % of the original image', $row);
        $this->assertStringContainsString('at 25 % / 10 % from top left', $row);
    }

    /**
     * Without any originals, the report says so instead of showing an empty table.
     */
    public function test_an_empty_report(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = $this->render_report();

        $this->assertStringContainsString(get_string('report_nothingtodisplay', 'tool_imagepicker'), $html);
        $this->assertStringNotContainsString('<table', $html);
    }
}
