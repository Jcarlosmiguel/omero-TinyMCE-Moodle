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
 * Version details.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// The current plugin version (Date: YYYYMMDDXX). Bumped: adds garbage
// collection for this plugin's own data - a course_deleted event observer
// (classes/observer.php, db/events.php) that purges everything scoped to a
// deleted course, and a daily purge_orphaned_embed_tracking scheduled task
// (db/tasks.php) that sweeps for individual embeds no longer referenced
// anywhere in their course's content. No schema changes. Also carries the
// prior sourceurl fix (82c0aa3) that landed after the 1.3.1/v1.3.2
// Marketplace tag without its own version bump.
$plugin->version   = 2026081100;
$plugin->requires  = 2024100100;         // Requires this Moodle version (4.5+).
$plugin->component = 'local_omeroembed'; // Full name of the plugin (used for diagnostics).
$plugin->release   = '1.3.2';
$plugin->maturity  = MATURITY_STABLE;
$plugin->supported = [405, 502];         // One codebase tested against both 4.5 and 5.2 - see MOODLE_5.2_COMPAT.md.
