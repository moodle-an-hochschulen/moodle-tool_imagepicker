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
 * Admin tool "Image Picker" - Preserved originals handling.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_imagepicker\local;

use context_user;
use file_reference_exception;
use file_storage;
use moodle_url;
use stdClass;
use stored_file;

/**
 * Handles the preservation of original (uncropped) images.
 *
 * When enabled, the original image which backs a cropped image is preserved in a dedicated file area so that cropping stays
 * non-destructive: the displayed / stored file is always the cropped version, but cropping always starts from the original.
 *
 * A record identifies the image it backs by two things together: the place which that image lives in (its file area, i.e.
 * context, component, file area and item id) and the content hash of the currently displayed file ('derivedhash').
 *
 * The content hash alone would not do. It says what an image is, not which image it is, and the same content can sit in any
 * number of places at once - which happens routinely when a course is duplicated or restored. Keying on it alone would let
 * two unrelated fields be taken for one another, so that one of them would end up handing its original over to the other.
 *
 * The place is not always the final one. A freshly uploaded image lives nowhere but in a draft area until the form is
 * submitted, so at the time of its first crop the draft area is the only place it has. Such a record names that draft area
 * as its place and is adopted into the real place (see adopt_place()) as soon as that is known: normally the moment the
 * form is saved and core copies the image into its file area (see adopt_saved_file(), which is driven by a hook), and
 * otherwise the next time the image is edited from there. The content hash is what carries the link across that gap, and
 * it is also what survives the draft area round trip, in which every file gets a new id but keeps its content.
 *
 * A preserved original is always stored in the context of the place it names: in the course context for a course header
 * image, for example, and in the user context of the uploading user while the image is nothing but a draft. It is stored
 * without an author. It therefore belongs to the image and not to the user who happened to crop it, which is what allows a
 * second user to re-crop the image from the original later on - and what keeps the original alive when the first user is
 * deleted.
 *
 * @package    tool_imagepicker
 * @copyright  2026 Alexander Bias, ssystems GmbH <abias@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class originals {
    /**
     * Whether the preservation of original images is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        // Return the admin setting which controls the feature.
        return (bool) get_config('tool_imagepicker', 'preserveoriginals');
    }

    /**
     * Get the files in the root of the given file area.
     *
     * @param int $contextid The context id.
     * @param string $component The component.
     * @param string $filearea The file area.
     * @param int $itemid The item id.
     * @return stored_file[] The files in the root of the area, empty if it holds none.
     */
    public static function get_root_files(int $contextid, string $component, string $filearea, int $itemid): array {
        // Get the files which sit directly in the root of the area, leaving out the directory entries. Files in
        // subdirectories are ignored on purpose as the image picker is a single-file field without subdirectories, so
        // anything below the root cannot be the image which we are looking for.
        return get_file_storage()->get_directory_files($contextid, $component, $filearea, $itemid, '/', false, false, 'filename');
    }

    /**
     * Get the (single) file in the root of the given file area.
     *
     * @param int $contextid The context id.
     * @param string $component The component.
     * @param string $filearea The file area.
     * @param int $itemid The item id.
     * @return stored_file|null The file, or null if the area has no file in its root.
     */
    public static function get_root_file(int $contextid, string $component, string $filearea, int $itemid): ?stored_file {
        // Pick the first file in the root of the area. The areas which this is used on hold a single file, so that is it.
        $files = self::get_root_files($contextid, $component, $filearea, $itemid);

        // If we have no file, the area is empty (or holds nothing but subdirectories).
        return $files ? reset($files) : null;
    }

    /**
     * Get the preserved original record which backs the given displayed file in the given place, if any.
     *
     * @param stored_file $current The currently displayed (potentially cropped) file.
     * @param array $place The place which the image lives in, as returned by resolve_place().
     * @return stdClass|null The record, or null if there is none.
     */
    public static function get_record_for(stored_file $current, array $place): ?stdClass {
        global $DB;

        $derivedhash = $current->get_contenthash();

        // Look for an original which is tied to exactly this place. This is the normal case for an image which has been
        // cropped before, no matter if it lives in a real file area or (still) in a draft area: its record names that place.
        $records = $DB->get_records('tool_imagepicker_original', [
            'derivedhash' => $derivedhash,
            'contextid' => $place['contextid'],
            'component' => $place['component'],
            'filearea' => $place['filearea'],
            'itemid' => $place['itemid'],
        ], 'timemodified DESC, id DESC');

        if ($records) {
            return reset($records);
        }

        // An image which is nothing but a draft has no other place its original could be tied to, so that is it for it.
        // In particular, an original which is tied to somebody else's draft area is never handed to a draft of ours which
        // happens to hold the same content. Uploading a copy of a cropped image must not open up its original.
        if (self::is_draft_place($place)) {
            return null;
        }

        // Otherwise, the image lives in a real file area and we look for an original which still names a draft area as its
        // place, i.e. one which was written while its image lived nowhere but in a draft area. Such a record is waiting to
        // be adopted into the place its image has ended up in.
        //
        // The draft area may well belong to another user than the current one: the image was uploaded and cropped by whoever
        // created it, and it is now edited from its real place by whoever may do so.
        //
        // Deliberately note that a record which names a *different* real place is not considered here. That is the whole
        // point of recording the place: an image which happens to have the same content as another one is not the same
        // image, and it gets an original of its own rather than taking the one which belongs to the other.
        $records = $DB->get_records_select(
            'tool_imagepicker_original',
            "derivedhash = :derivedhash AND component = 'user' AND filearea = 'draft'",
            ['derivedhash' => $derivedhash],
            'timemodified DESC, id DESC'
        );

        // Two images which were uploaded (but not yet saved) with the very same content cannot be told apart at this point.
        // The most recently touched record wins then. Both would preserve the same content as their original anyway, so the
        // two are interchangeable in everything but their crop region.
        return $records ? reset($records) : null;
    }

    /**
     * Whether the given place is a draft area rather than a real file area.
     *
     * An image which lives in a draft area has not been saved anywhere yet (or, at least, its record was written before it
     * was). Such a place is only ever a stand-in until the real place becomes known.
     *
     * @param array $place The place, as returned by resolve_place() (or a record which holds one).
     * @return bool
     */
    public static function is_draft_place(array $place): bool {
        return ($place['component'] ?? null) === 'user' && ($place['filearea'] ?? null) === 'draft';
    }

    /**
     * Whether the two given places are the same.
     *
     * @param array $one The one place.
     * @param array $other The other place.
     * @return bool
     */
    public static function is_same_place(array $one, array $other): bool {
        return (int) $one['contextid'] === (int) $other['contextid']
            && (string) $one['component'] === (string) $other['component']
            && (string) $one['filearea'] === (string) $other['filearea']
            && (int) $one['itemid'] === (int) $other['itemid'];
    }

    /**
     * Get the preserved original file for the given record.
     *
     * @param stdClass $record The preserved original record.
     * @return stored_file|null The preserved original file, or null if it is missing.
     */
    public static function get_file(stdClass $record): ?stored_file {
        // A preserved original is stored in the root of its own file area, with the id of its record as the item id.
        return self::get_root_file($record->contextid, 'tool_imagepicker', 'original', $record->id);
    }

    /**
     * Ensure that an original is preserved for the given displayed file in the given place, and return its record.
     *
     * If no original is preserved yet for the file in that place, the file itself is stored as the original (i.e. it is
     * treated as the original on its first crop). If one is preserved already, it is adopted into the place if it still
     * names a draft area.
     *
     * @param stored_file $current The currently displayed file (before it is replaced by a cropped version).
     * @param array $place The place which the image lives in, as returned by resolve_place().
     * @return stdClass The preserved original record.
     */
    public static function ensure_original(stored_file $current, array $place): stdClass {
        // Check if an original is preserved for the given file in the given place already.
        $record = self::get_record_for($current, $place);

        // If it is, the given file is itself a cropped version of that original. There is nothing to preserve then, we only
        // make sure that the original has arrived at the place of the image it backs.
        if ($record) {
            return self::adopt_place($record, $place);
        }

        // Otherwise, the given file is being cropped for the first time here. It is therefore the original itself and has to
        // be preserved before it is replaced by the cropped version.
        return self::stash($current, $place);
    }

    /**
     * Adopt the preserved original of the given record into the given place, moving the file along if needed.
     *
     * This is what settles a record which was written before the real place of its image could be known: an image which is
     * cropped on a form that creates its owning instance only on submission (a header image on the form of a not yet
     * existing course, for example) lives nowhere but in a draft area at that point. Its original is then preserved in the
     * user context of the uploading user, with the draft area recorded as its place, and it is adopted into the real place
     * the next time the image is edited.
     *
     * A record which names a real place already is never moved. It is only ever found for exactly that place (see
     * get_record_for()), so there is nothing to correct - and moving it is precisely the mistake which recording the place is
     * meant to prevent. Likewise, a record is never adopted into a draft area: that would tie it to a place which is a
     * stand-in itself.
     *
     * @param stdClass $record The preserved original record.
     * @param array $place The place which the image lives in, as returned by resolve_place().
     * @return stdClass The (updated) preserved original record.
     */
    public static function adopt_place(stdClass $record, array $place): stdClass {
        global $DB;

        // Nothing to learn if the record names a real place already, or if the caller does not know one either.
        if (!self::is_draft_place((array) $record) || self::is_draft_place($place)) {
            return $record;
        }

        // Move the preserved file over to the context of the place (if it is still there at all). As file storage has no move
        // operation across contexts, this is done by copying the file to the new place and deleting the old one afterwards.
        // The file content itself is not duplicated on disk, as file storage stores it only once per content hash.
        if ((int) $record->contextid !== (int) $place['contextid']) {
            if ($file = self::get_file($record)) {
                $fs = get_file_storage();
                $fs->create_file_from_storedfile(
                    self::build_file_record((int) $place['contextid'], $record->id, $file->get_filename()),
                    $file
                );
                $fs->delete_area_files($record->contextid, 'tool_imagepicker', 'original', $record->id);
            }
        }

        // Remember the place in the record. We update the given object as well, as the caller continues to work with it.
        $record->contextid = (int) $place['contextid'];
        $record->component = $place['component'];
        $record->filearea = $place['filearea'];
        $record->itemid = $place['itemid'];
        $record->timemodified = time();
        $DB->update_record('tool_imagepicker_original', (object) [
            'id' => $record->id,
            'contextid' => $record->contextid,
            'component' => $record->component,
            'filearea' => $record->filearea,
            'itemid' => $record->itemid,
            'timemodified' => $record->timemodified,
        ]);

        // Return the updated record.
        return $record;
    }

    /**
     * Adopt a draft-placed original into the place of the given file, if the file is the image which that original backs.
     *
     * This is called for every file which is created anywhere on the site (see \tool_imagepicker\hook_callbacks), so it has
     * to be cheap for the overwhelming majority of files, which have nothing to do with the image picker. A file which cannot
     * be an image picker image (a draft file, one of our own originals, a file in a subdirectory) is dismissed without a
     * query, and every other file costs a single lookup on the indexed content hash column.
     *
     * Only a draft-placed original is ever adopted. An original which names a real place already belongs to the image in
     * that place, and a copy of that image which turns up somewhere else (a duplicated course, for example) is another image
     * which gets an original of its own when it is cropped (see get_record_for()).
     *
     * @param stored_file $file The file which has just been created.
     * @return stdClass|null The adopted record, or null if the file backs no draft-placed original.
     */
    public static function adopt_saved_file(stored_file $file): ?stdClass {
        global $DB;

        // A draft file is a stand-in itself (that is what a draft-placed original names already), and our own originals are
        // not images which an original could back (adopting one creates its file in the new context, which fires the hook
        // again). An image picker image sits in the root of its area, so anything below it cannot be one either.
        $place = [
            'contextid' => (int) $file->get_contextid(),
            'component' => (string) $file->get_component(),
            'filearea' => (string) $file->get_filearea(),
            'itemid' => (int) $file->get_itemid(),
        ];
        if (
            $file->is_directory()
            || $file->get_filepath() !== '/'
            || self::is_draft_place($place)
            || ($place['component'] === 'tool_imagepicker' && $place['filearea'] === 'original')
        ) {
            return null;
        }

        // This is the check which nearly every file on the site fails, so it is the only one they pay for.
        $waiting = $DB->record_exists_select(
            'tool_imagepicker_original',
            "derivedhash = :derivedhash AND component = 'user' AND filearea = 'draft'",
            ['derivedhash' => $file->get_contenthash()]
        );
        if (!$waiting) {
            return null;
        }

        // Look the record up the way a crop from the new place would, so that the same rules apply (a record which names
        // this very place already wins over a draft-placed one, and the most recently touched draft-placed one is taken if
        // there are several), and settle it.
        $record = self::get_record_for($file, $place);
        if (!$record || !self::is_draft_place((array) $record)) {
            return null;
        }

        return self::adopt_place($record, $place);
    }

    /**
     * Resolve the place which the image behind the given draft file lives in.
     *
     * When a draft area is prepared from an existing file area, core records where each file came from in the source field of
     * the draft file (see file_prepare_draft_area()). That reference names the full file area which the image actually lives
     * in, so it is the most reliable answer and it does not depend on anything the client sends us.
     *
     * For a freshly uploaded image there is no such reference yet: the image does not live anywhere but in the draft area,
     * so the draft area itself is returned as its place. That is a stand-in (see is_draft_place()), and it is replaced by
     * the real place as soon as that becomes known (see adopt_place()).
     *
     * @param stored_file $draftfile The file in the draft area.
     * @return array The place, with keys 'contextid', 'component', 'filearea' and 'itemid'.
     */
    public static function resolve_place(stored_file $draftfile): array {
        // Read the source field of the draft file. Core stores a serialized object there, so unserializing may well fail for
        // a file which was not prepared by file_prepare_draft_area(). We therefore suppress the warning and simply fall back
        // below if we do not get what we expect. The object is a plain one, so nothing else is allowed to be unserialized.
        $source = @unserialize((string) $draftfile->get_source(), ['allowed_classes' => ['stdClass']]);

        // If the source names the place which the file was copied from, that is the place we are after. Anything which is
        // not the plain object that core writes (a serialized array, or an object of a class which was not allowed above and
        // hence came back incomplete) is not what we are after either.
        if ($source instanceof stdClass && !empty($source->original)) {
            try {
                $reference = file_storage::unpack_reference($source->original);

                // The component is what decides whether the reference names a file area at all. An item id of 0 is perfectly
                // normal for many file areas, so it must not be treated as a missing value here.
                if (!empty($reference['contextid']) && !empty($reference['component'])) {
                    return [
                        'contextid' => (int) $reference['contextid'],
                        'component' => (string) $reference['component'],
                        'filearea' => (string) $reference['filearea'],
                        'itemid' => (int) $reference['itemid'],
                    ];
                }
            } catch (file_reference_exception $e) {
                // The reference is unusable, so fall back below.
                debugging('tool_imagepicker: could not unpack the source reference of a draft file.', DEBUG_DEVELOPER);
            }
        }

        // Otherwise, the file does not tell us where it belongs (which is the case for a freshly uploaded image). The draft
        // area it sits in is the only place it has then.
        return [
            'contextid' => (int) $draftfile->get_contextid(),
            'component' => (string) $draftfile->get_component(),
            'filearea' => (string) $draftfile->get_filearea(),
            'itemid' => (int) $draftfile->get_itemid(),
        ];
    }

    /**
     * Whether the current user is allowed to read the preserved original of the given record.
     *
     * A preserved original is only ever needed by someone who is editing the image which it backs. What proves that is the
     * draft file through which they are editing it: when a form is opened, core copies the image into a draft area of the
     * editing user and records in that draft file where it came from (see resolve_place()). Only a user who was allowed to
     * open that form holds such a draft file. Requiring exactly that is what lets a second user crop from an original which
     * a first user has preserved, while it keeps the original away from everybody else - and it does so without having to
     * guess which capability governs the (arbitrary) form the image picker is used on.
     *
     * Note that holding the cropped image itself is deliberately not enough. The cropped image may be on public display (a
     * course header, for example), so anybody could download it and upload it into a draft area of their own. Such a draft
     * file carries no origin, so it proves nothing.
     *
     * An original whose image is nothing but a draft yet is tied to that draft area, which belongs to exactly one user, and
     * is readable by that user alone.
     *
     * @param stdClass $record The preserved original record.
     * @return bool
     */
    public static function can_access(stdClass $record): bool {
        global $DB, $USER;

        // Guests and not logged in users never edit a form which holds an image picker, so they are ruled out right away.
        // This also makes sure that we do not look into the draft files of the guest user account below, which is shared by
        // everyone who browses the site as a guest.
        if (!isloggedin() || isguestuser()) {
            return false;
        }

        $usercontextid = context_user::instance($USER->id)->id;
        $place = (array) $record;

        // An original which is tied to a draft area belongs to the user whose draft area that is.
        if (self::is_draft_place($place)) {
            return (int) $record->contextid === $usercontextid;
        }

        // Otherwise, the current user has to hold the displayed file which this original backs in one of their own draft
        // areas, and that draft file has to have been prepared from the very place the original is tied to.
        $fs = get_file_storage();
        $candidates = $DB->get_records('files', [
            'contextid' => $usercontextid,
            'component' => 'user',
            'filearea' => 'draft',
            'contenthash' => $record->derivedhash,
        ]);
        foreach ($candidates as $candidate) {
            $draftfile = $fs->get_file_instance($candidate);
            if (self::is_same_place(self::resolve_place($draftfile), $place)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get an SQL expression which counts the files that hold the displayed (cropped) image of a preserved original.
     *
     * The displayed image can exist in several places at once (in its file area and in the draft areas of the users who
     * are editing it, for example), and it is gone once the count is zero. This is the question which decides whether an
     * original is orphaned, see get_orphaned_records(), and the expression is meant to be used in a query on the originals
     * table.
     *
     * The preserved originals themselves are left out of the count. Right after an original has been stashed (and until
     * set_derived() has pointed its record at the cropped file), the record names the original's own content as the
     * displayed image, so the original would keep itself alive if it counted - forever, if the request died in between.
     *
     * @param string $alias The alias of the originals table in the query.
     * @return string The SQL expression.
     */
    public static function get_derived_count_sql(string $alias): string {
        return "(SELECT COUNT(1)
                   FROM {files} f
                  WHERE f.contenthash = {$alias}.derivedhash
                        AND NOT (f.component = 'tool_imagepicker' AND f.filearea = 'original'))";
    }

    /**
     * Get the preserved original records which are orphaned.
     *
     * An original is orphaned once the displayed (cropped) file it backs no longer exists anywhere, for example after the
     * image was removed from the field or the surrounding activity / course was deleted.
     *
     * @return stdClass[] The records, keyed by id.
     */
    public static function get_orphaned_records(): array {
        global $DB;

        $sql = "SELECT o.*
                  FROM {tool_imagepicker_original} o
                 WHERE " . self::get_derived_count_sql('o') . " = 0
              ORDER BY o.id";

        return $DB->get_records_sql($sql);
    }

    /**
     * Delete a preserved original, file and record alike.
     *
     * @param stdClass $record The preserved original record.
     */
    public static function delete(stdClass $record): void {
        global $DB;

        get_file_storage()->delete_area_files($record->contextid, 'tool_imagepicker', 'original', $record->id);
        $DB->delete_records('tool_imagepicker_original', ['id' => $record->id]);
    }

    /**
     * Delete all preserved originals on the site, files and records alike.
     *
     * The number of originals is open ended (every image picker field on the site can have one), so this is done in as few
     * operations as possible rather than original by original: the files are removed per context, as file storage can drop
     * a whole file area of a component in one go, and the records are removed with a single query afterwards.
     *
     * @return int The number of originals which were deleted.
     */
    public static function delete_all(): int {
        global $DB;

        // This can take a moment on a site with many originals, so the request must not time out.
        \core_php_time_limit::raise();

        $count = $DB->count_records('tool_imagepicker_original');

        // Remove the files, context by context.
        $fs = get_file_storage();
        $contextids = $DB->get_fieldset_sql('SELECT DISTINCT contextid FROM {tool_imagepicker_original}');
        foreach ($contextids as $contextid) {
            $fs->delete_area_files($contextid, 'tool_imagepicker', 'original');
        }

        // Remove the records.
        $DB->delete_records('tool_imagepicker_original');

        return $count;
    }

    /**
     * Update the derived (displayed) file content hash and the last crop region of a preserved original record.
     *
     * @param int $id The record id.
     * @param string $derivedhash The content hash of the new displayed (cropped) file.
     * @param array|null $crop The crop region as fractions (keys 'x', 'y', 'width', 'height'), or null to leave it unchanged.
     */
    public static function set_derived(int $id, string $derivedhash, ?array $crop = null): void {
        global $DB;

        // Compose the fields to update. Pointing the record at the new displayed file is what keeps the link between the two
        // intact, as the previous displayed file does not exist anymore after a crop.
        $record = (object) [
            'id' => $id,
            'derivedhash' => $derivedhash,
            'timemodified' => time(),
        ];

        // Add the crop region if we were given one. It is stored so that the crop modal can show the previous selection
        // again the next time the image is cropped.
        if ($crop !== null) {
            $record->cropx = $crop['x'];
            $record->cropy = $crop['y'];
            $record->cropwidth = $crop['width'];
            $record->cropheight = $crop['height'];
        }

        // Update the record.
        $DB->update_record('tool_imagepicker_original', $record);
    }

    /**
     * Get the URL under which a preserved original file is served.
     *
     * @param stored_file $file The preserved original file.
     * @return moodle_url
     */
    public static function get_url(stored_file $file): moodle_url {
        // Compose the pluginfile URL from the file itself, so that it always points to the place where the file really is.
        // The request is served (and access-checked) by tool_imagepicker_pluginfile().
        return moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'tool_imagepicker',
            'original',
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
    }

    /**
     * Store the given file as a preserved original for the given place.
     *
     * @param stored_file $current The file to preserve.
     * @param array $place The place which the image lives in, as returned by resolve_place().
     * @return stdClass The created record.
     */
    protected static function stash(stored_file $current, array $place): stdClass {
        global $DB;

        $now = time();

        // Create the record first. Its id is needed as the item id of the file area below, which is what keeps the preserved
        // originals of different images apart from each other.
        // At this point, the record still points at the file which is about to be replaced. The caller updates it to the
        // cropped file with set_derived() as soon as that file has been written.
        // The place is recorded as it is: if it is a draft area, the record is adopted into the real place by adopt_place()
        // as soon as the image is edited again from there.
        $record = (object) [
            'contextid' => (int) $place['contextid'],
            'component' => $place['component'],
            'filearea' => $place['filearea'],
            'itemid' => $place['itemid'],
            'derivedhash' => $current->get_contenthash(),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('tool_imagepicker_original', $record);

        // Copy the file into our own file area, in the context of the place. The file content is not duplicated on disk, as
        // file storage stores it only once per content hash. This copy is what makes the original outlive the draft area it
        // currently lives in.
        $fs = get_file_storage();
        $fs->create_file_from_storedfile(
            self::build_file_record((int) $place['contextid'], $record->id, $current->get_filename()),
            $current
        );

        // Return the created record.
        return $record;
    }

    /**
     * Build the file record under which a preserved original is stored.
     *
     * The file is deliberately stored without an author: the original belongs to the image which it backs, not to the user
     * who happened to crop that image. Recording an author would tie the original to that user's personal data and would
     * make it subject to deletion together with them, which would leave the image without its original.
     *
     * The same goes for everything else which describes where the file came from and whose work it is. When a file is copied
     * from another stored file, file storage takes these fields over from the source unless they are given explicitly, so
     * they are all cleared here: the user reference, the author text, the license and the source reference of the draft
     * file. None of them is needed to serve the original (its record knows the place it belongs to), and all of them are the
     * personal data or the bookkeeping of the draft file rather than of the original.
     *
     * @param int $contextid The context in which the original is stored.
     * @param int $itemid The item id (which is the id of the preserved original record).
     * @param string $filename The file name.
     * @return array The file record.
     */
    protected static function build_file_record(int $contextid, int $itemid, string $filename): array {
        // Compose the file record. The file is always stored in the root of the area, as an image picker field holds exactly
        // one image without any subdirectories.
        return [
            'contextid' => $contextid,
            'component' => 'tool_imagepicker',
            'filearea' => 'original',
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => null,
            'author' => null,
            'license' => null,
            'source' => null,
        ];
    }
}
