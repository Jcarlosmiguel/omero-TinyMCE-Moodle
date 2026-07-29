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
 * Non-admin settings page: lets anyone with local/omeroembed:managesettings
 * (Managers by default - see db/access.php) control the OMERO base URL, subject
 * accounts, and viewer overlay settings without needing Site administration
 * access. These are site-wide settings, not tied to any one course - hence
 * checking the capability at the system context rather than a course context,
 * unlike author.php.
 *
 * Deliberately NOT built on Moodle's admin_settingpage framework (that's
 * inherently gated on $hassiteconfig/moodle/site:config, admin-only, no way to
 * open it up to another role) - this is a plain form reading/writing the exact
 * same config keys settings.php's admin_setting_* instances use, so whichever
 * page last saved just wins, no synchronisation needed between them.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/omeroembed:managesettings', $context);

$pageurl = new moodle_url('/local/omeroembed/manage.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('managetitle', 'local_omeroembed'));
$PAGE->set_heading(get_string('managetitle', 'local_omeroembed'));

$overlaysettings = ['hideoverview', 'hiderotate', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom'];

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    set_config('omerobaseurl', required_param('omerobaseurl', PARAM_URL), 'local_omeroembed');
    set_config('subjects', required_param('subjects', PARAM_RAW), 'local_omeroembed');
    foreach ($overlaysettings as $setting) {
        set_config($setting, optional_param($setting, 0, PARAM_BOOL), 'local_omeroembed');
    }

    $saved = true;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managetitle', 'local_omeroembed'));
echo html_writer::tag('p', get_string('manageintro', 'local_omeroembed'), ['class' => 'text-muted']);

if ($saved) {
    echo $OUTPUT->notification(get_string('settingssaved', 'local_omeroembed'), 'success');
}

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('form-group', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag('label', get_string('omerobaseurl', 'local_omeroembed'), ['for' => 'id_omerobaseurl', 'class' => 'font-weight-bold']);
echo html_writer::tag('p', get_string('omerobaseurl_desc', 'local_omeroembed'), ['class' => 'text-muted', 'style' => 'margin-bottom:0.25rem;']);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'omerobaseurl', 'id' => 'id_omerobaseurl',
    'value' => get_config('local_omeroembed', 'omerobaseurl'),
    'class' => 'form-control', 'style' => 'max-width:30em;',
]);
echo html_writer::end_div();

echo html_writer::start_div('form-group', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag('label', get_string('subjects', 'local_omeroembed'), ['for' => 'id_subjects', 'class' => 'font-weight-bold']);
echo html_writer::tag('p', get_string('subjects_desc', 'local_omeroembed'), ['class' => 'text-muted', 'style' => 'margin-bottom:0.25rem;']);
echo html_writer::tag('textarea', s(get_config('local_omeroembed', 'subjects')), [
    'name' => 'subjects', 'id' => 'id_subjects', 'rows' => 6, 'class' => 'form-control', 'style' => 'max-width:40em; font-family:monospace;',
]);
echo html_writer::end_div();

echo html_writer::tag('h3', get_string('overlaysheading', 'local_omeroembed'), ['style' => 'margin-top:2rem;']);
echo html_writer::tag('p', get_string('overlaysheading_desc', 'local_omeroembed'), ['class' => 'text-muted']);

foreach ($overlaysettings as $setting) {
    echo html_writer::start_div('form-check', ['style' => 'margin-bottom: 0.75rem;']);
    echo html_writer::checkbox($setting, 1, (bool) get_config('local_omeroembed', $setting),
        get_string($setting, 'local_omeroembed'), ['class' => 'form-check-input']);
    echo html_writer::tag('p', get_string($setting . '_desc', 'local_omeroembed'),
        ['class' => 'text-muted', 'style' => 'margin-left:1.5rem; margin-top:0.25rem;']);
    echo html_writer::end_div();
}

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('savechanges', 'local_omeroembed'), 'class' => 'btn btn-primary', 'style' => 'margin-top:1rem;',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
