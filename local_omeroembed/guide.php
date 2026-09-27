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

$courseid = optional_param('courseid', 0, PARAM_INT);
$contextid = optional_param('contextid', 0, PARAM_INT);

// Same dual acceptance as author.php's own (see that file's comment on
// this same pair of lines) - the TinyMCE Help tab this link is reached
// from only ever knows the editor's own contextid (editor_tiny/options'
// getContextId()), never a courseid directly.
if (!$courseid && $contextid) {
    $context = context::instance_by_id($contextid);
    $coursecontext = $context->get_course_context();
    $courseid = $coursecontext->instanceid;
}
if (!$courseid) {
    throw new moodle_exception('missingparam', 'error', '', 'courseid');
}

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
    $diskpath = $CFG->dirroot . '/local/omeroembed/pix/guide/' . $filename;
    // Cache-busted on the file's own mtime, not the plugin version - these
    // images can change (e.g. a slide swapped for a non-human one) without
    // a version.php bump, and a browser that already cached the old file
    // under this same URL would otherwise keep showing it indefinitely.
    // Real, reported case: a swapped-out image was still visible to a
    // browser that had loaded this page before the swap.
    $version = is_readable($diskpath) ? filemtime($diskpath) : 0;
    $html = html_writer::empty_tag('img', [
        'src' => $CFG->wwwroot . '/local/omeroembed/pix/guide/' . $filename . '?v=' . $version,
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

// Not a numbered step - genuinely optional, skippable entirely, same as
// "A few things worth knowing"/"Hotspot questions" further down getting a
// plain heading rather than a step number. Placed here to match this
// section's own real position on the page (collapsed, directly below the
// layout choice, above the write-up box) - see author.php's own
// $viewerdisplaycustomised comment for why it starts collapsed.
echo html_writer::tag('h3', get_string('guideviewingoptionsheading', 'local_omeroembed'));
echo html_writer::div(
    format_text(get_string('guideviewingoptionsbody', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image('step2b-viewing-options.png', get_string('guideviewingoptionsimage', 'local_omeroembed'));

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

// Explicit id, not relying on Moodle's own heading-anchor generation (it
// doesn't add one at all here) - author.php's/the qtype edit forms' own
// "how do I draw this?" help links (see those files' own comments) jump
// straight to this heading rather than just the top of the page.
echo html_writer::tag('h4', get_string('guidehotspotdrawingheading', 'local_omeroembed'), [
    'id' => 'drawing-and-adjusting-a-region-step-by-step',
]);
echo html_writer::div(
    format_text(get_string('guidehotspotdrawingbody1', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image(
    'hotspot-e-toolbar-default.jpg',
    get_string('guidehotspotdrawingimagee', 'local_omeroembed'),
    get_string('guidehotspotdrawingimageecaption', 'local_omeroembed')
);
echo local_omeroembed_guide_image(
    'hotspot-f-ellipse-mode-active.jpg',
    get_string('guidehotspotdrawingimagef', 'local_omeroembed'),
    get_string('guidehotspotdrawingimagefcaption', 'local_omeroembed')
);
echo local_omeroembed_guide_image(
    'hotspot-g-rectangle-mode-active.jpg',
    get_string('guidehotspotdrawingimageg', 'local_omeroembed'),
    get_string('guidehotspotdrawingimagegcaption', 'local_omeroembed')
);
echo local_omeroembed_guide_image(
    'hotspot-h-drawing-rectangle.jpg',
    get_string('guidehotspotdrawingimageh', 'local_omeroembed'),
    get_string('guidehotspotdrawingimagehcaption', 'local_omeroembed')
);
echo html_writer::div(
    format_text(get_string('guidehotspotdrawingbody2', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image(
    'hotspot-i-lock-square.jpg',
    get_string('guidehotspotdrawingimagei', 'local_omeroembed'),
    get_string('guidehotspotdrawingimageicaption', 'local_omeroembed')
);
echo html_writer::div(
    format_text(get_string('guidehotspotdrawingbody3', 'local_omeroembed'), FORMAT_HTML),
    '',
    ['style' => 'max-width:700px;']
);
echo local_omeroembed_guide_image(
    'hotspot-j-selected-rotate-resize.jpg',
    get_string('guidehotspotdrawingimagej', 'local_omeroembed'),
    get_string('guidehotspotdrawingimagejcaption', 'local_omeroembed')
);

echo html_writer::tag('p', get_string('guidehotspotqtypenote', 'local_omeroembed'), [
    'class' => 'text-muted', 'style' => 'max-width:700px;',
]);

echo $OUTPUT->footer();
