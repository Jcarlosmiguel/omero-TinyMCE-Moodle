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

$string['pluginname'] = 'OMERO slide embed';

$string['omerobaseurl'] = 'OMERO base URL';
$string['omerobaseurl_desc'] = 'The OMERO.web server this plugin proxies content from, e.g. https://your-omero-server.example.org. Never shown to students - all requests go through this plugin\'s own proxy.';

$string['subjects'] = 'Subject accounts';
$string['subjects_desc'] = 'One subject per line, in the form <code>subject_key|username|password</code>. The subject_key is what teachers pick from the dropdown on the "Embed an OMERO slide" tool. These are OMERO service-account credentials, used server-side only - never sent to students\' browsers.';

$string['overlaysheading'] = 'Embedded viewer overlays';
$string['overlaysheading_desc'] = 'OMERO.iviewer\'s own on-image controls, individually hideable to reduce clutter for students. Purely cosmetic - hiding a control here never affects pan/zoom, view-links, or the opening view. Applies to both the authoring tool\'s live preview and the final student-facing embed.';
$string['hideoverview'] = 'Hide overview map';
$string['hideoverview_desc'] = 'The small inset thumbnail of the whole image with a box showing the current viewport.';
$string['hiderotate'] = 'Hide rotate control';
$string['hiderotate_desc'] = 'The "reset rotation" arrow icon - rarely relevant for a flat slide image.';
$string['hideintensity'] = 'Hide coordinate/zoom readout';
$string['hideintensity_desc'] = 'A small mouse-hover display of the pixel coordinate and value under the cursor, plus the current zoom percentage. Safe to hide - the authoring tool\'s "Insert view link" and "Set as opening view" read the actual view position directly from the viewer itself, not from this on-screen display.';
$string['hidefullscreen'] = 'Hide full-screen button';
$string['hidefullscreen_desc'] = 'Lets students expand the slide to fill the screen - hide only if that\'s not wanted for this deployment.';
$string['hidescaleline'] = 'Hide scale bar';
$string['hidescaleline_desc'] = 'Shows a real-world size reference (e.g. "5 mm"). Consider leaving this visible - it\'s often pedagogically useful for judging magnification.';
$string['hidezoom'] = 'Hide zoom controls';
$string['hidezoom_desc'] = 'The zoom in/out buttons, "1:1" reset, and zoom percentage input. Hiding this removes the ability to zoom interactively, not just a cosmetic change - only enable if the embed is meant to show a single fixed view with no student interaction.';

$string['annotationsheading'] = 'Student annotations';
$string['annotationsheading_desc'] = 'A separate annotation layer (in development) let students mark up the slide themselves - independent of, and not visible in, OMERO\'s own ROI system.';
$string['enableannotations'] = 'Enable student annotations';
$string['enableannotations_desc'] = 'Turns on the annotation layer for the final student-facing embed only - never on the authoring tool\'s own live preview. While this is off (the default), embeds behave exactly as before, including OMERO.iviewer\'s own right-click ROI menu.';

$string['privacy:metadata:local_omeroembed_annotations'] = 'A point the student marked on a specific embedded OMERO slide.';
$string['privacy:metadata:local_omeroembed_annotations:userid'] = 'The ID of the user who created the annotation.';
$string['privacy:metadata:local_omeroembed_annotations:courseid'] = 'The course the annotated embed belongs to.';
$string['privacy:metadata:local_omeroembed_annotations:embedid'] = 'The specific embed placement the annotation was drawn on.';
$string['privacy:metadata:local_omeroembed_annotations:geometry'] = 'The position on the slide the student marked.';
$string['privacy:metadata:local_omeroembed_annotations:colour'] = 'The colour the student chose for this annotation.';
$string['privacy:metadata:local_omeroembed_annotations:label'] = 'An optional short note the student entered for this annotation.';
$string['privacy:metadata:local_omeroembed_annotations:timecreated'] = 'The time the annotation was created.';

