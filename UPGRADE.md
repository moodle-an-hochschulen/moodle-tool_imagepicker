Upgrading this plugin
=====================

This is an internal documentation for plugin developers with some notes what has to be considered when updating this plugin to a new Moodle major version.

General
-------

* Generally, this is a plugin with one purpose: it provides a form element which is a file manager for a single web image with a built-in image cropper.
* However, to do that, it hooks deeply into Moodle core's file manager form element, both on the PHP side (the element is a subclass of MoodleQuickForm_filemanager) and on the JavaScript side (the crop button is inserted into the toolbar of the rendered file manager, and the file manager is refreshed after a crop by driving its YUI instance through the DOM). None of this is a stable API.
* In addition to that, it bundles the third-party library Cropper.js.
* Thus, the upgrading effort is medium: the PHP side should remain quite stable, but the JavaScript side has to be checked against the file manager of each new Moodle major version.


Upstream changes
----------------

* This plugin subclasses MoodleQuickForm_filemanager from /lib/form/filemanager.php. You should check if anything has changed in its constructor (the handling of the options array, especially maxfiles, subdirs and accepted_types), in its export_for_template() method and in its protected $_options property, as the plugin relies on all of them.
* The crop button is added to the toolbar of the rendered file manager by JavaScript (see injectCropButton() in amd/src/imagepicker.js). The toolbar markup is rendered by core_files_renderer in /files/renderer.php (see fm_print_generallayout()) from the template /lib/templates/filemanager_page_generallayout.mustache; the plugin looks for the .fp-toolbar element there and mimics the .fp-btn-add button. You should check if the toolbar markup or the class names have changed, and if the toolbar buttons are still built the way the plugin mimics them.
* After a crop, the file manager has to learn about the new file. The plugin refreshes it by clicking the root folder of the path bar of the file manager (see refreshFileManager() in amd/src/imagepicker.js), because the YUI instance of the file manager is not reachable otherwise, and it patches the DOM of the file list as a fallback for the tree view. You should check if the file manager YUI module in /lib/form/filemanager.js and its markup still behave this way. This is the most fragile part of the plugin.
* The plugin reads where an image lives from the source field of its draft file, which core writes in file_prepare_draft_area() in /lib/filelib.php as a serialized object with a packed file reference in its 'original' property (see originals::resolve_place()). You should check if the format of that source field has changed.
* The plugin serves draft files and preserved originals through its own pluginfile callback with cacheability headers. You should check if send_stored_file() and its options (especially 'cacheability') in /lib/filelib.php have changed.
* The plugin listens to the core hook \core_files\hook\after_file_created to settle preserved originals as soon as an image is saved. You should check if this hook still exists and still carries the stored file.
* The plugin relies on the web_image file type group of \core_form\filetypes_util and on get_user_max_upload_file_size() together with the maxbytes field of the course record for the upload limit. You should check if these have changed.
* The plugin uses several core JavaScript modules and Bootstrap conventions: core/modal_save_cancel (including the fact that removeOnClose only removes the modal on user-initiated closes), core/ajax, core/str, core/notification, the core/loading template and the Bootstrap spinner-border, badge and visually-hidden classes. You should check if any of these have changed.
* The plugin registers its demo page and its report page as hidden admin pages and moves their navigation nodes below the settings page of the plugin for the breadcrumb (see \tool_imagepicker\local\adminpage). You should check if $PAGE->settingsnav->find() and navigation_node::add_node() still work this way.
* This plugin bundles Cropper.js (https://github.com/fengyuanchen/cropperjs) as an AMD module in amd/src/cropper.js. The bundled version is listed in thirdpartylibs.xml. Cropper.js is under active development and should be updated within the plugin every now and then. To do so, take the UMD build (dist/cropper.js) of the new release, replace amd/src/cropper.js with it, update the version in thirdpartylibs.xml and rebuild the AMD modules with grunt.
* Please note that the plugin uses the web components API of Cropper.js 2 (cropper-canvas, cropper-image, cropper-selection and so on) and that it switches off the interactions it does not need (see the cropper configuration in amd/src/imagepicker.js). If a new release of Cropper.js changes this API, amd/src/imagepicker.js has to be adapted accordingly.
* Please note that Cropper.js 2 has no setting which keeps the crop selection within the image. The plugin does that on its own by cancelling the (cancellable) change event of the cropper-selection element and by changing the selection to what fits instead (see limitSelectionToImage() in amd/src/imagepicker.js), and it relies on the $move() and $resize() methods of that element going through $change(), which is what fires the event. You should check if this still holds true in a new release of Cropper.js, or if it has got a setting of its own for this in the meantime. The Behat scenarios about moving and resizing the selection beyond the image will tell.
* Please note that the library source is excluded from ESLint by its entry in thirdpartylibs.xml (grunt puts every third-party location into the generated .eslintignore), but that ESLint nevertheless reports one warning for it: in the component mode which moodle-plugin-ci uses, grunt passes every AMD source file explicitly, and ESLint (in the eslintrc mode which Moodle uses) warns about every explicitly linted file which matches an ignore pattern. This warning cannot be switched off from within the plugin, so the CI workflow of the plugin tolerates exactly one lint warning (see the grunt-max-lint-warnings input in .github/workflows/moodle-plugin-ci.yml). If a further third-party AMD module is ever added, that number has to be raised accordingly. If Moodle core moves ESLint to the flat config mode one day, the warning should disappear (ESLint has a --no-warn-ignored option there) and the input can be dropped again.


Automated tests
---------------

* The plugin has a good coverage with PHPUnit tests which test the server side of the plugin: the preservation of originals, the web services, the file size limit, the file serving, the form element, the report table, the scheduled task, the hook callbacks and the privacy provider.
* The plugin has a good coverage with Behat tests which test the plugin's user stories on its demo page: the crop button, the crop modal and the cropper with all accepted image types, cropping with and without preserved originals, the file size dialog with both reduction strategies, the settings and the preserved originals report.


Manual tests
------------

* The Behat tests run in the browser of the Moodle Behat setup (Firefox by default). You should test the crop modal once in another browser, especially the file format notices and the SVG cases, as browsers differ in how they load an SVG image without an intrinsic size.
* You should test the integration into the Boost Union theme by editing the course header image of a course with the theme_boost_union course header image feature enabled, as this is not covered by the Behat tests of this plugin.


Visual checks
-------------

* You should check that the crop button fits in with the core buttons in the toolbar of the file manager, as the plugin mimics their markup.
* You should check the crop modal, which is widened to almost full screen width and which shows a loading spinner while the image is fetched, as well as the notices below the cropper.
* You should check the file size dialog and the preserved originals report, as they use Bootstrap badges, buttons and modals which Moodle themes can always change in small details.
