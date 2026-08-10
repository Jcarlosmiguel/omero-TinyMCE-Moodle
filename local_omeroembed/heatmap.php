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
 * Teacher-facing view of the heatmap feature: where students' viewports
 * have spent time on one embed placement, for however long the teacher
 * chooses to track it via the Start/Stop tracking button below (moved off
 * author.php's tracking panel a while back, confirmed with the user:
 * author.php only ever links here). All of this page's own POST handling
 * (start/stop, delete) follows manage.php's own inline plain-form
 * convention, not ajax.php/JS - this page is plain server-rendered PHP
 * throughout.
 *
 * The actual density render happens inside an iframe pointing back at
 * proxy.php in its heatmap=1 mode (see that file's $heatmap and
 * inject_heatmap_view_script()) - reusing the exact same reverse-proxy
 * plumbing as the real embed and the authoring tool's own live preview,
 * just in a third mode. Reachable either from author.php's own "View
 * heatmap" link, or directly from the real embed on a course page (see
 * proxy.php's inject_teacher_heatmap_link()) - either path can bootstrap
 * this page from scratch (see $sourceurlparam below), so neither is a
 * hard prerequisite for the other.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_omeroembed\tracking_repository;
use local_omeroembed\heatmap_frame_repository;

$courseid = required_param('courseid', PARAM_INT);
$embedid = required_param('embedid', PARAM_ALPHANUMEXT);

// Only ever present on the "View heatmap" link author.php builds (see that
// file's own comment) - lets this page learn which OMERO subject/image the
// embed points to the first time it's ever visited, since nothing else
// records that against an embedid (see tracking_repository's own comment
// on the sourceurl field). Harmless noise on every subsequent visit - only
// actually used below when there's no stored sourceurl yet, or the teacher
// re-saves the settings form while it happens to be present.
$sourceurlparam = optional_param('sourceurl', '', PARAM_URL);

$savetracking = optional_param('savetracking', false, PARAM_BOOL);

