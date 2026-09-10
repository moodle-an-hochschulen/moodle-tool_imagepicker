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
 * Image picker behaviour.
 *
 * Enhances the (core) file manager rendered for a tool_imagepicker element with additional toolbar buttons.
 *
 * @module     tool_imagepicker/imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import $ from 'jquery';
import Ajax from 'core/ajax';
import Cropper from 'tool_imagepicker/cropper';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import {getString, getStrings} from 'core/str';
import {markFormChangedFromNode} from 'core_form/changechecker';
import Notification from 'core/notification';
import Log from 'core/log';

/**
 * @var {Number} The encoding quality (0..1) below which the quality of a cropped image is not lowered any further to fit into
 * the size limit.
 *
 * Below this, compression artifacts dominate the image, so once this quality is reached the image is scaled down instead.
 */
const MIN_ENCODING_QUALITY = 0.5;

/**
 * @var {Number} The step by which the encoding quality (0..1) is lowered per attempt to fit into the size limit.
 */
const ENCODING_QUALITY_STEP = 0.1;

/**
 * @var {Number} The width (in pixels) below which a cropped image is not scaled down any further to fit into the size limit.
 *
 * An image that small is of no use anyway, so hitting this width means that the limit cannot be met by scaling.
 */
const MIN_SCALED_WIDTH = 16;

/**
 * @var {String} The size limit strategy which scales the cropped image down, i.e. reduces its pixel size.
 */
const STRATEGY_SCALEDOWN = 'scaledown';

/**
 * @var {String} The size limit strategy which lowers the encoding quality of the cropped image before scaling it down.
 */
const STRATEGY_LOWERQUALITY = 'lowerquality';

/**
 * @var {String[]} The extensions of the image types which the cropper handles.
 *
 * This is a fixed list: the 'web_image' file type group of Moodle core, without '.svgz'. A file of any other type gets no
 * crop button, no matter how it got into the field - whether it was uploaded before the field was handled by this element,
 * or whether an admin has added its type to the 'web_image' group (which the form element then accepts) via the custom
 * file types. This list is therefore not the same as the file types which the form element accepts (see
 * config.acceptedtypes), and both are checked.
 *
 * '.svgz' is left out although Moodle counts it as a web image: it is a gzipped SVG, which a browser only renders if it is
 * served with a 'Content-Encoding: gzip' header, and Moodle serves it without one. The image therefore never loads, so
 * offering to crop it would only ever lead to an error.
 *
 * A plain '.svg' is listed, but it is not croppable by itself either: the cropper works on raster dimensions throughout, so
 * an SVG can only be cropped (and is then rasterized) if it carries an intrinsic width and height. Whether it does is not
 * visible in its file name, so that case is caught once the image has been loaded, see prepareImage().
 */
const DISPLAYABLE_EXTENSIONS = ['.jpg', '.jpeg', '.jpe', '.png', '.gif', '.webp', '.svg'];

/**
 * @var {String[]} The extensions of the (displayable) image types which can carry an animation.
 *
 * GIF is the obvious one, but PNG (as APNG) and WebP can be animated as well, and an SVG can animate itself. JPEG cannot.
 * An image of one of these types is not necessarily animated, of course - this list only says which types have to be looked
 * at, see isAnimatedImage().
 */
const ANIMATABLE_EXTENSIONS = ['.gif', '.png', '.webp', '.svg'];

/**
 * @var {Object} The image type which a cropped image is encoded as, by the extension of the image it was cropped from.
 *
 * A browser canvas can only encode JPEG, PNG and WebP, so these are the image types which keep their format when they are
 * cropped, and this list holds exactly those. The format of every other image type changes to the fallback below. The server
 * derives the file name of the stored file from the image content itself, so it always agrees with what is decided here -
 * including the extension, which keeps its spelling if it fits the type already ('.jpg', '.jpeg' and '.jpe' are all JPEG).
 */
const ENCODING_BY_EXTENSION = {
    '.jpg': {mimetype: 'image/jpeg', extension: '.jpg'},
    '.jpeg': {mimetype: 'image/jpeg', extension: '.jpeg'},
    '.jpe': {mimetype: 'image/jpeg', extension: '.jpe'},
    '.png': {mimetype: 'image/png', extension: '.png'},
    '.webp': {mimetype: 'image/webp', extension: '.webp'},
};

/**
 * @var {Object} The image type which a cropped image is encoded as if its source type cannot be encoded as such.
 *
 * PNG is the fallback, as it is the only lossless one of the three types which a canvas can encode.
 */
const ENCODING_FALLBACK = {mimetype: 'image/png', extension: '.png'};

/**
 * The error which says that a cropped image could not be reduced enough to fit into the size limit.
 *
 * It is told apart from every other error because it is a condition which the user is told about in plain words (and can
 * do something about, by cropping a smaller area), not something which went wrong.
 */
class ReductionError extends Error {
}

/**
 * Initialise the image picker behaviour for a single form element.
 *
 * @param {String} elementid The id attribute of the file manager form element.
 * @param {Object} config The element configuration.
 * @param {Boolean} config.enablecrop Whether the crop button is enabled.
 * @param {Number|null} config.cropaspectratio The aspect ratio to enforce when cropping (null for free cropping).
 * @param {String[]} config.acceptedtypes The file types which the form element accepts.
 * @param {Boolean} config.preserveoriginals Whether the site preserves the uncropped original of a cropped image.
 * @param {Number} config.encodingquality The quality (0..1) with which a cropped image is encoded (for lossy formats).
 * @param {String} config.sizelimitstrategy How a cropped image which exceeds the size limit is reduced ('scaledown' or
 *                                          'lowerquality').
 */
export const init = (elementid, config) => {
    // The form item is the container of the whole element; without it, there is nothing to enhance.
    const fitem = document.getElementById(`fitem_${elementid}`);
    if (!fitem) {
        Log.debug(`tool_imagepicker: form item fitem_${elementid} not found.`);
        return;
    }

    // The file manager markup, including its toolbar, is rendered on the server and is therefore already there. Only the file
    // list is filled in later by the (YUI based) core file manager, and that is nothing we have to wait for here: the button
    // is hidden by the plugin stylesheet until the file list has arrived.
    const filemanager = fitem.querySelector('.filemanager');
    const toolbar = fitem.querySelector('.fp-toolbar');
    if (!filemanager || !toolbar) {
        Log.debug(`tool_imagepicker: no file manager found in form item fitem_${elementid}.`);
        return;
    }

    // The crop button is the only enhancement so far.
    if (config.enablecrop) {
        injectCropButton(toolbar, filemanager, elementid, config).catch(Notification.exception);
    }
};

/**
 * Inject the crop button into the file manager toolbar, right after the 'Add' button.
 *
 * @param {HTMLElement} toolbar The .fp-toolbar element.
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @param {String} elementid The id attribute of the file manager form element.
 * @param {Object} config The element configuration.
 */
const injectCropButton = async(toolbar, filemanager, elementid, config) => {
    // The button is placed next to the 'Add' button, so that one has to be there.
    const addbutton = toolbar.querySelector('.fp-btn-add');
    if (!addbutton) {
        return;
    }

    // The button has no visible label, its title is what names it (as a tooltip and for screen readers).
    const title = await getString('cropimage', 'tool_imagepicker');

    // Build the button the way the core toolbar buttons are built (a wrapper around a small secondary button which holds an
    // icon), so that it fits in with them. The wrapper class is what the stylesheet and the availability watcher act on.
    const wrapper = document.createElement('div');
    wrapper.className = 'tool-imagepicker-btn-crop';

    const link = document.createElement('a');
    link.setAttribute('role', 'button');
    link.setAttribute('href', '#');
    link.setAttribute('title', title);
    link.className = 'btn btn-secondary btn-sm';

    const icon = document.createElement('i');
    icon.className = 'icon fa fa-crop fa-fw';
    icon.setAttribute('aria-hidden', 'true');
    link.appendChild(icon);
    wrapper.appendChild(link);

    // Insert right after the 'Add' button.
    addbutton.parentNode.insertBefore(wrapper, addbutton.nextSibling);

    // Opening the modal takes a moment (it is created asynchronously), and until it is on screen with its backdrop, nothing
    // would keep a second click from opening a second one. So the button is guarded while a modal is being opened.
    let opening = false;
    link.addEventListener('click', async(e) => {
        e.preventDefault();
        if (opening) {
            return;
        }
        opening = true;
        try {
            await openCropperModal(filemanager, elementid, config);
        } catch (error) {
            showError(error);
        } finally {
            opening = false;
        }
    });

    // Whether the button is of any use depends on the file which is currently in the field, so it is kept in sync with it.
    watchCropAvailability(wrapper, filemanager, elementid, config);
};

/**
 * Keep the availability of the crop button in sync with the file which is currently in the file manager.
 *
 * The button is hidden while the field holds a file which cannot be cropped (because it is not an image at all) or which
 * cannot be stored after cropping (because the resulting file type is not accepted by the form element). Note that the empty
 * field is already handled in CSS, via the 'fm-nofiles' class which the core file manager maintains.
 *
 * @param {HTMLElement} wrapper The wrapper element of the crop button.
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @param {String} elementid The id attribute of the file manager form element.
 * @param {Object} config The element configuration.
 */
