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
//
// 2026092300 (1.7.0): real bugs found via a thorough independent test
// pass (Ferenc Lengyel, University of Glasgow IT Services, ticket VLE-265)
// against 1.6.0 - not this session's own testing catching them first, an
// external reviewer's did:
// - Re-opening an existing embed via the TinyMCE button silently reset
// every viewer-display checkbox and the annotation-colour selection back
// to the site default, discarding whatever was actually saved. Two
// distinct root causes, both fixed: (1) $optionswereunlocked (the flag
// added in 1.6.0 to stop a disabled-checkbox POST losing state) was
// never true on a re-edit's GET request, since tiny_omeroembed's ui.js
// never set it - fixed there, forwarding options_unlocked=1 for a
// genuine re-edit's already-known-good values. (2) annotation colours
// were never read back at all on re-edit - ui.js forwards them as one
// combined annotationcolours param, but author.php only ever read the
// individual colour_<hex> checkboxes a real form POST sends - fixed
// with a dedicated read for the combined format.
// - The TinyMCE modal's Cancel button did nothing at all when clicked.
// Root cause confirmed against real Moodle core source: the button
// markup used data-action="cancel", which only core/modal_save_cancel
// wires up - this modal extends the plain core/modal base class, which
// never registers that action. Fixed to data-action="hide", which the
// base class does wire up (tiny_omeroembed, see its own version.php).
// - capture_heatmap_frames failed with "Class curl not found" when run
// standalone via admin/cli/scheduled_task.php (not via normal site
// cron) - heatmap_renderer.php used Moodle's \curl class without ever
// explicitly loading the library that defines it, working under normal
// cron only by load-order luck. Fixed with an explicit require_once.
// - manage.php/mysubjects.php/author.php/heatmap.php all called
// \core\session\manager::write_close() early, carried over from
// proxy.php's own real concurrent-tile-request rationale without that
// rationale actually applying to any of the four - real DEVELOPER-debug
// warnings resulted ("mutated the session after it was closed") because
// Moodle's own header()/footer() genuinely do write to session-backed
// navigation caches while rendering. Removed from all four; on reflection
// author.php's own live-preview-iframe-concurrency justification for
// this doesn't hold up either, since that iframe only starts loading
// after this script has already finished executing in the normal case.
// - The site-level enablehotspot/enablehotspotmulti/hotspotheading
// descriptions read like they described per-embed authoring behaviour
// ("for this embed", "in the authoring tool"), when they're actually
// only the default offered to a newly-created embed - reworded to say
// so explicitly, matching how they actually behave.
// - Moodle 5.x (Bootstrap 5): the Load-slide row's submit button (and
// every other child of that row) rendered stretched to full width -
// real cause: Bootstrap 5's `.row > *` rule applies width:100% to every
// direct child unconditionally, unlike Bootstrap 4, and this row used
// Bootstrap's .form-group/.row grid classes with no .col-* children to
// opt out. Fixed by switching to the same hand-rolled flexbox every
// other row in this file already uses - no Bootstrap grid dependency,
// no version-specific behaviour.
// - heatmap-view.js's live sample-count legend was fixed to the canvas's
// top-left corner regardless of length, which could sit on top of
// OMERO.iviewer's own zoom controls (OpenLayers' own default position)
// once the count grew wide enough - moved to bottom-right, the one corner
// this heatmap-only view never puts anything else in (see 2026092301
// below for why bottom-left didn't turn out to be clear either).
// - "Manage your OMERO connections" opened inside the TinyMCE modal's own
// iframe, replacing the authoring form and discarding whatever was
// already picked/typed there - now opens in a new tab instead.
// - heatmap.php had no way back to the authoring tool at all - added,
// reusing mysubjects.php's own existing 'backtoauthoring' string/link
// pattern.
// Also tested (real, not just researched) against Moodle 5.3's own `main`
// development branch (self-labelled "5.3beta", build 2026091600 - no
// MOODLE_503_STABLE branch exists publicly yet, so this is the earliest
// real source available, not a formal release candidate): fresh install
// and a full DEVELOPER-debug sweep across every plugin-facing page, both
// clean. One real finding worth flagging, not yet reflected in $plugin->
// supported below since 5.3 isn't released: its own environment.xml bumps
// the minimum MariaDB requirement to 11.4.0 (5.2 only required 10.11.0).
// $plugin->supported deliberately left at [405, 502] - 5.3 isn't out yet.
//
// 2026092301 (still 1.7.0 - not yet released, so this is the same version
// gaining more content, not a new one): a second pass through Ferenc's
// full VLE-265 document, navigating by section heading rather than trying
// to trust a first read-through alone - found real things the first pass
// missed, including two bugs personally reproduced live rather than only
// read about:
// - Real modal-nesting bug: the TinyMCE toolbar button's onAction had no
// guard against being invoked twice while a modal was still opening -
// TinyMCE never disables a button while its action is pending, so two
// rapid clicks created two independent, fully-separate modal instances
// stacked on top of each other. Fixed with an open-state flag in ui.js,
// cleared via the existing ModalEvents.hidden listener (and a real
// robustness gap closed alongside it: Modal.create() failing would
// otherwise have left the flag stuck forever).
// - The heatmap-view.js legend fix above (bottom-left) turned out to just
// trade one overlap for another - OMERO's own scale bar also defaults to
// bottom-left, and nothing hides it for heatmap-only mode. Re-fixed to
// bottom-right, confirmed genuinely clear there via proxy.php's own
// $heatmap dispatch branch, which never injects the annotation/hotspot
// toolbar in that mode.
// - A second, separate overlap: the "recorded for teaching analytics"
// tracking notice (track.js) and OMERO's own scale bar defaulted to
// almost the same corner (bottom:0.5rem/8px;left:0.5rem/8px) - designed
// to collide, not a coincidence. Moved to bottom-centre, the same
// solution proxy.php's own "View heatmap" pill already used for the same
// underlying problem, offset above that pill rather than exactly on top
// of it since a teacher can genuinely see both at once. Folded in the
// same pass: bumped its font size/background opacity/padding, a second,
// separately-reported "visibility/prominence could be improved" point
// about the same element.
// - Real, substantial settings.php cleanup: removed the second
// "OMERO slide embed settings" Site Administration Tree entry entirely
// (manage.php, its admin_externalpage registration, and the
// local/omeroembed:managesettings capability) along with every setting
// that was really just a site-wide *default* for something a teacher
// already chooses per-embed directly (all 7 viewer-display checkboxes,
// default width/height, annotations, annotation colours, hotspot) -
// direct feedback that the two-entries split and the hotspot wording in
// particular were genuinely confusing, and a considered decision that
// simplifying was better than re-explaining. Only omerobaseurl and
// retentionperiod remain as real site-wide/infrastructure settings.
// author.php's own defaults for the removed settings are now fixed
// literals matching exactly what each setting used to default to, not
// silently different behaviour - see $overlaydefaults's own comment.
// - New: a real documentation link on settings.php (Moodle's own
// format_text()-rendered admin_setting_heading, not dependent on
// whatever the Marketplace listing's plain-text description field can
// render) pointing at ADMIN.md/USAGE.md on GitHub.
// - New: help icons on the authoring form (subject account, layout,
// hotspot mode, and a combined one covering "Insert view link" vs.
// "Set as opening view" together, since the *distinction* between them
// was the actual reported confusion) - Moodle's own standard
// $OUTPUT->help_icon() mechanism.
// - New: guide.php, a native in-Moodle visual walkthrough for teachers
// (linked from author.php), built to survive an institution's network
// blocking GitHub and read as part of the product rather than a
// bounce-out - ships with placeholder image boxes pending real
// screenshots, deliberately visible rather than silently omitted.
// - New: a real subject/image identifier on heatmap.php (reusing
// heatmap_renderer::parse_sourceurl(), made public for this rather than
// writing a second parser) - real gap with 2-8 embeds typical per class,
// where the course name alone didn't say which embed's heatmap a teacher
// was looking at.
// - The "View heatmap" link/pill already opened in a new window
// (target="_blank"), but literally "_blank" opens a fresh window on
// every click - changed to a named target so repeat clicks reuse the
// same window, matching the real workflow this exists for (a lectern
// PC's second screen, not a pile of new windows).
// ADMIN.md/README.md/USAGE.md updated to match throughout - kept
// deliberately in sync with the in-product pages, not just the native
// Moodle settings/help screens.
$plugin->version   = 2026092301;
$plugin->requires  = 2024100100;         // Requires this Moodle version (4.5+).
$plugin->component = 'local_omeroembed'; // Full name of the plugin (used for diagnostics).
$plugin->release   = '1.7.0';
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
