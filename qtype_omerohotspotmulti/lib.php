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
 * Library functions for qtype_omerohotspotmulti.
 *
 * No custom file areas (the question's own slide is hosted by OMERO, never
 * stored as a Moodle file) - same reasoning qtype_omerohotspot's own
 * lib.php already gives for why there is no qtype_omerohotspotmulti_pluginfile()
 * here either.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Rotates a point into a shape's own unrotated local frame - identical
 * formula to qtype_omerohotspot's own qtype_omerohotspot_unrotate() (itself
 * ported from local_omeroembed's classes/hotspot_multi_repository.php),
 * ported again here rather than called cross-plugin: this qtype's
 * geometry lives in its own qtype_omerohotspotmulti_options table, not
 * either of local_omeroembed's - only the formula is shared.
 *
 * @param float $px
 * @param float $py
 * @param float $rotation Radians.
 * @return array [x, y] in the shape's own local frame.
 */
function qtype_omerohotspotmulti_unrotate(float $px, float $py, float $rotation): array {
    $cos = cos($rotation);
    $sin = sin($rotation);
    return [$px * $cos + $py * $sin, -$px * $sin + $py * $cos];
}

/**
 * The security-critical hit-test this whole plugin exists for: checks a
 * student's clicked image-pixel coordinates against every region in the
 * hidden set, entirely server-side, short-circuiting on the first match -
 * correct if the click lands inside ANY one of them (an "any-of-N" model,
 * not "find all N" - see this plugin's own plan doc). Same "no HIT_RADIUS
 * clamp" reasoning as qtype_omerohotspot's own qtype_omerohotspot_check() -
 * that clamp only exists to keep a tiny on-screen shape clickable when
 * *selecting* it in the drawing tools, applying it here would make the
 * true regions larger than what the teacher actually drew.
 *
 * @param array $regions Array of {type, x, y, rx, ry, rotation} shapes.
 * @param float $x Image-pixel x of the student's click.
 * @param float $y Image-pixel y of the student's click.
 * @return bool
 */
function qtype_omerohotspotmulti_check(array $regions, float $x, float $y): bool {
    foreach ($regions as $region) {
        $local = qtype_omerohotspotmulti_unrotate($x - $region['x'], $y - $region['y'], $region['rotation'] ?? 0);
        if ($region['type'] === 'ellipse') {
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