const watchCropAvailability = (wrapper, filemanager, elementid, config) => {
    // Hide the button (via a class which the stylesheet acts on) while the file in the field cannot be cropped.
    const refresh = () => {
        const filename = getCurrentFilename(filemanager);
        wrapper.classList.toggle('tool-imagepicker-btn-unavailable', !!filename && !isCroppable(filename, config));
    };

    // Start with the file which is there right now.
    refresh();

    // The core file manager re-renders its file list whenever the files change (on an upload as well as on a deletion), so
    // watching that list is what tells us that the file we are looking at is not the file which is there any more.
    //
    // This observer is deliberately never disconnected: the field can be changed at any point while the form is open, so
    // there is no point at which we would be done watching. It is bound to the file list it observes and goes away with it.
    const content = filemanager.querySelector('.fp-content');
    if (!content) {
        return;
    }

    // Watch the file list for changes, and re-check the file once a change has settled.
    let pending = false;
    const observer = new MutationObserver(() => {
        if (pending) {
            return;
        }
        pending = true;
        // The list is rendered in several steps, so we wait for it to settle instead of reacting to every single step.
        setTimeout(() => {
            pending = false;
            refresh();
        }, 100);
    });
    observer.observe(content, {childList: true, subtree: true});
};

/**
 * Get the element which shows the name of the file which is currently in the file manager.
 *
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @return {HTMLElement|null} The file name element, or null if the field holds no file.
 */
const getCurrentFileEntry = (filemanager) => {
    // The file manager wraps every entry of its file list, in each of its three view modes, in an element which carries the
    // name of the entry in a '.fp-filename' element. The markup around it differs from view mode to view mode, but folder
    // entries always carry an 'fp-folder' class on one of their ancestors, which is how they are told apart from the files.
    const entries = filemanager.querySelectorAll('.fp-content .fp-filename');
    for (const entry of entries) {
        // Folders are not files.
        if (entry.closest('.fp-folder')) {
            continue;
        }
        // The first entry with a name is the file; an empty name belongs to a template node.
        if (entry.textContent.trim()) {
            return entry;
        }
    }

    return null;
};

/**
 * Read the name of the file which is currently shown in the file manager.
 *
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @return {String|null} The file name, or null if the field holds no file.
 */
const getCurrentFilename = (filemanager) => {
    const entry = getCurrentFileEntry(filemanager);

    return entry ? entry.textContent.trim() : null;
};

/**
 * Whether the given file can be cropped and the result of cropping it can be stored.
 *
 * @param {String} filename The file name.
 * @param {Object} config The element configuration.
 * @return {Boolean}
 */
const isCroppable = (filename, config) => {
    // Only an image which a browser can display can be loaded into the cropper in the first place.
    if (!hasExtension(filename, DISPLAYABLE_EXTENSIONS)) {
        return false;
    }

    // Cropping changes the file type of some images (a GIF, for example, comes out as a PNG), so the file type of the result
    // is what has to be accepted by the form element - not the file type of the image which is being cropped.
    return isTypeAccepted(getOutputFilename(filename), config.acceptedtypes);
};

/**
 * Whether the given file name ends with one of the given extensions.
 *
 * @param {String} filename The file name.
 * @param {String[]} extensions The extensions to look for (each with a leading dot).
 * @return {Boolean}
 */
const hasExtension = (filename, extensions) => {
    // Extensions are compared regardless of case.
    const lower = filename.toLowerCase();

    return extensions.some((extension) => lower.endsWith(extension));
};

/**
 * Whether a file of the given name may be stored in the form element.
 *
 * The accepted types are never empty and never a wildcard: the form element always reduces them to plain web image
 * extensions (see restrict_accepted_types() in the form element class), so a plain extension check is all there is to it.
 *
 * @param {String} filename The file name.
 * @param {String[]} acceptedtypes The accepted file types, as plain extensions.
 * @return {Boolean}
 */
const isTypeAccepted = (filename, acceptedtypes) => hasExtension(filename, acceptedtypes);

/**
 * Get the notice to show in the crop modal if cropping the given image loses something which the user should know about.
 *
 * That is the case if cropping changes the file format of the image (because a browser canvas cannot encode its type), and
 * it is the case if the image is animated (because a canvas only ever takes the first frame of it). Both may apply to the
 * same image, so the notice is made up of one sentence per case.
 *
 * @param {String} filename The file name of the image which is being cropped.
 * @param {Boolean} animated Whether the image is animated.
 * @return {Promise<String|null>} The notice, or null if there is nothing to say.
 */
const getCropNotice = async(filename, animated) => {
    const sentences = [];

    // The format changes exactly when the type of the image is not one which a canvas can encode. This is decided by the
    // type and not by the extension, so that a '.jpe' counts as the JPEG which it is.
    if (!ENCODING_BY_EXTENSION[splitFilename(filename).extension]) {
        const target = getEncoding(filename).extension.replace('.', '').toUpperCase();
        sentences.push(await getString('note_formatchange', 'tool_imagepicker', target));
    }
    // The animation is lost whatever the format, as a still of the first frame is what gets cropped (see prepareImage()).
    if (animated) {
        sentences.push(await getString('note_animated', 'tool_imagepicker'));
    }

    // Each sentence stands on its own, so they are simply joined.
    return sentences.length ? sentences.join(' ') : null;
};

/**
 * Open a modal which lets the user crop the current image of the file manager.
 *
 * The modal opens at once, with a spinner in place of the image, so that the click has a visible effect right away. The
 * image is fetched and prepared while the modal is on screen (see prepareImage()), and the cropper takes the place of the
 * spinner as soon as it is ready. Until then, the save button is disabled.
 *
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @param {String} elementid The id attribute of the file manager form element.
 * @param {Object} config The element configuration.
 */
