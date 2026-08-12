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
 * External service (Web service) function definitions.
 *
 * First slice of the ajax.php -> External Services migration (issue #8):
 * only the read-only 'list' action moves here so far - see
 * classes/external/get_annotations.php's own docblock for why this one was
 * chosen first and what the other 13 ajax.php actions still do.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_omeroembed_get_annotations' => [
        'classname'     => 'local_omeroembed\external\get_annotations',
        'methodname'    => 'execute',
        'description'   => 'Returns the current user\'s own annotations for one embed placement.',
        'type'          => 'read',
        'ajax'          => true,
        // Documentation only - see get_annotations::execute()'s own
        // comment, this does not enforce anything by itself.
        'capabilities'  => 'local/omeroembed:annotate',
        // Matches ajax.php's own \core\session\manager::write_close() intent
        // - see get_annotations::execute()'s own comment for why this alone
        // doesn't fully avoid the write-lock cost on a typical site.
        'readonlysession' => true,
    ],
];
