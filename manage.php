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
 * (Managers by default - see db/access.php) control the OMERO base URL and
 * default viewer overlay settings without needing Site administration
 * access. These are site-wide settings, not tied to any one course - hence
 * checking the capability at the system context rather than a course context,
 * unlike author.php. OMERO subject-account credentials used to live here too,
 * but are now teacher-owned (see mysubjects.php and
 * classes/subject_repository.php) - no site-wide credential setting exists
 * any more for this page to expose.
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

use local_omeroembed\annotations_repository;

require_login();
$context = context_system::instance();
require_capability('local/omeroembed:managesettings', $context);
// PERFORMANCE: nothing below writes to $_SESSION - see proxy.php's own
// write_close() comment. set_config() writes are ordinary DB/MUC cache
// writes, unaffected by the session lock.
\core\session\manager::write_close();

$pageurl = new moodle_url('/local/omeroembed/manage.php');
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('managetitle', 'local_omeroembed'));
$PAGE->set_heading(get_string('managetitle', 'local_omeroembed'));

// No "hide rotate control" here either - always hidden, unconditionally,
// in proxy.php's inject_overlay_hide_css() (see settings.php's own
// comment on why).
$overlaysettings = ['hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom', 'hidenavbar'];

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    set_config('omerobaseurl', required_param('omerobaseurl', PARAM_URL), 'local_omeroembed');
    foreach ($overlaysettings as $setting) {
        set_config($setting, optional_param($setting, 0, PARAM_BOOL), 'local_omeroembed');
    }

    $submittedcolours = [];
    foreach (annotations_repository::COLOUR_PALETTE as $hex) {
        if (optional_param('colour_' . strtolower(ltrim($hex, '#')), 0, PARAM_BOOL)) {
            $submittedcolours[] = $hex;
        }
    }
    // parse_colours() re-validates/caps this the same way proxy.php does
    // for a teacher's own per-embed choice - this page isn't any more
    // trusted just because it needs a capability to reach.
    set_config('annotationcolours', implode(',', annotations_repository::parse_colours(implode(',', $submittedcolours))), 'local_omeroembed');

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

echo html_writer::tag('h3', get_string('annotationcolours', 'local_omeroembed'), ['style' => 'margin-top:2rem;']);
echo html_writer::tag('p', get_string('annotationcolours_desc', 'local_omeroembed', annotations_repository::MAX_COLOURS),
    ['class' => 'text-muted']);

$currentcolours = annotations_repository::parse_colours((string) get_config('local_omeroembed', 'annotationcolours'));
echo html_writer::start_div('', ['id' => 'omero-annotation-colours']);
foreach (annotations_repository::get_colour_choices() as $hex => $label) {
    echo html_writer::start_div('form-check', ['style' => 'margin-bottom: 0.5rem;']);
    echo html_writer::checkbox('colour_' . strtolower(ltrim($hex, '#')), 1, in_array($hex, $currentcolours, true),
        $label . ' (' . $hex . ')', ['class' => 'form-check-input']);
    echo html_writer::end_div();
}
echo html_writer::end_div();
// Same fix as author.php's own colour picker - see that file's comment for
// why this needs to actively disable further checkboxes, not just rely on
// parse_colours()'s own silent server-side truncation.
echo html_writer::script(
    'document.addEventListener("DOMContentLoaded", function() {'
    . 'var container = document.getElementById("omero-annotation-colours");'
    . 'if (!container) { return; }'
    . 'var boxes = Array.prototype.slice.call(container.querySelectorAll("input[type=checkbox]"));'
    . 'var max = ' . (int) annotations_repository::MAX_COLOURS . ';'
    . 'function refresh() {'
    . '  var checkedcount = boxes.filter(function(cb) { return cb.checked; }).length;'
    . '  boxes.forEach(function(cb) { cb.disabled = !cb.checked && checkedcount >= max; });'
    . '}'
    . 'boxes.forEach(function(cb) { cb.addEventListener("change", refresh); });'
    . 'refresh();'
    . '});'
);

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('savechanges', 'local_omeroembed'), 'class' => 'btn btn-primary', 'style' => 'margin-top:1rem;',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
