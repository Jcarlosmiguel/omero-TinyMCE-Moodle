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
 * AJAX endpoint for the student annotations feature (list/create/update/
 * delete a user's own point/ellipse/rectangle annotations), the teacher
 * heatmap feature (sample records one viewport sample, heatmap fetches the
 * gathered samples for heatmap.php's density render), and the
 * click-to-answer hotspot feature (hotspot_get/hotspot_save/hotspot_clear
 * for a teacher authoring the hidden answer region, hotspot_attempt for a
 * student's click). Configuring tracking itself - on/off, gather-hours,
 * delete, export - is plain server-rendered PHP on heatmap.php now, not
 * this endpoint (moved off author.php's old JS-driven tracking panel -
 * confirmed with the user). Loaded from inside the proxied iviewer page
 * (js/annotate.js, js/track.js, js/heatmap-view.js, js/hotspot-author.js,
 * js/hotspot-attempt.js), same-origin through proxy.php, so this
 * re-derives the real session and re-checks access every request exactly
 * like proxy.php itself does - never trusts anything the client sends
 * beyond what require_login()/require_capability() confirm.
 *
 * The three feature groups need different capabilities
 * (local/omeroembed:annotate vs local/omeroembed:viewheatmap vs
 * local/omeroembed:hotspotauthor - see db/access.php for why they're
 * deliberately not the same), so unlike a single upfront
 * require_capability() call, the check here is chosen per action group.
 * The one exception is action=sample: any enrolled viewer can generate a
 * sample (that's the whole point), not just capability holders - see its
 * own branch below for the actual gate that applies instead.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_omeroembed\annotations_repository;
use local_omeroembed\tracking_repository;
use local_omeroembed\hotspot_repository;
use local_omeroembed\hotspot_multi_repository;

$courseid = required_param('courseid', PARAM_INT);
$embedid = required_param('embedid', PARAM_ALPHANUMEXT);
// PARAM_ALPHANUMEXT, not PARAM_ALPHA, for the action name too - not
// strictly needed by any of the current action names, but a former one
// (tracking_set) contained an underscore and PARAM_ALPHA silently mangled
// it to "trackingset" before it ever reached the checks below (a real bug,
// only caught by hitting the endpoint over HTTP, not by exercising the
// underlying PHP classes directly) - kept this way so an underscored
// action name never quietly breaks the same way again.
$action = required_param('action', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);

// hotspot_attempt sits alongside list/create/update/delete on purpose -
// "any enrolled viewer may do this" is exactly what local/omeroembed:annotate
// already means in this plugin, and attempting a hotspot question is a
// normal student action, not a privileged one (defining the *answer* is -
// see $hotspotauthoractions below, a genuinely different capability).
// hotspotmulti_attempt sits alongside hotspot_attempt for the identical
// reason - attempting a multi-region hotspot question is the same normal
// student action, not a privileged one.
$annotateactions = ['list', 'create', 'update', 'delete', 'hotspot_attempt', 'hotspotmulti_attempt'];
// tracking_get/tracking_set used to live here too, when author.php had its
// own JS-driven tracking panel - that panel has since moved entirely onto
// heatmap.php as a plain server-rendered form (confirmed with the user),
// which calls tracking_repository directly rather than round-tripping
// through this endpoint, so those two actions were removed rather than
// left unused.
$heatmapactions = ['heatmap'];
// The one place in this plugin that reads/writes the actual secret a
// student is being asked to find - deliberately its own capability, not
// :annotate (students hold that) or :viewheatmap (a different privileged
// action entirely). hotspot_get is a GET but still gated here, not treated
// like list/heatmap's "any enrolled viewer" shape - unlike those, this
// response contains the hidden geometry itself, so it needs the same
// authoring-only gate as the two writes.
// hotspotmulti_get/save/clear are the multi-region sibling of the three
// above - same authoring-only gate, same reasoning, just operating on a
// set of regions instead of one shape (see classes/hotspot_multi_repository.php).
$hotspotauthoractions = ['hotspot_get', 'hotspot_save', 'hotspot_clear',
        'hotspotmulti_get', 'hotspotmulti_save', 'hotspotmulti_clear'];

if (in_array($action, $annotateactions, true)) {
    require_capability('local/omeroembed:annotate', $context);
} else if (in_array($action, $heatmapactions, true)) {
    require_capability('local/omeroembed:viewheatmap', $context);
} else if (in_array($action, $hotspotauthoractions, true)) {
    require_capability('local/omeroembed:hotspotauthor', $context);
} else if ($action !== 'sample') {
    throw new \moodle_exception('invalidaction', 'local_omeroembed', '', $action);
}

header('Content-Type: application/json');
// action=heatmap/list are GETs to an otherwise-identical URL every time
// they're polled (heatmap-view.js re-fetches action=heatmap on a timer -
// see js/heatmap-view.js's REFRESH_INTERVAL_MS) - without this, a browser
// can serve a cached response instead of hitting this endpoint again,
// silently freezing the heatmap on whatever it first fetched. Confirmed
// live: this was a real bug, not a hypothetical one.
header('Cache-Control: no-store, must-revalidate');

if ($action === 'list') {
    $annotations = annotations_repository::list_for_user($embedid, $USER->id);
    echo json_encode(array_map(function ($record) {
        return [
            'id' => (int) $record->id,
            'type' => $record->type,
            'geometry' => json_decode($record->geometry, true),
            'colour' => $record->colour,
            'label' => $record->label,
        ];
    }, $annotations));
    exit;
}

if ($action === 'heatmap') {
    $samples = tracking_repository::get_samples($embedid);
    echo json_encode(array_map(function ($record) {
        return [
            'x' => (float) $record->x,
            'y' => (float) $record->y,
            'zoompercent' => (float) $record->zoompercent,
        ];
    }, $samples));
    exit;
}

if ($action === 'hotspot_get') {
    // Authoring-only (see $hotspotauthoractions above) - the one response
    // in this whole endpoint that's allowed to contain the hidden
    // geometry, so the authoring UI can show a teacher their own existing
    // region as a reference outline when reopening it.
    $geometry = hotspot_repository::get_geometry($embedid);
    echo json_encode(['geometry' => $geometry]);
    exit;
}

if ($action === 'hotspotmulti_get') {
    // Multi-region sibling of hotspot_get above - same authoring-only
    // reasoning, returns the whole array of regions instead of one shape.
    $geometry = hotspot_multi_repository::get_geometry($embedid);
    echo json_encode(['geometry' => $geometry]);
    exit;
}

// Everything past this point changes data - confirm the request actually
// came from a page this session generated, not a forged cross-site request.
require_sesskey();

if ($action === 'create') {
    $type = required_param('type', PARAM_ALPHA);
    $colour = required_param('colour', PARAM_RAW);
    $label = optional_param('label', '', PARAM_TEXT);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        throw new \moodle_exception('invalidcolour', 'local_omeroembed', '', $colour);
    }

    if ($type === annotations_repository::TYPE_POINT) {
        $x = required_param('x', PARAM_FLOAT);
        $y = required_param('y', PARAM_FLOAT);
        $geometry = ['x' => $x, 'y' => $y];
    } else if ($type === annotations_repository::TYPE_ELLIPSE || $type === annotations_repository::TYPE_RECTANGLE) {
        // Same geometry shape for both - see TYPE_RECTANGLE's own comment.
        $x = required_param('x', PARAM_FLOAT);
        $y = required_param('y', PARAM_FLOAT);
        $rx = required_param('rx', PARAM_FLOAT);
        $ry = required_param('ry', PARAM_FLOAT);
        if ($rx <= 0 || $ry <= 0) {
            throw new \moodle_exception('invalidradius', 'local_omeroembed', '', "rx=$rx, ry=$ry");
        }
        $geometry = ['x' => $x, 'y' => $y, 'rx' => $rx, 'ry' => $ry, 'rotation' => 0];
    } else if ($type === annotations_repository::TYPE_POLYGON) {
        // Nested arrays don't fit the flat x/y/rx/ry param convention the
        // other two shapes use - sent as a JSON-encoded string instead,
        // decoded and validated here rather than trusted (a malformed or
        // hand-crafted request should fail loudly, not get stored as a
        // shape js/annotate.js can't render back).
        $pointsraw = required_param('points', PARAM_RAW);
        $points = json_decode($pointsraw, true);
        if (!is_array($points) || count($points) < 3) {
            throw new \moodle_exception('invalidpolygon', 'local_omeroembed', '', $pointsraw);
        }
        foreach ($points as $point) {
            if (!is_array($point) || count($point) !== 2 || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
                throw new \moodle_exception('invalidpolygon', 'local_omeroembed', '', $pointsraw);
            }
        }
        $geometry = ['points' => array_map(function ($point) {
            return [(float) $point[0], (float) $point[1]];
        }, array_values($points))];
    } else {
        // Refuse anything else outright rather than silently accepting a
        // shape this version can't render back.
        throw new \moodle_exception('invalidannotationtype', 'local_omeroembed', '', $type);
    }

    $record = annotations_repository::create($courseid, $USER->id, $embedid, $type, $geometry, $colour, $label);
    echo json_encode([
        'id' => (int) $record->id,
        'type' => $record->type,
        'geometry' => $record->geometry,
        'colour' => $record->colour,
        'label' => $record->label,
    ]);
    exit;
}

if ($action === 'update') {
    // Only rotation can be changed so far - the rotate handle is the only
    // thing that edits an existing annotation (see js/annotate.js).
    $id = required_param('id', PARAM_INT);
    $rotation = required_param('rotation', PARAM_FLOAT);
    $record = annotations_repository::update_rotation($id, $USER->id, $rotation);
    if (!$record) {
        echo json_encode(['updated' => false]);
        exit;
    }
    echo json_encode([
        'updated' => true,
        'id' => (int) $record->id,
        'geometry' => $record->geometry,
    ]);
    exit;
}

if ($action === 'delete') {
    $id = required_param('id', PARAM_INT);
    $deleted = annotations_repository::delete_owned($id, $USER->id);
    echo json_encode(['deleted' => $deleted]);
    exit;
}

if ($action === 'hotspot_save') {
    // Same ellipse/rectangle geometry validation as 'create' above (see
    // that branch's own comment) - no colour/label needed, this shape is
    // never rendered to anyone, only tested against.
    $type = required_param('type', PARAM_ALPHA);
    if ($type !== annotations_repository::TYPE_ELLIPSE && $type !== annotations_repository::TYPE_RECTANGLE) {
        throw new \moodle_exception('invalidannotationtype', 'local_omeroembed', '', $type);
    }
    $x = required_param('x', PARAM_FLOAT);
    $y = required_param('y', PARAM_FLOAT);
    $rx = required_param('rx', PARAM_FLOAT);
    $ry = required_param('ry', PARAM_FLOAT);
    if ($rx <= 0 || $ry <= 0) {
        throw new \moodle_exception('invalidradius', 'local_omeroembed', '', "rx=$rx, ry=$ry");
    }
    $geometry = ['type' => $type, 'x' => $x, 'y' => $y, 'rx' => $rx, 'ry' => $ry, 'rotation' => 0];

    $record = hotspot_repository::save($courseid, $embedid, $USER->id, $geometry);
    echo json_encode(['geometry' => $record->geometry]);
    exit;
}

if ($action === 'hotspotmulti_save') {
    // Multi-region sibling of hotspot_save above - the whole current set
    // of regions is sent as one JSON-encoded 'regions' param (per-field
    // params like hotspot_save's don't scale to N shapes) and replaces the
    // stored set outright, same "bulk overwrite" convention. Every element
    // is validated inside hotspot_multi_repository::save() itself, using
    // the same annotations_repository::TYPE_ELLIPSE/TYPE_RECTANGLE
    // constants and invalidregion exception for a malformed element.
    $regionsraw = required_param('regions', PARAM_RAW);
    $regions = json_decode($regionsraw, true);
    if (!is_array($regions)) {
        throw new \moodle_exception('invalidregion', 'local_omeroembed');
    }

    $record = hotspot_multi_repository::save($courseid, $embedid, $USER->id, $regions);
    echo json_encode(['geometry' => $record->geometry]);
    exit;
}

if ($action === 'hotspot_clear') {
    $cleared = hotspot_repository::clear($embedid);
    echo json_encode(['cleared' => $cleared]);
    exit;
}

if ($action === 'hotspotmulti_clear') {
    $cleared = hotspot_multi_repository::clear($embedid);
    echo json_encode(['cleared' => $cleared]);
    exit;
}

if ($action === 'hotspot_attempt') {
    $x = required_param('x', PARAM_FLOAT);
    $y = required_param('y', PARAM_FLOAT);

    // The only thing this response - or any code path reachable from a
    // student session - is ever allowed to reveal about the hidden region.
    $correct = hotspot_repository::check_attempt($embedid, $x, $y);
    hotspot_repository::record_attempt($courseid, $embedid, $USER->id, $x, $y, $correct);
    echo json_encode(['correct' => $correct]);
    exit;
}

if ($action === 'hotspotmulti_attempt') {
    // Multi-region sibling of hotspot_attempt above - correct if the click
    // lands inside ANY one of the stored regions (see
    // hotspot_multi_repository::check_attempt()'s own docblock for why).
    $x = required_param('x', PARAM_FLOAT);
    $y = required_param('y', PARAM_FLOAT);

    $correct = hotspot_multi_repository::check_attempt($embedid, $x, $y);
    hotspot_multi_repository::record_attempt($courseid, $embedid, $USER->id, $x, $y, $correct);
    echo json_encode(['correct' => $correct]);
    exit;
}

if ($action === 'sample') {
    $x = required_param('x', PARAM_FLOAT);
    $y = required_param('y', PARAM_FLOAT);
    $zoompercent = required_param('zoompercent', PARAM_FLOAT);

    // Teachers/managers casually viewing the real embed shouldn't pollute
    // the heatmap they're the ones reading (confirmed with the user) - this
    // is the actual enforcement point, proxy.php's own equivalent check
    // (in inject_tracking_script()) only controls whether js/track.js gets
    // loaded in the first place, and can't be trusted as the only gate.
    if (has_capability('moodle/course:manageactivities', $context)) {
        echo json_encode(['recorded' => false]);
        exit;
    }

    $recorded = tracking_repository::record_sample($courseid, $embedid, $USER->id, $x, $y, $zoompercent);
    echo json_encode(['recorded' => $recorded]);
    exit;
}

throw new \moodle_exception('invalidaction', 'local_omeroembed', '', $action);
