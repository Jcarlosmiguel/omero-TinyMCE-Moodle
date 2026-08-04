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
 * Admin settings.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_omeroembed\annotations_repository;

// Site administration entry point for manage.php, reachable by anyone with
// local/omeroembed:managesettings (Manager by default) - deliberately
// unconditional, NOT inside the $hassiteconfig block below. Core's own
// admin/settings/plugins.php calls every local plugin's load_settings()
// unconditionally regardless of $hassiteconfig ("their settings may be in
// any part of the settings tree and may be visible not only for
// administrators" - see that file's own comment), and admin_externalpage's
// own check_access() genuinely respects whatever capability is passed as
// its 4th constructor argument, not just moodle/site:config - the same
// mechanism core itself uses (e.g. contentbank's customfields page).
// Attached to the 'modules' ("Plugins") category rather than 'localplugins'
// - that subcategory is itself only created when $hassiteconfig is true,
// so attaching there would silently fail to appear for a Manager who lacks
// full site-config. This was previously a primary-navigation-bar link
// (classes/hook_callbacks.php) - moved here since Moodle's own convention
// is to keep primary nav sparse and put plugin settings in the admin tree;
// this capability check achieves the same "reachable without site:config"
// goal without needing primary nav for it.
$ADMIN->add('modules', new admin_externalpage(
    'local_omeroembed_manage',
    get_string('managetitle', 'local_omeroembed'),
    (new moodle_url('/local/omeroembed/manage.php'))->out(false),
    'local/omeroembed:managesettings'
));

