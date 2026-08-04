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

use local_omeroembed\subject_repository;
use local_omeroembed\annotations_repository;

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

// Per-embed overrides of the site-wide overlay/annotation defaults (Site
// administration > Plugins > Local plugins > OMERO slide embed) - null
// means "not yet chosen" (first visit to this form), in which case the
// checkbox below pre-checks from the site default; once anything is
// submitted, that value round-trips through the setup form's own
// GET-resubmit exactly like $browsable already does. Always written into
// $proxyparams as an explicit 1/0 further down (never omitted) - see
// proxy.php's resolve_overlay_setting() for why that matters.
// Rotate isn't included - always hidden, not a real choice (see
// proxy.php's inject_overlay_hide_css() for why).
$overlaysettings = [];
foreach (['hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom', 'hidenavbar', 'showomerorois', 'enableannotations', 'enablehotspot', 'enablehotspotmulti'] as $key) {
    $submitted = optional_param($key, null, PARAM_BOOL);
    $overlaysettings[$key] = $submitted ?? (bool) get_config('local_omeroembed', $key);
}

// Same round-trip idea as $overlaysettings above, but for a *set* rather
// than independent booleans - colours_submitted (a plain hidden field,
// not per-checkbox) is what distinguishes "first visit, nothing chosen
// yet" from "resubmitted with everything intentionally unchecked",
// since a set of 8 individual null-vs-bool checks can't tell those apart
// on its own the way a single boolean's null can.
$coloursformsubmitted = optional_param('colours_submitted', 0, PARAM_BOOL);
if ($coloursformsubmitted) {
    $overlaycolours = [];
    foreach (annotations_repository::COLOUR_PALETTE as $hex) {
        $overlaycolours[$hex] = (bool) optional_param('colour_' . strtolower(ltrim($hex, '#')), 0, PARAM_BOOL);
    }
} else {
    $sitedefaultcolours = annotations_repository::parse_colours((string) get_config('local_omeroembed', 'annotationcolours'));
    $overlaycolours = array_fill_keys(annotations_repository::COLOUR_PALETTE, false);
    foreach ($sitedefaultcolours as $hex) {
        $overlaycolours[$hex] = true;
    }
}

$layout = optional_param('layout', 'slideleft', PARAM_ALPHA);
// Default matches the actual measured width of this Moodle instance's own
// content column (mod/page's #region-main, ~814px at a 1920px window) - NOT
// "100%", which only reflects how wide THIS tool's own page happens to be,
// not the much narrower column a Label/Page/Book will actually render the
// final embed inside. This is a max-width on the preview/output, not a fixed
// iframe width - see where it's used below.
$width = optional_param('width', '800px', PARAM_TEXT);
$height = optional_param('height', '500px', PARAM_TEXT);
// Set only when re-opening an *existing* embed for editing (tiny_omeroembed's
// ui.js reads it back from the wrapper's own data-omero-annotate-id - see
// that file's readExistingEmbed()) - forwarded into $jsconfig so js/author.js's
// getOrMintAnnotateId() reuses it instead of minting a fresh token, which
// would otherwise orphan that embed's existing student annotations on every
// teacher edit. Blank on a fresh insert; author.js mints one itself in that case.
$annotateid = optional_param('annotateid', '', PARAM_ALPHANUMEXT);

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

