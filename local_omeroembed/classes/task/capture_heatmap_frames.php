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

namespace local_omeroembed\task;

use local_omeroembed\tracking_repository;
use local_omeroembed\heatmap_frame_repository;
use local_omeroembed\heatmap_renderer;

/**
 * Periodically captures one server-side heatmap frame for every currently-
 * active tracking session, site-wide - the frames video.php later stitches
 * into a downloadable animated GIF. Entirely independent of any browser
 * being open, since this is what lets the real, unattended, multi-student
 * session be turned into a video at all - see
 * classes/heatmap_renderer.php's own docblock for why that's
 * feasible without ffmpeg/a headless browser.
 *
 * Registered in db/tasks.php with a default 5-minute schedule - frequent
 * enough that even a short in-class activity gets several frames, but
 * without generating an excessive number of frames (and therefore an
 * excessively long/slow-to-download GIF) for a long-running session.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class capture_heatmap_frames extends \core\task\scheduled_task {
    /**
     * The task's name, shown in the admin scheduled tasks list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_captureheatmapframes', 'local_omeroembed');
    }

    /**
     * Captures one heatmap frame for every embed currently being tracked.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $active = $DB->get_records_select(
            'local_omeroembed_embed_tracking',
            'enabled = 1 AND trackinguntil > :now',
            ['now' => time()]
        );

        if (!$active) {
            mtrace('No active tracking sessions - nothing to capture.');
            return;
        }

        $captured = 0;
        $skipped = 0;
        foreach ($active as $tracking) {
            $base = heatmap_renderer::fetch_base_image_for_embed($tracking->embedid);
            if (!$base) {
                // Dataset-based/browsable embed with no single fixed
                // image, or OMERO temporarily unreachable - skip this one
                // embed rather than failing the whole task run.
                $skipped++;
                continue;
            }

            $samples = tracking_repository::get_samples($tracking->embedid);
            $frame = heatmap_renderer::render_frame($base['jpeg'], $samples, $base['nativewidth'], $base['nativeheight']);
            if ($frame === null) {
                $skipped++;
                continue;
            }

            heatmap_frame_repository::store_frame($tracking->courseid, $tracking->embedid, $frame);
            $captured++;
        }

        mtrace("Captured {$captured} heatmap frame(s), skipped {$skipped}, across " . count($active) . ' active session(s).');
    }
}
