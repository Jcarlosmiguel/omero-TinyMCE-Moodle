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
 * Plain $DB wrapper for the click-to-answer hotspot feature -
 * local_omeroembed_hotspots (the hidden correct-answer region, one row per
 * embedid) and local_omeroembed_hotspot_attempts (every student click).
 *
 * The one hard rule every method here exists to enforce: the region's own
 * geometry must never reach a student's browser. get_geometry() is for
 * teacher-authoring use only (ajax.php's hotspot_get action, gated behind
 * local/omeroembed:hotspotauthor) - check_attempt() is the only thing the
 * student-facing hotspot_attempt action calls, and it returns a bare bool,
 * never the geometry it checked against.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hotspot_repository {
    /**
     * Confirms $embedid actually belongs to $courseid before any caller
     * proceeds to read/write/clear/attempt its hidden region.
     *
     * SECURITY: ajax.php's require_capability() checks the *declared*
     * $courseid; every actual operation below (get_geometry, save, clear,
     * check_attempt) looks up by $embedid alone. Without this,
     * local/omeroembed:hotspotauthor in ANY course would let a teacher
     * read, overwrite, or delete another course's hidden correct-answer
     * region - and hotspot_attempt's plain correct/incorrect response
     * would work as a brute-force oracle against it for anyone holding
     * :annotate anywhere. Confirmed exploitable before this fix, found
     * during a security audit ahead of Marketplace submission.
     *
     * A never-before-seen embedid (no row yet) passes silently - a
     * teacher defining a region for the first time has nothing yet to
     * conflict with.
     *
     * @param string $embedid
     * @param int $courseid
     * @throws \moodle_exception if a hotspot row exists for this embedid
     *                            under a *different* courseid.
     */
    public static function verify_course(string $embedid, int $courseid): void {
        global $DB;

        $record = $DB->get_record('local_omeroembed_hotspots', ['embedid' => $embedid], 'courseid');
        if ($record && (int) $record->courseid !== $courseid) {
            throw new \moodle_exception('embedcoursemismatch', 'local_omeroembed');
        }
    }

    /**
     * The current hidden region for one embed, decoded - teacher-authoring
     * use only (rendering the existing region as a reference outline when
     * reopening author.php). Never call this from the student attempt path.
     *
     * @param string $embedid
     * @return array|null Decoded geometry, or null if no region is defined yet.
     */
    public static function get_geometry(string $embedid): ?array {
        global $DB;

        $record = $DB->get_record('local_omeroembed_hotspots', ['embedid' => $embedid]);
        if (!$record) {
            return null;
        }
        return json_decode($record->geometry, true);
    }

    /**
     * Whether a region has been defined at all yet - used by proxy.php to
     * decide whether the student-facing attempt script is worth injecting,
     * without needing the geometry itself.
     *
     * @param string $embedid
     * @return bool
     */
    public static function exists(string $embedid): bool {
        global $DB;

        return $DB->record_exists('local_omeroembed_hotspots', ['embedid' => $embedid]);
    }

    /**
     * Creates or replaces the hidden region for one embed - "one region per
     * embed" (see this class's own docblock) means there's no partial-edit
     * case to handle, a new drawn region always simply supersedes the old
     * one outright.
     *
     * @param int $courseid
     * @param string $embedid
     * @param int $userid The teacher defining this region.
     * @param array $geometry {type, x, y, rx, ry, rotation} - same shape as
     *                        an annotation's own ellipse/rectangle geometry.
     * @return \stdClass The saved row, geometry re-decoded for the caller's convenience.
     */
    public static function save(int $courseid, string $embedid, int $userid, array $geometry): \stdClass {
        global $DB;

        $now = time();
        $existing = $DB->get_record('local_omeroembed_hotspots', ['embedid' => $embedid]);
        if ($existing) {
            $DB->delete_records('local_omeroembed_hotspots', ['embedid' => $embedid]);
        }
        $record = (object) [
            'courseid' => $courseid,
            'embedid' => $embedid,
            'createdby' => $userid,
            'geometry' => json_encode($geometry),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_omeroembed_hotspots', $record);
        $record->geometry = $geometry;
        return $record;
    }

    /**
     * Deletes the hidden region for one embed, if any.
     *
     * @param string $embedid
     * @return bool True if a row actually existed and was removed.
     */
    public static function clear(string $embedid): bool {
        global $DB;

        if (!$DB->record_exists('local_omeroembed_hotspots', ['embedid' => $embedid])) {
            return false;
        }
        $DB->delete_records('local_omeroembed_hotspots', ['embedid' => $embedid]);
        return true;
    }

    /**
     * Rotates a point into a shape's own unrotated local frame - identical
     * formula to js/annotate.js's own unrotate(), ported to PHP so the same
     * hit-test logic that already decides which shape a click selects can
     * decide whether a hotspot click is correct.
     *
     * @param float $px
     * @param float $py
     * @param float $rotation Radians.
     * @return array [x, y] in the shape's own local frame.
     */
    private static function unrotate(float $px, float $py, float $rotation): array {
        $cos = cos($rotation);
        $sin = sin($rotation);
        return [$px * $cos + $py * $sin, -$px * $sin + $py * $cos];
    }

    /**
     * The security-critical method this whole feature exists for: checks a
     * student's clicked image-pixel coordinates against the stored hidden
     * region, entirely server-side, and returns nothing but a bare bool -
     * the geometry it checked against never leaves this function.
     *
     * Deliberately does NOT apply the HIT_RADIUS clamp js/annotate.js's own
     * client-side annotationAtPixel() uses (that clamp exists purely to
     * keep a tiny on-screen shape clickable for *selecting* it - applying
     * it here would make the true region larger than what the teacher
     * actually drew, a real correctness bug, not just an inconsistency).
     *
     * @param string $embedid
     * @param float $x Image-pixel x of the student's click.
     * @param float $y Image-pixel y of the student's click.
     * @return bool
     */
    public static function check_attempt(string $embedid, float $x, float $y): bool {
        $geometry = self::get_geometry($embedid);
        if ($geometry === null) {
            return false;
        }

        $local = self::unrotate($x - $geometry['x'], $y - $geometry['y'], $geometry['rotation'] ?? 0);
        if ($geometry['type'] === annotations_repository::TYPE_ELLIPSE) {
            $normalised = (($local[0] ** 2) / ($geometry['rx'] ** 2)) + (($local[1] ** 2) / ($geometry['ry'] ** 2));
            return $normalised <= 1;
        }
        return abs($local[0]) <= $geometry['rx'] && abs($local[1]) <= $geometry['ry'];
    }

    /**
     * Logs one attempt - every click, right or wrong, kept purely so a
     * future teacher-review page can be added without losing history (none
     * reads this yet in this first pass).
     *
     * @param int $courseid
     * @param string $embedid
     * @param int $userid
     * @param float $x
     * @param float $y
     * @param bool $correct
     * @return void
     */
    public static function record_attempt(
        int $courseid,
        string $embedid,
        int $userid,
        float $x,
        float $y,
        bool $correct
    ): void {
        global $DB;

        $DB->insert_record('local_omeroembed_hotspot_attempts', (object) [
            'courseid' => $courseid,
            'embedid' => $embedid,
            'userid' => $userid,
            'x' => $x,
            'y' => $y,
            'correct' => $correct ? 1 : 0,
            'timecreated' => time(),
        ]);
    }
}
