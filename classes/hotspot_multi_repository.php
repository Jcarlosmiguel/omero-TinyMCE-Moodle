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
 * Plain $DB wrapper for the multi-region hotspot feature -
 * local_omeroembed_hotspot_multi (the hidden set of acceptable
 * correct-answer regions, one row per embedid) and
 * local_omeroembed_hotspot_multi_attempts (every student click). A sibling
 * of hotspot_repository.php, not a mode of it - see this plugin's own plan
 * doc for why a click is correct against ANY one of several teacher-marked
 * regions here, rather than one single shape.
 *
 * The one hard rule every method here exists to enforce: no region's own
 * geometry must ever reach a student's browser. get_geometry() is for
 * teacher-authoring use only (ajax.php's hotspotmulti_get action, gated
 * behind local/omeroembed:hotspotauthor) - check_attempt() is the only
 * thing the student-facing hotspotmulti_attempt action calls, and it
 * returns a bare bool, never the geometry it checked against.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hotspot_multi_repository {
    /** @var int Defensive backstop against a malformed/hostile payload writing an unbounded JSON blob - not a real region-count limit (there is none), see save()'s own docblock. */
    const MAX_REGIONS = 500;

    /**
     * The current set of hidden regions for one embed, decoded - teacher-
     * authoring use only (rendering the existing regions as reference
     * outlines when reopening author.php). Never call this from the
     * student attempt path.
     *
     * @param string $embedid
     * @return array|null Decoded array of {type,x,y,rx,ry,rotation} shapes, or null if none are defined yet.
     */
    public static function get_geometry(string $embedid): ?array {
        global $DB;

        $record = $DB->get_record('local_omeroembed_hotspot_multi', ['embedid' => $embedid]);
        if (!$record) {
            return null;
        }
        return json_decode($record->geometry, true);
    }

    /**
     * Whether at least one region has been defined at all yet - used by
     * proxy.php to decide whether the student-facing attempt script is
     * worth injecting, without needing the geometry itself.
     *
     * @param string $embedid
     * @return bool
     */
    public static function exists(string $embedid): bool {
        global $DB;

        $record = $DB->get_record('local_omeroembed_hotspot_multi', ['embedid' => $embedid]);
        return $record !== false && json_decode($record->geometry, true) !== [];
    }

    /**
     * Creates or replaces the whole set of hidden regions for one embed -
     * "one row per embed, save replaces the whole value" (see this class's
     * own docblock), same convention hotspot_repository::save() already
     * uses, just for an array instead of one shape. Every element is
     * validated before anything is persisted - reject the whole request
     * rather than partially save (same "never trust the client" stance
     * ajax.php's own hotspot_save/create actions already take).
     *
     * @param int $courseid
     * @param string $embedid
     * @param int $userid The teacher defining these regions.
     * @param array $regions Array of {type, x, y, rx, ry, rotation} shapes.
     * @return \stdClass The saved row, geometry re-decoded for the caller's convenience.
     * @throws \moodle_exception If any element is malformed, or the array is implausibly large.
     */
    public static function save(int $courseid, string $embedid, int $userid, array $regions): \stdClass {
        global $DB;

        if (count($regions) > self::MAX_REGIONS) {
            throw new \moodle_exception('toomanyregions', 'local_omeroembed', '', self::MAX_REGIONS);
        }
        foreach ($regions as $region) {
            if (!is_array($region)
                    || !isset($region['type'], $region['x'], $region['y'], $region['rx'], $region['ry'])
                    || ($region['type'] !== annotations_repository::TYPE_ELLIPSE
                        && $region['type'] !== annotations_repository::TYPE_RECTANGLE)
                    || (float) $region['rx'] <= 0 || (float) $region['ry'] <= 0) {
                throw new \moodle_exception('invalidregion', 'local_omeroembed');
            }
        }

        $now = time();
        $existing = $DB->get_record('local_omeroembed_hotspot_multi', ['embedid' => $embedid]);
        if ($existing) {
            $DB->delete_records('local_omeroembed_hotspot_multi', ['embedid' => $embedid]);
        }
        $record = (object) [
            'courseid' => $courseid,
            'embedid' => $embedid,
            'createdby' => $userid,
            'geometry' => json_encode(array_values($regions)),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_omeroembed_hotspot_multi', $record);
        $record->geometry = array_values($regions);
        return $record;
    }

    /**
     * Deletes every hidden region for one embed, if any.
     *
     * @param string $embedid
     * @return bool True if a row actually existed and was removed.
     */
    public static function clear(string $embedid): bool {
        global $DB;

        if (!$DB->record_exists('local_omeroembed_hotspot_multi', ['embedid' => $embedid])) {
            return false;
        }
        $DB->delete_records('local_omeroembed_hotspot_multi', ['embedid' => $embedid]);
        return true;
    }

    /**
     * Rotates a point into a shape's own unrotated local frame - identical
     * formula to hotspot_repository::unrotate()/js/annotate.js's own
     * unrotate(), ported again here rather than called cross-method since
     * this class's own check_attempt() calls it once per region in a loop.
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
     * student's clicked image-pixel coordinates against every stored
     * region, entirely server-side, short-circuiting on the first match -
     * correct if the click lands inside ANY one of them (an "any-of-N"
     * model, not "find all N" - see this plugin's own plan doc). Returns
     * nothing but a bare bool - no region's geometry ever leaves this
     * function.
     *
     * Deliberately does NOT apply the HIT_RADIUS clamp the client-side
     * selection code uses, same reasoning as hotspot_repository's own
     * check_attempt().
     *
     * @param string $embedid
     * @param float $x Image-pixel x of the student's click.
     * @param float $y Image-pixel y of the student's click.
     * @return bool
     */
    public static function check_attempt(string $embedid, float $x, float $y): bool {
        $regions = self::get_geometry($embedid);
        if (empty($regions)) {
            return false;
        }

        foreach ($regions as $region) {
            $local = self::unrotate($x - $region['x'], $y - $region['y'], $region['rotation'] ?? 0);
            if ($region['type'] === annotations_repository::TYPE_ELLIPSE) {
                $normalised = (($local[0] ** 2) / ($region['rx'] ** 2)) + (($local[1] ** 2) / ($region['ry'] ** 2));
                if ($normalised <= 1) {
                    return true;
                }
            } else if (abs($local[0]) <= $region['rx'] && abs($local[1]) <= $region['ry']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Logs one attempt - every click, right or wrong, kept purely so a
     * future teacher-review page can be added without losing history (none
     * reads this yet in this first pass) - identical shape to
     * hotspot_repository::record_attempt().
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

        $DB->insert_record('local_omeroembed_hotspot_multi_attempts', (object) [
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
