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

// Local plugins don't get an automatic settings page the way e.g. filter plugins
// do - $settings has to be created and added to the tree explicitly here, gated
// on $hassiteconfig (whether the current user has moodle/site:config), matching
// the same pattern core's own admin/settings/plugins.php uses for every local
// plugin's load_settings() call.
//
// Deliberately the ONLY two settings here (as of 1.7.0) - every other former
// setting (viewer-display overlays, default width/height, annotations,
// annotation colours, hotspot) was a site-wide *default* for something a
// teacher already chooses per-embed in the authoring tool directly, and was
// found to be a genuine source of confusion for site administrators (real
// feedback: "I cannot see the purpose of the two entries..." /
// "particularly confusing because the wording appears to describe
// embed-level authoring options, rather than site-level configuration") -
// removed rather than re-explained, not because the underlying features
// went away (they're still fully available per-embed in author.php,
// unchanged) but because exposing a rarely-needed site-wide override for
// each of them wasn't worth the confusion. See author.php's own comment
// on $overlaysettings/$defaultwidth/etc. for where the fixed defaults now
// live instead. This also removes the second "OMERO slide embed settings"
// entry that used to sit under Site administration > Plugins (see the
// removed local_omeroembed:managesettings capability in db/access.php) -
// site administrators are now the only people who ever configure this
// plugin, via this one page, full stop.
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_omeroembed', get_string('pluginname', 'local_omeroembed'));
    $ADMIN->add('localplugins', $settings);

    // Real documentation pointer, independent of whatever the Marketplace
    // listing can render (real finding: its description field is plain
    // text only, no real Markdown/HTML - matches direct feedback that it
    // reads as "no formatting, no paragraphs, no bullet points"). This
    // heading's own description IS rendered through Moodle's real
    // format_text() though, so it's a genuine, well-formatted link,
    // exactly where a confused administrator was already looking (real
    // feedback: the two-settings-pages confusion this page's own removal
    // note above addresses).
    $settings->add(new admin_setting_heading(
        'local_omeroembed/docslink',
        get_string('docslinkheading', 'local_omeroembed'),
        get_string('docslinkheading_desc', 'local_omeroembed')
    ));

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
