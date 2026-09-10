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
 * Admin tool "Image Picker" - Behat steps.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;
use tool_imagepicker\form\demo_form;
use tool_imagepicker\local\originals;

/**
 * Behat steps for tool_imagepicker.
 *
 * The steps work on the demo page of the plugin, whose fields are addressed by their form field name ('imagepicker' or
 * 'imagepickerratio'), on the crop modal and on the preserved originals.
 *
 * @package    tool_imagepicker
 * @category   test
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_imagepicker extends behat_base {
    /** @var string The CSS selector of the crop modal. */
    const CROP_MODAL = '.tool-imagepicker-crop-modal';

    /**
     * @var int The tolerance (in pixels) which is allowed when the dimensions of a cropped image are checked.
     *
     * The crop region is read from element positions in the browser, which browsers round differently (Chrome, for example,
     * ends up two pixels short on a full-height selection where Firefox is exact).
     */
    const DIMENSION_TOLERANCE = 2;

    /** @var float The tolerance which is allowed when a crop region (given as fractions of the image) is checked. */
    const FRACTION_TOLERANCE = 0.02;

    /**
     * Convert page names to URLs for steps like 'When I am on the "tool_imagepicker > [page name]" page'.
     *
     * Recognised page names are:
     * | Settings | The settings page of the plugin |
     * | Demo     | The demo page                   |
     * | Report   | The preserved originals report  |
     *
     * @param string $page The name of the page, with the component name removed.
     * @return moodle_url The corresponding URL.
     * @throws Exception If the page is not recognised.
     */
    protected function resolve_page_url(string $page): moodle_url {
        return match (strtolower(trim($page))) {
            'settings' => new moodle_url('/admin/settings.php', ['section' => 'tool_imagepicker']),
            'demo' => new moodle_url('/admin/tool/imagepicker/demo.php'),
            'report' => new moodle_url('/admin/tool/imagepicker/report.php'),
            default => throw new Exception('Unrecognised tool_imagepicker page "' . $page . '"'),
        };
    }

    /**
     * Get the item id under which the image of the given demo form field is stored.
     *
     * @param string $field The demo form field name.
     * @return int The item id.
     * @throws ExpectationException If the field is unknown.
     */
    private function get_demo_itemid(string $field): int {
        if (!isset(demo_form::FIELDS[$field])) {
            throw new ExpectationException('Unknown demo form field "' . $field . '"', $this->getSession());
        }

        return demo_form::FIELDS[$field];
    }

    /**
     * Get the image which is stored for the given demo form field.
     *
     * @param string $field The demo form field name.
     * @return stored_file|null The image, or null if the field holds none.
     */
    private function get_demo_file(string $field): ?stored_file {
        return originals::get_root_file(
            context_system::instance()->id,
            'tool_imagepicker',
            demo_form::FILEAREA,
            $this->get_demo_itemid($field)
        );
    }

    /**
     * Get the image which is stored for the given demo form field, failing if there is none.
     *
     * @param string $field The demo form field name.
     * @return stored_file The image.
     * @throws ExpectationException If the field holds no image.
     */
    private function require_demo_file(string $field): stored_file {
        $file = $this->get_demo_file($field);
        if (!$file) {
            throw new ExpectationException('The image of "' . $field . '" does not exist', $this->getSession());
        }

        return $file;
    }

    /**
     * Checks the pixel dimensions of the image which is stored for a demo form field.
     *
     * A tolerance of one pixel is allowed, as the crop region is read from element positions in the browser.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should have the dimensions "(?P<width>\d+) x (?P<height>\d+)"$/
     * @param string $field The demo form field name.
     * @param int $width The expected width.
     * @param int $height The expected height.
     * @throws ExpectationException
     */
    public function the_image_of_should_have_the_dimensions(string $field, int $width, int $height): void {
        $file = $this->require_demo_file($field);
        $imageinfo = $file->get_imageinfo();
        if (!$imageinfo) {
            throw new ExpectationException(
                'The image of "' . $field . '" (' . $file->get_filename() . ') is not an image',
                $this->getSession()
            );
        }
        if (
            abs($imageinfo['width'] - $width) > self::DIMENSION_TOLERANCE ||
            abs($imageinfo['height'] - $height) > self::DIMENSION_TOLERANCE
        ) {
            throw new ExpectationException(
                'The image of "' . $field . '" has the dimensions ' . $imageinfo['width'] . ' x ' . $imageinfo['height'] .
                    ', expected ' . $width . ' x ' . $height,
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the image which is stored for a demo form field is smaller (in both dimensions) than the given size.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should have dimensions smaller than "(?P<width>\d+) x (?P<height>\d+)"$/
     * @param string $field The demo form field name.
     * @param int $width The width which the image has to stay below.
     * @param int $height The height which the image has to stay below.
     * @throws ExpectationException
     */
    public function the_image_of_should_have_dimensions_smaller_than(string $field, int $width, int $height): void {
        $file = $this->require_demo_file($field);
        $imageinfo = $file->get_imageinfo();
        if (!$imageinfo || $imageinfo['width'] >= $width || $imageinfo['height'] >= $height) {
            throw new ExpectationException(
                'The image of "' . $field . '" has the dimensions ' . ($imageinfo['width'] ?? '?') . ' x ' .
                    ($imageinfo['height'] ?? '?') . ', expected dimensions smaller than ' . $width . ' x ' . $height,
                $this->getSession()
            );
        }
    }

    /** @var int[] The file sizes which were remembered per demo form field, see i_remember_the_file_size(). */
    private array $rememberedsizes = [];

    /**
     * Remembers the file size of the image which is stored for a demo form field, so that it can be compared later on.
     *
     * @When /^I remember the file size of the image of "(?P<field>[^"]*)"$/
     * @param string $field The demo form field name.
     */
    public function i_remember_the_file_size(string $field): void {
        $this->rememberedsizes[$field] = $this->require_demo_file($field)->get_filesize();
    }

    /**
     * Checks that the image which is stored for a demo form field has (about) the file size which was remembered for the
     * field.
     *
     * A tolerance of one percent is allowed: a browser does not necessarily encode the very same pixels into the very same
     * bytes twice, so two encodings of one image can differ by a few bytes.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should have about the remembered file size$/
     * @param string $field The demo form field name.
     * @throws ExpectationException
     */
    public function the_image_of_should_have_the_remembered_file_size(string $field): void {
        if (!isset($this->rememberedsizes[$field])) {
            throw new ExpectationException('No file size was remembered for the image of "' . $field . '"', $this->getSession());
        }
        $size = $this->require_demo_file($field)->get_filesize();
        if (abs($size - $this->rememberedsizes[$field]) > $this->rememberedsizes[$field] / 100) {
            throw new ExpectationException(
                'The image of "' . $field . '" has a file size of ' . $size . ' bytes, expected the remembered ' .
                    $this->rememberedsizes[$field] . ' bytes',
                $this->getSession()
            );
        }
    }

    /**
     * Checks the file name of the image which is stored for a demo form field.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should have the file name "(?P<filename>[^"]*)"$/
     * @param string $field The demo form field name.
     * @param string $filename The expected file name.
     * @throws ExpectationException
     */
    public function the_image_of_should_have_the_file_name(string $field, string $filename): void {
        $file = $this->require_demo_file($field);
        if ($file->get_filename() !== $filename) {
            throw new ExpectationException(
                'The image of "' . $field . '" is named "' . $file->get_filename() . '", expected "' . $filename . '"',
                $this->getSession()
            );
        }
    }

    /**
     * Checks the file size of the image which is stored for a demo form field.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should have a file size (?P<relation>below|above) "(?P<bytes>\d+)" bytes$/
     * @param string $field The demo form field name.
     * @param string $relation 'below' or 'above'.
     * @param int $bytes The file size to compare with.
     * @throws ExpectationException
     */
    public function the_image_of_should_have_a_file_size(string $field, string $relation, int $bytes): void {
        $file = $this->require_demo_file($field);
        $size = $file->get_filesize();
        $ok = ($relation === 'below') ? ($size < $bytes) : ($size > $bytes);
        if (!$ok) {
            throw new ExpectationException(
                'The image of "' . $field . '" has a file size of ' . $size . ' bytes, expected a size ' . $relation . ' ' .
                    $bytes . ' bytes',
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the image which is stored for a demo form field is, byte for byte, the given fixture file.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should be identical to the fixture "(?P<fixture>[^"]*)"$/
     * @param string $field The demo form field name.
     * @param string $fixture The fixture file, relative to the Moodle root directory.
     * @throws ExpectationException
     */
    public function the_image_of_should_be_identical_to_the_fixture(string $field, string $fixture): void {
        global $CFG;

        $file = $this->require_demo_file($field);
        $path = $CFG->dirroot . '/' . ltrim($fixture, '/');
        if (!is_readable($path)) {
            throw new ExpectationException('The fixture "' . $fixture . '" does not exist', $this->getSession());
        }
        if ($file->get_contenthash() !== sha1_file($path)) {
            throw new ExpectationException(
                'The image of "' . $field . '" (' . $file->get_filename() . ') differs from the fixture "' . $fixture . '"',
                $this->getSession()
            );
        }
    }

    /**
     * Checks that no image is stored for a demo form field.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should not exist$/
     * @param string $field The demo form field name.
     * @throws ExpectationException
     */
    public function the_image_of_should_not_exist(string $field): void {
        $file = $this->get_demo_file($field);
        if ($file) {
            throw new ExpectationException(
                'The image of "' . $field . '" exists (' . $file->get_filename() . '), expected none',
                $this->getSession()
            );
        }
    }

    /**
     * Checks whether the crop button of a demo form field is visible, hidden or missing altogether.
     *
     * The button is hidden while the file manager holds no file (or a file which cannot be cropped), and it shows up a
     * moment after a file has been added, so this waits for the expected state.
     *
     * The button is missing altogether for a field which was created without cropping. As the button is injected
     * asynchronously, its absence only means something once the page has settled, so this is checked once the file manager
     * has loaded its file list (which takes at least as long as loading the module) and a moment has passed on top.
     *
     * @Then /^the crop button of "(?P<field>[^"]*)" should be (?P<state>visible|hidden|missing)$/
     * @param string $field The demo form field name.
     * @param string $state 'visible', 'hidden' or 'missing'.
     * @throws ExpectationException
     */
    public function the_crop_button_should_be(string $field, string $state): void {
        $this->get_demo_itemid($field);
        $script = "
            return (function() {
                var wrapper = document.querySelector('#fitem_id_{$field} .tool-imagepicker-btn-crop');
                if (wrapper === null) {
                    return 'missing';
                }
                var loading = wrapper.closest('.filemanager.fm-loading') !== null;
                if (loading) {
                    return 'loading';
                }
                return (wrapper.offsetParent !== null && getComputedStyle(wrapper).display !== 'none') ? 'visible' : 'hidden';
            })();
        ";

        if ($state === 'missing') {
            $loadedscript = "
                return (function() {
                    var filemanager = document.querySelector('#fitem_id_{$field} .filemanager');
                    return filemanager !== null && !filemanager.classList.contains('fm-loading');
                })();
            ";
            $this->spin(function ($context) use ($loadedscript) {
                return ($context->evaluate_script($loadedscript) === true) ? true : false;
            }, [], self::get_extended_timeout(), new ExpectationException(
                'The file manager of "' . $field . '" did not load',
                $this->getSession()
            ), true);
            usleep(1000000);
            if ($this->evaluate_script($script) !== 'missing') {
                throw new ExpectationException(
                    'The crop button of "' . $field . '" exists, expected it to be missing',
                    $this->getSession()
                );
            }
            return;
        }

        $this->spin(function ($context) use ($script, $state) {
            return ($context->evaluate_script($script) === $state) ? true : false;
        }, [], self::get_extended_timeout(), new ExpectationException(
            'The crop button of "' . $field . '" did not become ' . $state,
            $this->getSession()
        ), true);
    }

    /**
     * Clicks the crop button of a demo form field, without waiting for anything to happen.
     *
     * @When /^I click on the crop button of "(?P<field>[^"]*)"$/
     * @param string $field The demo form field name.
     */
    public function i_click_on_the_crop_button(string $field): void {
        $this->the_crop_button_should_be($field, 'visible');
        $this->execute('behat_general::i_click_on', [
            '#fitem_id_' . $field . ' .tool-imagepicker-btn-crop a',
            'css_element',
        ]);
    }

    /**
     * Clicks the crop button of a demo form field twice, without any pause in between.
     *
     * The two clicks are dispatched from a single script, so nothing (not even the round trip to the browser) lies between
     * them. This is what a double click on the button amounts to.
     *
     * @When /^I click on the crop button of "(?P<field>[^"]*)" twice in quick succession$/
     * @param string $field The demo form field name.
     */
    public function i_click_on_the_crop_button_twice(string $field): void {
        $this->the_crop_button_should_be($field, 'visible');
        $this->execute_script("
            (function() {
                var link = document.querySelector('#fitem_id_{$field} .tool-imagepicker-btn-crop a');
                link.click();
                link.click();
            })();
        ");
    }

    /**
     * Opens the crop modal of a demo form field and waits until the cropper is ready to be used.
     *
     * @When /^I open the crop modal of "(?P<field>[^"]*)"$/
     * @param string $field The demo form field name.
     */
    public function i_open_the_crop_modal(string $field): void {
        $this->i_click_on_the_crop_button($field);
        $this->the_cropper_should_be_ready();
    }

    /**
     * Checks that exactly one crop modal is on the page.
     *
     * This waits until a crop modal is open and its cropper is ready, which gives a second modal every chance to show up,
     * and counts the crop modals then.
     *
     * @Then /^there should be exactly one crop modal$/
     * @throws ExpectationException
     */
    public function there_should_be_exactly_one_crop_modal(): void {
        $this->the_crop_modal_should_be('open');
        $this->the_cropper_should_be_ready();
        $count = (int) $this->evaluate_script("return document.querySelectorAll('" . self::CROP_MODAL . "').length;");
        if ($count !== 1) {
            throw new ExpectationException($count . ' crop modals exist, expected exactly one', $this->getSession());
        }
    }

    /**
     * Clicks the save button of the crop modal and checks that it shows its saving state right away.
     *
     * The saving state (a spinner in place of the label, the button disabled) is put on the button the moment it is clicked,
     * and it is taken off again only if nothing was stored. So it is read from within the very script which clicks the
     * button, before the crop has had any chance to be stored. The crop is stored as usual afterwards.
     *
     * @Then /^clicking the save button of the crop modal should show its saving state$/
     * @throws ExpectationException
     */
    public function clicking_the_save_button_should_show_its_saving_state(): void {
        $this->the_cropper_should_be_ready();
        $modal = self::CROP_MODAL;
        $json = $this->evaluate_script("
            return (function() {
                var save = document.querySelector('{$modal} [data-action=\"save\"]');
                save.click();
                return JSON.stringify({
                    disabled: save.disabled,
                    spinner: save.querySelector('.spinner-border') !== null,
                    text: save.textContent.trim()
                });
            })();
        ");
        $state = json_decode($json, true);
        $expectedtext = get_string('saving', 'tool_imagepicker');
        if (!$state['disabled'] || !$state['spinner'] || $state['text'] !== $expectedtext) {
            throw new ExpectationException(
                'The save button did not show its saving state when clicked (disabled: ' . var_export($state['disabled'], true) .
                    ', spinner: ' . var_export($state['spinner'], true) . ', text: "' . $state['text'] . '")',
                $this->getSession()
            );
        }
    }

    /**
     * Read the state of the cropper in the crop modal.
     *
     * The crop region is read the way the plugin reads it (see getNormalizedSelection() in the JavaScript module), i.e.
     * as fractions of the displayed image. Along with it come the natural size and the source of the loaded image, the
     * displayed width of the canvas and whether the modal is ready to be saved.
     *
     * @return array|null The state, or null if there is no cropper.
     */
    private function get_cropper_state(): ?array {
        $modal = self::CROP_MODAL;

        $json = $this->evaluate_script("
            return (function() {
                var modal = document.querySelector('{$modal}');
                var selection = modal ? modal.querySelector('cropper-selection') : null;
                var cropperimage = modal ? modal.querySelector('cropper-image') : null;
                var canvas = modal ? modal.querySelector('cropper-canvas') : null;
                var save = modal ? modal.querySelector('[data-action=\"save\"]') : null;
                if (!modal || !selection || !cropperimage || !canvas) {
                    return null;
                }
                var s = selection.getBoundingClientRect();
                var i = cropperimage.getBoundingClientRect();
                var c = canvas.getBoundingClientRect();
                var image = cropperimage.\$image || null;
                return JSON.stringify({
                    x: i.width ? (s.left - i.left) / i.width : 0,
                    y: i.height ? (s.top - i.top) / i.height : 0,
                    width: i.width ? s.width / i.width : 0,
                    height: i.height ? s.height / i.height : 0,
                    naturalwidth: image ? image.naturalWidth : 0,
                    naturalheight: image ? image.naturalHeight : 0,
                    src: image ? image.src : '',
                    canvaswidth: Math.round(c.width),
                    ready: !!(save && !save.disabled && image && image.naturalWidth > 0 && i.width > 0)
                });
            })();
        ");

        return $json ? json_decode($json, true) : null;
    }

    /**
     * Read the state of the cropper in the crop modal, failing if there is none.
     *
     * @return array The state.
     * @throws ExpectationException
     */
    private function require_cropper_state(): array {
        $state = $this->get_cropper_state();
        if ($state === null) {
            throw new ExpectationException('There is no cropper in a crop modal', $this->getSession());
        }

        return $state;
    }

    /**
     * Waits until the cropper in the crop modal has loaded its image and the modal can be saved.
     *
     * @Then /^the cropper should be ready$/
     * @throws ExpectationException
     */
    public function the_cropper_should_be_ready(): void {
        $exception = new ExpectationException('The cropper did not become ready', $this->getSession());
        $this->spin(function ($context) {
            $state = $context->get_cropper_state();
            return ($state !== null && $state['ready']) ? true : false;
        }, [], self::get_extended_timeout(), $exception, true);

        // The plugin restores a stored crop region a couple of animation frames after the cropper has become ready, so give
        // that a moment to land before the selection is read or changed.
        usleep(300000);
    }

    /**
     * Checks whether the crop modal is open.
     *
     * The modal is removed from the page when it is closed, so 'closed' means that there is no crop modal at all. A modal
     * fades out, and its backdrop goes last: until it is gone, it still covers the page and swallows every click, so
     * 'closed' also waits for the backdrop of the modal to be gone. A backdrop which belongs to a modal that is open (an
     * alert on top of the closed crop modal, for example) does not count.
     *
     * @Then /^the crop modal should be (?P<state>open|closed)$/
     * @param string $state 'open' or 'closed'.
     * @throws ExpectationException
     */
    public function the_crop_modal_should_be(string $state): void {
        $modal = self::CROP_MODAL;
        $script = "
            return (function() {
                var modal = document.querySelector('{$modal}');
                if (modal !== null && modal.classList.contains('show')) {
                    return 'open';
                }
                // The backdrop stays in the page (hidden) once it has faded out, so only a displayed one counts.
                var backdrops = 0;
                document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
                    if (backdrop.getClientRects().length > 0) {
                        backdrops++;
                    }
                });
                var openmodals = document.querySelectorAll('.modal.show').length;
                return (backdrops > openmodals) ? 'closing' : 'closed';
            })();
        ";
        $exception = new ExpectationException('The crop modal is not ' . $state, $this->getSession());
        $this->spin(function ($context) use ($script, $state) {
            return ($context->evaluate_script($script) === $state) ? true : false;
        }, [], self::get_extended_timeout(), $exception, true);
    }

    /**
     * Sets the crop selection, given as fractions (0..1) of the image: x, y, width and height.
     *
     * @When /^I set the crop selection to "(?P<x>[\d.]+) \/ (?P<y>[\d.]+) \/ (?P<width>[\d.]+) \/ (?P<height>[\d.]+)"$/
     * @param float $x The x position.
     * @param float $y The y position.
     * @param float $width The width.
     * @param float $height The height.
     */
    public function i_set_the_crop_selection_to(float $x, float $y, float $width, float $height): void {
        $this->require_cropper_state();
        $modal = self::CROP_MODAL;
        $this->execute_script("
            (function() {
                var modal = document.querySelector('{$modal}');
                var selection = modal.querySelector('cropper-selection');
                var i = modal.querySelector('cropper-image').getBoundingClientRect();
                var c = modal.querySelector('cropper-canvas').getBoundingClientRect();
                selection.\$change(
                    (i.left - c.left) + {$x} * i.width,
                    (i.top - c.top) + {$y} * i.height,
                    {$width} * i.width,
                    {$height} * i.height
                );
            })();
        ");
        // The cropper renders the change on the next animation frame.
        usleep(200000);
    }

    /**
     * Moves the crop selection by the given number of pixels, the way dragging it with the mouse does.
     *
     * This calls the very method of the cropper which its mouse handling calls, so it is subject to everything a drag is
     * subject to - in particular to the limits which keep the selection within the image.
     *
     * @When /^I move the crop selection by "(?P<x>-?\d+) \/ (?P<y>-?\d+)" pixels$/
     * @param int $x The horizontal distance.
     * @param int $y The vertical distance.
     */
    public function i_move_the_crop_selection_by(int $x, int $y): void {
        $this->require_cropper_state();
        $modal = self::CROP_MODAL;
        $this->execute_script("
            document.querySelector('{$modal} cropper-selection').\$move({$x}, {$y});
        ");
        // The cropper renders the change on the next animation frame.
        usleep(200000);
    }

    /**
     * Resizes the crop selection by dragging one of its handles by the given number of pixels, the way the mouse does.
     *
     * This calls the very method of the cropper which its mouse handling calls, see i_move_the_crop_selection_by().
     *
     * @When /^I drag the "(?P<handle>[a-z]+)" handle of the crop selection by "(?P<x>-?\d+) \/ (?P<y>-?\d+)" pixels$/
     * @param string $handle The handle: 'north', 'east', 'south', 'west', 'northeast', 'northwest', 'southeast' or 'southwest'.
     * @param int $x The horizontal distance.
     * @param int $y The vertical distance.
     * @throws ExpectationException
     */
    public function i_drag_the_handle_of_the_crop_selection_by(string $handle, int $x, int $y): void {
        $this->require_cropper_state();
        $actions = [
            'north' => 'n-resize',
            'east' => 'e-resize',
            'south' => 's-resize',
            'west' => 'w-resize',
            'northeast' => 'ne-resize',
            'northwest' => 'nw-resize',
            'southeast' => 'se-resize',
            'southwest' => 'sw-resize',
        ];
        if (!isset($actions[$handle])) {
            throw new ExpectationException('Unknown handle "' . $handle . '"', $this->getSession());
        }
        $action = $actions[$handle];
        $modal = self::CROP_MODAL;
        $this->execute_script("
            document.querySelector('{$modal} cropper-selection').\$resize('{$action}', {$x}, {$y});
        ");
        // The cropper renders the change on the next animation frame.
        usleep(200000);
    }

    /**
     * Checks that the image which is stored for a demo form field has no empty edge.
     *
     * Whatever a crop selection covers beyond the image is rendered as an empty area: transparent in a PNG or a WebP image,
     * black in a JPEG image. Such an area shows up as an outermost row or column of pixels which is empty throughout, which
     * is what this looks for. It only makes sense for an image whose content is not black at its edges, of course.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should have no empty edge$/
     * @param string $field The demo form field name.
     * @throws ExpectationException
     */
    public function the_image_of_should_have_no_empty_edge(string $field): void {
        $file = $this->require_demo_file($field);
        $image = @imagecreatefromstring($file->get_content());
        if (!$image) {
            throw new ExpectationException(
                'The image of "' . $field . '" (' . $file->get_filename() . ') cannot be read',
                $this->getSession()
            );
        }
        $width = imagesx($image);
        $height = imagesy($image);

        // Whether the pixel is empty, i.e. (nearly) transparent or (nearly) black.
        $isempty = function (int $x, int $y) use ($image): bool {
            $colour = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            return $colour['alpha'] >= 120 || ($colour['red'] < 12 && $colour['green'] < 12 && $colour['blue'] < 12);
        };

        // The share of empty pixels in each of the four outermost rows and columns.
        $edges = ['left' => 0, 'right' => 0, 'top' => 0, 'bottom' => 0];
        for ($y = 0; $y < $height; $y++) {
            $edges['left'] += $isempty(0, $y) ? 1 / $height : 0;
            $edges['right'] += $isempty($width - 1, $y) ? 1 / $height : 0;
        }
        for ($x = 0; $x < $width; $x++) {
            $edges['top'] += $isempty($x, 0) ? 1 / $width : 0;
            $edges['bottom'] += $isempty($x, $height - 1) ? 1 / $width : 0;
        }
        foreach ($edges as $edge => $share) {
            if ($share > 0.5) {
                throw new ExpectationException(
                    'The image of "' . $field . '" is empty at its ' . $edge . ' edge (' . round($share * 100) .
                        ' % of the pixels there are transparent or black)',
                    $this->getSession()
                );
            }
        }
    }

    /**
     * Checks the crop selection, given as fractions (0..1) of the image: x, y, width and height (with a small tolerance).
     *
     * @Then /^the crop selection should cover approximately "([\d.]+) \/ ([\d.]+) \/ ([\d.]+) \/ ([\d.]+)"$/
     * @param float $x The x position.
     * @param float $y The y position.
     * @param float $width The width.
     * @param float $height The height.
     * @throws ExpectationException
     */
    public function the_crop_selection_should_cover(float $x, float $y, float $width, float $height): void {
        $state = $this->require_cropper_state();
        $expected = ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
        foreach ($expected as $key => $value) {
            if (abs($state[$key] - $value) > self::FRACTION_TOLERANCE) {
                throw new ExpectationException(
                    'The crop selection covers ' . $this->format_region($state) . ', expected ' .
                        $this->format_region($expected),
                    $this->getSession()
                );
            }
        }
    }

    /**
     * Checks the aspect ratio of the crop selection (with a small tolerance).
     *
     * @Then /^the crop selection should have the aspect ratio "(?P<width>\d+):(?P<height>\d+)"$/
     * @param int $width The width part of the ratio.
     * @param int $height The height part of the ratio.
     * @throws ExpectationException
     */
    public function the_crop_selection_should_have_the_aspect_ratio(int $width, int $height): void {
        $state = $this->require_cropper_state();
        // The fractions are relative to the image, so the image ratio has to be taken into account.
        $imageratio = $state['naturalwidth'] / $state['naturalheight'];
        $actual = ($state['width'] * $imageratio) / $state['height'];
        $expected = $width / $height;
        if (abs($actual - $expected) > 0.02) {
            throw new ExpectationException(
                'The crop selection has the aspect ratio ' . round($actual, 3) . ', expected ' . $width . ':' . $height .
                    ' (' . round($expected, 3) . ')',
                $this->getSession()
            );
        }
    }

    /**
     * Checks the natural size of the image which is loaded into the cropper.
     *
     * This tells whether the cropper works on the preserved original or on the (smaller) cropped image.
     *
     * @Then /^the cropper should show an image of "(?P<width>\d+) x (?P<height>\d+)" pixels$/
     * @param int $width The expected natural width.
     * @param int $height The expected natural height.
     * @throws ExpectationException
     */
    public function the_cropper_should_show_an_image_of(int $width, int $height): void {
        $state = $this->require_cropper_state();
        if ($state['naturalwidth'] !== $width || $state['naturalheight'] !== $height) {
            throw new ExpectationException(
                'The cropper shows an image of ' . $state['naturalwidth'] . ' x ' . $state['naturalheight'] .
                    ' pixels, expected ' . $width . ' x ' . $height,
                $this->getSession()
            );
        }
    }

    /**
     * Checks whether the cropper works on a still of the image (which is what an animated image is turned into) rather than
     * on the served file itself.
     *
     * @Then /^the cropper image should (?P<not>not )?be a still$/
     * @param string $not 'not ' to expect the served file.
     * @throws ExpectationException
     */
    public function the_cropper_image_should_be_a_still(string $not = ''): void {
        $state = $this->require_cropper_state();
        $isstill = str_starts_with($state['src'], 'blob:');
        if ($isstill === ($not !== '')) {
            throw new ExpectationException(
                'The cropper image is loaded from "' . $state['src'] . '", expected ' . ($not ? 'the served file' : 'a still'),
                $this->getSession()
            );
        }
    }

    /**
     * Checks the width at which the cropper canvas is displayed.
     *
     * @Then /^the cropper canvas should be "(?P<width>\d+)" pixels wide$/
     * @param int $width The expected width.
     * @throws ExpectationException
     */
    public function the_cropper_canvas_should_be_pixels_wide(int $width): void {
        $state = $this->require_cropper_state();
        if (abs($state['canvaswidth'] - $width) > self::DIMENSION_TOLERANCE) {
            throw new ExpectationException(
                'The cropper canvas is ' . $state['canvaswidth'] . ' pixels wide, expected ' . $width,
                $this->getSession()
            );
        }
    }

    /**
     * Saves the crop modal and waits until it has gone.
     *
     * This is for a crop which is stored right away. If the cropped image runs into the size limit, a second dialogue asks
     * what to do, and the crop modal stays open; use the usual click steps in that case.
     *
     * @When /^I save the crop$/
     */
    public function i_save_the_crop(): void {
        $this->execute('behat_general::i_click_on_in_the', ['Save', 'button', self::CROP_MODAL, 'css_element']);
        $this->the_crop_modal_should_be('closed');
    }

    /**
     * Removes all draft files of the given user, as the draft area cleanup of the site does after a few days.
     *
     * A preserved original counts as in use as long as the image which it backs exists anywhere, and that includes the draft
     * areas of the forms in which it was edited. So an original only becomes orphaned once these drafts are gone, which is
     * what this step brings about right away.
     *
     * @Given /^the draft files of "(?P<username>[^"]*)" are cleaned up$/
     * @param string $username The user name.
     * @throws ExpectationException
     */
    public function the_draft_files_of_user_are_cleaned_up(string $username): void {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['username' => $username]);
        if (!$userid) {
            throw new ExpectationException('The user "' . $username . '" does not exist', $this->getSession());
        }
        get_file_storage()->delete_area_files(context_user::instance($userid)->id, 'user', 'draft');
    }

    /**
     * Checks the number of preserved originals on the site.
     *
     * @Then /^"(?P<count>\d+)" preserved originals? should exist$/
     * @param int $count The expected number.
     * @throws ExpectationException
     */
    public function preserved_originals_should_exist(int $count): void {
        global $DB;

        $actual = $DB->count_records('tool_imagepicker_original');
        if ($actual !== $count) {
            throw new ExpectationException(
                $actual . ' preserved originals exist, expected ' . $count,
                $this->getSession()
            );
        }
    }

    /**
     * Get the single preserved original record on the site.
     *
     * @return stdClass The record.
     * @throws ExpectationException If there is not exactly one.
     */
    private function require_single_original(): stdClass {
        global $DB;

        $records = $DB->get_records('tool_imagepicker_original');
        if (count($records) !== 1) {
            throw new ExpectationException(
                count($records) . ' preserved originals exist, expected exactly one',
                $this->getSession()
            );
        }

        return reset($records);
    }

    /**
     * Checks the place which the (single) preserved original names, given as "component / filearea / itemid". The item id
     * may be "*" to accept any.
     *
     * @Then /^the preserved original should (?P<not>not )?be placed in "(?P<place>[^"]*)"$/
     * @param string $not 'not ' to expect any other place.
     * @param string $place The place.
     * @throws ExpectationException
     */
    public function the_preserved_original_should_be_placed_in(string $not, string $place): void {
        $record = $this->require_single_original();
        $parts = array_map('trim', explode('/', $place));
        if (count($parts) !== 3) {
            throw new ExpectationException('The place has to be given as "component / filearea / itemid"', $this->getSession());
        }
        [$component, $filearea, $itemid] = $parts;
        $actual = $record->component . ' / ' . $record->filearea . ' / ' . $record->itemid;
        $matches = $record->component === $component && $record->filearea === $filearea &&
            ($itemid === '*' || (int) $record->itemid === (int) $itemid);
        if ($matches === ($not !== '')) {
            throw new ExpectationException(
                'The preserved original is placed in "' . $actual . '", expected ' . ($not ? 'any place but' : '') . ' "' .
                    $place . '"',
                $this->getSession()
            );
        }
    }

    /**
     * Checks the context in which the (single) preserved original and its file are stored.
     *
     * @Then /^the preserved original should (?P<not>not )?be stored in the (?P<level>system|user) context$/
     * @param string $not 'not ' to expect any other kind of context.
     * @param string $level 'system' or 'user'.
     * @throws ExpectationException
     */
    public function the_preserved_original_should_be_stored_in_the_context(string $not, string $level): void {
        $record = $this->require_single_original();
        $context = context::instance_by_id($record->contextid, IGNORE_MISSING);
        $expectedlevel = ($level === 'system') ? CONTEXT_SYSTEM : CONTEXT_USER;
        $matches = $context && $context->contextlevel === $expectedlevel;
        if ($matches === ($not !== '')) {
            throw new ExpectationException(
                'The preserved original record is stored in context ' . $record->contextid . ', expected ' .
                    ($not ? 'anything but ' : '') . 'a ' . $level . ' context',
                $this->getSession()
            );
        }
        $file = originals::get_file($record);
        if (!$file) {
            throw new ExpectationException('The preserved original file is missing', $this->getSession());
        }
        if ((int) $file->get_contextid() !== (int) $record->contextid) {
            throw new ExpectationException(
                'The preserved original file is stored in context ' . $file->get_contextid() . ' while its record names ' .
                    'context ' . $record->contextid,
                $this->getSession()
            );
        }
    }

    /**
     * Removes the file of the (single) preserved original from the file storage, leaving its record behind.
     *
     * @When /^I delete the preserved original file from storage$/
     * @throws ExpectationException
     */
    public function i_delete_the_preserved_original_file_from_storage(): void {
        $record = $this->require_single_original();
        $file = originals::get_file($record);
        if (!$file) {
            throw new ExpectationException('The preserved original file is missing already', $this->getSession());
        }
        $file->delete();
    }

    /**
     * Checks the author and the licence of the image which is stored for a demo form field.
     *
     * @Then /^the image of "(?P<field>[^"]*)" should have the author "(?P<author>[^"]*)" and the licence "(?P<licence>[^"]*)"$/
     * @param string $field The demo form field name.
     * @param string $author The expected author.
     * @param string $licence The expected licence shortname.
     * @throws ExpectationException
     */
    public function the_image_of_should_have_the_author_and_licence(string $field, string $author, string $licence): void {
        $file = $this->require_demo_file($field);
        if ((string) $file->get_author() !== $author || (string) $file->get_license() !== $licence) {
            throw new ExpectationException(
                'The image of "' . $field . '" has the author "' . $file->get_author() . '" and the licence "' .
                    $file->get_license() . '", expected "' . $author . '" and "' . $licence . '"',
                $this->getSession()
            );
        }
    }

    /**
     * Checks whether the demo form is marked as changed (which is what warns the user about unsaved changes when they leave
     * the page).
     *
     * @Then /^the demo form should (?P<not>not )?be marked as changed$/
     * @param string $not 'not ' to expect an unchanged form.
     * @throws ExpectationException
     */
    public function the_demo_form_should_be_marked_as_changed(string $not = ''): void {
        // The form change checker of core marks a changed form with a data attribute.
        $dirty = $this->evaluate_script("
            return (function() {
                var form = document.querySelector('#fitem_id_imagepicker').closest('form');
                return form !== null && form.dataset.formDirty === 'true';
            })();
        ");
        if (($dirty === true) === ($not !== '')) {
            throw new ExpectationException(
                'The demo form is ' . ($dirty ? '' : 'not ') . 'marked as changed, expected it ' . ($not ? 'not ' : '') .
                    'to be',
                $this->getSession()
            );
        }
    }

    /**
     * Read the state of the file dialog of the core file manager which is currently open.
     *
     * Every file manager on the page has a file dialog of its own, which is hidden until a file entry is clicked, so the
     * visible one is the one which is open.
     *
     * @return array|null The 'filename', 'author' and 'licence' which the dialog shows, or null if no dialog is open.
     */
    private function get_file_dialog_state(): ?array {
        $json = $this->evaluate_script("
            return (function() {
                var dialogs = document.querySelectorAll('.filemanager.fp-select');
                for (var i = 0; i < dialogs.length; i++) {
                    var dialog = dialogs[i];
                    if (dialog.getClientRects().length === 0) {
                        continue;
                    }
                    var licence = dialog.querySelector('.fp-license select');
                    var option = (licence && licence.selectedIndex >= 0) ? licence.options[licence.selectedIndex] : null;
                    return JSON.stringify({
                        filename: dialog.querySelector('.fp-saveas input').value,
                        author: dialog.querySelector('.fp-author input').value,
                        licence: option ? option.textContent.trim() : ''
                    });
                }
                return null;
            })();
        ");

        return $json ? json_decode($json, true) : null;
    }

    /**
     * Opens the file dialog of the core file manager for the file which a demo form field holds.
     *
     * This clicks the file entry, which is how the file manager opens the dialog. It works in the icon view of the file
     * manager, which is its default view.
     *
     * @When /^I open the file dialog of "(?P<field>[^"]*)"$/
     * @param string $field The demo form field name.
     * @throws ExpectationException
     */
    public function i_open_the_file_dialog(string $field): void {
        $this->get_demo_itemid($field);
        $this->execute('behat_general::i_click_on', [
            '#fitem_id_' . $field . ' .fp-content .fp-file .fp-filename-field',
            'css_element',
        ]);
        $this->spin(function ($context) {
            return ($context->get_file_dialog_state() !== null) ? true : false;
        }, [], self::get_extended_timeout(), new ExpectationException(
            'The file dialog of "' . $field . '" did not open',
            $this->getSession()
        ), true);
    }

    /**
     * Checks what the open file dialog of the core file manager shows.
     *
     * @Then /^the file dialog should show the (?P<property>file name|author|licence) "(?P<value>[^"]*)"$/
     * @param string $property 'file name', 'author' or 'licence'.
     * @param string $value The expected value (for the licence, its displayed name).
     * @throws ExpectationException
     */
    public function the_file_dialog_should_show(string $property, string $value): void {
        $state = $this->get_file_dialog_state();
        if ($state === null) {
            throw new ExpectationException('There is no open file dialog', $this->getSession());
        }
        $key = ($property === 'file name') ? 'filename' : $property;
        if ($state[$key] !== $value) {
            throw new ExpectationException(
                'The file dialog shows the ' . $property . ' "' . $state[$key] . '", expected "' . $value . '"',
                $this->getSession()
            );
        }
    }

    /**
     * Closes the open file dialog of the core file manager without changing anything.
     *
     * @When /^I close the file dialog$/
     * @throws ExpectationException
     */
    public function i_close_the_file_dialog(): void {
        if ($this->get_file_dialog_state() === null) {
            throw new ExpectationException('There is no open file dialog', $this->getSession());
        }
        $this->execute_script("
            (function() {
                var dialogs = document.querySelectorAll('.filemanager.fp-select');
                for (var i = 0; i < dialogs.length; i++) {
                    if (dialogs[i].getClientRects().length > 0) {
                        dialogs[i].querySelector('.fp-file-cancel').click();
                        return;
                    }
                }
            })();
        ");
        $this->spin(function ($context) {
            return ($context->get_file_dialog_state() === null) ? true : false;
        }, [], self::get_extended_timeout(), new ExpectationException(
            'The file dialog did not close',
            $this->getSession()
        ), true);
    }

    /**
     * Fetch a URL from within the current page, as the user who is logged in.
     *
     * This is how a response is inspected which the browser must not navigate to: a Moodle error page, for example, which
     * the Behat hooks of Moodle would report as a failure the moment it is on screen. The fetch bypasses the browser cache,
     * as every user of a scenario shares the same browser and the plugin lets the browser keep some of its files for a
     * minute (see \tool_imagepicker\local\fileserving): it has to be the server which answers here.
     *
     * @param string $url The URL.
     * @return array The response 'status', its content 'type' and (the beginning of) its 'body'.
     * @throws ExpectationException
     */
    private function fetch_as_current_user(string $url): array {
        $this->execute_script("
            window.toolImagepickerFetch = null;
            fetch(" . json_encode($url) . ", {credentials: 'same-origin', cache: 'no-store'}).then(function(response) {
                return response.text().then(function(body) {
                    window.toolImagepickerFetch = {
                        status: response.status,
                        type: response.headers.get('Content-Type') || '',
                        body: body.substring(0, 500000)
                    };
                    return null;
                });
            }).catch(function(error) {
                window.toolImagepickerFetch = {status: 0, type: '', body: String(error)};
            });
        ");
        $this->spin(function ($context) {
            return ($context->evaluate_script('return window.toolImagepickerFetch !== null;') === true) ? true : false;
        }, [], self::get_extended_timeout(), new ExpectationException(
            'The URL ' . $url . ' could not be fetched',
            $this->getSession()
        ), true);

        return json_decode($this->evaluate_script('return JSON.stringify(window.toolImagepickerFetch);'), true);
    }

    /**
     * Checks whether the (single) preserved original is served to the user who is logged in.
     *
     * @Then /^the preserved original should (?P<not>not )?be served to me$/
     * @param string $not 'not ' to expect the original to be refused.
     * @throws ExpectationException
     */
    public function the_preserved_original_should_be_served_to_me(string $not = ''): void {
        $record = $this->require_single_original();
        $file = originals::get_file($record);
        if (!$file) {
            throw new ExpectationException('The preserved original file is missing', $this->getSession());
        }
        $response = $this->fetch_as_current_user(originals::get_url($file)->out(false));
        $served = ($response['status'] === 200) && str_starts_with($response['type'], 'image/');
        if ($served === ($not !== '')) {
            throw new ExpectationException(
                'The preserved original was ' . ($served ? '' : 'not ') . 'served (status ' . $response['status'] .
                    ', content type "' . $response['type'] . '"), expected it ' . ($not ? 'not ' : '') . 'to be',
                $this->getSession()
            );
        }
        if (!$served && $response['status'] !== 404) {
            throw new ExpectationException(
                'The preserved original was refused with the status ' . $response['status'] . ', expected 404',
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the user who is logged in is refused access to one of the admin pages of the plugin.
     *
     * @Then /^I should be refused access to the "tool_imagepicker > (?P<page>[^"]*)" page$/
     * @param string $page The page name, see resolve_page_url().
     * @throws ExpectationException
     */
    public function i_should_be_refused_access_to_the_page(string $page): void {
        $response = $this->fetch_as_current_user($this->resolve_page_url($page)->out(false));
        $refused = str_contains($response['body'], get_string('accessdenied', 'admin'));
        if (!$refused) {
            throw new ExpectationException(
                'The "' . $page . '" page did not refuse access (status ' . $response['status'] . ')',
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the browser shows an image (rather than a page) right now.
     *
     * @Then /^the current page should be an image$/
     * @throws ExpectationException
     */
    public function the_current_page_should_be_an_image(): void {
        $contenttype = (string) $this->evaluate_script('return document.contentType;');
        if (!str_starts_with($contenttype, 'image/')) {
            throw new ExpectationException(
                'The current page has the content type "' . $contenttype . '", expected an image',
                $this->getSession()
            );
        }
    }

    /**
     * Format a crop region for a message.
     *
     * @param array $region The region with the keys 'x', 'y', 'width' and 'height'.
     * @return string
     */
    private function format_region(array $region): string {
        return round($region['x'], 3) . ' / ' . round($region['y'], 3) . ' / ' . round($region['width'], 3) . ' / ' .
            round($region['height'], 3);
    }
}
