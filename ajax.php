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
 * Student annotation AJAX endpoint - list/create/delete a user's own point
 * annotations on one specific embed placement (identified by $embedid, see
 * js/author.js's generateEmbed()). Loaded from inside the proxied iviewer
 * page (js/annotate.js), same-origin through proxy.php, so this re-derives
 * the real session and re-checks access every request exactly like
 * proxy.php itself does - never trusts anything the client sends beyond
 * what require_login()/require_capability() confirm.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_omeroembed\annotations_repository;

$courseid = required_param('courseid', PARAM_INT);
$embedid = required_param('embedid', PARAM_ALPHANUMEXT);
$action = required_param('action', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($courseid);
require_capability('local/omeroembed:annotate', $context);

header('Content-Type: application/json');

if ($action === 'list') {
    $annotations = annotations_repository::list_for_user($embedid, $USER->id);
    echo json_encode(array_map(function ($record) {
        return [
            'id' => (int) $record->id,
            'type' => $record->type,
            'geometry' => json_decode($record->geometry, true),
            'colour' => $record->colour,
        ];
    }, $annotations));
    exit;
}

// Everything past this point changes data - confirm the request actually
// came from a page this session generated, not a forged cross-site request.
require_sesskey();

if ($action === 'create') {
    $type = required_param('type', PARAM_ALPHA);
    $x = required_param('x', PARAM_FLOAT);
    $y = required_param('y', PARAM_FLOAT);
    $colour = required_param('colour', PARAM_RAW);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        throw new \moodle_exception('invalidcolour', 'local_omeroembed', '', $colour);
    }
    if ($type !== annotations_repository::TYPE_POINT) {
        // Only 'point' is supported so far (see this table's own install.xml
        // comment) - refuse anything else outright rather than silently
        // accepting a shape this version can't render back.
        throw new \moodle_exception('invalidannotationtype', 'local_omeroembed', '', $type);
    }

    $record = annotations_repository::create($courseid, $USER->id, $embedid, $type, ['x' => $x, 'y' => $y], $colour);
    echo json_encode([
        'id' => (int) $record->id,
        'type' => $record->type,
        'geometry' => $record->geometry,
        'colour' => $record->colour,
    ]);
    exit;
}

if ($action === 'delete') {
    $id = required_param('id', PARAM_INT);
    $deleted = annotations_repository::delete_owned($id, $USER->id);
    echo json_encode(['deleted' => $deleted]);
    exit;
}

throw new \moodle_exception('invalidaction', 'local_omeroembed', '', $action);
