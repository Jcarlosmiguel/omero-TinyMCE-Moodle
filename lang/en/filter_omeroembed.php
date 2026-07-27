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
 * Strings for filter_omeroembed.
 *
 * @package    filter_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['filtername'] = 'OMERO slide embed';

$string['omerobaseurl'] = 'OMERO base URL';
$string['omerobaseurl_desc'] = 'The OMERO.web server this filter proxies content from, e.g. https://omero.mvls.gla.ac.uk. Never shown to students - all requests go through this plugin\'s own proxy.';

$string['subjects'] = 'Subject accounts';
$string['subjects_desc'] = 'One subject per line, in the form <code>subject_key|username|password</code>. The subject_key is what teachers use in the {omero:IMAGE_ID:subject_key} placeholder. These are OMERO service-account credentials, used server-side only - never sent to students\' browsers.';

$string['privacy:metadata'] = 'This plugin does not store any personal data about students. It stores OMERO service-account credentials (configured by an administrator) and short-lived server-side session caches, neither of which are personal data belonging to any individual user of this site.';

$string['unknownsubject'] = 'No OMERO subject account is configured for "{$a}" - check Site administration > Plugins > Filters > OMERO slide embed.';
$string['omerologinfailed'] = 'Could not log in to OMERO as subject "{$a}" - check the configured username/password for this subject.';
$string['omeroconnectionfailed'] = 'Could not reach the configured OMERO server.';
$string['invalidproxypath'] = 'Refusing to proxy path "{$a}" - not in the allowed list.';