// Local plugins don't get an automatic settings page the way e.g. filter plugins
// do - $settings has to be created and added to the tree explicitly here, gated
// on $hassiteconfig (whether the current user has moodle/site:config), matching
// the same pattern core's own admin/settings/plugins.php uses for every local
// plugin's load_settings() call.
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_omeroembed', get_string('pluginname', 'local_omeroembed'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_omeroembed/omerobaseurl',
        get_string('omerobaseurl', 'local_omeroembed'),
        get_string('omerobaseurl_desc', 'local_omeroembed'),
        'https://your-omero-server.example.org',
        PARAM_URL
    ));

    // OMERO service-account credentials moved from here to teacher-owned,
    // encrypted-at-rest rows each teacher manages themselves via
    // mysubjects.php (see db/install.xml's own comment on
    // local_omeroembed_subjects) - no admin setting for this any more.

    // iviewer's own UI controls, individually hideable - see proxy.php's
    // inject_overlay_hide_css() for how these are actually applied (a single
    // combined CSS override injected into the proxied /iviewer/ response,
    // covering both the authoring tool's live preview and the final
    // student-facing embed, since both load through this same proxy). Class
    // names are OpenLayers' own standard control classes, found by inspecting
    // the live viewer in a browser - not anything OMERO-specific.
    //
    // These are now just the *defaults* - author.php lets a teacher
    // override any of them per embed (see that file's own overlaysheading
    // fieldset and proxy.php's resolve_overlay_setting()). These settings
    // still apply as-is to every embed generated before that existed, and
    // seed the pre-checked state a teacher sees when building a new one.
    $settings->add(new admin_setting_heading(
        'local_omeroembed/overlaysheading',
        get_string('overlaysheading', 'local_omeroembed'),
        get_string('overlaysheading_desc', 'local_omeroembed')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/hideoverview',
        get_string('hideoverview', 'local_omeroembed'),
        get_string('hideoverview_desc', 'local_omeroembed'),
        1
    ));
    // No "hide rotate control" setting - the rotate control is always
    // hidden, unconditionally, in proxy.php's inject_overlay_hide_css() -
    // there's no way to actually control slide rotation from this embed
    // anyway, so a toggle for it would just be confusing, never a real
    // choice.
    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/hideintensity',
        get_string('hideintensity', 'local_omeroembed'),
        get_string('hideintensity_desc', 'local_omeroembed'),
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/hidefullscreen',
        get_string('hidefullscreen', 'local_omeroembed'),
        get_string('hidefullscreen_desc', 'local_omeroembed'),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/hidescaleline',
        get_string('hidescaleline', 'local_omeroembed'),
        get_string('hidescaleline_desc', 'local_omeroembed'),
        0
    ));
    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/hidezoom',
        get_string('hidezoom', 'local_omeroembed'),
        get_string('hidezoom_desc', 'local_omeroembed'),
        0
    ));
    // On by default, unlike the others above - this bar's own links point
    // outside the locked-down embed and don't work correctly here, so
    // there's no real reason a new embed would want it visible.
    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/hidenavbar',
        get_string('hidenavbar', 'local_omeroembed'),
        get_string('hidenavbar_desc', 'local_omeroembed'),
        1
    ));
    // Off by default - OMERO's own ROI panel stays exactly as it is today
    // (collapsed) unless a teacher opts an embed into this. See proxy.php's
    // inject_show_rois_script() for what enabling it actually does.
    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/showomerorois',
        get_string('showomerorois', 'local_omeroembed'),
        get_string('showomerorois_desc', 'local_omeroembed'),
        0
    ));

    // Off by default - see proxy.php's inject_contextmenu_blocker() for what
    // this actually does once enabled (suppresses iviewer's own right-click
    // ROI menu on the final embed only, ready for a student annotation UI to
    // take that gesture over - not built yet, so leaving this off keeps
    // current behaviour for everyone until it is).
    $settings->add(new admin_setting_heading(
        'local_omeroembed/annotationsheading',
        get_string('annotationsheading', 'local_omeroembed'),
        get_string('annotationsheading_desc', 'local_omeroembed')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/enableannotations',
        get_string('enableannotations', 'local_omeroembed'),
        get_string('enableannotations_desc', 'local_omeroembed'),
        0
    ));

    // The site-wide default palette - a teacher can choose a different set
    // of up to annotations_repository::MAX_COLOURS for an individual embed
    // in author.php (see that file's own comment); this is only what a
    // freshly-generated embed starts with until they do. Always a subset
    // of COLOUR_PALETTE, never an arbitrary hex value - see
    // parse_colours()'s own docblock for why.
    $settings->add(new admin_setting_configmulticheckbox(
        'local_omeroembed/annotationcolours',
        get_string('annotationcolours', 'local_omeroembed'),
        get_string('annotationcolours_desc', 'local_omeroembed', annotations_repository::MAX_COLOURS),
        // admin_setting_configmulticheckbox's own write_setting()/output_html()
        // expect this keyed by the same option value as $choices below
        // (checks !empty($default[$key])), not a plain indexed list.
        array_fill_keys(annotations_repository::DEFAULT_COLOURS, 1),
        annotations_repository::get_colour_choices()
    ));

    // Off by default - see proxy.php's inject_hotspot_attempt_script() and
    // classes/hotspot_repository.php for what this actually turns on: a
    // teacher-defined hidden region a student answers by clicking, never
    // exposed to the browser before they do. The region itself is authored
    // separately, drawn live in author.php's own preview (see that page's
    // own comment) - this setting only controls whether the feature is
    // available on an embed at all.
    $settings->add(new admin_setting_heading(
        'local_omeroembed/hotspotheading',
        get_string('hotspotheading', 'local_omeroembed'),
        get_string('hotspotheading_desc', 'local_omeroembed')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/enablehotspot',
        get_string('enablehotspot', 'local_omeroembed'),
        get_string('enablehotspot_desc', 'local_omeroembed'),
        0
    ));

    // Multi-region sibling of enablehotspot above - a click is correct
    // against ANY one of several teacher-marked regions instead of one
    // single shape (see classes/hotspot_multi_repository.php's own
    // docblock). A separate setting/table/feature, not a mode of the
    // single-region one - kept under the same heading since it's the same
    // conceptual feature area.
    $settings->add(new admin_setting_configcheckbox(
        'local_omeroembed/enablehotspotmulti',
        get_string('enablehotspotmulti', 'local_omeroembed'),
        get_string('enablehotspotmulti_desc', 'local_omeroembed'),
        0
    ));

    // Applies to the heatmap feature's local_omeroembed_view_samples table
    // only - regardless of any individual embed's own gather-hours window
    // (see author.php's tracking panel) or a teacher manually deleting data
    // early (see heatmap.php's delete button), this is the absolute cap
    // that keeps that table from growing unbounded. Enforced by a daily
    // scheduled task (classes/task/purge_view_samples.php, see
    // db/tasks.php) - not applied retroactively/instantly on save, so a
    // change here takes effect at the task's next scheduled run.
    $settings->add(new admin_setting_heading(
        'local_omeroembed/retentionheading',
        get_string('retentionheading', 'local_omeroembed'),
        get_string('retentionheading_desc', 'local_omeroembed', get_string('task_purgeviewsamples', 'local_omeroembed'))
    ));

    $settings->add(new admin_setting_configduration(
        'local_omeroembed/retentionperiod',
        get_string('retentionperiod', 'local_omeroembed'),
        get_string('retentionperiod_desc', 'local_omeroembed'),
        30 * DAYSECS,
        DAYSECS
    ));
}