$mysubjects = subject_repository::get_for_user($USER->id);
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
    // Unlike images/dataset/browsable above, these 9 are *always* written
    // explicitly (never omitted when false) - proxy.php's
    // resolve_overlay_setting() needs to tell "this embed explicitly
    // wants X off" apart from "no opinion, use the site default", and
    // only an explicit '0' can say the former.
    foreach ($overlaysettings as $key => $value) {
        $proxyparams[$key] = $value ? '1' : '0';
    }
    // Always baked in explicitly too - a comma-separated hex list,
    // re-validated/capped through parse_colours() rather than trusted
    // as-is (a teacher's own browser is no more trusted here than
    // manage.php's equivalent submission is).
    $selectedcolours = array_keys(array_filter($overlaycolours));
    $proxyparams['annotationcolours'] = implode(',', annotations_repository::parse_colours(implode(',', $selectedcolours)));
    $proxyurl = new moodle_url("/local/omeroembed/proxy.php/{$courseid}/{$subject}", $proxyparams);

    // Auto-generated, never teacher-typed - the whole point of choosing this tool
    // was avoiding exactly the kind of typo/clash a hand-picked iframe name risks.
    $idfragment = $images !== '' ? $images : $dataset;
    $iframename = 'omero-embed-' . preg_replace('/[^A-Za-z0-9]/', '', (string) $idfragment);

    $imageonly = ($layout === 'imageonly');
    $textbelow = ($layout === 'textbelow');

    // Initial split-pane arrangement, so the page doesn't flash the wrong
    // layout before author.js's applyLayout() attaches (same reasoning as
    // the existing style branches below - these three need to stay in sync
    // with applyLayout()'s own client-side logic).
    $panedirection = '';
    if ($layout === 'slideright') {
        $panedirection = 'row-reverse';
    } else if ($textbelow) {
        $panedirection = 'column';
    }

    if ($imageonly) {
        $iframewrapstyle = 'flex:1 1 100%;';
    } else if ($textbelow) {
        $iframewrapstyle = 'flex:0 0 auto; width:100%;';
    } else {
        $iframewrapstyle = 'flex:1; min-width:0;';
    }

    if ($imageonly) {
        $writeupextrastyle = ' display:none;';
    } else if ($textbelow) {
        // A short question, not a full-height write-up pane - overrides the
        // shared height:Xpx set below (later wins, same inline style).
        $writeupextrastyle = ' flex:0 0 auto; width:100%; height:auto; min-height:3em;';
    } else {
        $writeupextrastyle = '';
    }

    $jsconfig = [
        'iframeId' => 'omero-live-preview',
        'iframeName' => $iframename,
        'writeupId' => 'omero-writeup',
        'baseProxyUrl' => $proxyurl->out(false),
        'layout' => $layout,
        'imageOnly' => $imageonly,
        'maxWidth' => $width,
        'embedded' => $embedded,
        'embedAnnotateId' => $annotateid,
        'strings' => [
            'previewnotready' => get_string('previewnotready', 'local_omeroembed'),
            'selecttextfirst' => get_string('selecttextfirst', 'local_omeroembed'),
            'selectinsidewriteup' => get_string('selectinsidewriteup', 'local_omeroembed'),
            'clicklinkfirst' => get_string('clicklinkfirst', 'local_omeroembed'),
            'copied' => get_string('copied', 'local_omeroembed'),
            'openingviewset' => get_string('openingviewset', 'local_omeroembed'),
            'resetview' => get_string('resetview', 'local_omeroembed'),
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

// Values are local_omeroembed_subjects.id (a plain int, cast to string by
// html_writer::select()) - not the display name. Only this teacher's own
// entries, never another's (see subject_repository::get_for_user()'s own
// docblock) - names are chosen per-teacher and aren't unique across
// teachers, so the name alone was never enough to identify a connection
// once ownership was introduced.
$subjectoptions = ['' => get_string('choosesubject', 'local_omeroembed')];
foreach ($mysubjects as $mysubject) {
    $subjectoptions[$mysubject->id] = $mysubject->name;
}
echo html_writer::tag('label', get_string('subjectlabel', 'local_omeroembed') . ' ' .
    html_writer::select($subjectoptions, 'subject', $subject, null));

$mysubjectsurl = new moodle_url('/local/omeroembed/mysubjects.php', ['courseid' => $courseid]);
echo html_writer::link($mysubjectsurl, get_string('managesubjectslink', 'local_omeroembed'), ['style' => 'margin-left:0.5rem;']);

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
$layoutoptions = [
    'slideleft' => 'layoutslideleft',
    'slideright' => 'layoutslideright',
    'imageonly' => 'layoutimageonly',
    'textbelow' => 'layouttextbelow',
];
foreach ($layoutoptions as $value => $stringkey) {
    $attrs = ['type' => 'radio', 'name' => 'layout', 'value' => $value];
    if ($layout === $value) {
        $attrs['checked'] = 'checked';
    }
    echo html_writer::tag('label', html_writer::empty_tag('input', $attrs) . ' ' . get_string($stringkey, 'local_omeroembed'),
        ['style' => 'margin-right: 1rem;']);
}
echo html_writer::end_tag('fieldset');

// Per-embed overrides of the site-wide overlay/annotation defaults - see
// $overlaysettings' own comment above for how these round-trip, and
// proxy.php's resolve_overlay_setting() for how they're applied. Reuses
// the exact same lang strings settings.php's own admin checkboxes use
// (hideoverview, hideintensity, etc.) - one label, two places it's shown.
//
// html_writer::checkbox() emits a *plain* checkbox with no hidden
// fallback (confirmed against core's own html_writer.php - unlike some
// other Moodle checkbox widgets, this one doesn't add one) - an
// unchecked box is simply absent from the submitted request, which
// $overlaysettings' own optional_param($key, null, ...) would then read
// as "not chosen yet" and silently fall back to the site default,
// undoing an explicit uncheck on the very next form resubmit. Each
// checkbox is preceded by its own hidden input of the same name/value=0
// (standard hidden-before-checkbox technique - both share a name, the
// browser submits both in document order, PHP's superglobals keep
// whichever came last: the hidden "0" if unchecked, the checkbox's own
// "1" if checked) so the field is always present either way.
echo html_writer::start_tag('fieldset', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag('legend', get_string('overlaysheading', 'local_omeroembed'), ['style' => 'font-size: 1rem;']);
foreach (['hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom', 'hidenavbar', 'showomerorois', 'enableannotations', 'enablehotspot', 'enablehotspotmulti'] as $key) {
    $style = ($key === 'enableannotations' || $key === 'enablehotspot' || $key === 'enablehotspotmulti')
            ? 'display:block; margin-top:0.5rem;' : 'display:block;';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => 0]);
    echo html_writer::tag('label',
        html_writer::checkbox($key, 1, $overlaysettings[$key], get_string($key, 'local_omeroembed')),
        ['style' => $style]);
}
echo html_writer::end_tag('fieldset');

// Same round-trip technique as the overlay fieldset above (hidden
// companion per checkbox, since html_writer::checkbox() has none of its
// own), plus one extra hidden field (colours_submitted) so a genuinely
// empty selection can be told apart from "form never touched yet" - see
// $overlaycolours' own comment above.
echo html_writer::start_tag('fieldset', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag('legend', get_string('annotationcolours', 'local_omeroembed'), ['style' => 'font-size: 1rem;']);
echo html_writer::tag('p', get_string('annotationcolours_desc', 'local_omeroembed', annotations_repository::MAX_COLOURS),
    ['class' => 'text-muted', 'style' => 'margin-top:0;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'colours_submitted', 'value' => 1]);
echo html_writer::start_div('', ['id' => 'omero-annotation-colours', 'style' => 'display:flex; flex-wrap:wrap; gap:0.75rem;']);
foreach (annotations_repository::get_colour_choices() as $hex => $label) {
    $paramname = 'colour_' . strtolower(ltrim($hex, '#'));
    $swatch = html_writer::span('', '', [
        'style' => 'display:inline-block; width:1rem; height:1rem; border-radius:50%; '
            . 'background:' . $hex . '; margin-right:0.35rem; vertical-align:middle; border:1px solid #ccc;',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $paramname, 'value' => 0]);
    // Empty label passed to checkbox() - it would otherwise wrap its own
    // nested <label>, and this whole thing is already one label itself
    // (swatch + checkbox + text), which would double up the click target.
    // data-hex carries the real mixed-case hex (the name attribute only has
    // the lowercased slug, e.g. colour_e6194b) - author.js's generateEmbed()
    // reads this back directly rather than reconstructing it from the name,
    // since COLOUR_PALETTE/parse_colours() are case-sensitive and a
    // reconstructed lowercase hex would silently fail to match.
    echo html_writer::tag('label',
        $swatch . html_writer::checkbox($paramname, 1, $overlaycolours[$hex], '', ['data-hex' => $hex]) . ' ' . s($label),
        ['style' => 'display:flex; align-items:center; white-space:nowrap;']);
}
echo html_writer::end_div();
echo html_writer::end_tag('fieldset');
// parse_colours() (see its own docblock) already caps a submission at
// MAX_COLOURS server-side, but silently, by truncating in palette order -
// without this, a teacher could tick 6 boxes here, save, and have no idea
// which 4 actually survived. Disabling the remaining unchecked boxes once
// the cap is reached stops a 5th selection from ever being possible in the
// first place, rather than accepting it and quietly dropping it later.
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
        'style' => $imageonly ? 'display:none;' : '', // Stays visible for textbelow - it's still a rich write-up box.
    ]);
    echo html_writer::tag('button', get_string('removeviewlink', 'local_omeroembed'), [
        'type' => 'button', 'id' => 'omero-remove-link-btn', 'class' => 'btn btn-secondary',
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
        'style' => 'display:flex; align-items:stretch; gap:1rem;' . ($panedirection ? " flex-direction:{$panedirection};" : ''),
    ]);

    // A separate clone, not $proxyurl itself - config.baseProxyUrl (built from
    // $proxyurl below) is reused verbatim by author.js's buildViewUrl()/
    // generateEmbed() as the base for the *final* embed's src, so marking
    // $proxyurl itself as "authoring" here would leak that marker into every
    // generated embed too. Only this one live-preview iframe's own src should
    // ever carry it - see proxy.php's $authoring for what it's used for.
    $previewurl = new moodle_url($proxyurl);
    $previewurl->param('authoring', 1);

    $iframe = html_writer::tag('iframe', '', [
        'id' => 'omero-live-preview',
        'name' => $iframename,
        'src' => $previewurl->out(false),
        'style' => 'width:100%; height:' . s($height) . '; border:1px solid #ccc; box-sizing:border-box; display:block;',
        'allowfullscreen' => 'allowfullscreen',
    ]);
    echo html_writer::div($iframe, '', [
        'id' => 'omero-iframe-wrap', 'style' => $iframewrapstyle,
    ]);

    echo html_writer::div('', '', [
        'id' => 'omero-writeup',
        'contenteditable' => 'true',
        'style' => 'flex:1; min-width:0; height:' . s($height) . '; border:1px solid #ccc; padding:0.5rem;'
            . ' box-sizing:border-box; overflow:auto;' . $writeupextrastyle,
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

    // Only meaningful once an embedid actually exists - a fresh embed's
    // token isn't minted until generateEmbed() runs client-side (see
    // getOrMintAnnotateId() in js/author.js), same constraint the student
    // annotations feature already lives with. $annotateid is only set when
    // re-opening an *existing* embed for editing (see author.php's own
    // param comment above).
    //
    // Tracking on/off, gather-hours, delete, export all live entirely on
    // heatmap.php now (confirmed with the user) - this is just a link
    // there, carrying $proxyurl as a one-time "sourceurl" so heatmap.php
    // can seed itself the first time it's ever visited for this embed
    // (see that file's own $sourceurlparam comment for why it needs this -
    // nothing else records which OMERO subject/image an embedid points to).
    if ($annotateid !== '' && has_capability('local/omeroembed:viewheatmap', $context)) {
        $heatmapurl = new moodle_url('/local/omeroembed/heatmap.php', [
            'courseid' => $courseid,
            'embedid' => $annotateid,
            'sourceurl' => $proxyurl->out(false),
        ]);
        echo html_writer::link($heatmapurl, get_string('viewheatmaplink', 'local_omeroembed'), [
            'target' => '_blank', 'class' => 'btn btn-secondary', 'style' => 'display:inline-block; margin-top:1rem;',
        ]);
    }

    echo html_writer::end_div();
}

echo $OUTPUT->footer();
