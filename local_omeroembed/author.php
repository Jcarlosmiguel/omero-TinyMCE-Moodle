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
// PERFORMANCE: nothing below writes to $_SESSION. This page's own live
// preview iframe hits proxy.php on the same session concurrently with
// this request - see proxy.php's own write_close() comment for why
// holding the lock any longer than necessary matters.
\core\session\manager::write_close();

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

// Whether the option controls below (viewer display, annotations/hotspot,
// colours) were actually interactive - not disabled - on the page that
// produced whatever request this is. Real, load-bearing distinction, not
// a nicety: a disabled form control is excluded from submission entirely
// (standard browser behaviour), so the very first "Load slide" click
// (submitted from a page where these were disabled, per $layoutdisabledattrs
// further down) would otherwise silently lose every checkbox's true state -
// only each one's non-disabled hidden "0" companion would survive, forcing
// every overlay/colour setting to read back as "off" regardless of the
// real site default. Rendered as a hidden field once $hasslide is known
// (see the "Load slide" section below), read back here on the next
// request to tell "genuinely submitted, trust it" apart from "was inert,
// this submission's values for these fields are meaningless."
$optionswereunlocked = (bool) optional_param('options_unlocked', 0, PARAM_BOOL);

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
foreach (
    [
    'hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline',
    'hidezoom', 'hidenavbar', 'showomerorois', 'enableannotations',
    ] as $key
) {
    $submitted = $optionswereunlocked ? optional_param($key, null, PARAM_BOOL) : null;
    $overlaysettings[$key] = $submitted ?? (bool) get_config('local_omeroembed', $key);
}

// The enablehotspot/enablehotspotmulti settings are presented as a single dropdown
// ('', 'single', 'multi') rather than two independent checkboxes -
// confirmed with the user: two checkboxes could reach an ambiguous
// "both checked" state that meant nothing real (proxy.php's own dispatch
// had to pick a winner for it), and needed JS on both sides just to keep
// them mutually exclusive. A dropdown can't represent "both" at all, and
// correctly pre-selects whichever was already saved when editing an
// existing embed. Still stored/consumed everywhere else (proxy.php,
// ajax.php, the DB) as the same two independent booleans as before -
// this is a presentation-layer change only, not a data model change.
$hotspotmode = optional_param('hotspotmode', null, PARAM_ALPHA);
if ($hotspotmode === null) {
    // No hotspotmode param - either a genuinely first visit to this form,
    // or (just as likely) tiny_omeroembed's ui.js re-opening an existing
    // embed for editing, which round-trips whatever that embed's own
    // stored iframe src already has as separate enablehotspot/
    // enablehotspotmulti GET params (the pre-dropdown format every embed
    // generated before this change - and still every embed generated
    // *after* it, since generateEmbed() only ever writes the two
    // underlying params, never a hotspotmode of its own) - see
    // OVERLAY_PARAM_KEYS in tiny_omeroembed/amd/src/ui.js. Checking those
    // explicitly first is what makes "comes back with its previous
    // settings" actually true when editing an existing hotspot embed,
    // rather than silently resetting it to the site default.
    $legacyhotspot = optional_param('enablehotspot', null, PARAM_BOOL);
    $legacyhotspotmulti = optional_param('enablehotspotmulti', null, PARAM_BOOL);
    if ($legacyhotspot !== null || $legacyhotspotmulti !== null) {
        if ($legacyhotspotmulti) {
            $hotspotmode = 'multi';
        } else if ($legacyhotspot) {
            $hotspotmode = 'single';
        } else {
            $hotspotmode = '';
        }
    } else {
        // Genuinely nothing submitted at all - fall back to the site-wide
        // defaults, same as every other overlay setting above.
        if ((bool) get_config('local_omeroembed', 'enablehotspotmulti')) {
            $hotspotmode = 'multi';
        } else if ((bool) get_config('local_omeroembed', 'enablehotspot')) {
            $hotspotmode = 'single';
        } else {
            $hotspotmode = '';
        }
    }
}
$overlaysettings['enablehotspot'] = ($hotspotmode === 'single');
$overlaysettings['enablehotspotmulti'] = ($hotspotmode === 'multi');

