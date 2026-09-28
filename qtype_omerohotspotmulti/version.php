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
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'qtype_omerohotspotmulti';
// 2026092600 (1.1.0): adds an optional opening-view position (pan/zoom) a
// teacher can set on the edit form's own live preview, forwarded to the
// student-facing embed the same way local_omeroembed's own "Set as
// opening view" feature already works, and identical to
// qtype_omerohotspot's own 1.1.0 change - previously there was no way to
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
// 2026092602 (still 1.1.0): same as qtype_omerohotspot's own 2026092602 -
// see that plugin's own version.php for the full writeup. README.md's
// rotate/resize bullet now covers the centre-out draw gesture and the
// mode-must-be-off-to-select detail, with a link to local_omeroembed's
// own fuller walkthrough; edit_omerohotspotmulti_form.php gained the
// same "See how to draw and adjust a region" link beside its own live
// preview.
//
// 2026092603 (still 1.1.0): same as qtype_omerohotspot's own 2026092603 -
// window.alert() replaced with Moodle's core/notification alert (ESLint
// no-alert, failing CI's grunt step), and the local_omeroembed dependency
// pin brought up to the build these components now ship with.
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

// A sibling of qtype_omerohotspot, not a mode of it - a click is correct
// against ANY one of several teacher-marked regions instead of one single
// shape (see local_omeroembed's classes/hotspot_multi_repository.php's own
// docblock). Reuses local_omeroembed's proxy.php (the entire locked-down
// OMERO-embedding mechanism) and subject_repository.php (OMERO connections)
// rather than duplicating either. Can never be installed without it.
// Pinned to the build this component ships alongside (2026092613), which
// also carries the stored-XSS/cross-course-IDOR/session-lock fixes first
// made in 2026080308 - all in files this qtype's own rendering path
// depends on directly.
$plugin->dependencies = [
    'local_omeroembed' => 2026092613,
];
