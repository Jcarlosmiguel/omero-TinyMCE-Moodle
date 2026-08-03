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
 * Strings for qtype_omerohotspotmulti.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'OMERO hotspot (multi-region)';
$string['pluginname_help'] = 'The student answers by clicking directly on a whole-slide OMERO image - correct if the click lands inside ANY of one or more regions you mark as acceptable answers (e.g. several equally-correct examples of a feature on the same slide), which stay hidden from students at all times, including in review.';
$string['pluginname_link'] = 'question/type/omerohotspotmulti';
$string['pluginnameadding'] = 'Adding an OMERO hotspot (multi-region) question';
$string['pluginnameediting'] = 'Editing an OMERO hotspot (multi-region) question';
$string['pluginnamesummary'] = 'The student clicks a location on a whole-slide OMERO image; correct if the click lands inside any one of several regions the teacher marked as hidden and never revealed.';

$string['imageidlabel'] = 'Image ID';
$string['datasetidlabel'] = 'Dataset ID (optional)';
$string['loadslidehelp'] = 'Choose a subject account and image, click "Load slide" below, then drag an ellipse or rectangle over each acceptable answer directly on the slide - mark as many as you need.';
$string['regionpreview'] = 'Correct answer regions';
$string['needscoursecontext'] = 'This question needs to be created inside a course (or activity) question bank - the slide preview needs a real course to check permissions against.';

$string['clickedat'] = 'Clicked at ({$a->x}, {$a->y})';
$string['currentanswer'] = 'Your answer: clicked at ({$a->x}, {$a->y})';
$string['clickedinregion'] = 'Clicked inside one of the marked regions';
$string['clickedoutsideregion'] = 'Clicked outside every marked region';
$string['pleaseclickimage'] = 'Please click a location on the image.';
$string['missinggeometry'] = 'No correct-answer regions have been drawn yet - load the slide below and drag out at least one correct region before saving.';

$string['privacy:metadata'] = 'The OMERO hotspot (multi-region) question type does not store any personal data itself - a student\'s clicked coordinates and grade are recorded by Moodle\'s own question engine (question_attempt_step_data), already covered by that subsystem\'s own privacy provider.';
