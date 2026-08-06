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
$plugin->version   = 2026080302;
$plugin->requires  = 2024100100;
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.0';
$plugin->supported = [405, 502];

// Reuses local_omeroembed's proxy.php (the entire locked-down OMERO-
// embedding mechanism) and subject_repository.php (OMERO connections)
// rather than duplicating either - see this plugin's own README/plan doc
// for why. Can never be installed without it. Pinned to 2026080308
// specifically - the release with the stored-XSS/cross-course-IDOR/
// session-lock fixes, all in files this qtype's own rendering path
// depends on directly.
$plugin->dependencies = [
    'local_omeroembed' => 2026080308,
];