// Two-step delete, same convention as core's own delete confirmations
// (e.g. course/delete.php) - a plain link reaches the confirm prompt
// below; only the prompt's own "Continue" button (confirm=1 + sesskey)
// actually deletes anything, so bookmarking/refreshing this URL with
// delete=1 alone can never trigger it by accident.
$delete = optional_param('delete', false, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/omeroembed:viewheatmap', $context);
// SECURITY: require_capability() above only checks the *declared*
// courseid - everything below looks up by embedid alone, so without this,
// local/omeroembed:viewheatmap in ANY course would let a teacher view,
// control, or delete another course's tracking data. See
// tracking_repository::verify_course()'s own docblock for the full
// reasoning. A brand-new embedid (nothing recorded yet) passes silently -
// the first-visit bootstrap flow below is what creates that first row.
tracking_repository::verify_course($embedid, $courseid);

$pageurl = new moodle_url('/local/omeroembed/heatmap.php', ['courseid' => $courseid, 'embedid' => $embedid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('course');
$PAGE->set_title(get_string('heatmaptitle', 'local_omeroembed'));
$PAGE->set_heading($course->fullname);

$settings = tracking_repository::get_settings($embedid);

// SECURITY: both checks here matter, not just one. PARAM_URL only
// validates that $sourceurlparam looks like a URL - it does NOT restrict
// it to this site (that's PARAM_LOCALURL, deliberately not used here
// since a genuine sourceurl is always this site's own proxy.php, never a
// bare local path) - so without the str_starts_with() check, any external
// https://... value would be accepted and stored verbatim, later rendered
// as this page's own iframe src on every future visit. confirm_sesskey()
// stops the write from being triggerable by a bare crafted link at all
// (a plain GET, no form) - proxy.php's inject_teacher_heatmap_link() has
// carried a real sesskey since this fix; a link an attacker constructs
// themselves cannot know the victim's current one. Confirmed exploitable
// before this fix: an embedid the site had never seen before + an
// external sourceurl, opened by anyone with :viewheatmap, planted a
// phishing iframe permanently on this page.
$proxybaseprefix = (new moodle_url('/local/omeroembed/proxy.php'))->out(false);
if (
    $settings['sourceurl'] === '' && $sourceurlparam !== '' && confirm_sesskey()
        && str_starts_with($sourceurlparam, $proxybaseprefix)
) {
    // First-ever visit for this embed - seed sourceurl so the page has
    // something to build an iframe from, but deliberately leave tracking
    // switched off until the teacher explicitly starts it below, same
    // default the old author.php panel used.
    tracking_repository::set_settings($courseid, $embedid, false, tracking_repository::DEFAULT_GATHERMINUTES, $sourceurlparam);
    // Strips the (fairly long) sourceurl param back off the URL rather
    // than leaving it there permanently.
    redirect($pageurl);
}

if ($savetracking && confirm_sesskey()) {
    $enabled = (bool) optional_param('enabled', 0, PARAM_BOOL);
    $gatherminutes = optional_param('gatherminutes', tracking_repository::DEFAULT_GATHERMINUTES, PARAM_INT);
    if ($gatherminutes < 1) {
        throw new \moodle_exception('invalidgatherminutes', 'local_omeroembed', '', $gatherminutes);
    }
    // A freshly-arrived sourceurl (teacher re-generated the embed with a
    // different image or OMERO connection, then followed the embed's own
    // updated "View heatmap" link) wins over whatever was already stored -
    // otherwise just keep what's there. SECURITY: same str_starts_with()
    // origin check as the first-visit branch above, for the same reason -
    // PARAM_URL alone doesn't restrict this to the site's own proxy.php.
    $sourceurl = ($sourceurlparam !== '' && str_starts_with($sourceurlparam, $proxybaseprefix))
        ? $sourceurlparam
        : $settings['sourceurl'];

    tracking_repository::set_settings($courseid, $embedid, $enabled, $gatherminutes, $sourceurl);
    $message = $enabled ? get_string('trackingstarted', 'local_omeroembed') : get_string('trackingstopped', 'local_omeroembed');
    redirect($pageurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($delete && $confirm && confirm_sesskey()) {
    // The delete link is only ever *shown* once tracking has ended (see
    // this page's own button-rendering section below) - re-checked here
    // too, since a shown-or-not UI element is never real enforcement on
    // its own (a teacher could reach this URL directly). Refuses outright
    // rather than deleting live-in-progress data out from under an active
    // session.
    if (tracking_repository::is_active($embedid)) {
        redirect(
            $pageurl,
            get_string('cannotdeletewhiletracking', 'local_omeroembed'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Also turns tracking off (see delete_samples()'s own docblock for
    // why) - confirmed with the user, so a forgotten still-enabled embed
    // doesn't quietly start gathering fresh data again right after being
    // cleared.
    $deletedcount = tracking_repository::delete_samples($embedid);
    redirect(
        $pageurl,
        get_string('datadeleted', 'local_omeroembed', $deletedcount),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// PERFORMANCE: nothing past this point writes to $_SESSION - see
// proxy.php's own write_close() comment. Deliberately placed *after* all
// the POST-handling above, not before it: redirect()'s $message argument
// works by writing to $SESSION->notifications for the next page load
// (see \core\notification::add()) - closing the session before that
// write happens means it's silently discarded and the confirmation (or
// refusal - see the "cannot delete while tracking" redirect just above)
// never appears on the page the user lands on. Confirmed as a real
// regression the earlier, too-early placement introduced.
\core\session\manager::write_close();

if ($delete) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('heatmaptitle', 'local_omeroembed'));
    $continueurl = new moodle_url($pageurl, ['delete' => 1, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->confirm(get_string('confirmdeletedata', 'local_omeroembed'), $continueurl, $pageurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('heatmaptitle', 'local_omeroembed'));

if ($settings['sourceurl'] === '') {
    // Never bootstrapped (bookmarked/typed directly before any embed ever
    // linked here with a sourceurl) - nothing to build a settings form or
    // iframe from yet. Not an error, just nothing to show.
    echo $OUTPUT->notification(get_string('heatmapnodata', 'local_omeroembed'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$currentlytracking = $settings['enabled'] && $settings['trackinguntil'] && $settings['trackinguntil'] > time();

echo html_writer::start_div('', [
    'id' => 'omero-tracking-panel',
    'style' => 'margin-bottom:1rem; padding:0.75rem; border:1px solid #ccc; border-radius:4px; max-width:1000px;',
]);
echo html_writer::tag('h5', get_string('trackingheading', 'local_omeroembed'), ['style' => 'margin-top:0;']);
echo html_writer::tag('p', get_string('trackingheading_desc', 'local_omeroembed'), ['class' => 'text-muted']);

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savetracking', 'value' => 1]);
if ($sourceurlparam !== '') {
    // Carries a freshly-arrived sourceurl (see this file's own comment on
    // $sourceurlparam) through this form's POST, same as the other hidden
    // fields above - without this, the $savetracking block below never
    // actually sees it, since $pageurl (this form's action) never includes
    // it. That silently defeated the "freshly-arrived sourceurl wins" logic
    // there entirely - this is the missing wiring for it.
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sourceurl', 'value' => $sourceurlparam]);
}

echo html_writer::start_div('', ['style' => 'display:flex; align-items:center; gap:1rem; flex-wrap:wrap;']);

if ($currentlytracking) {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enabled', 'value' => 0]);
    $minutesleft = (int) ceil(($settings['trackinguntil'] - time()) / MINSECS);
    echo html_writer::tag(
        'span',
        get_string('trackingremaining', 'local_omeroembed', $minutesleft),
        ['class' => 'text-muted']
    );
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('stoptracking', 'local_omeroembed'), 'class' => 'btn btn-outline-danger',
    ]);
} else {
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'enabled', 'value' => 1]);
    echo html_writer::tag('label', get_string('gatherminuteslabel', 'local_omeroembed') . ' ' .
        html_writer::empty_tag('input', [
            // The step attribute is a spinner nicety (nudges by 30), not an enforced
            // constraint - HTML5's step validation would otherwise refuse
            // to submit a manually-typed value that isn't an exact
            // multiple of it (e.g. 70), which is a real value a teacher
            // might legitimately want to type directly.
            'type' => 'number', 'name' => 'gatherminutes', 'min' => '30', 'step' => '1',
            'value' => max(30, $settings['gatherminutes']),
            'class' => 'form-control', 'style' => 'width:6em; display:inline-block;',
        ]));
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('starttracking', 'local_omeroembed'), 'class' => 'btn btn-primary',
    ]);
}

echo html_writer::end_div();
echo html_writer::end_tag('form');
echo html_writer::end_div();

// A clone, not the stored URL mutated in place - same reasoning as
// author.php's own $previewurl: this "heatmap" marker is only ever valid
// on this one iframe's src, never accidentally reused anywhere else.
// sourceurl is the pre-embedid base proxy.php URL author.php passed in its
// link (see this file's own $sourceurlparam comment) - embedid is only
// ever appended by generateEmbed() when building the real embed's own
// iframe src, never stored as part of it - so it has to be added back
// here explicitly, or inject_heatmap_view_script() would see an empty
// embedid and the resulting page would have nothing to query samples for.
$heatmapsrcurl = new moodle_url($settings['sourceurl']);
$heatmapsrcurl->param('heatmap', 1);
$heatmapsrcurl->param('embedid', $embedid);

if (!$settings['enabled']) {
    echo $OUTPUT->notification(get_string('heatmaptrackingoff', 'local_omeroembed'), 'warning');
} else if ($settings['trackinguntil'] && $settings['trackinguntil'] < time()) {
    echo $OUTPUT->notification(get_string(
        'heatmapgatheringended',
        'local_omeroembed',
        userdate($settings['trackinguntil'])
    ), 'info');
} else if ($settings['trackinguntil']) {
    echo html_writer::tag('p', get_string(
        'heatmapgatheringuntil',
        'local_omeroembed',
        userdate($settings['trackinguntil'])
    ), ['class' => 'text-muted']);
}

echo html_writer::tag('iframe', '', [
    'src' => $heatmapsrcurl->out(false),
    'style' => 'width:100%; max-width:1000px; height:600px; border:1px solid #ccc; box-sizing:border-box; display:block;',
    'allowfullscreen' => 'allowfullscreen',
]);

// Download data/video and delete are all "wrap up this session" actions -
// only enabled once tracking has actually ended (confirmed with the user:
// avoids the confusion of downloading/deleting data mid-session while it's
// still being gathered) *and*, for data/delete specifically, only once
// there's actually something to download/delete - a session that ended
// (time ran out, or the teacher stopped it) without ever gathering a
// single sample has nothing for either button to meaningfully do.
// Rendered as plain disabled-looking spans rather than hidden outright in
// every case, so a teacher can see what's coming without being able to
// click it yet.
echo html_writer::start_div('', ['style' => 'display:flex; gap:0.75rem; margin-top:1rem; max-width:1000px; flex-wrap:wrap;']);

$samplecount = tracking_repository::count_samples($embedid);
$framecount = heatmap_frame_repository::count_frames_for_embed($embedid);
$sessionended = !$currentlytracking;

$enddisabled = ['class' => 'btn btn-secondary disabled', 'aria-disabled' => 'true', 'tabindex' => '-1',
    'style' => 'pointer-events:none; opacity:0.5;'];
$deletedisabled = ['class' => 'btn btn-outline-danger disabled', 'aria-disabled' => 'true', 'tabindex' => '-1',
    'style' => 'pointer-events:none; opacity:0.5;'];

if ($sessionended && $samplecount > 0) {
    $exporturl = new moodle_url('/local/omeroembed/export.php', ['courseid' => $courseid, 'embedid' => $embedid]);
    echo html_writer::link($exporturl, get_string('downloaddata', 'local_omeroembed'), ['class' => 'btn btn-secondary']);
} else {
    echo html_writer::tag('span', get_string('downloaddata', 'local_omeroembed'), $enddisabled);
}

if ($framecount > 0) {
    if ($sessionended) {
        $videourl = new moodle_url('/local/omeroembed/video.php', ['courseid' => $courseid, 'embedid' => $embedid]);
        echo html_writer::link($videourl, get_string('downloadvideo', 'local_omeroembed'), ['class' => 'btn btn-secondary']);
    } else {
        echo html_writer::tag('span', get_string('downloadvideo', 'local_omeroembed'), $enddisabled);
    }
}

if ($sessionended && $samplecount > 0) {
    // A plain GET link to the confirm step above, not the delete itself -
    // see this file's own two-step handling near the top for why that's
    // safe to leave as a link rather than a form.
    $deleteurl = new moodle_url($pageurl, ['delete' => 1]);
    echo html_writer::link($deleteurl, get_string('deletedata', 'local_omeroembed'), ['class' => 'btn btn-outline-danger']);
} else {
    echo html_writer::tag('span', get_string('deletedata', 'local_omeroembed'), $deletedisabled);
}

echo html_writer::end_div();

if ($currentlytracking) {
    echo html_writer::tag(
        'p',
        get_string('availableafterend', 'local_omeroembed'),
        ['class' => 'text-muted', 'style' => 'margin-top:0.5rem; max-width:1000px;']
    );
} else if ($samplecount === 0) {
    echo html_writer::tag(
        'p',
        get_string('nodatarecorded', 'local_omeroembed'),
        ['class' => 'text-muted', 'style' => 'margin-top:0.5rem; max-width:1000px;']
    );
}

// Lets a teacher tell whether there's a real video worth downloading yet -
// frames are only captured every 5 minutes (classes/task/capture_heatmap_frames.php)
// while tracking is active, so a brand-new session may genuinely have none yet.
echo html_writer::tag(
    'p',
    get_string('framecount', 'local_omeroembed', $framecount),
    ['class' => 'text-muted', 'style' => 'margin-top:0.5rem; max-width:1000px;']
);

// Surfaces the *already-enforced* site-wide retention setting (Site
// administration > Plugins > Local plugins > OMERO slide embed > "Data
// retention") - deliberately worded as a rolling window, not a single
// fixed date, since the real purge task (classes/task/purge_view_samples.php)
// deletes row-by-row by age, not this whole embed's data at once on one
// cutoff.
$retentionperiod = (int) get_config('local_omeroembed', 'retentionperiod');
if ($retentionperiod > 0) {
    echo html_writer::tag(
        'p',
        get_string('retentionreminder', 'local_omeroembed', format_time($retentionperiod)),
        ['class' => 'text-muted', 'style' => 'margin-top:0.75rem; max-width:1000px;']
    );
}

echo $OUTPUT->footer();