// Same round-trip idea as $overlaysettings above, but for a *set* rather
// than independent booleans - colours_submitted (a plain hidden field,
// not per-checkbox) is what distinguishes "first visit, nothing chosen
// yet" from "resubmitted with everything intentionally unchecked",
// since a set of 8 individual null-vs-bool checks can't tell those apart
// on its own the way a single boolean's null can. Also gated on
// $optionswereunlocked, same reasoning as $overlaysettings above - a
// "submitted" request whose swatches were actually disabled at the time
// carries no real information about what was checked, only the hidden
// "0" companions each swatch also has.
$coloursformsubmitted = optional_param('colours_submitted', 0, PARAM_BOOL);
if ($coloursformsubmitted && $optionswereunlocked) {
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

// Both the viewer-display checkboxes and the annotation colours are tucked
// behind a collapsed-by-default toggle further down (see their own
// html_writer::start_div(['class' => 'collapse ...']) calls) - most
// teachers never touch either, so hiding them by default is a real win for
// how much of this page fits on screen without scrolling. But this form is
// reused for *editing* an already-generated embed too (reloading with the
// embed's own stored params - see this file's own "reloading/bookmarking"
// comment near $pageurl), not just building a brand new one - if a
// previous teacher already moved either setting away from the site
// default, defaulting to collapsed would hide that fact entirely, not just
// declutter it. So: collapsed only when everything in that section still
// matches the site default; expanded automatically the moment anything in
// it doesn't, so an existing customisation is never silently out of view.
$viewerdisplaycustomised = false;
foreach (['hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom', 'hidenavbar', 'showomerorois'] as $key) {
    if ($overlaysettings[$key] !== (bool) get_config('local_omeroembed', $key)) {
        $viewerdisplaycustomised = true;
        break;
    }
}
$sitedefaultcolourset = annotations_repository::parse_colours((string) get_config('local_omeroembed', 'annotationcolours'));
sort($sitedefaultcolourset);
$currentcolourset = array_keys(array_filter($overlaycolours));
sort($currentcolourset);
$colourscustomised = ($currentcolourset !== $sitedefaultcolourset);

$layout = optional_param('layout', 'slideleft', PARAM_ALPHA);
// Site-wide defaults (Site administration > Plugins > Local plugins >
// OMERO slide embed > Viewing options - local_omeroembed/defaultwidth(height)),
// same "just the starting point, teacher can still override per embed"
// relationship as every overlay checkbox already has to its own site
// setting. '800px'/'500px' here are only the last-resort fallback if the
// config value itself is somehow missing or malformed - the real default
// used to be these two literals hardcoded directly here with no admin
// control at all.
//
// The site default's own original reasoning, still true: it matches the
// actual measured width of this Moodle instance's own content column
// (mod/page's #region-main, ~814px at a 1920px window) - NOT "100%", which
// only reflects how wide THIS tool's own page happens to be, not the much
// narrower column a Label/Page/Book will actually render the final embed
// inside. This is a max-width on the preview/output, not a fixed iframe
// width - see where it's used below.
// PARAM_TEXT alone doesn't restrict format - both values end up
// string-concatenated into the final generated embed HTML client-side
// (js/author.js's generateEmbed(), which separately HTML-escapes them as
// the actual fix for that - see its own escapeHtmlAttr() docblock).
// Restricting to genuine CSS-length syntax here too, defense in depth:
// closes the hole even if some future code path forgets to escape, and
// rejects nonsensical values outright rather than accepting them and
// producing a visibly broken embed. Applied to the config-sourced default
// too, not just the submitted override - admin_setting_configtext doesn't
// itself enforce CSS-length syntax, so a malformed site setting shouldn't
// be able to reach generateEmbed() unvalidated either.
$cssunitpattern = '/^\d+(\.\d+)?(px|%|em|rem|vh|vw)$/';
$defaultwidth = (string) get_config('local_omeroembed', 'defaultwidth');
if (!preg_match($cssunitpattern, $defaultwidth)) {
    $defaultwidth = '800px';
}
$width = optional_param('width', $defaultwidth, PARAM_TEXT);
if (!preg_match($cssunitpattern, $width)) {
    $width = $defaultwidth;
}
$defaultheight = (string) get_config('local_omeroembed', 'defaultheight');
if (!preg_match($cssunitpattern, $defaultheight)) {
    $defaultheight = '500px';
}
$height = optional_param('height', $defaultheight, PARAM_TEXT);
if (!preg_match($cssunitpattern, $height)) {
    $height = $defaultheight;
}
// Folded into the same $viewerdisplaycustomised flag computed above (that
// comment's own reasoning applies identically here, now that width/height
// live in the same collapsed-by-default section) - a size override is
// exactly the kind of thing re-editing an existing embed shouldn't hide
// behind a collapsed toggle either.
if ($width !== $defaultwidth || $height !== $defaultheight) {
    $viewerdisplaycustomised = true;
}
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

// Real, reported gap: clicking "Load slide" with a required field still
// missing used to just reload this same page with nothing visibly
// different - no error, no highlighted field, nothing to tell a teacher
// why the preview never appeared. $coloursformsubmitted (computed above)
// already distinguishes "form genuinely submitted" from "fresh page load,
// nothing filled in yet" - reused here for the same reason it exists at
// all, not colour-specific despite the name: it's a plain hidden field
// present on every submission regardless of what else was filled in, so
// it's already exactly the right submitted-vs-untouched signal this needs
// too, without adding a second one that would have to be kept in sync.
if ($coloursformsubmitted && !$hasslide) {
    $missingmessage = ($subject === '')
        ? get_string('needsubject', 'local_omeroembed')
        : get_string('needimageordataset', 'local_omeroembed');
    \core\notification::add($missingmessage, \core\output\notification::NOTIFY_ERROR);
}

$proxyurl = null;
$iframename = '';

if ($hasslide) {
    // The courseid/subject travel as slash-arguments, not query params - see the
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
            'needwriteuptext' => get_string('needwriteuptext', 'local_omeroembed'),
            'writeupplaceholder' => get_string('writeupplaceholder', 'local_omeroembed'),
            'questiontextplaceholder' => get_string('questiontextplaceholder', 'local_omeroembed'),
        ],
    ];
    $PAGE->requires->js(new moodle_url('/local/omeroembed/js/author.js'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('authortitle', 'local_omeroembed'));
echo html_writer::tag('p', get_string('authorintro', 'local_omeroembed'), ['class' => 'text-muted']);

// Setup form - always visible, pre-filled from the current GET params so
// reloading/bookmarking the page with a slide already loaded works naturally.
//
// Section order below is deliberate, not just "load first because it looks
// tidier": Load (this teacher's own action, always available) -> Layout
// (how the embed is arranged - meaningless with no slide loaded to arrange,
// see $hasslide's disabled-until-loaded handling below) -> Viewer display
// (purely cosmetic on-image controls - safe to set any time, since they're
// only ever read at "Generate embed HTML" time, not live) -> Interactive
// features (annotations + hotspot, grouped together because they're the
// same category of choice - "what can a student DO with this embed" - and
// mutually interact, see the enableannotations/hotspotmode comment below;
// annotation colours live inside this section too, as a sub-detail of
// annotations rather than an unrelated top-level fieldset) -> Size (the
// generated embed's own dimensions, unrelated to anything above it).
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
// Records whether the option controls further down are about to render
// enabled or disabled (i.e. $hasslide, already known by this point) - read
// back as $optionswereunlocked on the next request. See that variable's
// own comment near the top of this file for why this exists: without it,
// the very first "Load slide" submission (made while these were disabled)
// would silently corrupt every overlay/colour setting back to "off".
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'options_unlocked', 'value' => $hasslide ? 1 : 0]);