const openCropperModal = async(filemanager, elementid, config) => {
    // The draft area which holds the image is what the web services work on.
    const draftitemid = getDraftItemId(elementid);

    // Create the modal with the spinner as its body. It is removed from the DOM once the user closes it.
    const modal = await ModalSaveCancel.create({
        title: getString('cropimage', 'tool_imagepicker'),
        body: await renderLoadingBody(),
        large: true,
        removeOnClose: true,
        buttons: {save: getString('save')},
    });
    // Widen the modal to (almost) full screen width, so there is more room for cropping than the default modal width.
    modal.getRoot().addClass('tool-imagepicker-crop-modal');

    // Nothing can be saved before the cropper is there.
    const savebutton = modal.getFooter().find(modal.getActionSelector('save'));
    savebutton.prop('disabled', true);

    // What the save button says (to screen readers) while it shows a spinner instead of its label.
    const savingtext = await getString('saving', 'tool_imagepicker');

    // The modal exists now, but it is not shown yet. Its event handlers are registered before it is shown, following the
    // usual order for modals (create, wire up, show): from the moment it is shown, the user can close it or click its buttons,
    // and a handler which is only registered later on would miss that. The state below is what the handlers work on. It is
    // declared here so that they can close over it, and it is filled in as the image is prepared and the cropper is set up
    // further down, after the modal has been shown.
    let cropper = null;
    let onresize = null;
    let stillurl = null;
    let closed = false;
    let image = null;
    let sourcefilename = null;
    let dimensions = null;

    // Clean up once the modal is gone, however it was closed.
    modal.getRoot().on(ModalEvents.hidden, () => {
        closed = true;
        if (onresize) {
            window.removeEventListener('resize', onresize);
            onresize = null;
        }
        // An object URL keeps its blob in memory for as long as it exists, and the still is of no use any more once the
        // modal is gone.
        if (stillurl) {
            URL.revokeObjectURL(stillurl);
            stillurl = null;
        }
    });

    // Encoding the cropped image and sending it to the server takes a moment, during which the modal stays open and its save
    // button stays clickable. Without a guard, a second click would encode and store the same crop a second time.
    let saving = false;

    // Store the crop when the user confirms. The default handling of the event (closing the modal) is prevented, as the
    // modal has to stay open while the crop is being stored, and it may have to stay open afterwards (see saveCrop()).
    modal.getRoot().on(ModalEvents.save, (e) => {
        e.preventDefault();

        // Without a cropper there is nothing to save yet.
        if (!cropper || saving) {
            return;
        }
        saving = true;

        // Show the state on the button as well (a spinner in place of its label), so it is visible and not just enforced
        // behind the scenes.
        setButtonBusy(savebutton, true, savingtext);

        // Encode the selection, store it in the draft area and close the modal (see saveCrop()). The button is released
        // again only if nothing was stored, as the modal is gone otherwise.
        saveCrop(cropper, config, draftitemid, image, sourcefilename, dimensions, filemanager, modal)
            .then((stored) => {
                // The user may have chosen to go back to cropping instead of storing the image (see saveCrop()). The modal
                // stays open then, so it has to accept the next attempt.
                if (!stored) {
                    saving = false;
                    setButtonBusy(savebutton, false);
                }
                return null;
            })
            .catch((error) => {
                // Nothing was stored, so let the user try again instead of leaving them with a dead button.
                saving = false;
                setButtonBusy(savebutton, false);
                showError(error);
            });
    });

    // Show the modal and prepare the image at the same time: both take a moment, and neither depends on the other. The
    // modal has to be fully shown before anything is done to it below, so that is awaited as well, whatever happens.
    const shown = modal.show();
    let prepared;
    try {
        prepared = await prepareImage(draftitemid);
    } catch (error) {
        await shown;
        modal.destroy();
        throw error;
    }
    await shown;

    // Without an image which can be cropped, the modal has nothing to show. Say why instead - unless the user has closed the
    // modal in the meantime, in which case they have given up on it anyway.
    if (prepared.error) {
        if (!closed) {
            modal.destroy();
            await showMessage(await getString(prepared.error, 'tool_imagepicker'));
        }
        return;
    }

    // Build the modal body which holds the image to crop. This may have to wait for language strings, which is why it is
    // done before the modal is touched.
    const body = await buildCropperBody(prepared, config);

    // The user may have closed the modal while the image was being prepared. Then there is nothing left to do. This is
    // checked right before the modal is touched, so that nothing waited for above can have opened a window for the modal to
    // be closed in unnoticed.
    if (closed) {
        if (prepared.stillurl) {
            URL.revokeObjectURL(prepared.stillurl);
        }
        return;
    }
    ({image, sourcefilename, dimensions, stillurl} = prepared);

    // The spinner makes way for the image. This deliberately bypasses the modal's setBody(): on a visible modal, that one
    // swaps the content in asynchronously (so the cropper could not be set up on the image right away) and it animates the
    // body to an explicit pixel height, which would take the body out of the flex layout that the cropper is sized against.
    modal.getBody().empty().append(body);

    // Set up the cropper on the image, now that it is in the (visible) modal.
    cropper = new Cropper(body.find('img')[0]);
    const cropperimage = cropper.getCropperImage();
    const cropperselection = cropper.getCropperSelection();

    // Configure the cropper for plain cropping. The default template of Cropper.js allows more than that, and what is not
    // wanted here is switched off explicitly, even where the library's default is off already, so that the behaviour does
    // not depend on those defaults.
    //
    // The image can neither be rotated nor skewed: the cropper offers no controls for it (rotating is a two finger gesture on
    // touch screens only), and a rotated image would not match the stored crop region, which is expressed relative to the
    // unrotated image. Zooming and moving the image are switched off as well, but only once the image has been fitted into
    // the canvas (see freezeImage()), as fitting it needs both.
    cropperimage.rotatable = false;
    cropperimage.skewable = false;

    // A single selection is all that is needed here: the element holds exactly one image, and cropping produces exactly one.
    cropperselection.multiple = false;

    // No keyboard control of the selection (arrow keys would move it, plus and minus would resize it, Delete would remove
    // it). It was tried and found not to be useful enough, and the Delete key in particular would only surprise users.
    cropperselection.keyboard = false;

    // Keep the selection within the image. The cropper itself lets the selection be moved and resized beyond the image (and
    // even beyond the canvas), and whatever of it lies outside of the image would end up as an empty (transparent or black)
    // area in the cropped image.
    limitSelectionToImage(cropper);

    // Size the cropper canvas to the aspect ratio of the image, fitted into the space which the modal body offers, so the
    // canvas matches the image and does not show empty letterbox / pillarbox areas around it.
    sizeCanvasToImage(cropper.getCropperCanvas(), dimensions);

    // Scale the image down (or up) so that it fits into the canvas completely. This has to wait until the cropper has
    // actually loaded the image: $center() derives the scale factor from the currently rendered size of the image, so
    // calling it earlier would silently do nothing and leave a large image at its natural size (i.e. cut off).
    cropperimage.$ready().then(() => {
        // The user may have closed the modal while the cropper was loading the image. Then nothing must be set up on it any
        // more - in particular no window resize handler, which the closing would have removed had it been there already.
        if (closed) {
            return null;
        }

        // Fit the image into the canvas and freeze it there.
        cropperimage.$center('contain');
        freezeImage(cropperimage, true);
        // Enforce a fixed aspect ratio if configured.
        if (config.cropaspectratio) {
            cropperselection.aspectRatio = config.cropaspectratio;
        }
        // Restore the last crop region, if one is stored. The cropper defers parts of its own initial layout work to
        // later ticks (the side effects of centering the image, and the initial selection, which it recomputes once the
        // aspect ratio has been set above), so the stored region is applied two frames later to make sure that it lands
        // after all of that and is not overwritten by the cropper's own initial selection.
        if (image.crop) {
            requestAnimationFrame(() => requestAnimationFrame(() => restoreSelection(cropper, image.crop)));
        }

        // Keep the canvas fitted to the (resized) modal while the browser window is resized. The current crop region is
        // read before and re-applied afterwards, so it survives the resize.
        onresize = createResizeHandler(cropper, dimensions);
        window.addEventListener('resize', onresize);

        // Now there is something to save.
        savebutton.prop('disabled', false);

        return null;
    }).catch(async(error) => {
        // The cropper could not load the image after all, although it could be preloaded. What it reports is a bare 'Failed
        // to load the image source', so the user is told what that means instead, and the modal (which has nothing to show)
        // is taken away - unless the user has closed it already.
        Log.debug(`tool_imagepicker: ${error.message}`);
        if (!closed) {
            modal.destroy();
            await showMessage(await getString('error_imageloadfailed', 'tool_imagepicker'));
        }
    });
};

/**
 * Tell the user about an error which occurred while the image was fetched or the crop was stored.
 *
 * An error which the plugin reports itself (its error codes start with 'error_', see the replace_draft_image web service)
 * comes with a message which says in plain words what is wrong and what to do about it, so it is shown as just that. The
 * exception dialogue of core would put the raw error code on top of it as its title. With debugging switched on, the error
 * carries debug information, and the exception dialogue is what shows that, so it is used then - and for every other error.
 *
 * @param {Object} error The error.
 */
const showError = async(error) => {
    const own = error && typeof error.errorcode === 'string' && error.errorcode.startsWith('error_');
    if (own && error.message && !error.debuginfo) {
        await showMessage(error.message);
        return;
    }
    Notification.exception(error);
};

/**
 * Tell the user in plain words that (and why) something could not be done.
 *
 * This is what every message of the plugin which is not an exception goes through, so that they all look the same.
 *
 * @param {String} message The message.
 */
const showMessage = async(message) => {
    await Notification.alert(await getString('error', 'core'), message);
};

/**
 * Build the modal body which holds the image to crop, along with the notices around it.
 *
 * @param {Object} prepared The prepared image, as returned by prepareImage().
 * @param {Object} config The element configuration.
 * @return {Promise<jQuery>} The body. The image to set the cropper up on is its <img>.
 */
const buildCropperBody = async(prepared, config) => {
    // The body is laid out as a flex column (see styles.css): the notices take the room they need, the image gets the rest.
    const body = $('<div class="tool-imagepicker-cropper-body"></div>');

    // A browser canvas cannot encode every image type, so cropping changes the format of some images (a GIF, for example,
    // comes out as a PNG), and it takes the first frame of an animated image only. Say so up front rather than letting the
    // user discover it afterwards. The image which is actually loaded into the cropper decides this - its type is what the
    // canvas has to re-encode.
    const notice = await getCropNotice(prepared.sourcefilename, prepared.animated);
    if (notice) {
        body.append($('<div class="alert alert-info small px-3 py-2 tool-imagepicker-notice" role="alert"></div>').text(notice));
    }

    // The image which the cropper is set up on. What it shows is the prepared image, i.e. the still of an animated one.
    const container = $('<div class="tool-imagepicker-cropper-container"></div>');
    container.append($('<img alt="">').attr('src', prepared.cropurl));
    body.append(container);

    // If the site keeps the uncropped original, say so below the cropper. Cropping is a common way to keep something out of
    // sight, and that expectation does not hold here: the original stays on the server so the crop can be adjusted later, and
    // it remains readable for everyone who may edit this image. Telling the user beforehand is what lets them decide to crop
    // sensitive content locally instead.
    if (config.preserveoriginals) {
        const preservednotice = await getString('note_originalpreserved', 'tool_imagepicker');
        body.append($('<div class="tool-imagepicker-notice text-center small mt-2"></div>').text(preservednotice));
    }

    return body;
};

/**
 * Show a spinner in place of the label of a button while the button is busy, or put the label back.
 *
 * This is the Bootstrap way of marking a button as busy (see the spinner component documentation). The button keeps the
 * width which it had with its label, so that the footer of the modal does not shift while the spinner is shown: the width
 * is read before the label is replaced and pinned for as long as the button is busy. A busy button is disabled as well.
 *
 * @param {jQuery} button The button.
 * @param {Boolean} busy Whether the button is busy.
 * @param {String} busytext The text which is read to screen readers while the button is busy (ignored otherwise).
 */
const setButtonBusy = (button, busy, busytext = '') => {
    if (busy) {
        // Remember the label and pin the width before the label is replaced.
        button.data('tool-imagepicker-label', button.html());
        button.css('width', `${button.outerWidth()}px`);
        button.empty()
            .append($('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>'))
            .append($('<span class="visually-hidden"></span>').text(busytext));
    } else {
        button.html(button.data('tool-imagepicker-label'));
        button.css('width', '');
    }
    button.prop('disabled', busy);
};

/**
 * Render the body which the crop modal shows while the image is being prepared: a spinner in place of the image.
 *
 * @return {Promise<jQuery>} The body.
 */
