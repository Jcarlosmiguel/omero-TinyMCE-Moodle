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
 * Strings for qtype_omerohotspot.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['clickedat'] = 'Clicked at ({$a->x}, {$a->y})';
$string['clickedinregion'] = 'Clicked inside the marked region';
$string['clickedoutsideregion'] = 'Clicked outside the marked region';
$string['currentanswer'] = 'Your answer: clicked at ({$a->x}, {$a->y})';
$string['datasetidlabel'] = 'Dataset ID (optional)';
$string['imageidlabel'] = 'Image ID';
$string['loadslide'] = 'Load slide';
$string['loadslidehelp'] = 'Choose a subject account and image, click "Load slide" below, then drag an ellipse or rectangle over the correct answer directly on the slide.';
$string['missinggeometry'] = 'No correct-answer region has been drawn yet - load the slide below and drag out the correct region before saving.';
$string['needscoursecontext'] = 'This question needs to be created inside a course (or activity) question bank - the slide preview needs a real course to check permissions against.';
$string['pleaseclickimage'] = 'Please click a location on the image.';
$string['pluginname'] = 'OMERO hotspot';
$string['pluginname_help'] = 'The student answers by clicking directly on a whole-slide OMERO image - correct if the click lands inside a region you mark as the answer, which stays hidden from students at all times, including in review.';
$string['pluginname_link'] = 'question/type/omerohotspot';
$string['pluginnameadding'] = 'Adding an OMERO hotspot question';
$string['pluginnameediting'] = 'Editing an OMERO hotspot question';
$string['pluginnamesummary'] = 'The student clicks a location on a whole-slide OMERO image; correct if the click lands inside a region the teacher marked as hidden and never revealed.';

$string['privacy:metadata'] = 'The OMERO hotspot question type does not store any personal data itself - a student\'s clicked coordinates and grade are recorded by Moodle\'s own question engine (question_attempt_step_data), already covered by that subsystem\'s own privacy provider.';
$string['regionpreview'] = 'Correct answer region';