// Load section - first, not because "the button has to go somewhere" but
// because it's the one thing here that's a genuine precondition for
// everything below it: Layout and the hotspot mode dropdown only make
// sense once there's an actual slide loaded to arrange/draw on (see their
// own disabled-until-loaded handling below - this was a real, reported bug:
// selecting either one before loading a slide either silently did nothing
// or threw a JS error, because both try to live-update the preview iframe,
// which doesn't exist in the page at all until $hasslide is true).
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

echo html_writer::tag(
    'label',
    html_writer::checkbox('browsable', 1, (bool) $browsable, get_string('browsablelabel', 'local_omeroembed'))
);

// The actual submit button now lives here, right next to what it submits,
// instead of at the bottom of the whole form below every option - a
// teacher's very first action on this page is loading a slide, so that's
// what's immediately in front of them, not something to scroll past three
// fieldsets to find. It's still one GET form either way (see the class
// docblock above): wherever the submit button sits, clicking it submits
// every field currently set, including layout/overlay/hotspot/colour/size
// choices made below it.
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('loadslide', 'local_omeroembed'), 'class' => 'btn btn-primary',
]);

echo html_writer::end_div();

// Disabled (with an explanatory title) until a slide is actually loaded -
// see this section's own opening comment for why: this is the one control
// besides the hotspot dropdown below that tries to live-update the preview
// iframe on change, which simply doesn't exist yet at this point.
$layoutdisabledattrs = $hasslide ? [] : [
    'disabled' => 'disabled',
    'title' => get_string('optionsneedslide', 'local_omeroembed'),
];
echo html_writer::start_tag('fieldset', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag('legend', get_string('layoutlabel', 'local_omeroembed'), ['style' => 'font-size: 1rem;']);
if (!$hasslide) {
    echo html_writer::tag('p', get_string('optionsneedslide', 'local_omeroembed'),
        ['class' => 'text-muted', 'style' => 'margin:0 0 0.5rem;']);
}
$layoutoptions = [
    'slideleft' => 'layoutslideleft',
    'slideright' => 'layoutslideright',
    'imageonly' => 'layoutimageonly',
    'textbelow' => 'layouttextbelow',
];
foreach ($layoutoptions as $value => $stringkey) {
    $attrs = ['type' => 'radio', 'name' => 'layout', 'value' => $value] + $layoutdisabledattrs;
    if ($layout === $value) {
        $attrs['checked'] = 'checked';
    }
    echo html_writer::tag(
        'label',
        html_writer::empty_tag('input', $attrs) . ' ' . get_string($stringkey, 'local_omeroembed'),
        ['style' => 'margin-right: 1rem;']
    );
}

// Lives here, not in Interactive features below, because it only makes
// sense for one specific layout: a hotspot region is meant to be answered
// against a short instruction ("click the...") the same way a quiz
// question would be, which is exactly what the text-below layout is - a
// short prompt under the image, not a full write-up beside it. Hidden
// (not just disabled) unless text-below is the current layout, both here
// at initial render (avoids a flash of the wrong state before author.js
// attaches, same reasoning as $panedirection/$iframewrapstyle above) and
// via applyLayout() in js/author.js on every subsequent layout change.
// currentHotspotMode() in that same file is the one place this rule is
// actually enforced, not just hidden from view - see its own comment for
// why hiding the control alone wouldn't have been enough.
echo html_writer::start_div('', [
    'id' => 'omero-hotspot-mode-wrap',
    'style' => 'margin-top:0.75rem;' . ($layout === 'textbelow' ? '' : ' display:none;'),
]);
echo html_writer::tag(
    'label',
    get_string('hotspotmodelabel', 'local_omeroembed'),
    ['style' => 'display:block;', 'for' => 'id_hotspotmode']
);
echo html_writer::select(
    [
        '' => get_string('hotspotmodenone', 'local_omeroembed'),
        'single' => get_string('hotspotmodesingle', 'local_omeroembed'),
        'multi' => get_string('hotspotmodemulti', 'local_omeroembed'),
    ],
    'hotspotmode',
    $hotspotmode,
    false,
    ['id' => 'id_hotspotmode'] + $layoutdisabledattrs
);
echo html_writer::end_div();
echo html_writer::end_tag('fieldset');

// Purely cosmetic, on-image viewer controls - unlike Layout/hotspot mode
// above/below, these never touch the live preview iframe on change, so
// there's no correctness reason they couldn't stay usable before a slide
// loads. Disabled anyway (same $layoutdisabledattrs as Layout above) -
// not a bug fix, a deliberate workflow choice: keeping every option
// inert until there's actually a slide to apply it to makes "load, then
// configure" the only path through this page, rather than a correctness-
// only subset of controls being disabled and the rest not, which reads as
// arbitrary to a teacher who doesn't know which controls are iframe-live
// and which aren't.
//
// Laid out as a wrapping row rather than one-per-line (the previous
// arrangement) purely to save vertical space - same flex-wrap technique
// the annotation colour swatches below already use, not a new pattern.
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
// Shared by both collapse toggles below (this one and the annotation
// colours one further down) - Bootstrap's own collapse JS (already loaded
// site-wide with this theme, nothing extra needed here) toggles a
// `collapsed` class on the trigger element itself, not just the collapsed
// content, so a plain CSS rule off that class is enough to rotate the
// chevron on open/close without writing any JS of our own.
// Also carries the write-up placeholder rule (#omero-writeup, defined
// further below) - a contenteditable div has no native placeholder
// attribute the way <input>/<textarea> do, so :empty::before reading
// data-placeholder (set server-side here and kept in sync by
// applyLayout() in js/author.js on every layout change) is the standard
// substitute. pointer-events:none so the placeholder text itself is never
// what actually receives a click - clicking anywhere in the (empty) box
// still focuses the real contenteditable element underneath it.
echo html_writer::tag('style', '.omero-collapse-chevron { display:inline-block; transition: transform 0.15s ease; }'
    . ' [data-toggle="collapse"].collapsed .omero-collapse-chevron { transform: rotate(-90deg); }'
    . ' #omero-writeup:empty::before { content: attr(data-placeholder); color: #6a737b; font-style: italic; pointer-events: none; }');

// Collapsed by default (see $viewerdisplaycustomised's own comment above
// for why not always, and for the edit-an-existing-embed case it protects
// against) - most teachers never touch any of these, so the checkbox grid
// itself is hidden behind a plain Bootstrap collapse (native to this
// theme, already loaded site-wide - no extra JS needed here) rather than
// permanently taking up vertical space. The legend doubles as the toggle
// button rather than sitting next to a separate one, so there's exactly
// one obvious thing to click.
echo html_writer::start_tag('fieldset', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag(
    'legend',
    html_writer::tag(
        'button',
        get_string('viewingoptionsheading', 'local_omeroembed') . ' ' .
            html_writer::span('&#9662;', 'omero-collapse-chevron'),
        [
            'type' => 'button',
            'class' => 'btn btn-link p-0' . ($viewerdisplaycustomised ? '' : ' collapsed'),
            'style' => 'font-size:1rem; font-weight:bold; text-decoration:none; color:inherit;',
            'data-toggle' => 'collapse',
            'data-target' => '#omero-viewerdisplay-collapse',
            'aria-expanded' => $viewerdisplaycustomised ? 'true' : 'false',
            'aria-controls' => 'omero-viewerdisplay-collapse',
        ]
    ),
    ['style' => 'font-size: 1rem;']
);
echo html_writer::start_div('collapse' . ($viewerdisplaycustomised ? ' show' : ''), ['id' => 'omero-viewerdisplay-collapse']);
echo html_writer::start_div('', ['style' => 'display:flex; flex-wrap:wrap; gap:0.4rem 1.5rem; margin-top:0.5rem;']);
foreach (['hideoverview', 'hideintensity', 'hidefullscreen', 'hidescaleline', 'hidezoom', 'hidenavbar', 'showomerorois'] as $key) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => 0]);
    echo html_writer::tag(
        'label',
        html_writer::checkbox($key, 1, $overlaysettings[$key], get_string($key, 'local_omeroembed'), $layoutdisabledattrs),
        ['style' => 'white-space:nowrap;']
    );
}
echo html_writer::end_div();