const renderLoadingBody = async() => {
    // This is the Bootstrap spinner, which turns on the spot at any size (unlike a scaled up icon font glyph).
    const spinner = $('<div class="spinner-border" role="status"></div>')
        .append($('<span class="visually-hidden"></span>').text(await getString('loadinghelp', 'core')));

    // The same layout as the real body (see buildCropperBody()), so that the spinner sits where the image will be.
    return $('<div class="tool-imagepicker-cropper-body"></div>')
        .append($('<div class="tool-imagepicker-cropper-container tool-imagepicker-loading"></div>').append(spinner));
};

/**
 * Fetch the current image of the draft area and get it ready for the cropper.
 *
 * @param {Number} draftitemid The draft area item id.
 * @return {Promise<Object>} Resolves with an 'error' (the name of the language string which says what is wrong) if there is
 *                           nothing to crop. Otherwise resolves with the 'image' (as returned by the get_draft_image web
 *                           service), its natural 'dimensions', the 'sourcefilename' of the file which is actually loaded,
 *                           whether it is 'animated', the 'cropurl' to load into the cropper, and the 'stillurl' (an object
 *                           URL which the caller has to revoke once it is done with it, or null if the image is loaded as
 *                           it is).
 */
const prepareImage = async(draftitemid) => {
    // Fetch the current image (in full resolution) from the draft area.
    const image = await Ajax.call([{
        methodname: 'tool_imagepicker_get_draft_image',
        args: {draftitemid},
    }])[0];
    // An empty draft area has nothing to crop.
    if (!image.url) {
        return {error: 'error_noimagetocrop'};
    }

    // Preload the image so that its natural dimensions are known once the cropper is set up. This makes the initial fit,
    // the sizing of the canvas to the image aspect ratio and the restoring of a stored crop region reliable. The image is
    // served with caching headers (see the get_draft_image web service), so the further loads below are served from the
    // browser cache rather than downloaded again.
    let preloaded;
    try {
        preloaded = await preloadImage(image.url);
    } catch (error) {
        // The image could not be loaded at all (the server refused it, or the connection failed), so there is nothing to
        // crop. This is told apart from an image which loads but has no dimensions (see below), as the two call for different
        // advice.
        Log.debug(`tool_imagepicker: ${error.message}`);
        return {error: 'error_imageloadfailed'};
    }
    const dimensions = {width: preloaded.naturalWidth, height: preloaded.naturalHeight};

    // Without natural dimensions there is nothing to crop either. The cropper derives everything from them - the size of the
    // image element, the size of the output canvas, and even whether it considers the image loaded at all - and rejects
    // outright on a zero sized image. This is what an SVG without an intrinsic width and height looks like here, so it is
    // reported as such instead of being handed to the cropper, which would only throw a raw 'Failed to load the image
    // source' at the user.
    if (!dimensions.width || !dimensions.height) {
        return {error: 'error_nodimensions'};
    }

    // The image which is actually loaded into the cropper is the preserved original where there is one (see the
    // get_draft_image web service), so its file name is what decides everything which depends on the image type - no matter
    // which type the displayed file was stored as by an earlier crop.
    const sourcefilename = image.sourcefilename || image.filename;

    // The cropper shows the image as a plain <img>, and a plain <img> plays an animation. The cropped result, however, is
    // drawn from the image with a canvas, and a canvas takes the first frame of an animated image only (that is what the HTML
    // specification prescribes for drawImage()). So an animated image is not handed to the cropper as it is, but as a still
    // of its first frame - which is exactly what will be saved, so that is what the user gets to see and to crop. Every
    // other image is handed to the cropper as it is, so that nothing gets between the cropper and the file unless it has to.
    let cropurl = image.url;
    let stillurl = null;
    const animated = await isAnimatedImage(image.url, sourcefilename);
    if (animated) {
        stillurl = await rasterizeFirstFrame(preloaded);
        cropurl = stillurl;
    }

    return {image, dimensions, sourcefilename, animated, cropurl, stillurl};
};

/**
 * Store the cropped image back into the draft area and reflect the change in the file manager.
 *
 * @param {Object} cropper The cropper instance.
 * @param {Object} config The element configuration.
 * @param {Number} draftitemid The draft area item id.
 * @param {Object} image The image which is being cropped, as returned by the get_draft_image web service.
 * @param {String} sourcefilename The file name of the image which is actually loaded into the cropper.
 * @param {Object} dimensions The natural 'width' and 'height' of the source image.
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @param {Object} modal The modal instance.
 * @return {Promise<Boolean>} Resolves with true once the image has been stored, or with false if the user has decided
 *                            against storing it (the modal stays open then).
 */
const saveCrop = async(cropper, config, draftitemid, image, sourcefilename, dimensions, filemanager, modal) => {
    // The type of the image which is loaded into the cropper is what the canvas has to re-encode, so its file name decides
    // the encoding - and not the name of the displayed file, which may already be the result of an earlier crop.
    const {mimetype} = getEncoding(sourcefilename);

    // Read the crop region (as fractions of the original image) so it can be stored and restored later on.
    const crop = getNormalizedSelection(cropper);

    // Get the cropped image as a canvas and as an encoded blob. Without an explicit output size the cropper would render the
    // selection at the size at which it happens to be displayed in the modal, which would make the resolution of the result
    // depend on the size of the browser window. So we ask for exactly those pixels of the source image which the selection
    // covers, which keeps the cropped image at the resolution of the source image.
    let encoded = await encodeSelection(cropper, mimetype, getOutputSize(crop, dimensions), config.encodingquality);

    // Cropping does not necessarily make an image smaller: the browser re-encodes it, and that can make it considerably
    // bigger than the file it was cropped from (an indexed GIF which comes back as a plain PNG, for example). So the cropped
    // image can exceed the size limit which the server enforces (see the replace_draft_image web service) although the file
    // it was cropped from was fine. Rather than running into that refusal, the user is told beforehand what the problem is
    // and gets to decide: let the cropped image be reduced until it fits, or go back and crop a smaller area instead. How it
    // is reduced (by scaling it down, or by lowering its encoding quality first) is a site setting.
    if (image.maxbytes > 0 && encoded.blob.size > image.maxbytes) {
        // Lowering the quality is only possible for a lossy format. PNG is lossless and ignores the quality, so a PNG can
        // only be scaled down, whatever the site prefers.
        const lossy = mimetype !== ENCODING_FALLBACK.mimetype;
        const strategy = (config.sizelimitstrategy === STRATEGY_LOWERQUALITY && lossy)
            ? STRATEGY_LOWERQUALITY : STRATEGY_SCALEDOWN;

        const reduce = await confirmReduction(encoded.blob.size, image.maxbytes, strategy);
        if (!reduce) {
            return false;
        }
        try {
            encoded = await reduceToFit(cropper, mimetype, encoded, image.maxbytes, strategy);
        } catch (error) {
            if (!(error instanceof ReductionError)) {
                throw error;
            }
            // The limit cannot be met. That is nothing which went wrong, so it is told as a plain message rather than as an
            // exception, and the crop modal stays open so that the user can still crop a smaller area.
            await Notification.alert(await getString('sizelimit_title', 'tool_imagepicker'), error.message);
            return false;
        }
    }

    // The web service takes the image content as base64 encoded text.
    const filecontent = await blobToBase64(encoded.blob);

    // Store the cropped image in the draft area (this is what makes the change persist on form submission). The file name is
    // not sent along: the server names the stored file after the image which was cropped and after the image type which the
    // content really has, so it does not have to take our word for it.
    const args = {
        draftitemid,
        filecontent,
        acceptedtypes: config.acceptedtypes,
    };
    // The crop region is only sent along if it could be read, so that it can be restored the next time.
    if (crop) {
        args.cropx = crop.x;
        args.cropy = crop.y;
        args.cropwidth = crop.width;
        args.cropheight = crop.height;
    }
    const stored = await Ajax.call([{
        methodname: 'tool_imagepicker_replace_draft_image',
        args,
    }])[0];

    // Reflect the change in the file manager. The draft area is already updated on the server, so the change is picked up
    // correctly when the form is submitted whatever happens here - this is about what the user sees in the meantime, and
    // about what the core file manager believes to be in the field (see refreshFileManager()).
    refreshFileManager(filemanager, encoded.blob, stored.filename);

    // Mark the surrounding form as changed so the user is warned about unsaved changes when leaving the page.
    markFormChangedFromNode(filemanager);

    // The modal removes itself from the DOM when the user closes it, but not when it is closed from code, so it is destroyed
    // (which hides it first) rather than just hidden.
    modal.destroy();

    return true;
};

/**
 * Render the current crop selection at the given output size and encode it as an image file.
 *
 * @param {Object} cropper The cropper instance.
 * @param {String} mimetype The MIME type to encode to.
 * @param {Object|undefined} outputsize The output size for $toCanvas() (see getOutputSize()).
 * @param {Number} quality The encoding quality (0..1, for lossy formats).
 * @return {Promise<Object>} Resolves with the rendered 'canvas', the encoded 'blob' and the 'quality' it was encoded with.
 */
const encodeSelection = async(cropper, mimetype, outputsize, quality) => {
    // Let the cropper render the selection into a canvas, then encode that canvas as an image file.
    const canvas = await cropper.getCropperSelection().$toCanvas(outputsize);
    const blob = await canvasToBlob(canvas, mimetype, quality);

    return {canvas, blob, quality};
};

