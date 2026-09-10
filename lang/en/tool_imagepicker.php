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
 * Admin tool "Image Picker" - Language pack.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['cropimage'] = 'Crop image';
$string['demo_image'] = 'Image';
$string['demo_imagejpeg'] = 'Image (JPEG only)';
$string['demo_imagenocrop'] = 'Image (without cropping)';
$string['demo_imagepicker'] = 'Image picker element';
$string['demo_imagepicker_desc'] = 'This is the dedicated image picker element which this plugin provides. It always holds a single web image, no matter which options it was created with, and it offers free cropping.';
$string['demo_imagepickerjpeg'] = 'Image picker element restricted to JPEG images';
$string['demo_imagepickerjpeg_desc'] = 'This is the dedicated image picker element again, created with the accepted file types .jpg, .jpeg, .jpe and .pdf. The element drops the file types which are not web images, so this field accepts JPEG images only. An image of another type which sits in the field nevertheless (because it was put there before the field was handled by this element, for example) gets no crop button, as cropping it would yield a PNG image which the field does not accept.';
$string['demo_imagepickernocrop'] = 'Image picker element without cropping';
$string['demo_imagepickernocrop_desc'] = 'This is the dedicated image picker element again, created with the enablecrop option set to false. It is a plain file manager for a single web image then and offers no crop button.';
$string['demo_imagepickerratio'] = 'Image picker element with a fixed aspect ratio';
$string['demo_imagepickerratio_desc'] = 'This is the dedicated image picker element again, created with the cropaspectratio option set to 16:9. The crop selection keeps this aspect ratio.';
$string['demo_imageratio'] = 'Image (16:9)';
$string['demo_pageintro'] = 'This page shows the ways in which the image picker performs in a form. The images are stored when the form is saved, so the whole life cycle of an image can be tried out here: upload it, crop it, save the form and crop it again. This page serves as a pure demo of the functionalities of this plugin, the uploaded images are shown nowhere.';
$string['demo_pagetitle'] = 'Image picker demo';
$string['error_cannotscaledown'] = 'The cropped image cannot be scaled down far enough to fit into the maximum file size which is allowed here.';
$string['error_imageloadfailed'] = 'The image could not be loaded from the server, so it cannot be cropped. Please try again later.';
$string['error_imagetoolarge'] = 'The cropped image is larger than the maximum file size which is allowed here ({$a}).';
$string['error_invalidcropregion'] = 'The selected crop area does not lie completely within the image. Please select an area within the image and try again.';
$string['error_invalidimagecontent'] = 'The image content is invalid.';
$string['error_invalidimagetype'] = 'The image type is not allowed.';
$string['error_nodimensions'] = 'This image cannot be cropped, as it could not be loaded at its natural size. This is the case for vector images (SVG) which do not specify a width and a height.';
$string['error_noimagetocrop'] = 'Please add an image before cropping it.';
$string['note_animated'] = 'This image is animated. Only its first frame will be kept after cropping.';
$string['note_formatchange'] = 'This image will be saved as {$a} after cropping, as its current file format cannot be written by the image cropper.';
$string['note_originalpreserved'] = 'The original image is preserved on the server to allow modifications of the cropping area later if needed. If you want to crop sensitive image content, please consider cropping the image locally first instead.';
$string['pluginname'] = 'Image picker';
$string['privacy:metadata'] = 'The image picker preserves the original image which backs a cropped image. Such an original is stored in the same context as the image which it backs and it is stored without an author, as it belongs to that image and not to the user who cropped it. While the image has not been saved anywhere yet, this is the user context of the user who uploaded it, next to their draft files; the original moves along with the image as soon as it is saved and edited again. As soon as the image which it backs is deleted, the original is deleted as well. Thus, the plugin stores no personal data.';
$string['report_column_crop'] = 'Last crop region';
$string['report_column_cropped'] = 'Cropped image status';
$string['report_column_id'] = 'ID';
$string['report_column_original'] = 'Original image status';
$string['report_column_place'] = 'Place of the image (component / file area / item id)';
$string['report_column_timemodified'] = 'Last modified';
$string['report_contextmissing'] = 'Context {$a} (deleted)';
$string['report_cropped_inuse'] = 'In use';
$string['report_cropped_orphaned'] = 'Orphaned';
$string['report_cropposition_percent'] = 'at {$a->x} % / {$a->y} % from top left';
$string['report_cropposition_px'] = 'at {$a->x} / {$a->y} px from top left';
$string['report_cropsize_percent'] = '{$a->width} % × {$a->height} % of the original image';
$string['report_cropsize_px'] = '{$a->width} × {$a->height} px';
$string['report_delete'] = 'Delete original';
$string['report_deleteall'] = 'Delete all originals';
$string['report_deleteallconfirm'] = 'Do you really want to delete all preserved originals on this site? The cropped images which they back are not affected, but they cannot be cropped from their originals any more afterwards.';
$string['report_deleteconfirm'] = 'Do you really want to delete the preserved original with the ID {$a}? The cropped image which it backs is not affected, but it cannot be cropped from the original any more afterwards.';
$string['report_deleted'] = 'The preserved original was deleted.';
$string['report_deletedall'] = '{$a} preserved originals were deleted.';
$string['report_disabled'] = 'The preservation of original images is currently disabled, so cropping does not preserve anything at the moment. Originals which were preserved before are still listed here, but they are not used for cropping any more: as soon as the image which such an original backs is cropped again or deleted, the original becomes orphaned and is removed by the scheduled task. If you do not want to keep them until then, you can delete them here.';
$string['report_draftplace'] = 'Draft, not saved yet';
$string['report_enabled'] = 'The preservation of original images is currently enabled. Every image which is cropped for the first time is preserved as an original and listed here.';
$string['report_nothingtodisplay'] = 'There are no preserved originals on this site at the moment.';
$string['report_original_inplace'] = 'In place';
$string['report_original_missing'] = 'Missing';
$string['report_pageintro'] = 'This report lists the original (uncropped) images which the image picker preserves when an image is cropped. An original is orphaned as soon as the cropped image which it backs does not exist anywhere any more; orphaned originals are removed by a scheduled task, but they can be deleted here right away as well.';
$string['report_pagetitle'] = 'Preserved originals';
$string['report_view'] = 'View original';
$string['saving'] = 'Saving the cropped image...';
$string['setting_croppingheading'] = 'Cropping';
$string['setting_demobutton'] = 'View demo page';
$string['setting_demobutton_desc'] = 'There is a demo page where you can try out the image picker in its different forms. It is available to admins only.';
$string['setting_demoheading'] = 'Demo';
$string['setting_encodingquality'] = 'Image quality after cropping';
$string['setting_encodingquality_desc'] = 'The quality with which a cropped image is encoded. This only applies to lossy image formats (JPEG and WebP); PNG is lossless and ignores this setting. A higher quality means a larger file. The default of 92 % is a good compromise between image quality and file size for most images.';
$string['setting_preserveoriginals'] = 'Preserve original images';
$string['setting_preserveoriginals_desc'] = 'If enabled, the original (uncropped) image is preserved when an image is cropped. Cropping then always starts from the original image, so it stays non-destructive and the original is never lost. This requires additional file storage. Preserved originals which are no longer used are removed automatically by a scheduled task.<br /><br />If disabled, cropping starts from the currently stored image and replaces it. Originals which were preserved while the setting was enabled are kept, but they are not used for cropping any more: as soon as such an image is cropped again or deleted, its original becomes unused and is removed by the scheduled task as well. Originals which you do not want to keep until then can be deleted right away in the preserved originals report.';
$string['setting_reportbutton'] = 'View preserved originals';
$string['setting_reportbutton_desc'] = 'There is a report which lists all original images which the plugin preserves on the site, where they can be viewed and deleted. It is available to admins only.';
$string['setting_reportheading'] = 'Report';
$string['setting_sizelimitstrategy'] = 'Strategy when the file size limit is exceeded';
$string['setting_sizelimitstrategy_desc'] = 'A cropped image can exceed the maximum file size which applies to the form field, even though it covers only a part of the original image, because the browser has to re-encode it. In that case, the user is asked to either go back and crop a smaller area or to let the cropped image be reduced until it fits. This setting controls how it is reduced.<br />Reduce pixel size: The cropped image is scaled down step by step until it fits. Its quality stays as configured above.<br />Reduce quality first: The quality with which the cropped image is encoded is lowered step by step (down to 50 %) before its pixel size is touched. Only if even the lowest quality is not enough, the image is scaled down as well. This keeps the resolution of the image as long as possible, at the price of more visible compression artifacts. It only works for lossy image formats (JPEG and WebP); a PNG image is always scaled down.';
$string['setting_sizelimitstrategy_lowerquality'] = 'Reduce quality first';
$string['setting_sizelimitstrategy_scaledown'] = 'Reduce pixel size';
$string['sizelimit_back'] = 'Back to cropping';
$string['sizelimit_choice_lowerquality'] = 'You can let Moodle reduce the quality of the cropped image until it fits into the maximum file size. The cropped area and its resolution stay the same, only the compression is increased. If even the lowest quality is not enough, the image is scaled down as well. Alternatively, you can go back and crop a smaller area of the image.';
$string['sizelimit_choice_scaledown'] = 'You can let Moodle scale the cropped image down until it fits into the maximum file size. The cropped area stays the same, only its resolution is reduced. Alternatively, you can go back and crop a smaller area of the image.';
$string['sizelimit_explanation'] = 'The cropped image has a file size of {$a->size}, but the maximum file size which is allowed here is {$a->limit}. This can happen even though the cropped image covers only a part of the original image: the image cropper has to re-encode the image, and this does not necessarily make it smaller than the original file.';
$string['sizelimit_lowerquality'] = 'Reduce image quality';
$string['sizelimit_scaledown'] = 'Scale down image';
$string['sizelimit_title'] = 'Cropped image too large';
$string['task_cleanuporiginals'] = 'Clean up orphaned original images';
