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
 * Navigation hook - without it, author.php is only reachable by typing its
 * URL directly. Re-checks the exact same capability author.php itself
 * checks, so this link only ever appears for someone who could actually use
 * the page anyway - never a dead link for students or ordinary staff.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds an "Embed an OMERO slide" link to a course's own navigation, for anyone
 * who could actually use author.php in that course - mirrors exactly the
 * capability check author.php itself makes, at the same course context Moodle
 * hands this callback, so this never shows a link that then 403s.
 *
 * Skipped entirely when this same user already gets a one-click way to reach
 * the same tool from inside the editor itself (tiny_omeroembed's toolbar
 * button) - see local_omeroembed_tiny_button_available()'s own docblock for
 * why. When it IS shown, this is genuinely the only way a teacher without
 * that button can build an embed: they build it here, copy the generated
 * HTML, then switch back to whichever activity they were actually editing to
 * paste it in - so the link opens in its own named tab/window rather than
 * navigating the current one away, which would otherwise silently discard
 * unsaved work in an activity being edited elsewhere.
 *
 * @param navigation_node $coursenode
 * @param stdClass $course
 * @param context_course $context
 * @return void
 */
function local_omeroembed_extend_navigation_course(navigation_node $coursenode, stdClass $course, context_course $context): void {
    if (!has_capability('moodle/course:manageactivities', $context)) {
        return;
    }

    if (local_omeroembed_tiny_button_available()) {
        return;
    }

    $url = new moodle_url('/local/omeroembed/author.php', ['courseid' => $course->id]);
    $title = get_string('authortitle', 'local_omeroembed');
    $action = new action_link($url, $title, null, ['target' => 'omero-embed-author']);
    $node = navigation_node::create(
        $title,
        $action,
        navigation_node::TYPE_SETTING,
        null,
        'local_omeroembed_author',
        new pix_icon('t/edit', '')
    );
    // Same real bug as local_omeroembed_extend_settings_navigation() below,
    // same fix - see that function's own comment for the full explanation
    // (moremenu_children.mustache's promoted-tab branch drops target=
    // entirely, so this node has to stay forced into the dropdown or the
    // named-tab behaviour silently breaks whenever the course page happens
    // to have room to promote it).
    $node->set_force_into_more_menu(true);
    $coursenode->add_node($node);
}

/**
 * Same link as local_omeroembed_extend_navigation_course(), but placed where
 * it's actually needed most: the activity's own "More" menu (the same one
 * that already lists Filters, Permissions, Logs, Backup, Restore) - present
 * consistently across that activity's own pages, including its edit-settings
 * screen, unlike the course-level "More" from the function above, which
 * isn't reachable at all in the exact moment someone is staring at an empty
 * write-up box wondering how to get a slide into it.
 *
 * Two dead ends tried and ruled out before this one, for real, not assumed -
 * both confirmed against actual page output:
 * - A mform static element added via moodleform_mod's own
 *   plugin_extend_coursemodule_standard_elements(): the element really did
 *   render, but Moodle's collapsible-section renderer groups an inserted
 *   element into whichever section's fieldset it structurally lands next to,
 *   not necessarily where insertElementBefore() was aimed - so it ended up
 *   folded into the Competencies section instead of sitting cleanly after
 *   Content, an unpredictable, fragile position no fixed anchor reliably
 *   controls.
 * - This exact function, tried once already: it was wrongly concluded to
 *   render nothing, because that check searched for generic settings-block
 *   markup ("block_settings", "settingsnav") which isn't how this menu is
 *   actually built - a live request later showed the real "More" dropdown
 *   (Filters/Permissions/Logs/Backup/Restore) genuinely present in the page,
 *   meaning this hook likely worked the first time and was abandoned on a
 *   false negative.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 * @return void
 */
function local_omeroembed_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    if ($context->contextlevel !== CONTEXT_MODULE) {
        return;
    }

    $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, IGNORE_MISSING);
    // Deliberately excludes 'book': unlike the others, a Book has no single
    // "just start writing" step - the activity itself is created empty, then
    // each chapter is added through its own separate screen, so this link
    // would appear surrounded by chapter-structure work rather than at the
    // moment someone's actually about to paste an embed into a chapter's
    // content editor.
    if (!$cm || !in_array($cm->modname, ['label', 'page', 'lesson'], true)) {
        return;
    }

    $coursecontext = context_course::instance($cm->course);
    if (!has_capability('moodle/course:manageactivities', $coursecontext)) {
        return;
    }

    if (local_omeroembed_tiny_button_available()) {
        return;
    }

    $modulesettings = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$modulesettings) {
        return;
    }

    $url = new moodle_url('/local/omeroembed/author.php', ['courseid' => (int) $cm->course]);
    $title = get_string('authortitle', 'local_omeroembed');
    $action = new action_link($url, $title, null, ['target' => 'omero-embed-author']);
    $node = $modulesettings->add(
        $title,
        $action,
        navigation_node::TYPE_SETTING,
        null,
        'local_omeroembed_author_activity',
        new pix_icon('t/edit', '')
    );
    // Real bug, found on Lesson specifically and confirmed against the
    // template, not guessed: when the secondary nav has room, it promotes a
    // "More" entry to its own top-level tab instead of leaving it in the
    // dropdown - moremenu_children.mustache's tab-branch (istablist) renders
    // a plain href with no actionattributes loop at all, unlike the dropdown
    // branch, so target="omero-embed-author" silently disappears whenever
    // this node gets promoted. Page happened not to promote it (dropdown
    // branch, worked); Lesson did (tab branch, target lost, navigated the
    // current tab away). Forcing this into the dropdown every time sidesteps
    // the gap entirely rather than depending on how many other tabs a given
    // module type happens to have.
    $node->set_force_into_more_menu(true);
}

/**
 * Whether the CURRENT user already has a one-click way to build an embed
 * from inside the editor itself, via tiny_omeroembed's own toolbar button -
 * in which case the course-level "Embed an OMERO slide" link would just be a
 * redundant, worse path to the exact same tool (it opens the same author.php
 * either way).
 *
 * Deliberately checks more than "is tiny_omeroembed installed": Moodle
 * supports several editors side by side (Tiny, Atto, plain textarea), and
 * which one a given user actually gets is a per-user preference (or a
 * site-forced choice) - a teacher whose own account is using Atto, on a site
 * where tiny_omeroembed happens to be installed, would still see no toolbar
 * button at all. Checking editors_get_preferred_editor() (the same function
 * Moodle itself uses to decide which editor to actually render) instead of
 * just "is the plugin installed" is what makes this safe - if there's any
 * doubt the button would really be there, this returns false and the
 * fallback course-level link stays visible instead.
 *
 * @return bool
 */
function local_omeroembed_tiny_button_available(): bool {
    $editor = editors_get_preferred_editor(FORMAT_HTML);
    if (!($editor instanceof \editor_tiny\editor)) {
        return false;
    }

    $plugininfo = \core_plugin_manager::instance()->get_plugin_info('tiny_omeroembed');
    return $plugininfo !== null && $plugininfo->is_enabled() === true;
}
