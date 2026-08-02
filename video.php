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
 * Downloads the real, aggregate heatmap progression for one embed
 * placement as an animated GIF - the frames classes/task/capture_heatmap_frames.php
 * has been periodically capturing server-side while tracking is active,
 * stitched together by classes/gif_encoder.php. Gated identically to
 * export.php/heatmap.php - same capability, same course-scoped context.
 * Entirely server-side, so this keeps building regardless of whether
 * anyone's browser was ever open for the session (a lecturer wanting an
 * actual screen recording of a live class can just screencast it
 * themselves - this plugin only needs to offer the real, frame-by-frame
 * record of what was actually gathered).
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_omeroembed\heatmap_frame_repository;
use local_omeroembed\gif_encoder;

$courseid = required_param('courseid', PARAM_INT);
$embedid = required_param('embedid', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/omeroembed:viewheatmap', $context);

$frames = heatmap_frame_repository::get_frames_for_embed($embedid);
if (empty($frames)) {
    throw new \moodle_exception('novideoframes', 'local_omeroembed');
}

$gif = gif_encoder::assemble($frames);

header('Content-Type: image/gif');
header('Content-Disposition: attachment; filename="heatmap-video-' . $embedid . '.gif"');
header('Content-Length: ' . strlen($gif));
echo $gif;
