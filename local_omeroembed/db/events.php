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
 * Event observer registration.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\local_omeroembed\observer::course_deleted',
    ],
    // Keeps both hotspot qtypes off a shared quiz page - see
    // classes/observer.php's own enforce_hotspot_own_page() docblock for
    // why. Registered here (not in either qtype plugin) since the rule
    // covers both of them together, and this is the one plugin both
    // already depend on.
    [
        'eventname' => '\mod_quiz\event\slot_created',
        'callback' => '\local_omeroembed\observer::quiz_slot_created',
    ],
    [
        'eventname' => '\mod_quiz\event\slot_deleted',
        'callback' => '\local_omeroembed\observer::quiz_slot_deleted',
    ],
    [
        'eventname' => '\mod_quiz\event\slot_moved',
        'callback' => '\local_omeroembed\observer::quiz_slot_moved',
    ],
    [
        'eventname' => '\mod_quiz\event\quiz_repaginated',
        'callback' => '\local_omeroembed\observer::quiz_repaginated',
    ],
];