/**
 * Ask the user whether the cropped image, which is too large to be stored, should be reduced until it fits.
 *
 * The alternative which is offered is to go back to cropping, so that the user can crop a smaller area instead.
 *
 * @param {Number} size The file size of the cropped image, in bytes.
 * @param {Number} limit The maximum file size which may be stored, in bytes.
 * @param {String} strategy How the image would be reduced (STRATEGY_SCALEDOWN or STRATEGY_LOWERQUALITY), which is what the
 *                          user is told.
 * @return {Promise<Boolean>} Resolves with true if the image should be reduced, or with false if the user wants to go back
 *                            to cropping.
 */
const confirmReduction = async(size, limit, strategy) => {
    const lowerquality = strategy === STRATEGY_LOWERQUALITY;

    // Everything the modal says is fetched in one go.
    const [sizetext, limittext] = await Promise.all([formatBytes(size), formatBytes(limit)]);
    const [explanation, choice, title, save, cancel] = await getStrings([
        {key: 'sizelimit_explanation', component: 'tool_imagepicker', param: {size: sizetext, limit: limittext}},
        {key: lowerquality ? 'sizelimit_choice_lowerquality' : 'sizelimit_choice_scaledown', component: 'tool_imagepicker'},
        {key: 'sizelimit_title', component: 'tool_imagepicker'},
        {key: lowerquality ? 'sizelimit_lowerquality' : 'sizelimit_scaledown', component: 'tool_imagepicker'},
        {key: 'sizelimit_back', component: 'tool_imagepicker'},
    ]);

    // The explanation of the problem and the choice which the user has, as two paragraphs.
    const body = $('<div></div>');
    body.append($('<p></p>').text(explanation));
    body.append($('<p class="mb-0"></p>').text(choice));

    // A save / cancel modal whose buttons say what they do: reduce the image, or go back to cropping.
    const modal = await ModalSaveCancel.create({
        title,
        body,
        buttons: {save, cancel},
        removeOnClose: true,
    });

    return new Promise((resolve) => {
        // Only the save button means yes. Everything else which closes the modal (the cancel button, the close button, the
        // escape key) means going back to cropping.
        let reduce = false;
        modal.getRoot().on(ModalEvents.save, () => {
            reduce = true;
        });
        modal.getRoot().on(ModalEvents.hidden, () => resolve(reduce));
        modal.show();
    });
};

/**
 * Reduce the cropped image until its file size fits into the given limit, following the given strategy.
 *
 * With STRATEGY_SCALEDOWN, the image is scaled down right away. With STRATEGY_LOWERQUALITY, its encoding quality is lowered
 * first, and it is only scaled down (at the lowest quality) if that alone does not suffice.
 *
 * @param {Object} cropper The cropper instance.
 * @param {String} mimetype The MIME type to encode to.
 * @param {Object} encoded The cropped image at its full resolution and configured quality, as returned by encodeSelection().
 * @param {Number} limit The maximum file size which may be stored, in bytes.
 * @param {String} strategy The strategy to follow (STRATEGY_SCALEDOWN or STRATEGY_LOWERQUALITY).
 * @return {Promise<Object>} Resolves with the rendered 'canvas', the encoded 'blob' and the 'quality' of the reduced image.
 */
const reduceToFit = async(cropper, mimetype, encoded, limit, strategy) => {
    let result = encoded;

    // Lower the quality first if that is the strategy, and stop there if it suffices.
    if (strategy === STRATEGY_LOWERQUALITY) {
        result = await lowerQualityToFit(result, mimetype, limit);
        if (result.blob.size <= limit) {
            return result;
        }
    }

    // Scale the image down, either right away or because the lowest quality was not enough.
    return scaleDownToFit(cropper, mimetype, result, limit);
};

/**
 * Lower the encoding quality of the cropped image, step by step, until its file size fits into the given limit or the
 * lowest quality is reached.
 *
 * The rendered image stays as it is, only its encoding changes, so nothing has to be rendered again.
 *
 * @param {Object} encoded The cropped image, as returned by encodeSelection().
 * @param {String} mimetype The MIME type to encode to.
 * @param {Number} limit The maximum file size which may be stored, in bytes.
 * @return {Promise<Object>} Resolves with the given object, with the 'blob' and 'quality' fields updated. The blob may still
 *                           exceed the limit if the lowest quality was reached.
 */
const lowerQualityToFit = async(encoded, mimetype, limit) => {
    let {blob, quality} = encoded;

    // Re-encode the rendered canvas at an ever lower quality until the file fits or the lowest quality is reached.
    while (blob.size > limit && quality > MIN_ENCODING_QUALITY) {
        // The quality is rounded to two decimals, so that the steps do not drift through floating point arithmetic.
        quality = Math.max(MIN_ENCODING_QUALITY, Math.round((quality - ENCODING_QUALITY_STEP) * 100) / 100);
        blob = await canvasToBlob(encoded.canvas, mimetype, quality);
    }

    return {...encoded, blob, quality};
};

/**
 * Scale the cropped image down, step by step, until its file size fits into the given limit.
 *
 * The crop selection stays as it is, only the resolution at which it is rendered is reduced. The image is encoded with the
 * quality which the given image was encoded with.
 *
 * @param {Object} cropper The cropper instance.
 * @param {String} mimetype The MIME type to encode to.
 * @param {Object} encoded The cropped image at its full resolution, as returned by encodeSelection().
 * @param {Number} limit The maximum file size which may be stored, in bytes.
 * @return {Promise<Object>} Resolves with the given object, with the 'canvas' and 'blob' of the scaled down image.
 */
const scaleDownToFit = async(cropper, mimetype, encoded, limit) => {
    let {canvas, blob} = encoded;

    while (blob.size > limit) {
        // The file size of an image grows roughly with its number of pixels, so scaling both sides by the square root of the
        // ratio of limit and size should about hit the limit. As that is only roughly true, a margin is taken, and it is done
        // step by step: if the estimate does not suffice, the next step starts from the smaller image. Each step shrinks the
        // image by at least a tenth, so that the loop is sure to progress even where the estimate is far off.
        const factor = Math.min(Math.sqrt(limit / blob.size) * 0.95, 0.9);
        const width = Math.floor(canvas.width * factor);
        // Below this width the image would be of no use anyway, so give up rather than going on forever.
        if (width < MIN_SCALED_WIDTH) {
            throw new ReductionError(await getString('error_cannotscaledown', 'tool_imagepicker'));
        }

        // Render the same selection at the smaller width (the height follows from the aspect ratio of the selection).
        ({canvas, blob} = await encodeSelection(cropper, mimetype, {width}, encoded.quality));
    }

    return {...encoded, canvas, blob};
};

/**
 * Format a number of bytes for display, the way display_size() does on the server.
 *
 * @param {Number} bytes The number of bytes.
 * @return {Promise<String>} The formatted size, such as '2.5 MB'.
 */
const formatBytes = async(bytes) => {
    // The largest unit which fits is used, with one decimal; anything below a kilobyte is given in plain bytes.
    const units = [
        ['sizegb', 1024 ** 3],
        ['sizemb', 1024 ** 2],
        ['sizekb', 1024],
    ];
    for (const [unit, factor] of units) {
        if (bytes >= factor) {
            return `${(bytes / factor).toFixed(1)} ${await getString(unit, 'core')}`;
        }
    }

    return `${bytes} ${await getString('sizeb', 'core')}`;
};

/**
 * Read the draft area item id from the hidden input of the file manager form element.
 *
 * @param {String} elementid The id attribute of the file manager form element.
 * @return {Number} The draft area item id.
 */
const getDraftItemId = (elementid) => {
    // The hidden input of the file manager element carries the draft area item id as its value.
    const input = document.getElementById(elementid);
    return input ? parseInt(input.value, 10) : 0;
};

/**
 * Split a file name into its base name and its extension.
 *
 * @param {String} filename The file name.
 * @return {Object} The 'basename' (without the extension) and the 'extension' (with its leading dot and in lower case, or
 *                  an empty string if the file name has none).
 */
const splitFilename = (filename) => {
    // No dot at all, or a dot at the very start only (a hidden file), means no extension.
    const dot = filename.lastIndexOf('.');
    if (dot <= 0) {
        return {basename: filename, extension: ''};
    }

    return {basename: filename.slice(0, dot), extension: filename.slice(dot).toLowerCase()};
};

/**
 * Determine how a cropped image is encoded, based on the file name of the image it was cropped from.
 *
 * @param {String} filename The file name of the source image.
 * @return {Object} The 'mimetype' to encode as and the file 'extension' which the result then has.
 */
const getEncoding = (filename) => ENCODING_BY_EXTENSION[splitFilename(filename).extension] || ENCODING_FALLBACK;

/**
 * Determine the file name which a cropped image is stored under.
 *
 * This mirrors what the server does when it stores the cropped image (see build_filename() in the replace_draft_image
 * external function), which is what allows the client to tell in advance whether the result may be stored at all.
 *
 * @param {String} filename The file name of the source image.
 * @return {String} The file name of the cropped image.
 */
const getOutputFilename = (filename) => splitFilename(filename).basename + getEncoding(filename).extension;

/**
 * Determine the output size which the cropped image has to be rendered at.
 *
 * The crop region is known as fractions of the source image, so multiplying it with the natural size of the source image
 * yields exactly those source pixels which the selection covers. Only the width is returned: the cropper derives the height
 * from the aspect ratio of the selection itself, which keeps a configured fixed aspect ratio exact.
 *
 * @param {Object|null} crop The crop region as fractions of the image ('x', 'y', 'width', 'height').
 * @param {Object} dimensions The natural 'width' and 'height' of the source image.
 * @return {Object|undefined} The output size for $toCanvas(), or undefined if it cannot be determined.
 */
