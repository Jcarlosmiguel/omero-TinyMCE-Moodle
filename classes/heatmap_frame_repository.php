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
 * Plain $DB wrapper for local_omeroembed_heatmap_frames - the periodic
 * server-side snapshots classes/task/capture_heatmap_frames.php produces
 * and video.php assembles into a downloadable animated GIF. Kept separate
 * from tracking_repository since these are a genuinely different table
 * with a different lifecycle (produced by a task, not by any direct
 * teacher/student action).
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class heatmap_frame_repository {
    /**
     * Stores one rendered frame (raw GIF bytes, as returned by
     * heatmap_renderer::render_frame()) - base64-encoded before storage,
     * see db/install.xml's own comment on why this table is a plain
     * LONGTEXT column rather than the File API.
     *
     * @param int $courseid
     * @param string $embedid
     * @param string $rawgifbytes
     * @return void
     */
    public static function store_frame(int $courseid, string $embedid, string $rawgifbytes): void {
        global $DB;

        $DB->insert_record('local_omeroembed_heatmap_frames', (object) [
            'courseid' => $courseid,
            'embedid' => $embedid,
            'framedata' => base64_encode($rawgifbytes),
            'timecreated' => time(),
        ]);
    }

    /**
     * All frames captured for one embed placement, oldest first (the order
     * video.php's animated GIF should play them back in) - raw GIF bytes,
     * already base64-decoded for the caller's convenience.
     *
     * @param string $embedid
     * @return string[]
     */
    public static function get_frames_for_embed(string $embedid): array {
        global $DB;

        $records = $DB->get_records('local_omeroembed_heatmap_frames', ['embedid' => $embedid], 'timecreated ASC');
        return array_map(function ($record) {
            return base64_decode($record->framedata);
        }, array_values($records));
    }

    /**
     * How many frames exist for one embed placement - used by heatmap.php
     * to show a teacher whether there's anything worth downloading yet,
     * without needing to base64-decode every frame just to count them.
     *
     * @param string $embedid
     * @return int
     */
    public static function count_frames_for_embed(string $embedid): int {
        global $DB;

        return $DB->count_records('local_omeroembed_heatmap_frames', ['embedid' => $embedid]);
    }

    /**
     * Site-wide retention purge - deletes any frame older than $cutoff,
     * regardless of embedid. Called by
     * \local_omeroembed\task\purge_view_samples alongside its existing
     * view_samples purge, using the exact same retentionperiod-derived
     * cutoff (confirmed with the user: frames follow the same setting,
     * not a separate one).
     *
     * @param int $cutoff Unix timestamp - rows older than this are deleted.
     * @return int The number of rows deleted.
     */
    public static function purge_frames_older_than(int $cutoff): int {
        global $DB;

        $count = $DB->count_records_select('local_omeroembed_heatmap_frames', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        $DB->delete_records_select('local_omeroembed_heatmap_frames', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        return $count;
    }
}
