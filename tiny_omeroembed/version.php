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
 * Tiny OMERO Embed plugin version file.
 *
 * @package    tiny_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// 2026081300 (1.0.2): patch only - lang.php re-sorted into strict
// alphabetical order (phpcs LangFilesOrdering cleanup). Same string
// keys, same string values, no behaviour change.
$plugin->version   = 2026081300;
$plugin->requires  = 2024100100;
$plugin->component = 'tiny_omeroembed';
$plugin->supported = [405, 502]; // Inclusive range (4.5-5.2) - core requires exactly [min, max], not a discrete list.
$plugin->release   = '1.0.2';
$plugin->maturity  = MATURITY_STABLE;
// Hard runtime dependency, not just thematic bundling - ui.js opens
// local_omeroembed's own author.php by URL directly (see amd/src/ui.js),
// the same way qtype_omerohotspot/qtype_omerohotspotmulti depend on it
// for rendering via local_omeroembed/proxy.php. Their own version.php
// files already declare this; this one didn't, which was a real gap.
// Pinned to 2026080308 specifically (not just "any version") because
// that's the release with the stored-XSS/cross-course-IDOR/session-lock
// fixes - this plugin's own ui.js opens author.php directly, so an older
// local_omeroembed here would leave the XSS path reachable regardless of
// this plugin's own version.
$plugin->dependencies = [
    'local_omeroembed' => 2026080308,
];
