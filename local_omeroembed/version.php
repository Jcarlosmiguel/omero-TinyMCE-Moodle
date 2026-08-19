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
//
// 2026081201: a pre-submission re-test run (real admin/cli/upgrade.php,
// real Moodle Code Checker, real backup/restore round-trips against a
// seeded course) caught a fatal bug in 2026081200 before it shipped:
// $plugin->supported had been "bumped" to [405, 500, 501, 502], which
// core rejects outright (supported must be a strict [min, max] pair, see
// the comment on that line below) - upgrade.php threw a coding_exception
// and would have broken plugin_manager for the whole site, not just this
// plugin. Reverted to [405, 502], which was correct - and already
// covered 5.0/5.1 - the whole time. Also fixes the resulting phpcs
// findings in the new backup/restore classes and lang file (missing
// one-line docblock summaries, a few line-length/comment-style issues,
// and the new mtrace_* string keys' own alphabetical ordering) surfaced
// by that same re-test pass.
// 2026081300 (1.5.0): first slice of issue #8 (Ajax -> External Services
// migration) - the 'list' annotations action moves to a real
// db/services.php + classes/external/get_annotations.php pair, called
// from js/annotate.js via a hand-rolled fetch() (no core/ajax AMD module
// available inside proxy.php's reverse-proxied, bootstrap-free OMERO
// page). Minor bump, not a patch: genuinely new server-side capability,
// not just a fix. The other 13 ajax.php actions are untouched - see
// classes/external/get_annotations.php's own docblock for why 'list'
// was chosen first (a real session write-lock regression risk found in
// core source, not a guess).
//
// 2026081900 (1.6.0): authoring tool (author.php) workflow overhaul.
// Real bug fix: the hotspot-mode dropdown and layout radios tried to
// live-update the preview iframe on change, silently doing nothing (or
// throwing) if clicked before a slide was loaded, since the iframe
// doesn't exist in the page until then - now server-side disabled until
// $hasslide is true, the actual fix, not a workaround. Extended to every
// other option (viewer-display checkboxes, annotations, colours, width/
// height) for a consistent "load, then configure" workflow. Real bug
// found and fixed while extending that: a disabled form control is
// excluded from submission entirely, so the first "Load slide" click was
// silently corrupting every checkbox's true state back to "off"
// regardless of the real site default - fixed with a new hidden
// options_unlocked field distinguishing "genuinely submitted" from "was
// inert, don't trust this". Viewer-display checkboxes and colours now
// collapsed behind a toggle by default (auto-expands if a value already
// differs from the site default, so re-editing an existing embed never
// hides a real customisation). Hotspot mode moved into Layout, restricted
// to the text-below layout - genuinely enforced everywhere it matters
// (the final embed, the annotations-greying logic, the live preview), not
// just visually hidden. Write-up text now required (with placeholder
// guidance) for every layout except image-only. Width/height moved from
// two hardcoded literals to a real site-wide admin default (new settings:
// local_omeroembed/defaultwidth, local_omeroembed/defaultheight) - the
// per-embed override stays. Minor, not patch: the authoring tool's
// workflow changed substantially and there's a new site-wide admin
// setting, not just a fix. local_omeroembed only - the three companion
// plugins are unchanged since 1.5.0.
$plugin->version   = 2026081900;
$plugin->requires  = 2024100100;         // Requires this Moodle version (4.5+).
$plugin->component = 'local_omeroembed'; // Full name of the plugin (used for diagnostics).
$plugin->release   = '1.6.0';
$plugin->maturity  = MATURITY_STABLE;
// Supported must be a strict [min, max] pair (core validates count()==2
// in lib/classes/plugininfo/base.php - anything else throws a
// coding_exception that breaks plugin_manager for the whole site, not just
// this plugin) - it is already an INCLUSIVE RANGE, so [405, 502] means
// "4.5 through 5.2", which already covered 5.0/5.1 all along. An earlier
// version of this comment claimed [405, 500, 501, 502] was needed to
// "include" 5.0/5.1 explicitly - that was wrong (confused this with a
// discrete version list, which this field can't express) and is fatal;
// caught via a real admin/cli/upgrade.php run. Now live-verified across
// the whole range - see MOODLE_5.2_COMPAT.md.
$plugin->supported = [405, 502];
