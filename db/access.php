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
 * Capability definitions.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Controls manage.php - the OMERO base URL, subject accounts
    // (course|username|password), and viewer overlay settings. These are
    // site-wide (not tied to any one course), so this is a CONTEXT_SYSTEM
    // capability, checked at the system context in manage.php - not scoped
    // per-course the way author.php's moodle/course:manageactivities check
    // is. Defaults to the Manager archetype rather than Editing teacher on
    // purpose - subject account passwords are a shared, site-wide secret,
    // not something every course's own teachers should be able to see or
    // change. Site administrators keep access too via the existing
    // $hassiteconfig-gated settings.php - this capability is an additional
    // path for non-admins, not a replacement.
    'local/omeroembed:managesettings' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Lets a user draw/delete their own point annotations on a final embed
    // (ajax.php) - checked per-course, since an embed can live inside any
    // course content (a Page, a quiz question, ...) with no course-module
    // of its own to hang a CONTEXT_MODULE capability off. Same archetype
    // shape as mod/choice:choose (student/teacher/editingteacher all
    // allowed by default) - annotating is a normal enrolled-user action,
    // not a privileged one.
    'local/omeroembed:annotate' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Lets a teacher configure viewport tracking on an embed and view the
    // resulting heatmap (heatmap.php, plus ajax.php's tracking_get/
    // tracking_set/heatmap actions) - deliberately NOT given to students,
    // unlike :annotate above. Checked per-course, same reasoning as
    // :annotate for why CONTEXT_COURSE rather than CONTEXT_MODULE.
    'local/omeroembed:viewheatmap' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Lets a teacher define (or clear) the hidden correct-answer region for
    // the click-to-answer hotspot feature (author.php's live preview, plus
    // ajax.php's hotspot_save/hotspot_clear/hotspot_get actions) -
    // deliberately NOT given to students, unlike :annotate above: this is
    // the one action in the whole plugin that reads/writes the actual
    // secret a student is being asked to find, so it gets its own
    // capability rather than reusing :annotate (which students hold) or
    // :viewheatmap (a different privileged action entirely). Checked per-
    // course, same reasoning as :annotate/:viewheatmap for why
    // CONTEXT_COURSE rather than CONTEXT_MODULE.
    'local/omeroembed:hotspotauthor' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],
];
