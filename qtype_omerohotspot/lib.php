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
 * Whether a value is a real, finite number - accepts a PHP int/float or a
 * numeric string (a stored qt var or a decoded-JSON value can be either).
 * Both halves are needed: is_numeric('1e999') is true, but (float) '1e999'
 * overflows to INF - exactly how a hand-edited hidden clickx field once
 * got "INF" stored as a student's response, which later crashed round()
 * with a TypeError in summarise_response() (and, via quiz's own
 * update_overdue_attempts cron task, which only catches moodle_exception,
 * blocked every other student's overdue auto-submission too).
 *
 * @param mixed $value
 * @return bool
 */
function qtype_omerohotspot_is_finite_number($value): bool {
    return is_numeric($value) && is_finite((float) $value);
}

/**
 * The one shared rule for what counts as a usable correct-answer region -
 * called from the edit form's validation(), save_question_options(), the
 * restore class, and qtype_omerohotspot_check() itself, so a degenerate or
 * tampered-with shape can't get in through any one door the others close.
 * A zero rx/ry (reachable just by dragging a perfectly horizontal/vertical
 * line in the drawing tool) used to reach the ellipse formula's division
 * unchecked and throw DivisionByZeroError for every student attempting
 * the question.
 *
 * 'rotation' is the one optional key - older saved/backed-up geometry can
 * lack it, and the hit-test has always treated a missing one as 0 - but
 * if present it must still be a finite number.
 *
 * @param mixed $geometry Decoded geometry, as json_decode(..., true) gives it.
 * @return array|null Normalised {type, x, y, rx, ry, rotation} (all numbers cast to float), or null if not usable.
 */
function qtype_omerohotspot_clean_geometry($geometry): ?array {
    if (!is_array($geometry) || !in_array($geometry['type'] ?? null, ['ellipse', 'rectangle'], true)) {
        return null;
    }
    $clean = ['type' => $geometry['type']];
    foreach (['x', 'y', 'rx', 'ry'] as $key) {
        if (!array_key_exists($key, $geometry) || !qtype_omerohotspot_is_finite_number($geometry[$key])) {
            return null;
        }
        $clean[$key] = (float) $geometry[$key];
    }
    $rotation = $geometry['rotation'] ?? 0;
    if (!qtype_omerohotspot_is_finite_number($rotation)) {
        return null;
    }
    $clean['rotation'] = (float) $rotation;
    if ($clean['rx'] <= 0 || $clean['ry'] <= 0) {
        return null;
    }
    return $clean;
}

/**
 * Validates a decoded opening view ({x, y, zm}) - all three required, each
 * a scalar finite number. isset() alone (the old check) let through e.g.
 * {"x":[1],"y":1,"zm":1} from a crafted backup, which renderer.php then
 * handed to moodle_url, throwing "Url parameters values can not be
 * arrays!" and breaking the question's display for everyone.
 *
 * @param mixed $view Decoded opening view, as json_decode(..., true) gives it.
 * @return array|null Normalised {x, y, zm} (cast to float), or null if not usable.
 */
function qtype_omerohotspot_clean_openingview($view): ?array {
    if (!is_array($view)) {
        return null;
    }
    $clean = [];
    foreach (['x', 'y', 'zm'] as $key) {
        if (!array_key_exists($key, $view) || !qtype_omerohotspot_is_finite_number($view[$key])) {
            return null;
        }
        $clean[$key] = (float) $view[$key];
    }
    return $clean;
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
 * Re-validates $geometry itself as a last line of defence (a row saved or
 * restored before the shared validation above existed can still be
 * degenerate) - an unusable region is simply "not a hit", never a thrown
 * error, since a throw here takes down attempt review, finishing, and the
 * quiz overdue-attempts cron task with it.
 *
 * @param mixed $geometry {type, x, y, rx, ry, rotation}, or anything else (treated as no usable region).
 * @param float $x Image-pixel x of the student's click.
 * @param float $y Image-pixel y of the student's click.
 * @return bool
 */
function qtype_omerohotspot_check($geometry, float $x, float $y): bool {
    $geometry = qtype_omerohotspot_clean_geometry($geometry);
    if ($geometry === null || !is_finite($x) || !is_finite($y)) {
        return false;
    }
    $local = qtype_omerohotspot_unrotate($x - $geometry['x'], $y - $geometry['y'], $geometry['rotation']);
    if ($geometry['type'] === 'ellipse') {
        // Divide before squaring (mathematically identical to
        // x^2/rx^2 + y^2/ry^2) - a tiny-but-positive rx such as 1e-200
        // still passes the rx > 0 rule, but rx ** 2 underflows to 0.0 and
        // would bring the DivisionByZeroError straight back.
        $normalised = (($local[0] / $geometry['rx']) ** 2) + (($local[1] / $geometry['ry']) ** 2);
        return $normalised <= 1;
    }
    return abs($local[0]) <= $geometry['rx'] && abs($local[1]) <= $geometry['ry'];
}