const getOutputSize = (crop, dimensions) => {
    // Without a usable region or a known image width, the cropper's own default output size has to do.
    if (!crop || !crop.width || !dimensions.width) {
        return undefined;
    }

    return {width: Math.round(crop.width * dimensions.width)};
};

/**
 * Convert a canvas to a Blob.
 *
 * @param {HTMLCanvasElement} canvas The canvas.
 * @param {String} mimetype The MIME type to encode to.
 * @param {Number} quality The encoding quality (for lossy formats).
 * @return {Promise<Blob>} The resulting blob.
 */
const canvasToBlob = (canvas, mimetype, quality) => new Promise((resolve, reject) => {
    // The function toBlob() hands over null if the canvas cannot be encoded (a tainted or an oversized canvas, for example).
    canvas.toBlob((blob) => {
        if (blob) {
            resolve(blob);
        } else {
            reject(new Error('Could not encode the cropped image.'));
        }
    }, mimetype, quality);
});

/**
 * Convert a Blob to a base64 encoded string (without the data URL prefix).
 *
 * @param {Blob} blob The blob.
 * @return {Promise<String>} The base64 encoded content.
 */
const blobToBase64 = (blob) => new Promise((resolve, reject) => {
    // The function readAsDataURL() yields 'data:<type>;base64,<content>', of which only the content is wanted.
    const reader = new FileReader();
    reader.onloadend = () => resolve(String(reader.result).split(',')[1]);
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(blob);
});

/**
 * Make the file manager show the cropped image in place of the image which it was cropped from.
 *
 * The core file manager does not expose its (YUI based) instance, so it cannot be asked to reload its file list directly.
 * But it reloads the list from the server whenever a folder of its path bar is clicked, and the root folder is always there
 * in the icon view and in the table view. So that is what is clicked: the file manager then fetches the current content of
 * the draft area, which is the cropped image, and renders it with its own means (thumbnail, file name, buttons). This also
 * brings the file list which the file manager keeps for itself up to date, so that a click on the file entry afterwards
 * opens the file dialog for the cropped file and not for a file which no longer exists.
 *
 * The tree view has no path bar. There, the shown entry is updated in the DOM instead (see updateFileEntry()). The list
 * which the file manager keeps for itself stays as it was in this case, so it still names the old file, and the file dialog
 * which it opens for the entry then acts on a file which no longer exists if cropping has changed the file name.
 *
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @param {Blob} blob The cropped image.
 * @param {String} filename The file name of the cropped image.
 */
const refreshFileManager = (filemanager, blob, filename) => {
    // The path bar starts with the root folder. Note that the path bar template node is not a candidate here: the file
    // manager removes it from the DOM when it initialises.
    const rootfolder = filemanager.querySelector('.fp-pathbar .fp-path-folder-name');
    if (rootfolder) {
        rootfolder.click();
        return;
    }

    // No path bar (tree view): patch the entry in the DOM instead.
    updateFileEntry(filemanager, blob, filename);
};

/**
 * Update the thumbnail and the file name of the file entry shown in the file manager (best effort, for immediate visual
 * feedback). The thumbnail is fed from the blob which was just uploaded, and the file name is taken from the server, as
 * cropping may have changed the file type of the image.
 *
 * @param {HTMLElement} filemanager The .filemanager container element.
 * @param {Blob} blob The new image.
 * @param {String} filename The new file name.
 */
const updateFileEntry = (filemanager, blob, filename) => {
    // The thumbnail is the (only) image in the file list.
    const thumbnail = filemanager.querySelector('.fp-content img');
    if (thumbnail) {
        // An object URL keeps the blob in memory for as long as it exists, so it is released once the image has been loaded.
        const url = URL.createObjectURL(blob);
        thumbnail.addEventListener('load', () => URL.revokeObjectURL(url), {once: true});
        thumbnail.addEventListener('error', () => URL.revokeObjectURL(url), {once: true});
        thumbnail.src = url;
    }

    // The file name is the text of the entry.
    const entry = getCurrentFileEntry(filemanager);
    if (entry) {
        entry.textContent = filename;
    }
};

/**
 * Preload an image so that its natural dimensions are available.
 *
 * @param {String} url The image URL.
 * @return {Promise<HTMLImageElement>} Resolves with the loaded image element (whose natural width and height are both 0 if
 *                                     the image has no intrinsic size), or rejects if the image could not be loaded.
 */
const preloadImage = (url) => new Promise((resolve, reject) => {
    // A detached image element is enough to load the image and to read its natural size.
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error(`could not load the image ${url}`));
    img.src = url;
});

/**
 * Render the first frame of a (possibly animated) image into a still image.
 *
 * Drawing an image onto a canvas takes its first frame only, which is what makes this a still. The still is encoded as PNG,
 * which is lossless, so it is pixel for pixel what the canvas took from the image.
 *
 * @param {HTMLImageElement} img The loaded image.
 * @return {Promise<String>} Resolves with an object URL of the still. The caller has to revoke it once it is done with it.
 */
const rasterizeFirstFrame = async(img) => {
    // Draw the image at its natural size onto a canvas of the same size. This is where the first frame is taken.
    const canvas = document.createElement('canvas');
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    canvas.getContext('2d').drawImage(img, 0, 0);

    // Encode the still and hand it over as an object URL, which the <img> of the cropper loads like any other URL.
    const blob = await canvasToBlob(canvas, 'image/png');

    return URL.createObjectURL(blob);
};

/**
 * Whether the image behind the given URL is animated.
 *
 * Only an image of a type which can carry an animation at all (see ANIMATABLE_EXTENSIONS) is looked at. For such an image,
 * the file is fetched and its content is inspected: the raster formats declare an animation in their container structure,
 * which is read with a small parser each (see isAnimatedGif(), isAnimatedPng() and isAnimatedWebp()), and an SVG is searched
 * for the markup which animates it (see isAnimatedSvg()).
 *
 * Whatever goes wrong on the way (the file cannot be fetched, or it is not what its name says it is) makes the image count
 * as not animated, so that it is handed to the cropper as it is.
 *
 * @param {String} url The image URL.
 * @param {String} filename The file name of the image, which tells its type.
 * @return {Promise<Boolean>}
 */
const isAnimatedImage = async(url, filename) => {
    // A type which cannot be animated is not even fetched.
    if (!hasExtension(filename, ANIMATABLE_EXTENSIONS)) {
        return false;
    }

    try {
        // Fetch the file (from the browser cache, normally) and hand its content to the parser for its type.
        const response = await fetch(url, {credentials: 'same-origin'});
        if (!response.ok) {
            return false;
        }
        const buffer = await response.arrayBuffer();

        switch (splitFilename(filename).extension) {
            case '.gif':
                return isAnimatedGif(new Uint8Array(buffer));
            case '.png':
                return isAnimatedPng(new Uint8Array(buffer));
            case '.webp':
                return isAnimatedWebp(new Uint8Array(buffer));
            case '.svg':
                return isAnimatedSvg(new TextDecoder().decode(buffer));
            default:
                return false;
        }
    } catch (error) {
        Log.debug(`tool_imagepicker: could not inspect ${filename} for animation: ${error.message}`);
        return false;
    }
};

/**
 * Read a run of bytes as an ASCII string.
 *
 * @param {Uint8Array} bytes The file content.
 * @param {Number} offset The offset of the first byte.
 * @param {Number} length The number of bytes.
 * @return {String}
 */
const readAscii = (bytes, offset, length) => String.fromCharCode(...bytes.subarray(offset, offset + length));

/**
 * Whether the given GIF file is animated, i.e. holds more than one image.
 *
 * The file is walked block by block (see the GIF89a specification): a global colour table after the header, then extension
 * blocks (0x21, whose payload is a chain of length-prefixed sub-blocks) and image blocks (0x2C, with a descriptor, an optional
 * local colour table and a chain of sub-blocks of image data), up to the trailer (0x3B). The second image block is what makes
 * the file animated.
 *
 * @param {Uint8Array} bytes The file content.
 * @return {Boolean}
 */
const isAnimatedGif = (bytes) => {
    if (bytes.length < 13 || readAscii(bytes, 0, 3) !== 'GIF') {
        return false;
    }

    // The size of a colour table is encoded in the lowest three bits of the flags byte which announces it (in bit 7).
    // eslint-disable-next-line no-bitwise
    const colourTableSize = (flags) => ((flags & 0x80) ? 3 * (1 << ((flags & 0x07) + 1)) : 0);

    // Skip the header (6 bytes), the logical screen descriptor (7 bytes) and the global colour table, if there is one.
    let pos = 13 + colourTableSize(bytes[10]);

    // Skip a chain of sub-blocks: each starts with its length, and a zero length ends the chain.
    const skipSubBlocks = () => {
        while (pos < bytes.length) {
            const length = bytes[pos++];
            if (length === 0) {
                break;
            }
            pos += length;
        }
    };

    // Walk the blocks and count the images.
    let images = 0;
    while (pos < bytes.length) {
        const block = bytes[pos++];
        if (block === 0x3B) {
            break;
        } else if (block === 0x21) {
            // An extension: its label byte, then its sub-blocks.
            pos++;
            skipSubBlocks();
        } else if (block === 0x2C) {
            if (++images > 1) {
                return true;
            }
            // An image: its descriptor (9 bytes), its local colour table if any, the LZW minimum code size (1 byte), then
            // its data sub-blocks.
            pos += 9 + colourTableSize(bytes[pos + 8]);
            pos++;
            skipSubBlocks();
        } else {
            // Not a block we know, so the file is not what it claims to be. Do not guess.
            break;
        }
    }

    return false;
};

