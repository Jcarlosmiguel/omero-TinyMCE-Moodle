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

// The current plugin version (Date: YYYYMMDDXX). Bumped: MMR-101 reviewer
// feedback, answer-independent package - README "External services"
// disclosure (#7), GitHub Actions CI (#2), remaining hardcoded mtrace()
// strings wrapped in get_string() (#9), all 3 raw curl_init() call sites
// converted to Moodle's \curl class (#5 - omero_session.php, proxy.php,
// heatmap_renderer.php; live-verified against the real OMERO server,
// including a real behavioural fix: \curl defaults to following redirects
// unlike raw curl_init(), which would have silently treated a
// redirect-to-login response as valid image data in heatmap_renderer.php),
// and course backup/restore support for all 8 courseid-scoped tables (#3 -
// backup/moodle2/{backup,restore}_local_omeroembed_plugin.class.php, via
// core's generic backup_local_plugin/restore_local_plugin course
// connectionpoint; live-verified with a real seeded course backup/restore
// round-trip, including a real bug found and fixed along the way - a
// site-wide UNIQUE-on-embedid collision that would abort the whole course
// restore when the original course still exists, e.g. "Duplicate this
// course" - now defensively skipped instead). No schema changes.
$plugin->version   = 2026081200;
$plugin->requires  = 2024100100;         // Requires this Moodle version (4.5+).
$plugin->component = 'local_omeroembed'; // Full name of the plugin (used for diagnostics).
$plugin->release   = '1.4.0';
$plugin->maturity  = MATURITY_STABLE;
$plugin->supported = [405, 500, 501, 502]; // One codebase, live-verified against 4.5-5.2 - see MOODLE_5.2_COMPAT.md.
