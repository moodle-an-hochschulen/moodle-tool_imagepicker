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
 * Admin tool "Image Picker" - Settings.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_imagepicker', get_string('pluginname', 'tool_imagepicker'));
    $ADMIN->add('tools', $settings);

    // Register the demo page as a hidden admin page. It is reached through the button on the settings page below and does
    // not need an entry of its own in the admin tree.
    $ADMIN->add('tools', new admin_externalpage(
        'tool_imagepicker_demo',
        get_string('demo_pagetitle', 'tool_imagepicker'),
        new core\url('/admin/tool/imagepicker/demo.php'),
        'moodle/site:config',
        true
    ));

    // Register the report page as a hidden admin page as well.
    $ADMIN->add('tools', new admin_externalpage(
        'tool_imagepicker_report',
        get_string('report_pagetitle', 'tool_imagepicker'),
        new core\url('/admin/tool/imagepicker/report.php'),
        'moodle/site:config',
        true
    ));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'tool_imagepicker/croppingheading',
            get_string('setting_croppingheading', 'tool_imagepicker'),
            '',
        ));

        $settings->add(new admin_setting_configcheckbox(
            'tool_imagepicker/preserveoriginals',
            get_string('setting_preserveoriginals', 'tool_imagepicker'),
            get_string('setting_preserveoriginals_desc', 'tool_imagepicker'),
            0
        ));

        // Encoding quality of cropped images (in percent). Only applies to lossy image formats.
        $qualityoptions = [];
        foreach ([100, 95, 92, 90, 85, 80, 75, 70, 65, 60, 55, 50] as $quality) {
            $qualityoptions[$quality] = $quality . ' %';
        }
        $settings->add(new admin_setting_configselect(
            'tool_imagepicker/encodingquality',
            get_string('setting_encodingquality', 'tool_imagepicker'),
            get_string('setting_encodingquality_desc', 'tool_imagepicker'),
            92,
            $qualityoptions
        ));

        // Strategy for reducing a cropped image which exceeds the maximum file size of the form field.
        $settings->add(new admin_setting_configselect(
            'tool_imagepicker/sizelimitstrategy',
            get_string('setting_sizelimitstrategy', 'tool_imagepicker'),
            get_string('setting_sizelimitstrategy_desc', 'tool_imagepicker'),
            'scaledown',
            [
                'scaledown' => get_string('setting_sizelimitstrategy_scaledown', 'tool_imagepicker'),
                'lowerquality' => get_string('setting_sizelimitstrategy_lowerquality', 'tool_imagepicker'),
            ]
        ));

        // Report page.
        $settings->add(new admin_setting_heading(
            'tool_imagepicker/reportheading',
            get_string('setting_reportheading', 'tool_imagepicker'),
            '',
        ));

        $settings->add(new admin_setting_description(
            'tool_imagepicker/reportbutton',
            get_string('setting_reportbutton', 'tool_imagepicker'),
            html_writer::link(
                new core\url('/admin/tool/imagepicker/report.php'),
                get_string('setting_reportbutton', 'tool_imagepicker'),
                ['class' => 'btn btn-secondary mb-2', 'role' => 'button']
            ) .
            html_writer::tag('p', get_string('setting_reportbutton_desc', 'tool_imagepicker'))
        ));

        // Demo page.
        $settings->add(new admin_setting_heading(
            'tool_imagepicker/demoheading',
            get_string('setting_demoheading', 'tool_imagepicker'),
            '',
        ));

        $settings->add(new admin_setting_description(
            'tool_imagepicker/demobutton',
            get_string('setting_demobutton', 'tool_imagepicker'),
            html_writer::link(
                new core\url('/admin/tool/imagepicker/demo.php'),
                get_string('setting_demobutton', 'tool_imagepicker'),
                ['class' => 'btn btn-secondary mb-2', 'role' => 'button']
            ) .
            html_writer::tag('p', get_string('setting_demobutton_desc', 'tool_imagepicker'))
        ));
    }
}
