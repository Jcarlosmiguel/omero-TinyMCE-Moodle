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
 * Teacher-facing authoring tool: builds a ready-to-paste "slide + write-up" embed,
 * without the teacher ever hand-typing a proxy URL or HTML.
 *
 * SECURITY: gated on moodle/course:manageactivities, not just enrolment - this tool
 * generates embed markup (and briefly loads a live, subject-authenticated OMERO view),
 * students shouldn't reach it. proxy.php has its own independent enrolment gate anyway
 * for the actual slide-serving requests this page's preview iframe makes.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_omeroembed\omero_session;

$courseid = optional_param('courseid', 0, PARAM_INT);
$contextid = optional_param('contextid', 0, PARAM_INT);
$embedded = optional_param('embedded', false, PARAM_BOOL);

// The tiny_omeroembed TinyMCE plugin only knows the editor's own contextid
// (from editor_tiny/options' getContextId()), not a courseid directly - this
// page is what resolves one from the other, same as any content editor field
// would need to. Direct courseid links (e.g. the course nav entry added by
// lib.php) keep working unchanged.
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

$subject = optional_param('subject', '', PARAM_ALPHANUMEXT);
$images = optional_param('images', '', PARAM_SEQUENCE);
$dataset = optional_param('dataset', '', PARAM_INT);
// A blank "Dataset ID (optional)" field submits as dataset= (empty string), which
// PARAM_INT cleans to 0, not ''. OMERO dataset IDs are never 0, so normalise here
// rather than let a bogus dataset=0 get forwarded to the proxy/OMERO.
if ($dataset === 0) {
    $dataset = '';
}
$browsable = optional_param('browsable', 0, PARAM_BOOL);
$layout = optional_param('layout', 'slideleft', PARAM_ALPHA);
// Default matches the actual measured width of this Moodle instance's own
// content column (mod/page's #region-main, ~814px at a 1920px window) - NOT
// "100%", which only reflects how wide THIS tool's own page happens to be,
// not the much narrower column a Label/Page/Book will actually render the
// final embed inside. This is a max-width on the preview/output, not a fixed
// iframe width - see where it's used below.
$width = optional_param('width', '800px', PARAM_TEXT);
$height = optional_param('height', '500px', PARAM_TEXT);

$pageurlparams = ['courseid' => $courseid];
if ($embedded) {
    // Preserved across the setup form's own GET resubmission (picking a
    // different subject/image/dataset) so staying "embedded" - and therefore
    // popup layout + the postMessage handoff below - survives that reload too.
    $pageurlparams['embedded'] = 1;
}
$pageurl = new moodle_url('/local/omeroembed/author.php', $pageurlparams);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
// Embedded inside the tiny_omeroembed modal iframe - avoid rendering full
// course chrome/nav a second time inside the editor's own page. Matches
// tiny_media's own manage.php, which does the same for the same reason.
$PAGE->set_pagelayout($embedded ? 'popup' : 'course');
$PAGE->set_title(get_string('authortitle', 'local_omeroembed'));
$PAGE->set_heading($course->fullname);

$subjectkeys = omero_session::get_subject_keys();
$hasslide = ($subject !== '') && ($images !== '' || $dataset !== '');

$proxyurl = null;
$iframename = '';

if ($hasslide) {
    // courseid/subject travel as slash-arguments, not query params - see the
    // matching comment in proxy.php for why (OMERO's "host" mechanism needs this
    // script's own URL to carry only a single query param).
    $proxyparams = [];
    if ($images !== '') {
        $proxyparams['images'] = $images;
    }
    if ($dataset !== '') {
        $proxyparams['dataset'] = $dataset;
    }
    if ($browsable) {
        $proxyparams['browsable'] = 1;
    }
    $proxyurl = new moodle_url("/local/omeroembed/proxy.php/{$courseid}/{$subject}", $proxyparams);

    // Auto-generated, never teacher-typed - the whole point of choosing this tool
    // was avoiding exactly the kind of typo/clash a hand-picked iframe name risks.
    $idfragment = $images !== '' ? $images : $dataset;
    $iframename = 'omero-embed-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $idfragment);

    $imageonly = ($layout === 'imageonly');

    $jsconfig = [
        'iframeId' => 'omero-live-preview',
        'iframeName' => $iframename,
        'writeupId' => 'omero-writeup',
        'baseProxyUrl' => $proxyurl->out(false),
        'layout' => $layout,
        'imageOnly' => $imageonly,
        'maxWidth' => $width,
        'embedded' => $embedded,
        'strings' => [
            'previewnotready' => get_string('previewnotready', 'local_omeroembed'),
            'selecttextfirst' => get_string('selecttextfirst', 'local_omeroembed'),
            'selectinsidewriteup' => get_string('selectinsidewriteup', 'local_omeroembed'),
            'copied' => get_string('copied', 'local_omeroembed'),
            'openingviewset' => get_string('openingviewset', 'local_omeroembed'),
        ],
    ];
    $PAGE->requires->js(new moodle_url('/local/omeroembed/js/author.js'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('authortitle', 'local_omeroembed'));
echo html_writer::tag('p', get_string('authorintro', 'local_omeroembed'), ['class' => 'text-muted']);

// --- Setup form - always visible, pre-filled from the current GET params so
// reloading/bookmarking the page with a slide already loaded works naturally. ---
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $pageurl->out(false), 'id' => 'omero-setup-form']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
if ($embedded) {
    // A GET form submission is built entirely from its own <input> fields -
    // the action URL's own querystring (where $pageurl already carries
    // embedded=1) is discarded by the browser, not merged. Without this
    // hidden field, resubmitting the setup form (picking a different
    // subject/image) silently drops back to non-embedded mode: full course
    // layout instead of popup, and the copy-paste textarea instead of the
    // direct insert - exactly the bug this fixes.
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'embedded', 'value' => 1]);
}
echo html_writer::start_div('form-group row align-items-end', ['style' => 'gap: 1rem; margin-bottom: 1rem;']);

