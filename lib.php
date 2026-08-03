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
 * Navigation hook - without it, author.php is only reachable by typing its
 * URL directly. Re-checks the exact same capability author.php itself
 * checks, so this link only ever appears for someone who could actually use
 * the page anyway - never a dead link for students or ordinary staff.
 * manage.php's own discoverability is handled differently - see
 * settings.php's admin_externalpage registration (Site administration >
 * Plugins), not a navigation callback at all.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Adds an "Embed an OMERO slide" link to a course's own navigation, for anyone
 * who could actually use author.php in that course - mirrors exactly the
 * capability check author.php itself makes, at the same course context Moodle
 * hands this callback, so this never shows a link that then 403s.
 *
 * @param navigation_node $coursenode
 * @param stdClass $course
 * @param context_course $context
 * @return void
 */
function local_omeroembed_extend_navigation_course(navigation_node $coursenode, stdClass $course, context_course $context): void {
    if (!has_capability('moodle/course:manageactivities', $context)) {
        return;
    }

    $url = new moodle_url('/local/omeroembed/author.php', ['courseid' => $course->id]);
    $node = navigation_node::create(
        get_string('authortitle', 'local_omeroembed'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_omeroembed_author',
        new pix_icon('t/edit', '')
    );
    $coursenode->add_node($node);
}
