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
 * Version information.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'qtype_omerohotspot';
// 2026092600 (1.1.0): adds an optional opening-view position (pan/zoom) a
// teacher can set on the edit form's own live preview, forwarded to the
// student-facing embed the same way local_omeroembed's own "Set as
// opening view" feature already works - previously there was no way to
// define what position the slide opened on for a student at all, always
// OMERO's own default (whole slide, default zoom). New DB field
// (openingview), new edit-form button, new AMD editform.js logic,
// renderer.php now forwards x/y/zm to proxy.php when set. Purely
// additive/optional - an existing question with no opening view set keeps
// behaving exactly as before.
//
// 2026092601 (still 1.1.0): documentation only, no code changes. README.md
// now covers rotate/resize, the opening-view feature, and the
// one-hotspot-per-quiz-page-by-default behaviour local_omeroembed's own
// event observer added - all real, already-shipped features that were
// never written up here.
//
// 2026092602 (still 1.1.0): README.md's rotate/resize bullet now covers
// two easy-to-miss details - the draw gesture is centre-out (press where
// the feature is, drag outward to size it), and selecting an
// already-drawn region needs the ellipse/rectangle mode switched off
// first - and links to local_omeroembed's own fuller step-by-step
// walkthrough. New: a "See how to draw and adjust a region" link beside
// the live preview in edit_omerohotspot_form.php, deep-linking straight
// to that same walkthrough - always shown here, unlike author.php's own
// equivalent (which only shows while a hotspot mode is chosen), since
// this whole form is already one.
//
// 2026092603 (still 1.1.0): the "Set as opening view" button's
// "preview not ready yet" message used window.alert(), which ESLint's
// no-alert rule rejects (CI's grunt step runs with zero warnings allowed,
// so this failed every qtype_omerohotspot job on the previous push).
// Replaced with Moodle's own core/notification alert, which is also the
// standard Moodle look for this kind of message. Same wording, no other
// behaviour change. The local_omeroembed dependency pin is also brought up
// to the build these components now ship with (it still pointed at a much
// older build, 2026080308).
//
// 2026092604 (still 1.1.0): a real data-loss bug in this release's own new
// opening-view feature, found while preparing the Marketplace update by
// running an actual course backup and restore: the backup class listed the
// option fields explicitly (subjectid, imageid, datasetid, geometry) and had
// never been told about the new openingview field, so a backed-up, copied
// or migrated course silently lost every question's opening view - the
// restored questions all came back with it empty. Fixed by adding
// openingview to the backup element. Verified with real round trips on the
// dev site: before the fix the restored copies had no opening view at all;
// after it they carry the exact same values as the originals, questions
// without one stay empty, and a backup made before this fix (which has no
// such field) still restores cleanly, since the column is nullable.
//
// 2026100500 (still 1.1.0): no code change. Moodle 5.3.0 released
// 2026-10-05; tested directly against the real release - see
// local_omeroembed's own version.php for the full writeup, including
// this qtype's own edit form going through Moodle 5.3's new mod_qbank
// question bank with every field present and no errors. $plugin->
// supported extended to include 503, and the local_omeroembed dependency
// pin brought up to the build this component now ships with.
//
// 2026112500 (1.1.1): Security fix pack, following a Moodle Marketplace
// review. A student could set the hidden clickx/clicky inputs to an
// out-of-range value (e.g. 1e999) via devtools, which was stored as the
// string "INF" - summarise_response() then crashed casting it, breaking
// the student's own quiz finish, the teacher's review page, and the
// site-wide overdue-quiz auto-submit cron (it only catches
// moodle_exception, not the TypeError this produced). An unanswered
// question was also silently recorded as a click at (0,0) rather than
// not-answered, for the same underlying reason (PARAM_FLOAT cleaning an
// empty string to 0.0). Both now validated properly (see lib.php's new
// is_finite_number()/clean_geometry()). A degenerate (zero-width) region
// - reachable through the normal authoring UI, not just tampering - had
// the same fatal/cron-poisoning effect via a division by zero in the
// hit-test; validated at save, restore and hit-test time now. Restore
// no longer inserts unvalidated geometry/openingview from a crafted or
// corrupt backup. Subject name in the edit form now wrapped in
// format_string() (self-XSS only, but good practice).
//
// 2026112501 (1.1.2): no behaviour change of its own. The
// local_omeroembed dependency pin below still named 2026100500, the
// pre-fix build, even after the security fix pack above shipped
// local_omeroembed 2026112500 with the matching proxy.php access-control
// fix this plugin's own rendering path depends on directly - left as-is,
// a site could install this "fixed" release alongside the still-
// vulnerable local_omeroembed build and Moodle would never object.
// Bumped to a new release rather than silently editing the already
// tagged/released 1.1.1, since that build is already public. Pin
// corrected to require 2026112500 or later.
//
// Verified directly: the corrected pin resolves cleanly through a real
// admin/cli/upgrade.php run on both Moodle 4.5.16 and 5.3.1 (the latest
// point releases as of 2026-11-25), with no dependency/version-mismatch
// error.
$plugin->version   = 2026112501;
$plugin->requires  = 2024100100;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.1.2';
$plugin->supported = [405, 503]; // Inclusive range (4.5-5.3) - core requires exactly [min, max], not a discrete list.

// Reuses local_omeroembed's proxy.php (the entire locked-down OMERO-
// embedding mechanism) and subject_repository.php (OMERO connections)
// rather than duplicating either - see this plugin's own README/plan doc
// for why. Can never be installed without it. Pinned to the build
// carrying the matching proxy.php access-control and write-up
// sanitisation fixes (2026112500, release 1.7.1) - all in files this
// qtype's own rendering path depends on directly.
$plugin->dependencies = [
    'local_omeroembed' => 2026112500,
];