$subjectoptions = ['' => get_string('choosesubject', 'local_omeroembed')];
foreach ($subjectkeys as $key) {
    $subjectoptions[$key] = $key;
}
echo html_writer::tag('label', get_string('subjectlabel', 'local_omeroembed') . ' ' .
    html_writer::select($subjectoptions, 'subject', $subject, null));

echo html_writer::tag('label', get_string('imageidlabel', 'local_omeroembed') . ' ' .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'images', 'value' => $images,
        'inputmode' => 'numeric', 'pattern' => '[0-9]*', 'class' => 'form-control', 'style' => 'width:8em;']));

echo html_writer::tag('label', get_string('datasetidlabel', 'local_omeroembed') . ' ' .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'dataset', 'value' => $dataset,
        'inputmode' => 'numeric', 'pattern' => '[0-9]*', 'class' => 'form-control', 'style' => 'width:8em;']));

echo html_writer::tag('label',
    html_writer::checkbox('browsable', 1, (bool) $browsable, get_string('browsablelabel', 'local_omeroembed')));

echo html_writer::end_div();

echo html_writer::start_tag('fieldset', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag('legend', get_string('layoutlabel', 'local_omeroembed'), ['style' => 'font-size: 1rem;']);
foreach (['slideleft' => 'layoutslideleft', 'slideright' => 'layoutslideright', 'imageonly' => 'layoutimageonly'] as $value => $stringkey) {
    $attrs = ['type' => 'radio', 'name' => 'layout', 'value' => $value];
    if ($layout === $value) {
        $attrs['checked'] = 'checked';
    }
    echo html_writer::tag('label', html_writer::empty_tag('input', $attrs) . ' ' . get_string($stringkey, 'local_omeroembed'),
        ['style' => 'margin-right: 1rem;']);
}
echo html_writer::end_tag('fieldset');

echo html_writer::start_div('form-group row align-items-end', ['style' => 'gap: 1rem; margin-bottom: 1rem;']);
echo html_writer::tag('label', get_string('widthlabel', 'local_omeroembed') . ' ' .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'width', 'value' => $width,
        'class' => 'form-control', 'style' => 'width:8em;']));
echo html_writer::tag('label', get_string('heightlabel', 'local_omeroembed') . ' ' .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'height', 'value' => $height,
        'class' => 'form-control', 'style' => 'width:8em;']));
