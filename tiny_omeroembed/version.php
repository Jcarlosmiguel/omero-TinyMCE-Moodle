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

$plugin->version   = 2026080300;
$plugin->requires  = 2024100100;
$plugin->component = 'tiny_omeroembed';
$plugin->supported = [405, 502];
// Hard runtime dependency, not just thematic bundling - ui.js opens
// local_omeroembed's own author.php by URL directly (see amd/src/ui.js),
// the same way qtype_omerohotspot/qtype_omerohotspotmulti depend on it
// for rendering via local_omeroembed/proxy.php. Their own version.php
// files already declare this; this one didn't, which was a real gap.
$plugin->dependencies = [
    'local_omeroembed' => 2026080306,
];
