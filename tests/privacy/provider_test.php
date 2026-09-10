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
 * Admin tool "Image Picker" - Tests for the privacy provider.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Tests for the privacy provider.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    /**
     * The plugin declares that it stores no personal data, and explains why with a string which exists.
     */
    public function test_the_provider_stores_no_personal_data(): void {
        $this->assertTrue(is_subclass_of(provider::class, null_provider::class));

        $reason = provider::get_reason();
        $this->assertTrue(get_string_manager()->string_exists($reason, 'tool_imagepicker'));
        $this->assertNotEmpty(get_string($reason, 'tool_imagepicker'));
    }
}