// Moved in from a standalone row outside any fieldset - genuinely the
// same kind of "most teachers never touch this" setting as the checkboxes
// above, now that both have a real site-wide default to start from (see
// $defaultwidth/$defaultheight above) rather than these being the only
// way to set a size at all. Disabled until a slide loads too, same
// $layoutdisabledattrs as everything else here - unlike the checkboxes,
// these are plain text inputs with no hidden-companion trick to protect,
// so disabling them carries none of the submission-value-loss risk that
// needed $optionswereunlocked for the checkboxes (a disabled text input
// is simply absent from the request, which optional_param() already
// treats as "use the default" - exactly the right fallback here).
echo html_writer::start_div('form-group row align-items-end', ['style' => 'gap: 1rem; margin-top:0.75rem;']);
echo html_writer::tag('label', get_string('widthlabel', 'local_omeroembed') . ' ' .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'width', 'value' => $width,
        'class' => 'form-control', 'style' => 'width:8em;'] + $layoutdisabledattrs));
echo html_writer::tag('label', get_string('heightlabel', 'local_omeroembed') . ' ' .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'height', 'value' => $height,
        'class' => 'form-control', 'style' => 'width:8em;'] + $layoutdisabledattrs));
echo html_writer::end_div();
echo html_writer::tag('p', get_string('widthdesc', 'local_omeroembed'), [
    'class' => 'text-muted', 'style' => 'margin:0.25rem 0 0;',
]);

