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
 * Strings for local_omeroembed.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addsubject'] = 'Add connection';
$string['addsubjectheading'] = 'Add a connection';
$string['annotatetoolbar_cancelpolygon'] = 'Cancel this shape';
$string['annotatetoolbar_constrain'] = 'Constrain to a circle/square';
$string['annotatetoolbar_delete'] = 'Delete';
$string['annotatetoolbar_ellipse'] = 'Draw an ellipse (hold Shift, or use the lock button, for a circle)';
$string['annotatetoolbar_help'] = 'Help with these tools';
$string['annotatetoolbar_helpclose'] = 'Close';
$string['annotatetoolbar_helptitle'] = 'Annotation tools';
$string['annotatetoolbar_labelprompt'] = 'Add a short note for this annotation (optional):';
$string['annotatetoolbar_point'] = 'Place a pin';
$string['annotatetoolbar_polygon'] = 'Draw a free-form shape (click to place each point, click the first point again to finish, Esc or Cancel to stop)';
$string['annotatetoolbar_rectangle'] = 'Draw a rectangle (hold Shift, or use the lock button, for a square)';
$string['annotatetoolbar_snapshot'] = 'Snapshot';
$string['annotationcolours'] = 'Annotation colours';
$string['annotationcolours_desc'] = 'Which colours appear on the annotation toolbar\'s colour picker - choose up to {$a} in total. A teacher can choose a different set of up to {$a} for an individual embed in the authoring tool; this is just the default for embeds where they haven\'t.';
$string['annotationsheading'] = 'Student annotations';
$string['annotationsheading_desc'] = 'A separate annotation layer (in development) let students mark up the slide themselves - independent of, and not visible in, OMERO\'s own ROI system.';
$string['authorintro'] = 'Load a slide, write your text alongside it, and turn selected words into links that jump the slide to a specific saved view - no HTML or URLs to type by hand.';
$string['authortitle'] = 'Embed an OMERO slide';
$string['availableafterend'] = 'Available once tracking has ended - stop tracking above (or let it run out) to enable these.';
$string['backtoauthoring'] = 'Back to the authoring tool';
$string['browsablelabel'] = 'Let students browse other images in this dataset';
$string['cannotdeletewhiletracking'] = 'Can\'t delete gathered data while tracking is still active - stop tracking above (or let it run out) first.';
$string['choosesubject'] = 'Choose a subject account...';
$string['clicklinkfirst'] = 'Click inside an existing view link first, then click Remove view link. To point a link at a different view, remove it, then select the text again and click Insert view link.';
$string['colour_000000'] = 'Black';
$string['colour_3cb44b'] = 'Green';
$string['colour_42d4f4'] = 'Cyan';
$string['colour_4363d8'] = 'Blue';
$string['colour_911eb4'] = 'Purple';
$string['colour_e6194b'] = 'Red';
$string['colour_f58231'] = 'Orange';
$string['colour_ffe119'] = 'Yellow';
$string['confirmdeletedata'] = 'Delete all gathered viewing data for this embed? This also turns tracking off for it - start tracking again above if you want to gather fresh data later. This cannot be undone; download the data first if you still need it.';
$string['confirmdeletesubject'] = 'Delete this OMERO connection? Any embeds using it will stop working. This cannot be undone.';
$string['copied'] = 'Copied!';
$string['copyembed'] = 'Copy to clipboard';
$string['datadeleted'] = '{$a} sample(s) deleted. Tracking has been turned off for this embed.';
$string['datasetidlabel'] = 'Dataset ID (optional)';
$string['deletedata'] = 'Delete gathered data';
$string['downloaddata'] = 'Download data (CSV)';
$string['downloadvideo'] = 'Frame-by-frame session generator';
$string['editsubjectheading'] = 'Edit connection';
$string['embedcoursemismatch'] = 'This embed does not belong to the specified course.';
$string['enableannotations'] = 'Enable student annotations';
$string['enableannotations_desc'] = 'Turns on the annotation layer for the final student-facing embed only - never on the authoring tool\'s own live preview. While this is off (the default), embeds behave exactly as before, including OMERO.iviewer\'s own right-click ROI menu.';
$string['enablehotspot'] = 'Enable hotspot question';
$string['enablehotspot_desc'] = 'Turns on the click-to-answer hotspot feature for this embed. Checking this in the authoring tool reveals a small drawing toolbar on the live preview for marking the correct region - see the authoring tool for details.';
$string['enablehotspotmulti'] = 'Enable multi-region hotspot question';
$string['enablehotspotmulti_desc'] = 'Turns on the multi-region hotspot feature for this embed - like the single-region hotspot question above, but a student\'s click is correct if it lands inside ANY of several regions you mark (e.g. several equally-correct examples of a feature scattered across the same slide), not just one. Checking this in the authoring tool reveals a drawing toolbar on the live preview for marking as many correct regions as needed.';
$string['framecount'] = '{$a} frame(s) of the session captured so far (a new one is added roughly every 5 minutes while tracking is active).';
$string['gatherminuteslabel'] = 'Gather data for (minutes)';
$string['generateembed'] = 'Generate embed HTML';
$string['heatmapgatheringended'] = 'Data gathering ended on {$a}.';
$string['heatmapgatheringuntil'] = 'Currently gathering data until {$a}.';
$string['heatmapnodata'] = 'This embed hasn\'t been opened via a "View heatmap" link yet, so there\'s nothing to configure here - follow that link either from the embed itself on a course page, or from the embed\'s authoring page.';
$string['heatmaptitle'] = 'Student viewing heatmap';
$string['heatmaptrackingoff'] = 'Tracking is currently switched off for this embed - the heatmap below reflects only data gathered while it was previously on.';
$string['heightlabel'] = 'Height';
$string['hidefullscreen'] = 'Hide full-screen button';
$string['hidefullscreen_desc'] = 'Lets students expand the slide to fill the screen - hide only if that\'s not wanted for this deployment.';
$string['hideintensity'] = 'Hide coordinate/zoom readout';
$string['hideintensity_desc'] = 'A small mouse-hover display of the pixel coordinate and value under the cursor, plus the current zoom percentage. Safe to hide - the authoring tool\'s "Insert view link" and "Set as opening view" read the actual view position directly from the viewer itself, not from this on-screen display.';
$string['hidenavbar'] = 'Hide OMERO top navigation bar';
$string['hidenavbar_desc'] = 'OMERO.web\'s own top bar (File/ROIs/Help menus, its own branding) - not part of the slide viewer itself. Its links lead to pages outside this locked-down embed and don\'t work correctly here, so hiding it is recommended for every embed.';
$string['hideoverview'] = 'Hide overview map';
$string['hideoverview_desc'] = 'The small inset thumbnail of the whole image with a box showing the current viewport.';
$string['hidescaleline'] = 'Hide scale bar';
$string['hidescaleline_desc'] = 'Shows a real-world size reference (e.g. "5 mm"). Consider leaving this visible - it\'s often pedagogically useful for judging magnification.';
$string['hidezoom'] = 'Hide zoom controls';
$string['hidezoom_desc'] = 'The zoom in/out buttons, "1:1" reset, and zoom percentage input. Hiding this removes the ability to zoom interactively, not just a cosmetic change - only enable if the embed is meant to show a single fixed view with no student interaction.';
$string['hotspot_correct'] = 'Correct!';
$string['hotspot_incorrect'] = 'Not quite - try again';
$string['hotspot_saved'] = 'Saved';
$string['hotspotheading'] = 'Click-to-answer hotspot question';
$string['hotspotheading_desc'] = 'Lets a teacher mark a hidden region on the slide as the correct answer to a question (e.g. "Where is Meckel\'s cartilage?") - a student answers by clicking directly on the image, and is told right away whether they found it. The region itself is drawn separately, live in the authoring tool\'s own preview, and is never sent to a student\'s browser before they click.';
$string['hotspotmodelabel'] = 'Hotspot question';
$string['hotspotmodemulti'] = 'Multiple regions (any one of several is correct)';
$string['hotspotmodenone'] = 'None';
$string['hotspotmodesingle'] = 'Single region (one correct spot)';
$string['hotspotmultiqtype_drawstatus'] = 'Draw one or more correct answer regions';
$string['hotspotmultitoolbar_delete'] = 'Delete region';
$string['hotspotqtype_drawstatus'] = 'Draw the correct answer region';
$string['hotspottoolbar_clear'] = 'Clear';
$string['hotspottoolbar_ellipse'] = 'Mark the correct answer as an ellipse (hold Shift, or use the lock button, for a circle)';
$string['hotspottoolbar_rectangle'] = 'Mark the correct answer as a rectangle (hold Shift, or use the lock button, for a square)';
$string['imageidlabel'] = 'Image ID';
$string['insertintopage'] = 'Insert into page';
$string['insertviewlink'] = 'Insert view link';
$string['invalidaction'] = 'Invalid action "{$a}".';
$string['invalidannotationtype'] = 'Invalid annotation type "{$a}".';
$string['invalidcolour'] = 'Invalid annotation colour "{$a}" - expected a hex string like #ff0000.';
$string['invalidgatherminutes'] = 'Invalid gather window ({$a}) - must be at least 1 minute.';
$string['invalidgifframe'] = 'A captured video frame is corrupted and could not be processed.';
$string['invalidpolygon'] = 'Invalid polygon points ({$a}) - expected a JSON array of at least 3 [x,y] pairs.';
$string['invalidproxypath'] = 'Refusing to proxy path "{$a}" - not in the allowed list.';
$string['invalidradius'] = 'Invalid ellipse radius ({$a}) - both must be greater than zero.';
$string['invalidregion'] = 'One of the submitted regions is missing a required field, has an invalid type, or has a non-positive radius.';
$string['invalidsubjectform'] = 'A name, OMERO username, and (for a new connection) password are all required.';
$string['layoutimageonly'] = 'Slide only, no write-up text';
$string['layoutlabel'] = 'Layout';
$string['layoutslideleft'] = 'Slide on the left, text on the right';
$string['layoutslideright'] = 'Text on the left, slide on the right';
$string['layouttextbelow'] = 'Image with a short question below (for quiz questions)';
$string['loadslide'] = 'Load slide';
$string['manageintro'] = 'The OMERO server, subject accounts, and embedded viewer appearance used across every course\'s "Embed an OMERO slide" tool - not specific to any one course.';
$string['managesubjectslink'] = 'Manage your OMERO connections';
$string['managetitle'] = 'OMERO slide embed settings';
$string['missingimageordataset'] = 'This embed needs at least an image ID or a dataset ID - neither was given.';
$string['mtrace_capturedheatmapframes'] = 'Captured {$a->captured} heatmap frame(s), skipped {$a->skipped}, across {$a->total} active session(s).';
$string['mtrace_noactivetracking'] = 'No active tracking sessions - nothing to capture.';
$string['mtrace_notrackedembeds'] = 'No tracked embeds - nothing to sweep.';
$string['mtrace_purgedcoursedata'] = 'local_omeroembed: purged {$a->total} row(s) across {$a->tables} table(s) for deleted course {$a->courseid}.';
$string['mtrace_purgedheatmapframes'] = 'Purged {$a->count} local_omeroembed_heatmap_frames row(s) older than {$a->date}.';
$string['mtrace_purgedorphanedembeds'] = 'Checked {$a->checked} tracked embed(s), purged {$a->purged} whose embedid no longer appears anywhere in their course\'s content.';
$string['mtrace_purgedviewsamples'] = 'Purged {$a->count} local_omeroembed_view_samples row(s) older than {$a->date}.';
$string['mysubjectsintro'] = 'These are your own OMERO service-account connections - only you can see or use them. Pick one from the "Subject account" dropdown when embedding a slide.';
$string['mysubjectstitle'] = 'My OMERO connections';
$string['nodatarecorded'] = 'No data has been gathered for this embed yet, so there\'s nothing to download or delete.';
$string['nosubjectsyet'] = 'You haven\'t set up any OMERO connections yet - add one below.';
$string['novideoframes'] = 'No video frames have been captured for this embed yet - they\'re only captured while tracking is active (roughly every 5 minutes), so check back once tracking has been running a little while.';
$string['omerobaseurl'] = 'OMERO base URL';
$string['omerobaseurl_desc'] = 'The OMERO.web server this plugin proxies content from, e.g. https://your-omero-server.example.org. Never shown to students - all requests go through this plugin\'s own proxy.';
$string['omeroconnectionfailed'] = 'Could not reach the configured OMERO server.';
$string['omeroembed:annotate'] = 'Draw and delete your own point annotations on an embedded OMERO slide';
$string['omeroembed:hotspotauthor'] = 'Define the hidden correct-answer region for a click-to-answer hotspot question on an embedded OMERO slide';
$string['omeroembed:managesettings'] = 'Manage OMERO slide embed settings (base URL, subject accounts, viewer overlays)';
$string['omeroembed:viewheatmap'] = 'Configure viewport tracking and view the resulting heatmap on an embedded OMERO slide';
$string['omerologinfailed'] = 'Could not log in to OMERO as subject "{$a}" - check the configured username/password for this subject.';
$string['openingviewset'] = 'Opening view set!';
$string['overlaysheading'] = 'Embedded viewer overlays';
$string['overlaysheading_desc'] = 'OMERO.iviewer\'s own on-image controls, individually hideable to reduce clutter for students. Purely cosmetic - hiding a control here never affects pan/zoom, view-links, or the opening view. Applies to both the authoring tool\'s live preview and the final student-facing embed.';
$string['pluginname'] = 'OMERO slide embed';
$string['previewnotready'] = 'Could not read the slide\'s current position - wait for it to finish loading, then pan or zoom before trying again.';
$string['privacy:metadata:local_omeroembed_annotations'] = 'A point the student marked on a specific embedded OMERO slide.';
$string['privacy:metadata:local_omeroembed_annotations:colour'] = 'The colour the student chose for this annotation.';
$string['privacy:metadata:local_omeroembed_annotations:courseid'] = 'The course the annotated embed belongs to.';
$string['privacy:metadata:local_omeroembed_annotations:embedid'] = 'The specific embed placement the annotation was drawn on.';
$string['privacy:metadata:local_omeroembed_annotations:geometry'] = 'The position on the slide the student marked.';
$string['privacy:metadata:local_omeroembed_annotations:label'] = 'An optional short note the student entered for this annotation.';
$string['privacy:metadata:local_omeroembed_annotations:timecreated'] = 'The time the annotation was created.';
$string['privacy:metadata:local_omeroembed_annotations:userid'] = 'The ID of the user who created the annotation.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts'] = 'One attempt (right or wrong) at a click-to-answer hotspot question on an embedded OMERO slide.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts:correct'] = 'Whether this attempt was correct.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts:courseid'] = 'The course the hotspot question belongs to.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts:embedid'] = 'The specific embed placement this attempt was made on.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts:timecreated'] = 'The time the attempt was made.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts:userid'] = 'The ID of the user who made this attempt.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts:x'] = 'The horizontal image-pixel position the student clicked.';
$string['privacy:metadata:local_omeroembed_hotspot_attempts:y'] = 'The vertical image-pixel position the student clicked.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts'] = 'One attempt (right or wrong) at a multi-region hotspot question on an embedded OMERO slide.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts:correct'] = 'Whether this attempt was correct.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts:courseid'] = 'The course the hotspot question belongs to.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts:embedid'] = 'The specific embed placement this attempt was made on.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts:timecreated'] = 'The time the attempt was made.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts:userid'] = 'The ID of the user who made this attempt.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts:x'] = 'The horizontal image-pixel position the student clicked.';
$string['privacy:metadata:local_omeroembed_hotspot_multi_attempts:y'] = 'The vertical image-pixel position the student clicked.';
$string['privacy:metadata:local_omeroembed_subjects'] = 'An OMERO service-account connection a teacher registered, used to serve embedded slides to students on their behalf.';
$string['privacy:metadata:local_omeroembed_subjects:name'] = 'The teacher\'s own label for this connection.';
$string['privacy:metadata:local_omeroembed_subjects:omeropassword'] = 'The OMERO account password for this connection, stored encrypted and never included in a data export.';
$string['privacy:metadata:local_omeroembed_subjects:omerousername'] = 'The OMERO account username used to authenticate this connection.';
$string['privacy:metadata:local_omeroembed_subjects:timecreated'] = 'The time this connection was created.';
$string['privacy:metadata:local_omeroembed_subjects:timemodified'] = 'The time this connection was last edited.';
$string['privacy:metadata:local_omeroembed_subjects:userid'] = 'The ID of the teacher who owns this OMERO connection.';
$string['privacy:metadata:local_omeroembed_view_samples'] = 'One periodic sample of a student\'s viewport position on a tracked embedded OMERO slide, for the teacher heatmap feature.';
$string['privacy:metadata:local_omeroembed_view_samples:courseid'] = 'The course the tracked embed belongs to.';
$string['privacy:metadata:local_omeroembed_view_samples:embedid'] = 'The specific embed placement this sample was taken from.';
$string['privacy:metadata:local_omeroembed_view_samples:timecreated'] = 'The time the sample was recorded.';
$string['privacy:metadata:local_omeroembed_view_samples:userid'] = 'The ID of the user this sample belongs to.';
$string['privacy:metadata:local_omeroembed_view_samples:x'] = 'The horizontal image-pixel position the student\'s view was centred on.';
$string['privacy:metadata:local_omeroembed_view_samples:y'] = 'The vertical image-pixel position the student\'s view was centred on.';
$string['privacy:metadata:local_omeroembed_view_samples:zoompercent'] = 'The zoom level the student was viewing at.';
$string['removeviewlink'] = 'Remove view link';
$string['resetview'] = 'Reset view';
$string['retentionheading'] = 'Data retention';
$string['retentionheading_desc'] = 'Applies to every embed\'s gathered heatmap viewing data, site-wide - regardless of any individual embed\'s own gather-window setting or a teacher manually deleting data early, samples older than this are automatically deleted by a daily scheduled task (Site administration > Server > Scheduled tasks > "{$a}"). Teachers should download any data they still need before it ages out.';
$string['retentionperiod'] = 'Delete gathered data after';
$string['retentionperiod_desc'] = 'How long a viewing sample is kept before the retention task deletes it.';
$string['retentionreminder'] = 'Gathered data older than {$a} is automatically deleted daily - download a copy above if you need to keep it longer.';
$string['savechanges'] = 'Save changes';
$string['savesubject'] = 'Save changes';
$string['selectinsidewriteup'] = 'Select text inside the write-up box (not the slide or anything else on the page).';
$string['selecttextfirst'] = 'Select some text in the write-up box first, then click Insert view link.';
$string['setopeningview'] = 'Set as opening view';
$string['settingssaved'] = 'Settings saved.';
$string['showomerorois'] = 'Show OMERO ROIs by default';
$string['showomerorois_desc'] = 'Automatically expands the right-hand panel and switches to its "ROIs" tab when a student opens the embed, so OMERO.iviewer\'s own Regions of Interest are visible immediately instead of hidden behind a collapsed panel. Purely a starting state - students can still collapse the panel or switch tabs themselves afterwards.';
$string['starttracking'] = 'Start tracking';
$string['stoptracking'] = 'Stop tracking';
$string['subjectdeleted'] = 'Connection deleted.';
$string['subjectlabel'] = 'Subject account';
$string['subjectnamelabel'] = 'Name';
$string['subjectpasswordlabel'] = 'OMERO password';
$string['subjectpasswordlabeledit'] = 'OMERO password (leave blank to keep the current one)';
$string['subjectsaved'] = 'Connection saved.';
$string['subjectusernamelabel'] = 'OMERO username';
$string['task_captureheatmapframes'] = 'Capture heatmap video frames';
$string['task_purgeorphanedembedtracking'] = 'Purge tracking for removed embeds';
$string['task_purgeviewsamples'] = 'Purge old heatmap viewing data';
$string['toomanyregions'] = 'Too many regions submitted at once (maximum {$a}) - this is almost certainly not something drawn by hand, so the request was refused.';
$string['trackingheading'] = 'Track student viewing (for heatmap)';
$string['trackingheading_desc'] = 'Periodically records where each student\'s view is centred while they look at the final embed, so you can see an aggregate heatmap of what was actually looked at. Students see a small on-slide notice while this is on. Continues even if you navigate away from or close this page - it\'s driven entirely by this embed\'s own setting below, not by keeping this page open.';
$string['trackingnotice'] = 'This view is recorded for teaching analytics.';
$string['trackingremaining'] = 'Tracking active - {$a} minute(s) remaining.';
$string['trackingstarted'] = 'Tracking started.';
$string['trackingstopped'] = 'Tracking stopped.';
$string['unknownsubject'] = 'This embed\'s OMERO connection ("{$a}") no longer exists - it may have been deleted, or belong to a teacher who no longer has it. Whoever set up this embed will need to recreate it with a valid connection from "Manage your OMERO connections".';
$string['viewheatmaplink'] = 'View heatmap';
$string['widthdesc'] = 'Match this to the actual content width of a course page/label/book in your Moodle theme, so the view you pick here looks right once pasted there - the default is measured from this site\'s own content column, but themes vary.';
$string['widthlabel'] = 'Width';
