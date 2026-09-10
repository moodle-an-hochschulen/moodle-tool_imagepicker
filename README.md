moodle-tool_imagepicker
=======================

[![Moodle Plugin CI](https://github.com/moodle-an-hochschulen/moodle-tool_imagepicker/actions/workflows/moodle-plugin-ci.yml/badge.svg?branch=main)](https://github.com/moodle-an-hochschulen/moodle-tool_imagepicker/actions?query=workflow%3A%22Moodle+Plugin+CI%22+branch%3Amain)

Moodle plugin which enhances the Moodle filepicker with a cropping functionality for uploaded images.


Requirements
------------

This plugin requires Moodle 5.0+


Motivation for this plugin
--------------------------

Moodle's file manager form element is a generic file picker. It accepts whatever the form allows, it does not know anything about images, and it offers no way to adjust an image after it has been uploaded. Wherever an image has to fit a particular place with a particular aspect ratio (a course header, a banner, a tile), the user has to prepare the image in an external tool first, upload it, look at the result, and start over if it does not fit.

This plugin provides a form element which is a file manager for exactly one web image with an image cropper built in. The user uploads an image, clicks the crop button in the toolbar of the file manager, selects the region which should be kept (optionally with a fixed aspect ratio) and saves. The cropped image replaces the uploaded one in the form field, and the form stores it the way it would store any other file.

The element is meant to be used by other plugins. It was built for the course header image field of the Boost Union theme, but it is deliberately kept independent of any particular consumer.


Installation
------------

Install the plugin like any other plugin to folder
/admin/tool/imagepicker

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins


Usage & Settings
----------------

After installing the plugin, it does not do anything to Moodle yet. The image picker form element only appears on forms of plugins which use it (see the section "Using the image picker element in your plugin" below).

To configure the plugin and its behaviour, please visit:
Site administration -> Plugins -> Admin tools -> Image picker

There, you find three settings and two buttons:

### 1. Preserve original images

If enabled, the original (uncropped) image is preserved when an image is cropped. Cropping then always starts from the original image, so it stays non-destructive: the user can open the cropper again later and pick another region of the very same image, and the previous crop region is pre-selected. This requires additional file storage. Preserved originals which are no longer used are removed automatically by a scheduled task.

If disabled, cropping starts from the currently stored image and replaces it. Originals which were preserved while the setting was enabled are kept, but they are not used for cropping any more; as soon as such an image is cropped again or deleted, its original becomes unused and is removed by the scheduled task. Originals which you do not want to keep until then can be deleted right away in the preserved originals report.

This setting is disabled by default.

### 2. Image quality after cropping

The quality with which a cropped image is encoded, from 50 % to 100 %. This only applies to lossy image formats (JPEG and WebP), PNG is lossless and ignores this setting. A higher quality means a larger file. The default of 92 % is a good compromise between image quality and file size for most images.

### 3. Strategy when the file size limit is exceeded

A cropped image can exceed the maximum file size which applies to the form field, even though it covers only a part of the original image, because the browser has to re-encode it. In that case, the user is asked to either go back and crop a smaller area or to let the cropped image be reduced until it fits. This setting controls how it is reduced:

* Reduce pixel size (default): The cropped image is scaled down step by step until it fits. Its quality stays as configured above.
* Reduce quality first: The quality with which the cropped image is encoded is lowered step by step (down to 50 %) before its pixel size is touched. Only if even the lowest quality is not enough, the image is scaled down as well. This keeps the resolution of the image as long as possible, at the price of more visible compression artifacts. It only works for lossy image formats (JPEG and WebP); a PNG image is always scaled down.

### View preserved originals

This button leads to a report which lists all original images which the plugin preserves on the site. For each original, the report shows the place of the image it backs, whether the original file is still in place, whether the cropped image still exists anywhere (otherwise the original is orphaned and will be removed by the scheduled task), the last crop region and the time of the last change. Every original can be viewed and deleted, and all originals can be deleted at once. The report is available to admins only.

### View demo page

This button leads to a demo page where you can try out the image picker element in its different forms: with free cropping, with a fixed aspect ratio, restricted to JPEG images and without the crop button. The images are stored when the form is saved, so the whole life cycle of an image can be tried out there: upload it, crop it, save the form and crop it again. The uploaded images are shown nowhere else. The demo page is available to admins only.


Using the image picker element in your plugin
---------------------------------------------

The image picker is a form element which subclasses Moodle's file manager element. Storage-wise it behaves exactly like a plain file manager, so your plugin keeps using the usual draft area handling (file_prepare_draft_area() and file_save_draft_area_files()) and does not have to store anything in a new way.

The element has to be registered with QuickForm before it is created. The plugin ships a small helper class for that:

```php
use tool_imagepicker\element;

// In your form definition (or in a form-related hook callback).
element::register();
$mform->addElement(element::TYPE, 'headerimage', get_string('headerimage', 'yourplugin'), null, [
    'maxbytes' => $CFG->maxbytes,
    'accepted_types' => 'web_image',
    'enablecrop' => true,
    'cropaspectratio' => 16 / 9,
]);
```

The element accepts all options of the file manager element plus two of its own:

* enablecrop (bool): Whether the crop button is shown. Defaults to true. Without it, the element is a plain file manager which is restricted to a single web image.
* cropaspectratio (float or null): The aspect ratio which the crop selection keeps, given as width divided by height. Defaults to null, which means free cropping.

As the element handles exactly one web image, some of the inherited file manager options are enforced and cannot be overridden: maxfiles is always 1, subdirs is always 0, and accepted_types is reduced to the file types which are covered by Moodle's web_image file type group. If your plugin passes other file types (or the wildcard for all file types), they are silently dropped; if no web image type is left, the whole web_image group is accepted.

If you do not want to make your plugin depend on this plugin, use it as a soft dependency: check whether the element helper class exists and fall back to the plain file manager element otherwise. The unknown options are dropped by the file manager element anyway, so the options array can stay the same:

```php
if (class_exists(\tool_imagepicker\element::class)) {
    \tool_imagepicker\element::register();
    $elementtype = \tool_imagepicker\element::TYPE;
} else {
    $elementtype = 'filemanager';
}
$mform->addElement($elementtype, 'headerimage', get_string('headerimage', 'yourplugin'), null, $options);
```

This is how the Boost Union theme uses the element for its course header image field.


Capabilities
------------

This plugin does not add any additional capabilities.


Scheduled Tasks
---------------

This plugin also introduces these additional scheduled tasks:

### \tool_imagepicker\task\cleanup_originals

Removes preserved original images which have become orphaned, i.e. whose cropped image does not exist anywhere any more, for example because the image was removed from its form field or because the surrounding course or activity was deleted. The task removes the original file and its bookkeeping record.\
By default, the task is enabled and runs once a day at night.


How this plugin works / Pitfalls
--------------------------------

### Cropping

The image picker adds a crop button to the toolbar of the file manager. The button is only shown while the field holds an image which the browser can crop, i.e. a raster image or an SVG image which specifies its own width and height. Clicking it opens a modal dialog with the image and a resizable crop selection. The selection can be moved and resized freely, but it is kept within the image: it stops at the edges of the image, so that a cropped image never contains an empty area from beyond them. The image is loaded once and kept in the browser cache for a minute, so that the different steps of opening the dialog do not download it again and again.

Cropping happens entirely in the browser, on a canvas. The selected region is encoded by the browser and uploaded to the server through a web service, which replaces the image in the draft area of the form field with the cropped one. Nothing is stored anywhere else until the form is saved. The cropped image keeps the name, the author and the license of the image it was cropped from.

### File formats

A browser canvas can only encode JPEG, PNG and WebP images. An image of the other web image formats (GIF, SVG) is cropped just fine, but the result is a PNG image, and the file is renamed accordingly. An animated image (GIF, APNG, animated WebP or an SVG with animations) loses its animation: only a still of its first frame is kept. The crop dialog tells the user about both before they crop.

### File size limit

A cropped image is re-encoded by the browser, and that does not necessarily make it smaller than the image it was cropped from. An indexed GIF, for example, may come back as a much larger PNG. The plugin therefore checks the cropped image against the upload limit which applies to the image before it is uploaded: the site-wide maximum upload size and, if the image lives in a course (or in an activity of a course), the maximum upload size of that course, whichever is smaller. Users who may ignore file size limits (such as admins) are not limited. If the cropped image is too large, the user is asked whether it should be reduced (according to the configured strategy) or whether they want to go back and crop a smaller region. The server enforces the very same limit, so a cropped image which is too large is never stored.

Please note that the maximum file size which is configured for the form element itself is not applied to cropped images. The web services of the plugin have no way of knowing which of the arbitrarily many forms and elements they are currently serving, and asking the browser would lead to a value which the browser can forge. A field with a tighter limit of its own can therefore end up with a cropped image which is larger than a fresh upload into the same field would have been allowed to be, but never larger than the site and the course permit.

### Preserved originals

If the preservation of originals is enabled, the plugin stores the uncropped image in a file area of its own before the first crop replaces it, together with a bookkeeping record which ties the original to the image it backs. The original is stored in the same context as the image (in the course context for a course header image, for example), without an author and without any other reference to the user who cropped the image: it belongs to the image, not to the user, so that another user can crop the image from its original later on and so that the original survives if the first user is deleted. While an image has only been uploaded but not saved yet, its original is kept in the user context of the uploading user, next to their draft files, and moves along with the image as soon as the form is saved.

A preserved original is only ever served to a user who is editing the image which it backs, i.e. who has opened the form on which the image lives, and to admins. Holding a copy of the cropped image is deliberately not enough: the cropped image may be on public display, so anybody could download it, and uploading it into a form of their own must not open up the original.

Please note that this means that the uncropped image stays on the server for as long as the cropped image is in use. Cropping away sensitive parts of an image does not remove them from the site while originals are preserved. The crop dialog tells the user about this, and admins can see and delete every preserved original in the report.

### Web services and hooks

The plugin registers two external functions for AJAX use, which get the image of a draft area (and its crop source and last crop region) and replace it with a cropped one. Both work on the draft areas of the current user only, so nothing about the form or its context has to be handed over by the browser, and nothing can be tampered with. The plugin also listens to the core hook which is fired when a file is created, in order to settle a preserved original into the place of its image as soon as the form is saved.

### Third party integration

This plugin does not send any data to any third party service. The image cropper library is bundled with the plugin.


Theme support
-------------

This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.


Plugin repositories
-------------------

This plugin is published and regularly updated in the Moodle plugins repository:
http://moodle.org/plugins/view/tool_imagepicker

The latest development version can be found on Github:
https://github.com/moodle-an-hochschulen/moodle-tool_imagepicker


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/moodle-an-hochschulen/moodle-tool_imagepicker/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/moodle-an-hochschulen/moodle-tool_imagepicker/issues

Please create pull requests on Github:
https://github.com/moodle-an-hochschulen/moodle-tool_imagepicker/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

Due to limited resources, this plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

If you are running a legacy version of Moodle, but want or need to run the latest version of this plugin, you can get the latest version of the plugin, remove the line starting with $plugin->requires from version.php and use this latest plugin version then on your legacy Moodle. However, please note that you will run this setup completely at your own risk. We can't support this approach in any way and there is an undeniable risk for erratic behavior.


Translating this plugin
-----------------------

This Moodle plugin is shipped with an english language pack only. All translations into other languages must be managed through AMOS (https://lang.moodle.org) by what they will become part of Moodle's official language pack.

As the plugin creator, we manage the translation into german for our own local needs on AMOS. Please contribute your translation into all other languages in AMOS where they will be reviewed by the official language pack maintainers for Moodle.


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Credits
-------

The image cropper of this plugin is powered by Cropper.js, the JavaScript image cropper by Chen Fengyuan:\
https://fengyuanchen.github.io/cropperjs/ \
https://github.com/fengyuanchen/cropperjs

Cropper.js is published under the MIT license. It is bundled with this plugin as an AMD module (amd/src/cropper.js) and listed in thirdpartylibs.xml. Thank you very much for this great library!


Maintainers
-----------

The plugin is maintained by\
Moodle an Hochschulen e.V.


Copyright
---------

The copyright of this plugin is held by\
Moodle an Hochschulen e.V.

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
