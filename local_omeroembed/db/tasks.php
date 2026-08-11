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
 * Scheduled task definitions.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_omeroembed\task\purge_view_samples',
        // Once a day is plenty for a retention window measured in days -
        // admin-editable afterwards via Site administration > Server >
        // Scheduled tasks, same as any other task's default schedule.
        'minute' => '0',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => 'local_omeroembed\task\capture_heatmap_frames',
        // Every 5 minutes - frequent enough that a short in-class activity
        // still gets several frames, without piling up an excessive number
        // for a long-running session. '*/5' is cron's own "every 5" syntax,
        // same as core's own scheduled tasks use for sub-hourly schedules.
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
    [
        'classname' => 'local_omeroembed\task\purge_orphaned_embed_tracking',
        // Daily, offset 30 minutes after purge_view_samples so the two
        // don't contend for the same tables at the same moment - not that
        // either is slow, just avoids the coincidence.
        'minute' => '30',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
        'disabled' => 0,
    ],
];