$string['unknownsubject'] = 'No OMERO subject account is configured for "{$a}" - ask a Manager to check it under "OMERO slide embed settings" (or a Site administrator, under Site administration > Plugins > Local plugins > OMERO slide embed).';
$string['omerologinfailed'] = 'Could not log in to OMERO as subject "{$a}" - check the configured username/password for this subject.';
$string['omeroconnectionfailed'] = 'Could not reach the configured OMERO server.';
$string['invalidproxypath'] = 'Refusing to proxy path "{$a}" - not in the allowed list.';
$string['missingimageordataset'] = 'This embed needs at least an image ID or a dataset ID - neither was given.';

$string['authortitle'] = 'Embed an OMERO slide';
$string['authorintro'] = 'Load a slide, write your text alongside it, and turn selected words into links that jump the slide to a specific saved view - no HTML or URLs to type by hand.';
$string['choosesubject'] = 'Choose a subject account...';
$string['subjectlabel'] = 'Subject account';
$string['imageidlabel'] = 'Image ID';
$string['datasetidlabel'] = 'Dataset ID (optional)';
$string['browsablelabel'] = 'Let students browse other images in this dataset';
$string['layoutlabel'] = 'Layout';
$string['layoutslideleft'] = 'Slide on the left, text on the right';
$string['layoutslideright'] = 'Text on the left, slide on the right';
$string['layoutimageonly'] = 'Slide only, no write-up text';
$string['layouttextbelow'] = 'Image with a short question below (for quiz questions)';
$string['resetview'] = 'Reset view';
$string['widthlabel'] = 'Width';
$string['widthdesc'] = 'Match this to the actual content width of a course page/label/book in your Moodle theme, so the view you pick here looks right once pasted there - the default is measured from this site\'s own content column, but themes vary.';
$string['heightlabel'] = 'Height';
$string['loadslide'] = 'Load slide';
$string['insertviewlink'] = 'Insert view link';
$string['setopeningview'] = 'Set as opening view';
$string['openingviewset'] = 'Opening view set!';
$string['generateembed'] = 'Generate embed HTML';
$string['copyembed'] = 'Copy to clipboard';
$string['insertintopage'] = 'Insert into page';
$string['copied'] = 'Copied!';
$string['previewnotready'] = 'Could not read the slide\'s current position - wait for it to finish loading, then pan or zoom before trying again.';
$string['selecttextfirst'] = 'Select some text in the write-up box first, then click Insert view link.';
$string['selectinsidewriteup'] = 'Select text inside the write-up box (not the slide or anything else on the page).';

$string['omeroembed:managesettings'] = 'Manage OMERO slide embed settings (base URL, subject accounts, viewer overlays)';
$string['omeroembed:annotate'] = 'Draw and delete your own point annotations on an embedded OMERO slide';

$string['invalidcolour'] = 'Invalid annotation colour "{$a}" - expected a hex string like #ff0000.';
$string['invalidannotationtype'] = 'Invalid annotation type "{$a}".';
$string['invalidradius'] = 'Invalid circle radius "{$a}" - must be greater than zero.';
$string['invalidaction'] = 'Invalid action "{$a}".';

$string['annotatetoolbar_point'] = 'Place a pin';
$string['annotatetoolbar_circle'] = 'Draw a circle';
$string['annotatetoolbar_snapshot'] = 'Snapshot';
$string['annotatetoolbar_delete'] = 'Delete';
$string['annotatetoolbar_labelprompt'] = 'Add a short note for this annotation (optional):';
$string['managetitle'] = 'OMERO slide embed settings';
$string['manageintro'] = 'The OMERO server, subject accounts, and embedded viewer appearance used across every course\'s "Embed an OMERO slide" tool - not specific to any one course.';
$string['savechanges'] = 'Save changes';
$string['settingssaved'] = 'Settings saved.';
