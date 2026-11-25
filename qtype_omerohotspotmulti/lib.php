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
 * Whether a value is a real, finite number - same rule and reasoning as
 * qtype_omerohotspot's own qtype_omerohotspot_is_finite_number() (the
 * "1e999" -> INF -> round() TypeError -> poisoned quiz cron task chain),
 * ported rather than called cross-plugin for the same independence reason
 * qtype_omerohotspotmulti_unrotate() above gives.
 *
 * @param mixed $value
 * @return bool
 */
function qtype_omerohotspotmulti_is_finite_number($value): bool {
    return is_numeric($value) && is_finite((float) $value);
}

/**
 * The one shared rule for what counts as a usable correct-answer region -
 * applied per region here (this qtype stores an array of them), otherwise
 * identical to qtype_omerohotspot's own qtype_omerohotspot_clean_geometry()
 * (see its docblock for the zero-rx/ry DivisionByZeroError this closes,
 * and why 'rotation' alone is optional). Called from the edit form's
 * validation(), save_question_options(), the restore class, and
 * qtype_omerohotspotmulti_check() itself.
 *
 * @param mixed $region One decoded region, as json_decode(..., true) gives it.
 * @return array|null Normalised {type, x, y, rx, ry, rotation} (all numbers cast to float), or null if not usable.
 */
function qtype_omerohotspotmulti_clean_region($region): ?array {
    if (!is_array($region) || !in_array($region['type'] ?? null, ['ellipse', 'rectangle'], true)) {
        return null;
    }
    $clean = ['type' => $region['type']];
    foreach (['x', 'y', 'rx', 'ry'] as $key) {
        if (!array_key_exists($key, $region) || !qtype_omerohotspotmulti_is_finite_number($region[$key])) {
            return null;
        }
        $clean[$key] = (float) $region[$key];
    }
    $rotation = $region['rotation'] ?? 0;
    if (!qtype_omerohotspotmulti_is_finite_number($rotation)) {
        return null;
    }
    $clean['rotation'] = (float) $rotation;
    if ($clean['rx'] <= 0 || $clean['ry'] <= 0) {
        return null;
    }
    return $clean;
}

/**
 * Validates a decoded opening view ({x, y, zm}) - identical rule and
 * reasoning to qtype_omerohotspot's own
 * qtype_omerohotspot_clean_openingview() (an array-valued x/y/zm from a
 * crafted backup made moodle_url throw in renderer.php).
 *
 * @param mixed $view Decoded opening view, as json_decode(..., true) gives it.
 * @return array|null Normalised {x, y, zm} (cast to float), or null if not usable.
 */
function qtype_omerohotspotmulti_clean_openingview($view): ?array {
    if (!is_array($view)) {
        return null;
    }
    $clean = [];
    foreach (['x', 'y', 'zm'] as $key) {
        if (!array_key_exists($key, $view) || !qtype_omerohotspotmulti_is_finite_number($view[$key])) {
            return null;
        }
        $clean[$key] = (float) $view[$key];
    }
    return $clean;
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
 * Re-validates every region itself as a last line of defence, same
 * reasoning as qtype_omerohotspot_check() - an unusable region is skipped
 * (it can never be hit), the rest of the set still counts, and nothing
 * here ever throws.
 *
 * @param mixed $regions Array of {type, x, y, rx, ry, rotation} shapes, or anything else (treated as no usable regions).
 * @param float $x Image-pixel x of the student's click.
 * @param float $y Image-pixel y of the student's click.
 * @return bool
 */
function qtype_omerohotspotmulti_check($regions, float $x, float $y): bool {
    if (!is_array($regions) || !is_finite($x) || !is_finite($y)) {
        return false;
    }
    foreach ($regions as $region) {
        $region = qtype_omerohotspotmulti_clean_region($region);
        if ($region === null) {
            continue;
        }
        $local = qtype_omerohotspotmulti_unrotate($x - $region['x'], $y - $region['y'], $region['rotation']);
        if ($region['type'] === 'ellipse') {
            // Divide before squaring - same underflow reasoning as
            // qtype_omerohotspot_check()'s own comment on this line.
            $normalised = (($local[0] / $region['rx']) ** 2) + (($local[1] / $region['ry']) ** 2);
            if ($normalised <= 1) {
                return true;
            }
        } else if (abs($local[0]) <= $region['rx'] && abs($local[1]) <= $region['ry']) {
            return true;
        }
    }
    return false;
}
