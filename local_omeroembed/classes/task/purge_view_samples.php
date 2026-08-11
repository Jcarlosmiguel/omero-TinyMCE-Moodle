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

/**
 * Site-wide backstop for the heatmap feature's storage growth: deletes any
 * gathered viewport sample *and* any captured video frame (see
 * heatmap_frame_repository::purge_frames_older_than() - frames follow the
 * exact same retention setting rather than a separate one, confirmed with
 * the user) older than the configured retention period (Site
 * administration > Plugins > Local plugins > OMERO slide embed > "Data
 * retention"), regardless of whether a teacher ever manually deletes
 * anything via heatmap.php's own delete button. Registered in db/tasks.php
 * with a default daily schedule, editable afterwards like any other
 * scheduled task via Site administration > Server > Scheduled tasks.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_view_samples extends \core\task\scheduled_task {
    /**
     * The task's name, shown in the admin scheduled tasks list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purgeviewsamples', 'local_omeroembed');
    }

    /**
     * Deletes heatmap view samples older than the configured retention period.
     *
     * @return void
     */
    public function execute(): void {
        $retentionseconds = (int) get_config('local_omeroembed', 'retentionperiod');
        if ($retentionseconds <= 0) {
            // Belt-and-braces - a misconfigured/blank setting should never
            // be read as "retain forever", but also shouldn't purge
            // everything (cutoff of "now") either. Fall back to the
            // admin_setting_configduration's own default rather than guess.
            $retentionseconds = 30 * DAYSECS;
        }

        $cutoff = time() - $retentionseconds;

        $deletedsamples = tracking_repository::purge_older_than($cutoff);
        mtrace(get_string('mtrace_purgedviewsamples', 'local_omeroembed', (object) [
            'count' => $deletedsamples,
            'date' => userdate($cutoff),
        ]));

        $deletedframes = heatmap_frame_repository::purge_frames_older_than($cutoff);
        mtrace(get_string('mtrace_purgedheatmapframes', 'local_omeroembed', (object) [
            'count' => $deletedframes,
            'date' => userdate($cutoff),
        ]));
    }
}
