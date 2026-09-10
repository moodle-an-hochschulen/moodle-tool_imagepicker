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
 * Admin tool "Image Picker" - Tests for the image picker form element.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\formelement;

use tool_imagepicker\element;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Tests for the image picker form element.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @covers \tool_imagepicker\formelement\imagepicker
 */
final class imagepicker_test extends \advanced_testcase {
    /**
     * Render a form which holds an image picker element with the given options, and return the JavaScript which the page
     * ends with (which is where the element initializes its JavaScript, if it does).
     *
     * @param array $options The element options.
     * @return string The end code of the page.
     */
    private function render_element(array $options = []): string {
        global $PAGE;

        $PAGE->set_url('/admin/tool/imagepicker/demo.php');
        $PAGE->set_context(\context_system::instance());

        $form = new class (new \moodle_url('/admin/tool/imagepicker/demo.php'), $options) extends \moodleform {
            /**
             * Form definition.
             */
            public function definition() {
                element::register();
                $this->_form->addElement(element::TYPE, 'image', 'Image', null, $this->_customdata);
            }
        };
        $form->render();

        return $PAGE->requires->get_end_code();
    }

    /**
     * Render a form which holds an image picker element with the given options, and return the arguments with which the
     * element initializes its JavaScript.
     *
     * @param array $options The element options.
     * @return array The configuration which is handed to the JavaScript.
     */
    private function render_and_get_js_config(array $options = []): array {
        // The element initializes its JavaScript with a call to the init function of its AMD module, with the element id and
        // the configuration as its arguments. Pick the configuration out of that call.
        $endcode = $this->render_element($options);
        $matches = [];
        $found = preg_match(
            '~tool_imagepicker/imagepicker.*?init\(\s*"[^"]*"\s*,\s*(\{.*?\})\s*\)~s',
            $endcode,
            $matches
        );
        $this->assertSame(1, $found, 'The element did not initialize its JavaScript.');

        return json_decode($matches[1], true);
    }

    /**
     * Data provider: encoding quality settings and the quality which results from them.
     *
     * @return array
     */
    public static function encoding_quality_provider(): array {
        return [
            'never saved' => [null, 92],
            'valid value' => ['50', 50],
            'maximum' => ['100', 100],
            'minimum' => ['1', 1],
            'below the minimum' => ['0', 1],
            'negative' => ['-5', 1],
            'above the maximum' => ['150', 100],
            'decimal' => ['85.7', 85],
            'not a number' => ['abc', 92],
            'empty' => ['', 92],
        ];
    }

    /**
     * The encoding quality is the site setting, kept within the range which makes sense for a quality.
     *
     * @param string|null $setting The setting value, or null if the setting was never saved.
     * @param int $expected The resulting quality.
     * @dataProvider encoding_quality_provider
     */
    public function test_the_encoding_quality_is_kept_within_range(?string $setting, int $expected): void {
        $this->resetAfterTest();

        if ($setting !== null) {
            set_config('encodingquality', $setting, 'tool_imagepicker');
        }

        $this->assertSame($expected, imagepicker::get_encoding_quality());
    }

    /**
     * Data provider: size limit strategy settings and the strategy which results from them.
     *
     * @return array
     */
    public static function size_limit_strategy_provider(): array {
        return [
            'never saved' => [null, 'scaledown'],
            'scale down' => ['scaledown', 'scaledown'],
            'lower quality' => ['lowerquality', 'lowerquality'],
            'unknown' => ['foo', 'scaledown'],
            'wrong case' => ['LowerQuality', 'scaledown'],
            'empty' => ['', 'scaledown'],
        ];
    }

    /**
     * The size limit strategy is the site setting, as long as it names a strategy which the client knows.
     *
     * @param string|null $setting The setting value, or null if the setting was never saved.
     * @param string $expected The resulting strategy.
     * @dataProvider size_limit_strategy_provider
     */
    public function test_the_size_limit_strategy_falls_back_to_scaling_down(?string $setting, string $expected): void {
        $this->resetAfterTest();

        if ($setting !== null) {
            set_config('sizelimitstrategy', $setting, 'tool_imagepicker');
        }

        $this->assertSame($expected, imagepicker::get_size_limit_strategy());
    }

    /**
     * The settings reach the JavaScript as the client expects them: the quality as a fraction, the strategy by name, along
     * with the other configuration of the element.
     */
    public function test_the_settings_are_handed_to_the_javascript(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Without the settings ever being saved, the defaults are handed over.
        $config = $this->render_and_get_js_config(['accepted_types' => ['.png', '.jpg', '.pdf'], 'cropaspectratio' => 1.5]);

        $this->assertSame(0.92, $config['encodingquality']);
        $this->assertSame('scaledown', $config['sizelimitstrategy']);
        $this->assertFalse($config['preserveoriginals']);
        $this->assertTrue($config['enablecrop']);
        $this->assertSame(1.5, $config['cropaspectratio']);
        $this->assertEqualsCanonicalizing(['.png', '.jpg'], $config['acceptedtypes']);
    }

    /**
     * Saved settings (including an out of range quality) reach the JavaScript in a usable form.
     */
    public function test_saved_settings_are_handed_to_the_javascript(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('encodingquality', '150', 'tool_imagepicker');
        set_config('sizelimitstrategy', 'lowerquality', 'tool_imagepicker');
        set_config('preserveoriginals', 1, 'tool_imagepicker');

        $config = $this->render_and_get_js_config();

        $this->assertSame(1, $config['encodingquality']);
        $this->assertSame('lowerquality', $config['sizelimitstrategy']);
        $this->assertTrue($config['preserveoriginals']);
        $this->assertTrue($config['enablecrop']);
        $this->assertNull($config['cropaspectratio']);
    }

    /**
     * Without the crop button there is nothing for the JavaScript to do, so it is not loaded at all.
     */
    public function test_no_javascript_is_loaded_without_the_crop_button(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $endcode = $this->render_element(['enablecrop' => false]);

        $this->assertStringNotContainsString('tool_imagepicker/imagepicker', $endcode);
    }

    /**
     * The element handles exactly one image which is stored directly in the file area, no matter what the caller asks for.
     */
    public function test_the_file_manager_options_are_enforced(): void {
        $this->resetAfterTest();

        element::register();
        $element = new imagepicker('image', 'Image', null, ['maxfiles' => 5, 'subdirs' => 1, 'enablecrop' => false]);

        $this->assertSame(1, $element->getMaxfiles());
        $this->assertSame(0, $element->getSubdirs());
    }
}