echo html_writer::end_div();
echo html_writer::end_tag('fieldset');

// "What can a student DO with this embed" - just annotations now (colours
// live here too, as a sub-detail rather than an unrelated top-level
// fieldset of their own). Hotspot mode moved to the Layout fieldset above
// (only meaningful for the text-below layout - see its own comment
// there), but the two still interact exactly as before: js/author.js
// greys out (and unchecks) this checkbox whenever a genuinely *active*
// hotspot mode is selected (active meaning both a real mode chosen *and*
// text-below still the current layout - see currentHotspotMode()'s own
// comment), since proxy.php itself always overrides annotations off while
// a hotspot mode is active anyway (see its own
// $hotspotoverridesstudentview comment) - this just makes the form honest
// about that rather than letting a teacher believe both are active at
// once.
echo html_writer::start_tag('fieldset', ['style' => 'margin-bottom: 1rem;']);
echo html_writer::tag('legend', get_string('interactivefeaturesheading', 'local_omeroembed'), ['style' => 'font-size: 1rem;']);

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enableannotations', 'value' => 0]);
echo html_writer::tag(
    'label',
    html_writer::checkbox('enableannotations', 1, $overlaysettings['enableannotations'],
        get_string('enableannotations', 'local_omeroembed'), $layoutdisabledattrs),
    ['style' => 'display:block;']
);