/**
 * Whether the given PNG file is animated, i.e. is an APNG.
 *
 * An APNG announces itself with an 'acTL' (animation control) chunk, which has to precede the first 'IDAT' chunk. The file
 * is walked chunk by chunk (each is a big-endian length, a four character type, the data and a CRC) up to that point.
 *
 * @param {Uint8Array} bytes The file content.
 * @return {Boolean}
 */
const isAnimatedPng = (bytes) => {
    if (bytes.length < 8 || readAscii(bytes, 1, 3) !== 'PNG') {
        return false;
    }

    // Walk the chunks which follow the 8 byte signature.
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    let pos = 8;
    while (pos + 8 <= bytes.length) {
        const length = view.getUint32(pos);
        const type = readAscii(bytes, pos + 4, 4);
        if (type === 'acTL') {
            return true;
        }
        // The image data starts without an animation control chunk before it, so this is a plain PNG.
        if (type === 'IDAT' || type === 'IEND') {
            return false;
        }
        // Length (4 bytes), type (4 bytes), data, CRC (4 bytes).
        pos += 12 + length;
    }

    return false;
};

/**
 * Whether the given WebP file is animated.
 *
 * An animated WebP uses the extended file format, whose 'VP8X' chunk carries an animation flag, and it holds an 'ANIM'
 * chunk. The file is a RIFF container, which is walked chunk by chunk (each is a four character type, a little-endian
 * length and the data, padded to an even length).
 *
 * @param {Uint8Array} bytes The file content.
 * @return {Boolean}
 */
const isAnimatedWebp = (bytes) => {
    if (bytes.length < 12 || readAscii(bytes, 0, 4) !== 'RIFF' || readAscii(bytes, 8, 4) !== 'WEBP') {
        return false;
    }

    // Walk the chunks which follow the 12 byte RIFF header.
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    let pos = 12;
    while (pos + 8 <= bytes.length) {
        const type = readAscii(bytes, pos, 4);
        const length = view.getUint32(pos + 4, true);
        // The animation flag is bit 1 of the first flags byte of the chunk.
        // eslint-disable-next-line no-bitwise
        if (type === 'VP8X' && (bytes[pos + 8] & 0x02)) {
            return true;
        }
        // The animation chunks themselves are just as telling.
        if (type === 'ANIM' || type === 'ANMF') {
            return true;
        }
        // Type (4 bytes), length (4 bytes), data padded to an even length.
        pos += 8 + length + (length % 2);
    }

    return false;
};

/**
 * Whether the given SVG animates itself.
 *
 * An SVG which is shown in an <img> does not run scripts, but it does run SMIL animations (the animate elements) and CSS
 * animations (keyframes, or an animation property). This is a search of the markup for those, so it is a heuristic and not
 * a parse - but one which errs on the side of reporting an animation, and all that leads to is a still of the initial state.
 *
 * @param {String} markup The file content.
 * @return {Boolean}
 */
