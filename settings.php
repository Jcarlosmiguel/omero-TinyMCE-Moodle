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
 * Admin settings.
 *
 * @package    filter_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    $settings->add(new admin_setting_configtext(
        'filter_omeroembed/omerobaseurl',
        get_string('omerobaseurl', 'filter_omeroembed'),
        get_string('omerobaseurl_desc', 'filter_omeroembed'),
        'https://omero.mvls.gla.ac.uk',
        PARAM_URL
    ));

    // One "subject_key|username|password" triple per line - the simplest storage
    // shape that still lets a course use {omero:ID:SUBJECT} to select which
    // service-account credentials the proxy authenticates with. A dedicated
    // DB-table-backed admin UI (add/edit/delete rows individually) would be
    // friendlier for managing many subjects, but adds install.xml/DB schema and a
    // custom admin page - deliberately avoided here in favour of minimal plugin
    // footprint (see the project's own plan doc on why that matters for review).
    //
    // Note: like every other Moodle config value, this is stored in mdl_config_plugins
    // as plain text, not encrypted at rest - the same as any other Moodle plugin
    // storing a third-party service credential (e.g. an API key) in $CFG-backed config.
    $settings->add(new admin_setting_configtextarea(
        'filter_omeroembed/subjects',
        get_string('subjects', 'filter_omeroembed'),
        get_string('subjects_desc', 'filter_omeroembed'),
        '',
        PARAM_RAW
    ));
}
