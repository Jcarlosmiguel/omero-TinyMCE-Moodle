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
 * Library functions for qtype_omerohotspot.
 *
 * No custom file areas (the question's own slide is hosted by OMERO, never
 * stored as a Moodle file) - so unlike qtype_truefalse's own lib.php, there
 * is no qtype_omerohotspot_pluginfile() here; standard question text/
 * feedback files are already handled by core's own question_pluginfile()
 * dispatch without a qtype-specific hook.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Rotates a point into a shape's own unrotated local frame - identical
 * formula to local_omeroembed's classes/hotspot_repository.php's own
 * unrotate() (itself ported from js/annotate.js's client-side version),
 * ported again here rather than called cross-plugin: this qtype's
 * geometry lives in its own qtype_omerohotspot_options table, not
 * local_omeroembed_hotspots, so there's no shared row to call into - only
 * the formula is shared.
 *
 * @param float $px
 * @param float $py
 * @param float $rotation Radians.
 * @return array [x, y] in the shape's own local frame.
 */
function qtype_omerohotspot_unrotate(float $px, float $py, float $rotation): array {
    $cos = cos($rotation);
    $sin = sin($rotation);
    return [$px * $cos + $py * $sin, -$px * $sin + $py * $cos];
}

/**
 * The security-critical hit-test this whole plugin exists for: checks a
 * student's clicked image-pixel coordinates against the hidden region,
 * entirely server-side. Same "no HIT_RADIUS clamp" reasoning as
 * local_omeroembed's own check_attempt() - that clamp only exists to keep
 * a tiny on-screen shape clickable when *selecting* it in the drawing
 * tools, applying it here would make the true region larger than what the
 * teacher actually drew.
 *
 * @param array $geometry {type, x, y, rx, ry, rotation}
 * @param float $x Image-pixel x of the student's click.
 * @param float $y Image-pixel y of the student's click.
 * @return bool
 */
function qtype_omerohotspot_check(array $geometry, float $x, float $y): bool {
    $local = qtype_omerohotspot_unrotate($x - $geometry['x'], $y - $geometry['y'], $geometry['rotation'] ?? 0);
    if ($geometry['type'] === 'ellipse') {
        $normalised = (($local[0] ** 2) / ($geometry['rx'] ** 2)) + (($local[1] ** 2) / ($geometry['ry'] ** 2));
        return $normalised <= 1;
    }
    return abs($local[0]) <= $geometry['rx'] && abs($local[1]) <= $geometry['ry'];
}