echo html_writer::end_div();
echo html_writer::tag('p', get_string('widthdesc', 'local_omeroembed'), ['class' => 'text-muted', 'style' => 'margin-top:-0.5rem;']);

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('loadslide', 'local_omeroembed'), 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if ($hasslide) {
    // Config is embedded as inline JSON in the page body - not via
    // $PAGE->requires->js_init_code() - because that API's output lands *after*
    // $PAGE->requires->js()'s <script src="author.js"> tag in the rendered page
    // (Moodle groups included files and raw init snippets into separate, fixed-order
    // sections regardless of which PHP call happens first), and js_init_code's
    // second-argument "on domready" wrapping adds a further delay on top of that -
    // so by the time author.js actually ran, window.OMEROEMBED_CONFIG didn't exist
    // yet and its `if (!config) return;` guard silently no-opped every listener.
    // A plain inline <script type="application/json"> tag, printed here as part of
    // the ordinary page content, is guaranteed to appear before the footer-injected
    // author.js tag regardless of Moodle's own JS section ordering.
    echo html_writer::tag('script', json_encode($jsconfig), [
        'type' => 'application/json', 'id' => 'omero-embed-config',
    ]);

    // Constrains the whole preview (and, via config.maxWidth, the generated embed
    // too) to roughly the real width a Label/Page/Book will actually render it at -
    // not "however wide this tool's own page happens to be". Without this, a view
    // chosen while looking at a much wider preview doesn't match how the embed
    // actually frames the slide once pasted somewhere narrower - see $width's own
    // comment above for where the default came from.
    echo html_writer::start_div('', ['id' => 'omero-preview-wrap', 'style' => 'max-width:' . s($width) . ';']);

    // Shared toolbar above both boxes - not just above the write-up - so the
    // iframe and write-up boxes themselves start at the same vertical position
    // and end up the same height, instead of the write-up box being pushed down
    // (and therefore taller overall) by a button sitting only above it. Buttons
    // sit side by side with distinct colours so they're easy to tell apart at a
    // glance - "insert a link" and "set the opening view" are easy to mix up
    // otherwise, since both work from the same live pan/zoom position.
    echo html_writer::start_div('', ['style' => 'display:flex; gap:0.5rem; margin-bottom:0.5rem;']);
    echo html_writer::tag('button', get_string('insertviewlink', 'local_omeroembed'), [
        'type' => 'button', 'id' => 'omero-insert-link-btn', 'class' => 'btn btn-secondary',
        'style' => $imageonly ? 'display:none;' : '',
    ]);
    echo html_writer::tag('button', get_string('setopeningview', 'local_omeroembed'), [
        'type' => 'button', 'id' => 'omero-set-opening-btn', 'class' => 'btn btn-info',
    ]);
    echo html_writer::end_div();

    // Both the write-up box and the iframe are *always* rendered here, regardless
    // of layout - switching layout is a client-side-only, non-destructive toggle
    // (see js/author.js's applyLayout()), so a teacher who already wrote text and
    // then tries a different layout doesn't lose it to a full page reload. Only
    // the *initial* display/flex-direction below reflects $layout, to avoid a
    // flash of the wrong arrangement before author.js finishes attaching.
    echo html_writer::start_div('', [
        'id' => 'omero-split-pane',
        'style' => 'display:flex; align-items:stretch; gap:1rem;' . ($layout === 'slideright' ? ' flex-direction:row-reverse;' : ''),
    ]);

    $iframe = html_writer::tag('iframe', '', [
        'id' => 'omero-live-preview',
        'name' => $iframename,
        'src' => $proxyurl->out(false),
        'style' => 'width:100%; height:' . s($height) . '; border:1px solid #ccc; box-sizing:border-box; display:block;',
        'allowfullscreen' => 'allowfullscreen',
    ]);
    echo html_writer::div($iframe, '', [
        'id' => 'omero-iframe-wrap', 'style' => $imageonly ? 'flex:1 1 100%;' : 'flex:1; min-width:0;',
    ]);

    echo html_writer::div('', '', [
        'id' => 'omero-writeup',
        'contenteditable' => 'true',
        'style' => 'flex:1; min-width:0; height:' . s($height) . '; border:1px solid #ccc; padding:0.5rem;'
            . ' box-sizing:border-box; overflow:auto;' . ($imageonly ? ' display:none;' : ''),
    ]);

    echo html_writer::end_div();

    echo html_writer::tag('button', get_string($embedded ? 'insertintopage' : 'generateembed', 'local_omeroembed'), [
        'type' => 'button', 'id' => 'omero-generate-btn', 'class' => 'btn btn-primary', 'style' => 'margin-top:1rem;',
    ]);

    if (!$embedded) {
        // In embedded mode (opened from the tiny_omeroembed TinyMCE plugin),
        // the generated HTML is postMessage'd straight to the parent editor
        // and the modal closes - there's nothing here for the teacher to
        // copy/paste, so this whole box stays hidden. See js/author.js's
        // generateEmbed() for the embedded-vs-standalone branch.
        echo html_writer::start_div('', ['id' => 'omero-output-wrap', 'style' => 'display:none; margin-top:1rem;']);
        echo html_writer::tag('textarea', '', [
            'id' => 'omero-output', 'rows' => 8, 'readonly' => 'readonly',
            'style' => 'width:100%; font-family:monospace; font-size:0.85rem;',
        ]);
        echo html_writer::tag('button', get_string('copyembed', 'local_omeroembed'), [
            'type' => 'button', 'id' => 'omero-copy-btn', 'class' => 'btn btn-secondary', 'style' => 'margin-top:0.5rem;',
        ]);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
