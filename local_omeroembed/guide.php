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
 * Teacher-facing visual guide, rendered natively inside Moodle rather than
 * linking out to USAGE.md on GitHub - real reasons, not just preference:
 * survives an institution's network blocking GitHub, and reads as part of
 * the product rather than a bounce-out. Content matches USAGE.md's own
 * structure (kept in sync deliberately - see that file's own note), just
 * restructured around real screenshots at each step per direct feedback
 * ("teachers like images to be guided... something easy to the eyes").
 *
 * PLACEHOLDER IMAGES: every {{IMAGE: ...}} box below is a stand-in for a
 * real screenshot of the actual running product, not yet captured (see
 * this plugin's own submission process for the shot list this maps to).
 * Deliberately left as visible placeholders rather than silently omitted,
 * so it's obvious at a glance what still needs a real image dropped in -
 * replace pix/guide/*.png and the corresponding <img> tag below once
 * captured, don't leave a placeholder shipped in a real release.
 *
 * Gated identically to author.php (moodle/course:manageactivities) -
 * same audience, same "can this person actually use the tool this guide
 * explains" reasoning.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/course:manageactivities', $context);

$pageurl = new moodle_url('/local/omeroembed/guide.php', ['courseid' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('guidetitle', 'local_omeroembed'));
$PAGE->set_heading($course->fullname);

/**
 * A visible, unmissable stand-in for a real screenshot not yet captured -
 * see this file's own docblock for why these are deliberately obvious
 * rather than silently blank.
 *
 * @param string $description What the real screenshot should show.
 * @return string
 */
function local_omeroembed_guide_placeholder(string $description): string {
    return html_writer::div(
        html_writer::tag('strong', get_string('guideimageplaceholder', 'local_omeroembed')) . ' ' . s($description),
        '',
        [
            'style' => 'border:2px dashed #ccc; border-radius:4px; padding:1.5rem; margin:0.75rem 0 1.25rem; '
                . 'background:#f8f8f8; color:#666; font-style:italic; max-width:700px;',
        ]
    );
}

$authorurl = new moodle_url('/local/omeroembed/author.php', ['courseid' => $courseid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('guidetitle', 'local_omeroembed'));

echo html_writer::link($authorurl, get_string('backtoauthoring', 'local_omeroembed'), [
    'style' => 'display:inline-block; margin-bottom:1.25rem;',
]);

echo html_writer::tag('p', get_string('guideintro', 'local_omeroembed'), ['style' => 'max-width:700px;']);

echo html_writer::tag('h3', get_string('guidestep0heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep0body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_placeholder(get_string('guidestep0image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep1heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep1body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_placeholder(get_string('guidestep1image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep2heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep2body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_placeholder(get_string('guidestep2image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep3heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep3body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_placeholder(get_string('guidestep3imagea', 'local_omeroembed'));
echo local_omeroembed_guide_placeholder(get_string('guidestep3imageb', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep4heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep4body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_placeholder(get_string('guidestep4image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep5heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep5body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_placeholder(get_string('guidestep5image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guideworthknowingheading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guideworthknowingbody', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);

echo html_writer::tag('h3', get_string('guidehotspotheading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidehotspotbody', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_placeholder(get_string('guidehotspotimagea', 'local_omeroembed'));
echo local_omeroembed_guide_placeholder(get_string('guidehotspotimageb', 'local_omeroembed'));
echo local_omeroembed_guide_placeholder(get_string('guidehotspotimagec', 'local_omeroembed'));
echo html_writer::tag('p', get_string('guidehotspotqtypenote', 'local_omeroembed'), [
    'class' => 'text-muted', 'style' => 'max-width:700px;',
]);

echo $OUTPUT->footer();
