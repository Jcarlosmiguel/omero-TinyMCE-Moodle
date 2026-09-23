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
//
// 2026092300 (1.1.0): two real bugs found via an independent test pass
// (Ferenc Lengyel, University of Glasgow IT Services, VLE-265) - see
// local_omeroembed's own version.php for the fuller writeup of everything
// that landed in this same cycle. This component's own two:
// - handleAction()'s modal Cancel button had no click handler at all -
// modal.mustache used data-action="cancel", which only
// core/modal_save_cancel wires up; this modal extends the plain
// core/modal base class, confirmed against real Moodle core source to
// only ever wire up data-action="hide". Fixed to that instead - correct
// behaviour anyway, since this modal has no real save/cancel
// distinction, just close.
// - Reopening an existing embed never forwarded options_unlocked=1, so
// local_omeroembed's author.php (which gates every viewer-display
// checkbox on that flag, added in local_omeroembed 1.6.0 to stop a
// disabled-checkbox POST losing state) silently discarded every
// overlay/colour value this file's own readExistingEmbed() had
// correctly read out of the DOM, falling back to the site default
// instead. Minor, not patch - genuinely different behaviour on
// re-edit, not just a fix contained entirely within this file.
//
// 2026092301 (still 1.1.0 - not yet released): a second real bug found
// while going through Ferenc's full VLE-265 document a second time,
// personally reproduced live: the toolbar button's onAction had no guard
// against being invoked again while a modal was still opening - TinyMCE
// never disables a button while its action is pending, so two rapid
// clicks created two independent, fully-separate modal instances stacked
// on top of each other. Fixed with an open-state flag in handleAction(),
// cleared via the existing ModalEvents.hidden listener - and a real
// robustness gap closed alongside it: Modal.create() itself failing
// would otherwise have left that flag stuck forever, permanently
// disabling the button. Also: the "View heatmap" link this file's own
// readExistingEmbed() doesn't touch, but local_omeroembed's proxy.php/
// author.php do, changed from a literal target="_blank" (a fresh window
// every click) to a named target (repeat clicks reuse the same window) -
// noted here only because it's the same underlying UX area, no code in
// this component actually changed for it.
$plugin->version   = 2026092301;
$plugin->requires  = 2024100100;
$plugin->component = 'tiny_omeroembed';
$plugin->supported = [405, 502]; // Inclusive range (4.5-5.2) - core requires exactly [min, max], not a discrete list.
$plugin->release   = '1.1.0';
$plugin->maturity  = MATURITY_STABLE;
// Hard runtime dependency, not just thematic bundling - ui.js opens
// local_omeroembed's own author.php by URL directly (see amd/src/ui.js),
// the same way qtype_omerohotspot/qtype_omerohotspotmulti depend on it
// for rendering via local_omeroembed/proxy.php. Their own version.php
// files already declare this; this one didn't, which was a real gap.
// Pinned to match local_omeroembed's own current build - both components
// are shipping together as one coordinated (not yet released) cycle, so
// there's no reason to leave this pin pointing at an older build than
// what's actually being tested against.
$plugin->dependencies = [
    'local_omeroembed' => 2026092301,
];
