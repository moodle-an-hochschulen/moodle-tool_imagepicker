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
 * Admin tool "Image Picker" - Tests for the file size limit of cropped images.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\local;

use tool_imagepicker_generator as generator;

/**
 * Tests for the file size limit of cropped images.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\local\sizelimit
 */
final class sizelimit_test extends \advanced_testcase {
    /** @var int A site limit which is well below what PHP allows, so that it is the one which applies. */
    private const SITE_LIMIT = 100000;

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
     * Set up a site limit and a user who is subject to it.
     *
     * @return \context_user The user context of that user.
     */
    private function set_up_limited_user(): \context_user {
        global $CFG;

        $this->resetAfterTest();
        $CFG->maxbytes = self::SITE_LIMIT;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        return \context_user::instance($user->id);
    }

    /**
     * An image which is nothing but a draft yet is limited by the site limit, and only by that.
     */
    public function test_a_draft_is_limited_by_the_site_limit(): void {
        $usercontext = $this->set_up_limited_user();

        $this->assertSame(self::SITE_LIMIT, sizelimit::resolve($this->generator->build_draft_place(), $usercontext));
    }

    /**
     * A user who may ignore file size limits everywhere (such as an admin) is not limited at all.
     */
    public function test_an_admin_is_not_limited(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->maxbytes = self::SITE_LIMIT;
        $this->setAdminUser();
        $usercontext = \context_user::instance(get_admin()->id);

        $this->assertSame(0, sizelimit::resolve($this->generator->build_draft_place(), $usercontext));

        $course = $this->getDataGenerator()->create_course(['maxbytes' => 12345]);
        $place = $this->generator->build_place(\context_course::instance($course->id));
        $this->assertSame(0, sizelimit::resolve($place, $usercontext));
    }

    /**
     * Data provider: course limits and the limit which results from them together with the site limit.
     *
     * @return array
     */
    public static function course_limit_provider(): array {
        return [
            'course limit below the site limit' => [12345, 12345],
            'course limit above the site limit' => [self::SITE_LIMIT * 2, self::SITE_LIMIT],
            'course limit equal to the site limit' => [self::SITE_LIMIT, self::SITE_LIMIT],
            'no course limit' => [0, self::SITE_LIMIT],
        ];
    }

    /**
     * An image which lives in a course is limited by the course limit as well as by the site limit, whichever is smaller.
     *
     * @param int $coursebytes The upload limit of the course.
     * @param int $expected The resulting limit.
     * @dataProvider course_limit_provider
     */
    public function test_an_image_in_a_course_is_limited_by_the_course_limit(int $coursebytes, int $expected): void {
        $usercontext = $this->set_up_limited_user();

        $course = $this->getDataGenerator()->create_course(['maxbytes' => $coursebytes]);
        $place = $this->generator->build_place(\context_course::instance($course->id));

        $this->assertSame($expected, sizelimit::resolve($place, $usercontext));
    }

    /**
     * An image which lives in an activity is limited by the limit of the course which the activity belongs to.
     */
    public function test_an_image_in_an_activity_is_limited_by_the_course_limit(): void {
        $usercontext = $this->set_up_limited_user();

        $course = $this->getDataGenerator()->create_course(['maxbytes' => 12345]);
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $place = $this->generator->build_place(\context_module::instance($folder->cmid));

        $this->assertSame(12345, sizelimit::resolve($place, $usercontext));
    }

    /**
     * A user who may ignore file size limits in a course is not limited for an image which lives in that course - but still
     * for an image which lives elsewhere, as the capability is checked where the image lives.
     */
    public function test_the_capability_to_ignore_limits_is_checked_where_the_image_lives(): void {
        global $USER;

        $usercontext = $this->set_up_limited_user();

        $course = $this->getDataGenerator()->create_course(['maxbytes' => 12345]);
        $coursecontext = \context_course::instance($course->id);
        $othercourse = $this->getDataGenerator()->create_course(['maxbytes' => 12345]);
        $othercoursecontext = \context_course::instance($othercourse->id);

        $roleid = create_role('Unlimited uploader', 'unlimiteduploader', '');
        assign_capability('moodle/course:ignorefilesizelimits', CAP_ALLOW, $roleid, $coursecontext->id);
        role_assign($roleid, $USER->id, $coursecontext->id);

        $this->assertSame(0, sizelimit::resolve($this->generator->build_place($coursecontext), $usercontext));
        $this->assertSame(12345, sizelimit::resolve($this->generator->build_place($othercoursecontext), $usercontext));
        $this->assertSame(self::SITE_LIMIT, sizelimit::resolve($this->generator->build_draft_place(), $usercontext));
    }

    /**
     * An image whose place has been deleted in the meantime is limited as a draft would be: the site limit applies, checked
     * against the user's own context.
     */
    public function test_a_place_which_is_gone_falls_back_to_the_user_context(): void {
        $usercontext = $this->set_up_limited_user();

        $course = $this->getDataGenerator()->create_course(['maxbytes' => 12345]);
        $place = $this->generator->build_place(\context_course::instance($course->id));
        delete_course($course, false);

        $this->assertSame(self::SITE_LIMIT, sizelimit::resolve($place, $usercontext));
    }
}
