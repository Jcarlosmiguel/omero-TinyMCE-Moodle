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
 * Strings for tiny_omeroembed.
 *
 * @package    tiny_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['buttontitle'] = 'Insert OMERO slide';
$string['helptabbody'] = '<p>The <strong>Insert OMERO slide</strong> button (in the toolbar, and in the Insert menu) opens a live, pannable slide viewer - pick a subject account and image, write your commentary alongside it, and insert the finished embed directly into this text. No HTML or URLs to type by hand.</p><p>With the cursor on or inside an existing embed, the same button re-opens it pre-filled with that embed\'s current settings, ready to change.</p><p><a href="{$a}" target="_blank" rel="noopener">Read the full visual guide</a> for a step-by-step walkthrough with screenshots.</p>';
$string['helptabtitle'] = 'OMERO slide embed';
$string['modaltitle'] = 'Insert an OMERO slide';
$string['omeroembed:embed'] = 'Insert an OMERO slide embed';
$string['pluginname'] = 'OMERO embed';
$string['privacy:metadata'] = 'The OMERO embed TinyMCE plugin does not store any personal data itself - it has no database tables of its own, it only opens local_omeroembed\'s own authoring tool in a modal, and any actual data (embeds, annotations, tracking) belongs to that plugin\'s own privacy provider.';
