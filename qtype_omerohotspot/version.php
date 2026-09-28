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
$plugin->version   = 2026092604;
$plugin->requires  = 2024100100;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.1.0';
$plugin->supported = [405, 502]; // Inclusive range (4.5-5.2) - core requires exactly [min, max], not a discrete list.

// Reuses local_omeroembed's proxy.php (the entire locked-down OMERO-
// embedding mechanism) and subject_repository.php (OMERO connections)
// rather than duplicating either - see this plugin's own README/plan doc
// for why. Can never be installed without it. Pinned to the build this
// component ships alongside (2026092613), which also carries the
// stored-XSS/cross-course-IDOR/session-lock fixes first made in
// 2026080308 - all in files this qtype's own rendering path depends on
// directly.
$plugin->dependencies = [
    'local_omeroembed' => 2026092613,
];
