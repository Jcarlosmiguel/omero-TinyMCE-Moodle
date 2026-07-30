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

namespace local_omeroembed;

/**
 * Plain $DB wrapper for local_omeroembed_annotations - kept separate from
 * ajax.php so the query logic (in particular, the "a student only ever
 * sees/touches their own rows" scoping) lives in one place, reusable by
 * anything else that needs it later (e.g. a future teacher/admin-only
 * cross-student heatmap view would deliberately bypass list_for_user()
 * below and query the table directly instead, aggregating by embedid
 * without a userid filter - see this plugin's own plan doc).
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class annotations_repository {
    /** @var string A fixed-screen-size pin, Google Maps style - geometry {x,y}. */
    const TYPE_POINT = 'point';

    /** @var string A real region of the slide, scales with zoom - geometry {x,y,r}, all in image-pixel units. */
    const TYPE_CIRCLE = 'circle';

    /**
     * The current user's own annotations for one specific embed placement -
     * never another user's, regardless of who's asking. This is the only
     * read path ajax.php's `list` action uses.
     *
     * @param string $embedid
     * @param int $userid
     * @return array Rows as stdClass, geometry still JSON-encoded (callers
     *               that need it decoded, e.g. ajax.php's JSON response,
     *               decode it themselves - kept as a plain passthrough here
     *               rather than guessing what shape the caller wants).
     */
    public static function list_for_user(string $embedid, int $userid): array {
        global $DB;

        return array_values($DB->get_records('local_omeroembed_annotations', [
            'embedid' => $embedid,
            'userid' => $userid,
        ], 'timecreated ASC'));
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @param string $embedid
     * @param string $type
     * @param array $geometry Shape-specific coordinates, e.g. ['x' => .., 'y' => ..]
     *                        for TYPE_POINT - in real OMERO image-pixel coordinates.
     * @param string $colour Hex string, e.g. '#ff0000'.
     * @param string $label Optional short note shown when the annotation is clicked - '' for none.
     * @return \stdClass The newly-created row, geometry re-decoded back to an array
     *                   for the caller's convenience (ajax.php echoes it straight
     *                   back as part of its JSON response).
     */
    public static function create(
        int $courseid,
        int $userid,
        string $embedid,
        string $type,
        array $geometry,
        string $colour,
        string $label = ''
    ): \stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'embedid' => $embedid,
            'type' => $type,
            'geometry' => json_encode($geometry),
            'colour' => $colour,
            'label' => $label,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_omeroembed_annotations', $record);
        $record->geometry = $geometry;
        return $record;
    }

    /**
     * Deletes an annotation, but only if it actually belongs to $userid -
     * defense in depth beyond ajax.php's own capability check, so a bug
     * there (or a future code path that forgets it) still can't let one
     * student delete another's annotation.
     *
     * @param int $id
     * @param int $userid
     * @return bool True if a row was actually deleted.
     */
    public static function delete_owned(int $id, int $userid): bool {
        global $DB;

        $record = $DB->get_record('local_omeroembed_annotations', ['id' => $id, 'userid' => $userid]);
        if (!$record) {
            return false;
        }
        $DB->delete_records('local_omeroembed_annotations', ['id' => $id]);
        return true;
    }
}
