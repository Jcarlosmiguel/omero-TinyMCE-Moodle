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
$plugin->version   = 2026080400;
$plugin->requires  = 2024100100;
$plugin->maturity  = MATURITY_ALPHA;

// A sibling of qtype_omerohotspot, not a mode of it - a click is correct
// against ANY one of several teacher-marked regions instead of one single
// shape (see local_omeroembed's classes/hotspot_multi_repository.php's own
// docblock). Reuses local_omeroembed's proxy.php (the entire locked-down
// OMERO-embedding mechanism) and subject_repository.php (OMERO connections)
// rather than duplicating either. Can never be installed without it.
$plugin->dependencies = [
    'local_omeroembed' => 2026080306,
];
