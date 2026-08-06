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
 * Cache definitions.
 *
 * @package    local_omeroembed
 * @category   cache
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // One entry per OMERO subject account: cached session cookies + when they were
    // established, so proxy.php doesn't have to log in to OMERO.web on every single
    // request. Deliberately MODE_APPLICATION (shared across all users/requests, not
    // per-session) - these are subject *service accounts*, not individual student
    // identities, so there is exactly one real session to share, not one per student.
    'sessions' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'staticacceleration' => true,
        'staticaccelerationsize' => 10,
        'canuselocalstore' => true,
    ],
];