// Same round-trip technique as the overlay fieldset above (hidden
// companion per checkbox, since html_writer::checkbox() has none of its
// own), plus one extra hidden field (colours_submitted) so a genuinely
// empty selection can be told apart from "form never touched yet" - see
// $overlaycolours' own comment above. Disabled before a slide loads, same
// $layoutdisabledattrs/workflow reasoning as the display fieldset above -
// see the colour-cap script further below for the one place that
// reasoning has a real consequence beyond styling. Collapsed by default too, same
// $colourscustomised reasoning as $viewerdisplaycustomised above - the
// swatches themselves are the second-biggest single space cost on this
// page after the display checkboxes.
echo html_writer::tag(
    'button',
    get_string('annotationcolours', 'local_omeroembed') . ' ' .
        html_writer::span('&#9662;', 'omero-collapse-chevron'),
    [
        'type' => 'button',
        'class' => 'btn btn-link p-0' . ($colourscustomised ? '' : ' collapsed'),
        'style' => 'font-weight:bold; margin:0.75rem 0 0.25rem; text-decoration:none; color:inherit; display:block;',
        'data-toggle' => 'collapse',
        'data-target' => '#omero-colours-collapse',
        'aria-expanded' => $colourscustomised ? 'true' : 'false',
        'aria-controls' => 'omero-colours-collapse',
    ]
);
echo html_writer::start_div('collapse' . ($colourscustomised ? ' show' : ''), ['id' => 'omero-colours-collapse']);
echo html_writer::tag(
    'p',
    get_string('annotationcolours_desc', 'local_omeroembed', annotations_repository::MAX_COLOURS),
    ['class' => 'text-muted', 'style' => 'margin-top:0;']
);
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
    echo html_writer::tag(
        'label',
        $swatch . html_writer::checkbox($paramname, 1, $overlaycolours[$hex], '', ['data-hex' => $hex] + $layoutdisabledattrs) . ' ' . s($label),
        ['style' => 'display:flex; align-items:center; white-space:nowrap;']
    );
}
echo html_writer::end_div();
echo html_writer::end_div();
// The parse_colours() function (see its own docblock) already caps a submission at
// MAX_COLOURS server-side, but silently, by truncating in palette order -
// without this, a teacher could tick 6 boxes here, save, and have no idea
// which 4 actually survived. Disabling the remaining unchecked boxes once
// the cap is reached stops a 5th selection from ever being possible in the
// first place, rather than accepting it and quietly dropping it later.
//
// Only emitted once a slide is loaded - $layoutdisabledattrs already
// disabled every one of these boxes server-side otherwise (see above), and
// this script's own refresh() unconditionally sets .disabled based purely
// on the cap count with no awareness of that - running it before a slide
// loads would silently re-enable every box the moment DOMContentLoaded
// fires, undoing the server-rendered disabled state before a teacher ever
// touches anything.
if ($hasslide) {
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
}

echo html_writer::end_tag('fieldset');

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

    // Placeholder text (:empty::before, see the shared <style> block near
    // the Layout fieldset above) so an empty write-up box doesn't just look
    // like a blank rectangle - different wording for text-below (a short
    // quiz-style question) vs slide-left/slide-right (a genuine write-up),
    // matching whichever this teacher's *current* layout is. image-only
    // never shows this box at all ($writeupextrastyle above), so it never
    // needs a placeholder - left at the write-up wording as a harmless
    // default rather than adding a third, pointless string for a state
    // that's never visible.
    echo html_writer::div('', '', [
        'id' => 'omero-writeup',
        'contenteditable' => 'true',
        'data-placeholder' => ($layout === 'textbelow')
            ? get_string('questiontextplaceholder', 'local_omeroembed')
            : get_string('writeupplaceholder', 'local_omeroembed'),
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
