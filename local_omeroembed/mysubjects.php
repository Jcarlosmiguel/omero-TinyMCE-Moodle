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
 * Lets a teacher manage their own OMERO service-account connections -
 * replaces the old site-wide settings.php 'subjects' admin textarea (see
 * db/install.xml's own comment on local_omeroembed_subjects for the full
 * reasoning). Reached from a link on author.php, gated identically
 * (moodle/course:manageactivities in the same course) - this page itself
 * has no course-specific data of its own (a teacher's connections aren't
 * tied to one course), $courseid is only here for that capability check
 * and the "back to authoring tool" link, matching this plugin's
 * established single-course-scoped-entry-point pattern.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_omeroembed\subject_repository;

$courseid = required_param('courseid', PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);
$save = optional_param('save', false, PARAM_BOOL);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/course:manageactivities', $context);

$pageurl = new moodle_url('/local/omeroembed/mysubjects.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('mysubjectstitle', 'local_omeroembed'));
$PAGE->set_heading($course->fullname);

if ($save && confirm_sesskey()) {
    $id = optional_param('id', 0, PARAM_INT);
    $name = required_param('name', PARAM_TEXT);
    $omerousername = required_param('omerousername', PARAM_TEXT);
    $omeropassword = optional_param('omeropassword', '', PARAM_RAW);

    if (trim($name) === '' || trim($omerousername) === '') {
        throw new \moodle_exception('invalidsubjectform', 'local_omeroembed');
    }

    if ($id) {
        subject_repository::update($id, $USER->id, $name, $omerousername, $omeropassword);
    } else {
        if ($omeropassword === '') {
            throw new \moodle_exception('invalidsubjectform', 'local_omeroembed');
        }
        subject_repository::create($USER->id, $name, $omerousername, $omeropassword);
    }
    redirect($pageurl, get_string('subjectsaved', 'local_omeroembed'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($delete && $confirm && confirm_sesskey()) {
    subject_repository::delete($delete, $USER->id);
    redirect($pageurl, get_string('subjectdeleted', 'local_omeroembed'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// PERFORMANCE: nothing past this point writes to $_SESSION - see
// proxy.php's own write_close() comment. Deliberately placed *after* the
// POST-handling above, not before it: redirect()'s $message argument
// works by writing to $SESSION->notifications for the next page load
// (see \core\notification::add()) - closing the session before that
// write happens means it's silently discarded and the confirmation
// message never appears on the page the user lands on. Confirmed as a
// real regression the earlier, too-early placement introduced.
\core\session\manager::write_close();

if ($delete) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('mysubjectstitle', 'local_omeroembed'));
    $continueurl = new moodle_url($pageurl, ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->confirm(get_string('confirmdeletesubject', 'local_omeroembed'), $continueurl, $pageurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mysubjectstitle', 'local_omeroembed'));
echo html_writer::tag('p', get_string('mysubjectsintro', 'local_omeroembed'), ['class' => 'text-muted']);

$editing = $edit ? subject_repository::get_by_id_for_user($edit, $USER->id) : null;
if ($edit && !$editing) {
    throw new \moodle_exception('invalidsubjectform', 'local_omeroembed');
}

// --- List of this teacher's own entries - never their passwords. ---
$subjects = subject_repository::get_for_user($USER->id);
if ($subjects) {
    $table = new html_table();
    $table->head = [
        get_string('subjectnamelabel', 'local_omeroembed'),
        get_string('subjectusernamelabel', 'local_omeroembed'),
        '',
    ];
    foreach ($subjects as $subject) {
        $editurl = new moodle_url($pageurl, ['edit' => $subject->id]);
        $deleteurl = new moodle_url($pageurl, ['delete' => $subject->id]);
        $table->data[] = [
            s($subject->name),
            s($subject->omerousername),
            html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-secondary']) . ' ' .
                html_writer::link($deleteurl, get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nosubjectsyet', 'local_omeroembed'), 'info');
}

// --- Add/edit form - a plain GET-reachable page, POSTs to itself. ---
echo html_writer::tag('h5', get_string($editing ? 'editsubjectheading' : 'addsubjectheading', 'local_omeroembed'));

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'style' => 'max-width:30em;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'save', 'value' => 1]);
if ($editing) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $editing->id]);
}

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('subjectnamelabel', 'local_omeroembed'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'name', 'class' => 'form-control',
    'value' => $editing ? $editing->name : '', 'required' => 'required', 'maxlength' => 64,
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('subjectusernamelabel', 'local_omeroembed'));
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'omerousername', 'class' => 'form-control',
    'value' => $editing ? $editing->omerousername : '', 'required' => 'required',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string(
    $editing ? 'subjectpasswordlabeledit' : 'subjectpasswordlabel', 'local_omeroembed'
));
echo html_writer::empty_tag('input', [
    'type' => 'password', 'name' => 'omeropassword', 'class' => 'form-control',
    'autocomplete' => 'new-password', 'required' => $editing ? null : 'required',
]);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string($editing ? 'savesubject' : 'addsubject', 'local_omeroembed'),
    'class' => 'btn btn-primary',
]);
if ($editing) {
    echo ' ' . html_writer::link($pageurl, get_string('cancel'), ['class' => 'btn btn-secondary']);
}
echo html_writer::end_tag('form');

$authorurl = new moodle_url('/local/omeroembed/author.php', ['courseid' => $courseid]);
echo html_writer::link($authorurl, get_string('backtoauthoring', 'local_omeroembed'), ['style' => 'display:inline-block; margin-top:1.5rem;']);

echo $OUTPUT->footer();
