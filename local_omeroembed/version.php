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
//
// 2026092600 (still 1.7.0): a real regression from the settings.php
// cleanup above (2026092301), only now surfacing because the two qtype
// hotspot plugins' own edit-form preview was the first caller that never
// bakes every overlay param explicitly into its proxy.php URL (author.php
// always does - see $overlaydefaults's own comment for why). proxy.php's
// resolve_overlay_setting() still fell back to get_config('local_omeroembed',
// $key) for an absent param - a real site setting once, now nothing at
// all since settings.php no longer registers it, so it silently always
// returned false. hideoverview/hideintensity/hidenavbar's correct default
// is true, so any caller relying on that fallback got every one of those
// controls ON instead: reported live as OMERO's own File/ROIs/Help navbar
// and overview thumbnail cluttering the qtype edit form's preview with no
// way to turn them off. Fixed by moving the real default values (matching
// author.php's own $overlaydefaults exactly) into resolve_overlay_setting()
// itself - the get_config() fallback was never valid for a caller that
// doesn't set every param, now that there's no site setting to fall back
// to at all. Also: inject_hide_roi_panel_css() (OMERO's own right-hand
// ROI/rendering-settings panel, previously left open on every authoring
// preview - see that function's own docblock for the original reasoning)
// is now also applied to the two qtype hotspot plugins' own edit-form
// preview specifically, direct feedback that the panel was just confusing
// clutter there with no legitimate use (a hotspot question's region is
// entirely separate, plugin-managed geometry, unrelated to OMERO's native
// ROIs) - local_omeroembed's own general authoring preview is unchanged,
// where a teacher's real OMERO work on the slide is a genuine reason to
// want it open.
//
// 2026092601 (still 1.7.0): new event observer (classes/observer.php,
// db/events.php) keeping both hotspot qtypes off a shared quiz page -
// direct feedback, and live-confirmed: adding questions to a quiz via
// "Select multiple items" landed all of them on the same page by default,
// including a hotspot question sharing a page with two unrelated ones,
// which reads as genuinely confusing (each hotspot qtype loads a full
// OMERO slide viewer with its own click/drawing surface, not something
// that reads well crammed next to another question). Observes
// slot_created/slot_deleted/slot_moved/quiz_repaginated (mod_quiz's own
// events) and re-enforces "no other slot shares this hotspot slot's page"
// after each, reusing mod_quiz\structure's own page-break machinery
// rather than writing to quiz_slots directly. No schema changes.
//
// 2026092602 (still 1.7.0): "Enable student annotations" now defaults to
// on for a new embed (author.php's $overlaydefaults, and the matching
// literal in proxy.php's resolve_overlay_setting()) - direct feedback,
// the one deliberate exception in that list (every other value there is
// a preserved historical default, see that list's own comment). A
// teacher can still turn it off per embed, same as always.
//
// 2026092603 (still 1.7.0): "Hide zoom controls" now also defaults to on
// (hidezoom => true) for a new embed, same two files as the
// enableannotations change just above - a second direct-feedback default
// change, not a preserved historical value. Hiding the on-screen
// .ol-zoom buttons doesn't remove the ability to zoom - scroll-wheel and
// double-click zoom are separate OpenLayers interactions, unaffected by
// the control widget's own visibility (already confirmed live for the
// qtype hotspot authoring context in 2026092600 - same reasoning applies
// here, just as the new plain-embed default rather than a forced
// override).
//
// 2026092604 (still 1.7.0): guide.php/USAGE.md now cover the authoring
// form's "Viewing options" section (the 6 hide-checkboxes, "Show OMERO
// ROIs by default", and Width/Height) - real gap, confirmed absent from
// both before this: the section is collapsed by default (see
// author.php's own $viewerdisplaycustomised), easy to never notice it
// exists at all. New unnumbered section between Step 2 and Step 3 in
// both files (matching its own real position on the authoring page),
// not a numbered step - genuinely optional/skippable, same treatment "A
// few things worth knowing"/"Hotspot questions" already get. New
// screenshot: pix/guide/step2b-viewing-options.png.
//
// 2026092605 (still 1.7.0): documentation only, no code changes. README.md's
// "Hotspot questions" section now covers rotate/resize (standalone and
// both qtypes), the qtypes' own "Set as opening view" feature, and the
// new one-hotspot-per-quiz-page-by-default behaviour - all real,
// already-shipped features that were never written up here. ADMIN.md
// gained a matching "Known limitations" entry explaining that same
// auto-page-break default, including that it's only a default (a
// teacher can still manually join a hotspot question back onto a shared
// page afterward, live-verified: that specific action isn't one of the
// events the observer reacts to, so it sticks).
//
// 2026092606 (still 1.7.0): guide.php now accepts a contextid param as an
// alternative to courseid - the exact same dual acceptance author.php
// already had (see that file's own comment on this same pair of lines).
// Needed so tiny_omeroembed's own new Help-dialog tab (see that
// component's own version.php) can link to a real course's guide page
// using only what a TinyMCE instance actually knows (its editor context,
// never a bare courseid). Live-verified: guide.php?contextid=X resolves
// correctly to the right course's guide.
//
// 2026092607 (still 1.7.0): the hotspot drawing toolbar (ellipse/
// rectangle mode, the lock button, rotate/resize handles) has enough real
// detail that the existing guide content undersold it - direct feedback,
// with real screenshots of the actual toolbar states. guide.php/USAGE.md
// gained a new "Drawing and adjusting a region, step by step" section
// (guidehotspotdrawing* strings, 6 new screenshots: pix/guide/hotspot-e
// through -j) covering the exact button sequence, plus two details easy
// to miss without it: the draw gesture is centre-out (press where the
// feature is, drag outward to size it - not corner-to-corner), and
// selecting an already-drawn region for editing needs the ellipse/
// rectangle mode switched off first (a click while a mode is still on
// starts a new region instead). Both qtype README.md files also cover
// this now, with a link to the fuller walkthrough (see their own
// version.php entries).
//
// Also new: a "See how to draw and adjust a region" link beside the live
// preview itself (author.php, hidden until a hotspot mode is chosen -
// js/author.js's applyHotspotModeUI() toggles it the same way it already
// toggles the annotations checkbox for the same condition), deep-linking
// straight to that new guide.php section via a real anchor id added to
// its own heading. The qtype forms get the same link unconditionally
// (see their own version.php entries) - their whole form is already a
// hotspot, no toggling needed.
//
// 2026092608 (still 1.7.0): one more real gap in the same drawing
// walkthrough, direct feedback - confirmed against onViewportPointerDown()
// itself (js/hotspot-multi-author.js and its 3 siblings): resize and
// rotate are the *only* two ways to adjust an already-drawn region - a
// click-drag on a selected region's own body (not one of its handles)
// does nothing at all, there's no move gesture. guide.php/USAGE.md's new
// drawing section, both qtype README.md files, and this repo's own
// README.md now all say so explicitly - a region drawn in the wrong
// place has to be deleted and redrawn, not dragged.
//
// 2026092609 (still 1.7.0): real bug, direct report - re-opening an
// existing standalone hotspot embed for editing (tiny_omeroembed's own
// edit-in-place) showed the slide but not the previously-drawn region,
// as if it had never been saved, even though the row was genuinely still
// in local_omeroembed_hotspots the whole time. Root cause: the live
// preview iframe's embedid query param was only ever added by a
// *client-side* reload (author.js's own hotspot-mode-change handler) -
// fine the first time a teacher picks a hotspot mode (a real 'change'
// event fires and triggers it), but re-editing pre-selects that same
// dropdown value from this page's very first server render, so no
// change event ever fires and that reload never runs. The one and only
// iframe request that ever happened was missing embedid entirely, so
// hotspot-author.js's own hotspot_get fetch asked for the wrong (blank)
// embedid and got nothing back. Fixed server-side: author.php now bakes
// embedid into the live preview's own initial src directly, whenever
// $annotateid is already known (a genuine re-edit), removing the
// dependency on that change event firing at all. Live-verified against
// a real save-leave-reopen cycle through a real Page activity (not just
// author.php in isolation) - the region and its rotate/resize handles
// now render immediately on re-open.
//
// 2026092610 (still 1.7.0): a real move gesture, direct feedback - the
// only two ways to adjust an already-drawn region used to be resize and
// rotate (see 2026092608's own changelog entry, which documented that as
// a limitation rather than something still to build). Dragging a
// selected region's own body (not a handle) now translates it - a plain
// delta on its stored x/y, correct under any rotation with no extra math
// since rotation is defined around that same point (see
// tryStartMoveDrag()'s own docblock in each file). Touches all 4 hotspot-
// authoring files (standalone single/multi, both qtypes), same scope as
// the original rotate/resize work.
//
// For multi-region specifically (both the standalone activity and the
// qtype), a real regression risk was caught before it shipped: a plain
// click on the already-selected region's own body is how a teacher
// deselects it, and grabbing every pointerdown on that same body for the
// new move gesture would silently break that (a cancelable pointerdown's
// own preventDefault() can suppress the native 'click' event that would
// otherwise still fire). Fixed with a small movement threshold - a
// "drag" that never actually moved is treated as the plain click it
// really was, deselecting exactly as before with nothing persisted.
//
// Also new, matching direct feedback wanting a way to see where a drag
// would land before clicking: hover-cursor feedback over a selected
// region - the standard 'move' cursor over its body, a resize cursor
// over its corner handles, and (since CSS has no built-in keyword for
// this, unlike 'move') a small hand-authored inline SVG rotate cursor
// over its rotate handle - same convention this codebase already uses
// for its own icons (e.g. annotate.js's) rather than an icon library.
//
// 2026092611 (still 1.7.0): two real fixes to the move/cursor work just
// shipped in 2026092610, both found through live testing rather than
// code review alone.
//
// Correction to that entry's own description: the movement-threshold
// idea it describes ("a drag that never actually moved is treated as
// the plain click it really was, deselecting exactly as before") turned
// out to be wrong once actually tested against the two multi-region
// files (standalone and qtype) - a real drag's own trailing native
// 'click' event still fires after pointerup regardless of any
// preventDefault() during the drag (that assumption doesn't hold in
// practice), so a manual deselect-toggle replicated inside the
// threshold's "not a real drag" branch collided with the native click's
// own toggle and silently cancelled it back out - clicking to deselect
// an already-selected region did nothing. Fixed with a `suppressNextClick`
// flag instead: a real drag sets it right before persisting, so the
// click that inevitably follows is swallowed once; a plain click (never
// crossed the threshold) does nothing extra at all and simply lets the
// native, unmodified click handler deselect on its own, exactly as
// before this feature existed. Live-verified via a real select
// → re-click-to-deselect → re-select → drag sequence. This was already
// deployed to this dev stack under the 2026092610 version number
// without its own version bump - this entry gives it one, so a real
// browser's cached copy of the old script (served under that same
// versioned URL) gets invalidated too, not just this dev stack's own
// already-purged cache.
//
// Second, separate fix: the hover cursor (move/resize/rotate) was being
// set on the wrong element in all 4 files. viewportEl (from
// olmap.getTargetElement()) is the outer container iviewer's own
// <ol3-viewer> component was originally given as its target - but
// OpenLayers creates its own internal '.ol-viewport' div *inside* that
// container (returned by olmap.getViewport(), a distinct, real OL API
// method), and iviewer manages its own cursor directly on that closer,
// more specific descendant. CSS cursor inheritance means an explicit
// cursor set closer to the actual pointer position always wins over one
// set on an ancestor further out, so every cursor change this file made
// on viewportEl was being silently shadowed - the property was set
// correctly (which is all earlier automated checks against
// .style.cursor actually verified) but nothing ever visually changed
// for a real user. Fixed by introducing a separate cursorTargetEl
// (olmap.getViewport(), falling back to viewportEl itself if that API
// is ever unavailable) and pointing every cursor write at it instead -
// none of the hit-test math changes, only which element the resulting
// cursor value is written to.
//
// 2026092612 (still 1.7.0): two documentation-image fixes to guide.php's
// own "Hotspot questions" section.
//
// First: the three screenshots illustrating basic hotspot setup
// (hotspot-a-layout.png, hotspot-b-drawing.png, hotspot-c-feedback.png)
// were replaced - they previously showed a coronal section through a
// fetal head (image 1908), which may be perceived as a human specimen; no
// consent/IP position has been established for showing human tissue in
// this public-facing guide (matches the same standing rule already
// applied to slide-catalogue's own example slides). Replaced with fresh
// captures of the same non-human ovary slide (image 2051) already used
// by every other hotspot screenshot in this section (hotspot-d through
// hotspot-j) - drawn/captured live through the real authoring tool and a
// real student-facing attempt, not mocked. The whole repo was grepped for
// any other reference to image 1908 - none found outside one docblock
// comment in heatmap_renderer.php that only illustrates a URL *pattern*
// in source code, never rendered as an actual image to any viewer.
//
// Second, the real gap that let the old images keep showing up after
// that swap: local_omeroembed_guide_image() built each <img> src with no
// cache-busting at all, so a browser that had already loaded this page
// once could keep serving image 1908 straight from its own cache
// indefinitely, even though the file on disk had already changed -
// confirmed as the actual cause of a real report of still seeing the old
// image after the swap above. Every guide image src now carries a
// '?v=<mtime>' query string, so a changed file always gets a new URL and
// is never served stale.
//
// 2026092613 (still 1.7.0): no behaviour change. author.php's help-link
// call (the "See how to draw and adjust a region" link beside the live
// preview) was laid out across lines in a way the Moodle Code Checker
// rejects (opening parenthesis not last on its line, more than one
// argument per line, closing parenthesis not on its own line) - caught by
// GitHub Actions CI on the previous push, all local_omeroembed jobs
// failing on it. Reformatted to Moodle's multi-line call style.
$plugin->version   = 2026092613;
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
