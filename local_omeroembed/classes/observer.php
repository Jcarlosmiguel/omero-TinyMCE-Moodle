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
 * Event observers.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Purges every row this plugin holds for a deleted course, across all
     * eight of its own tables - unlike an individual embed being abandoned
     * (see classes/task/purge_orphaned_embed_tracking.php's own docblock
     * for why that case can't be handled this cleanly), a whole course
     * being deleted is unambiguous: nothing scoped to that courseid should
     * survive it, full stop, regardless of table.
     *
     * Deliberately course_deleted only, not course_content_deleted (course
     * reset) - a reset keeps the course itself and is a distinct, narrower
     * operation this plugin doesn't attempt to hook today.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $courseid = $event->objectid;

        $tables = [
            'local_omeroembed_annotations',
            'local_omeroembed_embed_tracking',
            'local_omeroembed_view_samples',
            'local_omeroembed_heatmap_frames',
            'local_omeroembed_hotspots',
            'local_omeroembed_hotspot_attempts',
            'local_omeroembed_hotspot_multi',
            'local_omeroembed_hotspot_multi_attempts',
        ];

        $total = 0;
        foreach ($tables as $table) {
            $total += $DB->count_records($table, ['courseid' => $courseid]);
            $DB->delete_records($table, ['courseid' => $courseid]);
        }

        if ($total > 0) {
            mtrace("local_omeroembed: purged {$total} row(s) across " . count($tables) . " table(s) for deleted course {$courseid}.");
        }
    }
}
