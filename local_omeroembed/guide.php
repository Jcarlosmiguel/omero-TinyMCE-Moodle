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
 * Screenshots live in pix/guide/*.png, captured against a real course
 * (Proxy Test Course) with real, non-human histology slides - see
 * USAGE.md for the same images reused on the GitHub-rendered side.
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
 * A step screenshot from pix/guide/, with the step's own description as
 * its alt text, and an optional short visible caption underneath.
 *
 * The caption is only needed where a step has more than one image in a
 * row with nothing distinguishing them in the surrounding text (alt text
 * alone isn't visible unless you hover or the image fails to load) - see
 * this file's own step 3 and hotspot sections, and USAGE.md's identical
 * captions on the GitHub-rendered side (kept in sync deliberately).
 *
 * @param string $filename Basename under pix/guide/ (e.g. 'step0-more-dropdown.png').
 * @param string $alt Alt text - reuses the same string that used to describe the placeholder.
 * @param string $caption Optional short visible caption below the image, or '' for none.
 * @return string
 */
function local_omeroembed_guide_image(string $filename, string $alt, string $caption = ''): string {
    global $CFG;
    $html = html_writer::empty_tag('img', [
        'src' => $CFG->wwwroot . '/local/omeroembed/pix/guide/' . $filename,
        'alt' => $alt,
        'style' => 'max-width:700px; width:100%; height:auto; border-radius:4px; margin:0.75rem 0 0.25rem; display:block;',
    ]);
    if ($caption !== '') {
        $html .= html_writer::tag('p', $caption, [
            'class' => 'text-muted', 'style' => 'max-width:700px; margin:0 0 1.25rem; font-style:italic;',
        ]);
    }
    return $html;
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
echo local_omeroembed_guide_image('step0-more-dropdown.png', get_string('guidestep0image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep1heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep1body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image('step1-load-slide.png', get_string('guidestep1image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep2heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep2body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image('step2-layout.png', get_string('guidestep2image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep3heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep3body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image(
    'step3a-select-text.png',
    get_string('guidestep3imagea', 'local_omeroembed'),
    get_string('guidestep3imageacaption', 'local_omeroembed')
);
echo local_omeroembed_guide_image(
    'step3b-view-link-inserted.png',
    get_string('guidestep3imageb', 'local_omeroembed'),
    get_string('guidestep3imagebcaption', 'local_omeroembed')
);

echo html_writer::tag('h3', get_string('guidestep4heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep4body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image('step4-opening-view.png', get_string('guidestep4image', 'local_omeroembed'));

echo html_writer::tag('h3', get_string('guidestep5heading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guidestep5body', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image('step5-generate-copy.png', get_string('guidestep5image', 'local_omeroembed'));

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
echo local_omeroembed_guide_image(
    'hotspot-a-layout.png',
    get_string('guidehotspotimagea', 'local_omeroembed'),
    get_string('guidehotspotimageacaption', 'local_omeroembed')
);
echo local_omeroembed_guide_image(
    'hotspot-b-drawing.png',
    get_string('guidehotspotimageb', 'local_omeroembed'),
    get_string('guidehotspotimagebcaption', 'local_omeroembed')
);
echo local_omeroembed_guide_image(
    'hotspot-c-feedback.png',
    get_string('guidehotspotimagec', 'local_omeroembed'),
    get_string('guidehotspotimageccaption', 'local_omeroembed')
);
echo local_omeroembed_guide_image(
    'hotspot-d-rotate-resize.png',
    get_string('guidehotspotimaged', 'local_omeroembed'),
    get_string('guidehotspotimagedcaption', 'local_omeroembed')
);
echo html_writer::tag('p', get_string('guidehotspotqtypenote', 'local_omeroembed'), [
    'class' => 'text-muted', 'style' => 'max-width:700px;',
]);

echo $OUTPUT->footer();