const isAnimatedSvg = (markup) => {
    // The SMIL animation elements.
    const smil = /<(animate|animateTransform|animateMotion|animateColor|set)[\s/>]/i;
    // CSS keyframes, or an animation property (in a <style> element or in a style attribute).
    const css = /@keyframes\s|[\s;{]animation(-name)?\s*:/i;

    return smil.test(markup) || css.test(markup);
};

/**
 * Size the cropper canvas so that it has the aspect ratio of the image and fits into the available modal body area.
 *
 * The image is scaled to fit into the available width and height (whichever is more constraining), preserving its aspect
 * ratio. This way the canvas matches the image (no empty letterbox / pillarbox areas) and never overflows the modal body,
 * so it never causes scroll bars.
 *
 * A small image is never blown up to fill the modal, it is shown at its natural size instead. Cropping an upscaled image
 * would suggest a level of detail which simply is not there, so the image is only ever scaled down, never up.
 *
 * @param {HTMLElement} canvasEl The cropper canvas element.
 * @param {Object} dimensions The natural 'width' and 'height' of the image. Both are guaranteed to be non-zero, as an image
 *                            without natural dimensions never reaches the cropper (see openCropperModal()).
 */
const sizeCanvasToImage = (canvasEl, dimensions) => {
    // The aspect ratio of the image is what the canvas has to end up with.
    const ratio = dimensions.width / dimensions.height;

    // The parent container fills the modal body, so its content box is the space actually available for the canvas.
    const parent = canvasEl.parentElement;
    const availwidth = parent ? parent.clientWidth : canvasEl.clientWidth;
    const availheight = parent ? parent.clientHeight : window.innerHeight;
    if (!availwidth || !availheight) {
        return;
    }

    // Fit the image into the available box, preserving its aspect ratio (limited by width or by height, whichever hits
    // first), so the image is scaled down as needed and never overflows.
    let width = availwidth;
    let height = width / ratio;
    if (height > availheight) {
        height = availheight;
        width = height * ratio;
    }

    // Never go beyond the natural size of the image, so that an image which is smaller than the available space is shown at
    // 1:1 instead of being blown up. The cropper fits the image into the canvas, so capping the canvas caps the image.
    if (width > dimensions.width) {
        width = dimensions.width;
        height = dimensions.height;
    }

    // Size the canvas explicitly; the cropper then fits the image into it (see $center()).
    canvasEl.style.width = `${Math.round(width)}px`;
    canvasEl.style.height = `${Math.round(height)}px`;
};

/**
 * Clamp a value to the [0, 1] range.
 *
 * @param {Number} value The value.
 * @return {Number} The clamped value.
 */
const clampFraction = (value) => Math.min(1, Math.max(0, value));

/**
 * Create a window resize handler which re-fits the cropper canvas and the image into the (resized) modal.
 *
 * The current crop region is read before the canvas is resized and re-applied afterwards, so the user does not lose their
 * selection when the browser window is resized. The handler is throttled to one run per animation frame.
 *
 * @param {Object} cropper The cropper instance.
 * @param {Object} dimensions The natural 'width' and 'height' of the image.
 * @return {Function} The resize handler.
 */
const createResizeHandler = (cropper, dimensions) => {
    // A burst of resize events is coalesced into one run per animation frame.
    let pending = false;

    return () => {
        if (pending) {
            return;
        }
        pending = true;

        requestAnimationFrame(() => {
            pending = false;

            // Remember the crop region before the canvas is resized (it is expressed relative to the image, so it stays
            // valid across the resize).
            const crop = getNormalizedSelection(cropper);

            // Fitting the image into the resized canvas needs the image to be released for a moment.
            const cropperimage = cropper.getCropperImage();
            sizeCanvasToImage(cropper.getCropperCanvas(), dimensions);
            freezeImage(cropperimage, false);
            cropperimage.$center('contain');
            freezeImage(cropperimage, true);

            // Re-apply the region once the cropper has laid the image out again, which takes it a frame.
            if (crop) {
                requestAnimationFrame(() => restoreSelection(cropper, crop));
            }
        });
    };
};

/**
 * Freeze the image within the cropper canvas, or release it again.
 *
 * The image is fitted into the canvas by scaling and moving it (see $center()), and it is meant to stay that way: a zoomed
 * out or moved image would leave parts of the canvas empty, and those would end up as transparent (or black) areas in the
 * cropped image. So once fitted, the image is frozen by switching off its scalability and translatability, which is what the
 * mouse wheel, the pinch gesture and the drag gesture act on. It is released only for the moment in which it is fitted again.
 *
 * @param {Object} cropperimage The cropper image element.
 * @param {Boolean} frozen Whether to freeze (true) or to release (false) the image.
 */
const freezeImage = (cropperimage, frozen) => {
    cropperimage.scalable = !frozen;
    cropperimage.translatable = !frozen;
};

/**
 * Read the current crop selection as fractions (0..1) of the original image.
 *
 * The values are relative to the displayed image (not the canvas), so they are independent of the current zoom level and
 * the size at which the image is displayed.
 *
 * @param {Object} cropper The cropper instance.
 * @return {Object|null} The crop region ('x', 'y', 'width', 'height'), or null if it could not be determined.
 */
const getNormalizedSelection = (cropper) => {
    // Both rectangles are in viewport coordinates, so their difference is the offset of the selection within the image.
    const selectionrect = cropper.getCropperSelection().getBoundingClientRect();
    const imagerect = cropper.getCropperImage().getBoundingClientRect();
    // An image without a rendered size (not laid out yet) yields nothing.
    if (!imagerect.width || !imagerect.height) {
        return null;
    }
    // The region is the part of the selection which lies within the image. The selection is kept within the image anyway
    // (see limitSelectionToImage()), but through rounding it may reach a hair beyond it. Note that the edges are clamped and
    // not the position and the size: clamping those one by one would turn a selection which reaches beyond the left edge
    // into a region of the same size which starts at the edge, which is not the part of the image that the selection covers.
    const left = clampFraction((selectionrect.left - imagerect.left) / imagerect.width);
    const top = clampFraction((selectionrect.top - imagerect.top) / imagerect.height);
    const right = clampFraction((selectionrect.right - imagerect.left) / imagerect.width);
    const bottom = clampFraction((selectionrect.bottom - imagerect.top) / imagerect.height);
    // A selection which does not touch the image at all covers nothing of it.
    if (right <= left || bottom <= top) {
        return null;
    }

    return {x: left, y: top, width: right - left, height: bottom - top};
};

/**
 * Get the area of the cropper canvas which the image covers, in the (whole pixel) coordinates which the selection uses.
 *
 * @param {Object} cropper The cropper instance.
 * @return {Object|null} The 'left', 'top', 'right' and 'bottom' edge of the image, or null if the image is not laid out yet.
 */
const getImageBounds = (cropper) => {
    const imagerect = cropper.getCropperImage().getBoundingClientRect();
    const canvasrect = cropper.getCropperCanvas().getBoundingClientRect();
    if (!imagerect.width || !imagerect.height) {
        return null;
    }
    // The selection works on whole pixels, whereas the image is fitted into the canvas at whatever size that takes. The
    // edges are rounded, so that a selection of the whole image is possible.
    return {
        left: Math.round(imagerect.left - canvasrect.left),
        top: Math.round(imagerect.top - canvasrect.top),
        right: Math.round(imagerect.right - canvasrect.left),
        bottom: Math.round(imagerect.bottom - canvasrect.top),
    };
};

/**
 * Whether the given rectangle lies within the given bounds.
 *
 * @param {Object} rect The rectangle ('x', 'y', 'width', 'height').
 * @param {Object} bounds The bounds ('left', 'top', 'right', 'bottom').
 * @return {Boolean}
 */
const isWithinBounds = (rect, bounds) => rect.x >= bounds.left && rect.y >= bounds.top
    && rect.x + rect.width <= bounds.right && rect.y + rect.height <= bounds.bottom;

/**
 * Shrink the given rectangle into the given bounds: it becomes the part of it which lies within the bounds, reduced further
 * to the given aspect ratio if there is one.
 *
 * The selection rounds its size to whole pixels, so the height which belongs to a width is only known after rounding. With
 * an aspect ratio, the width is therefore reduced until the rounded height fits as well.
 *
 * @param {Object} rect The rectangle ('x', 'y', 'width', 'height').
 * @param {Object} bounds The bounds ('left', 'top', 'right', 'bottom').
 * @param {Number} aspectratio The aspect ratio to keep (NaN for none).
 * @return {Object|null} The rectangle within the bounds, or null if the given one does not touch the bounds at all.
 */
const shrinkIntoBounds = (rect, bounds, aspectratio) => {
    const x = Math.max(rect.x, bounds.left);
    const y = Math.max(rect.y, bounds.top);
    let width = Math.min(rect.x + rect.width, bounds.right) - x;
    let height = Math.min(rect.y + rect.height, bounds.bottom) - y;
    if (width <= 0 || height <= 0) {
        return null;
    }
    if (aspectratio > 0) {
        // Start from the largest width whose height can still round down to the height which is available. Starting from
        // the exact width instead would make a selection which fits already (with a rounded height) a pixel smaller every
        // time.
        width = Math.floor(Math.min(width, (height + 0.5) * aspectratio));
        while (width > 1 && Math.round(width / aspectratio) > height) {
            width--;
        }
        height = width / aspectratio;
    }

    return {x, y, width, height};
};

/**
 * Work out what becomes of a change of the selection which would take it beyond the given bounds.
 *
 * - A selection which is moved stops at the edge, on each axis separately, so that it slides along an edge it has reached.
 * - A selection which is resized freely is cut off at the edge, so that the other edges keep following the pointer.
 * - A selection which is resized with a fixed aspect ratio cannot be cut off, as that would change its ratio. The cropper
 *   resizes such a selection in a straight line (every edge moves in proportion to the pointer), so the change is followed
 *   from the current selection towards the proposed one for as far as it stays within the bounds. Every rectangle on
 *   that way has the aspect ratio, as both of its ends have it.
 *
 * @param {Object} current The current selection ('x', 'y', 'width', 'height'), which lies within the bounds.
 * @param {Object} proposed The selection as the change would make it.
 * @param {Object} bounds The bounds ('left', 'top', 'right', 'bottom').
 * @param {Number} aspectratio The aspect ratio of the selection (NaN if it has none).
 * @return {Object|null} The selection to change to instead, or null if the change is to be dropped altogether.
 */
const fitSelectionChange = (current, proposed, bounds, aspectratio) => {
    const boundswidth = bounds.right - bounds.left;
    const boundsheight = bounds.bottom - bounds.top;

    // Moved: the size stays, the position is clamped.
    const moved = proposed.width === current.width && proposed.height === current.height;
    if (moved && proposed.width <= boundswidth && proposed.height <= boundsheight) {
        return {
            x: Math.min(Math.max(proposed.x, bounds.left), bounds.right - proposed.width),
            y: Math.min(Math.max(proposed.y, bounds.top), bounds.bottom - proposed.height),
            width: proposed.width,
            height: proposed.height,
        };
    }

    // Resized freely, or no usable starting point to follow the change from: cut the selection off at the edges.
    if (!(aspectratio > 0) || !isWithinBounds(current, bounds)) {
        return shrinkIntoBounds(proposed, bounds, aspectratio);
    }

    // Resized with a fixed aspect ratio: find out how far (0..1) the change can be followed until the first edge is hit.
    let fraction = 1;
    const limit = (from, to, edge) => {
        if (to !== from) {
            fraction = Math.min(fraction, Math.max(0, (edge - from) / (to - from)));
        }
    };
    if (proposed.x < bounds.left) {
        limit(current.x, proposed.x, bounds.left);
    }
    if (proposed.y < bounds.top) {
        limit(current.y, proposed.y, bounds.top);
    }
    if (proposed.x + proposed.width > bounds.right) {
        limit(current.x + current.width, proposed.x + proposed.width, bounds.right);
    }
    if (proposed.y + proposed.height > bounds.bottom) {
        limit(current.y + current.height, proposed.y + proposed.height, bounds.bottom);
    }
    const between = (from, to) => from + (to - from) * fraction;
    const followed = {
        x: between(current.x, proposed.x),
        y: between(current.y, proposed.y),
        width: between(current.width, proposed.width),
        height: between(current.height, proposed.height),
    };

    // The selection rounds to whole pixels, which could take it a pixel beyond an edge again. Rounding the position up and
    // shrinking the size into the bounds makes sure that it does not.
    return shrinkIntoBounds({...followed, x: Math.ceil(followed.x - 0.001), y: Math.ceil(followed.y - 0.001)}, bounds, aspectratio);
};

/**
 * Keep the selection of the cropper within the image while it is moved and resized.
 *
 * The cropper has no setting for this. What it offers is the change event of the selection, which is fired before a change
 * is applied and which can be cancelled. So a change which would take the selection beyond the image is cancelled, and the
 * selection is changed to what fits instead (see fitSelectionChange()), so that it goes right up to the edge rather than
 * stopping wherever the last change which still fitted has left it.
 *
 * @param {Object} cropper The cropper instance.
 */
const limitSelectionToImage = (cropper) => {
    const selection = cropper.getCropperSelection();

    // The change which is made instead fires the event again, and that one is to go through.
    let adjusting = false;

    selection.addEventListener('change', (event) => {
        if (adjusting) {
            return;
        }
        // Without an image which is laid out, there is nothing to keep the selection in yet.
        const bounds = getImageBounds(cropper);
        if (!bounds || isWithinBounds(event.detail, bounds)) {
            return;
        }

        // Drop the change, and make the one which fits instead (if there is one, and if it changes anything).
        event.preventDefault();
        const current = {x: selection.x, y: selection.y, width: selection.width, height: selection.height};
        const fitted = fitSelectionChange(current, event.detail, bounds, selection.aspectRatio);
        if (!fitted) {
            return;
        }
        adjusting = true;
        try {
            // The change is made without an aspect ratio: what fits has the aspect ratio of the selection already, and with
            // one the selection would adjust its size once more on its own, which can take it a pixel beyond an edge again.
            // This only says how this one change is made, the aspect ratio of the selection stays as it is.
            selection.$change(fitted.x, fitted.y, fitted.width, fitted.height, NaN);
        } finally {
            adjusting = false;
        }
    });
};

/**
 * Restore a crop selection which is given as fractions (0..1) of the original image.
 *
 * @param {Object} cropper The cropper instance.
 * @param {Object} crop The crop region ('x', 'y', 'width', 'height').
 */
const restoreSelection = (cropper, crop) => {
    const imagerect = cropper.getCropperImage().getBoundingClientRect();
    const canvasrect = cropper.getCropperCanvas().getBoundingClientRect();
    if (!imagerect.width || !imagerect.height) {
        return;
    }
    // Map the fractions (relative to the displayed image) to selection coordinates (relative to the cropper canvas): the
    // offset of the image within the canvas is added to the position, and the sizes are scaled to the displayed image.
    const x = (imagerect.left - canvasrect.left) + crop.x * imagerect.width;
    const y = (imagerect.top - canvasrect.top) + crop.y * imagerect.height;
    const width = crop.width * imagerect.width;
    const height = crop.height * imagerect.height;
    cropper.getCropperSelection().$change(x, y, width, height);
};
