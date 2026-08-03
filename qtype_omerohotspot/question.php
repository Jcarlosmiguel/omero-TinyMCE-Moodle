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
 * OMERO hotspot question definition class.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/questionbase.php');
require_once($CFG->dirroot . '/question/type/omerohotspot/lib.php');

/**
 * Represents an OMERO hotspot question - a student answers by clicking
 * directly on a whole-slide-image embed; correct/incorrect is decided by
 * qtype_omerohotspot_check() (lib.php) against $geometry, which is never
 * exposed anywhere a student's browser could read it before they answer -
 * get_correct_response() below deliberately returns null rather than the
 * region's coordinates, so even the post-submission review screen doesn't
 * reveal it (see this plugin's own plan doc's "explicit scope decisions").
 *
 * No question_answers row, no partial credit - extends
 * question_graded_automatically (not the _with_countback variant), same
 * plain hit-or-miss model the standalone local_omeroembed practice
 * activity already uses.
 */
class qtype_omerohotspot_question extends question_graded_automatically {
    /** @var int local_omeroembed_subjects.id - which OMERO connection. */
    public $subjectid;

    /** @var string OMERO image id. */
    public $imageid;

    /** @var string OMERO dataset id, if browsing a dataset rather than one fixed image. */
    public $datasetid;

    /** @var array {type,x,y,rx,ry,rotation} - the hidden correct-answer region. */
    public $geometry;

    public function get_expected_data() {
        return ['clickx' => PARAM_FLOAT, 'clicky' => PARAM_FLOAT];
    }

    public function get_correct_response() {
        // Deliberately not coordinates - see this class's own docblock.
        return null;
    }

    public function summarise_response(array $response) {
        if (!array_key_exists('clickx', $response) || !array_key_exists('clicky', $response)) {
            return null;
        }
        return get_string('clickedat', 'qtype_omerohotspot',
                (object) ['x' => round($response['clickx']), 'y' => round($response['clicky'])]);
    }

    public function un_summarise_response(string $summary) {
        // Reverse of summarise_response() above is not attempted - the
        // exact click position isn't recoverable from the localised
        // summary string, and nothing in core actually depends on this
        // round-tripping for questions where it isn't meaningful (matches
        // truefalse's own equivalent, which only round-trips because true/
        // false genuinely has just two fixed strings to match against).
        return [];
    }

    public function classify_response(array $response) {
        if (!$this->is_complete_response($response)) {
            return [$this->id => question_classified_response::no_response()];
        }
        [$fraction] = $this->grade_response($response);
        $responseclass = $fraction > 0
            ? get_string('clickedinregion', 'qtype_omerohotspot')
            : get_string('clickedoutsideregion', 'qtype_omerohotspot');
        return [$this->id => new question_classified_response($fraction > 0 ? 1 : 0, $responseclass, $fraction)];
    }

    public function is_complete_response(array $response) {
        return array_key_exists('clickx', $response) && array_key_exists('clicky', $response)
            && $response['clickx'] !== '' && $response['clicky'] !== '';
    }

    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('pleaseclickimage', 'qtype_omerohotspot');
    }

    public function is_gradable_response(array $response) {
        return $this->is_complete_response($response);
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'clickx')
            && question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'clicky');
    }

    public function grade_response(array $response) {
        $correct = qtype_omerohotspot_check($this->geometry, (float) $response['clickx'], (float) $response['clicky']);
        $fraction = $correct ? 1 : 0;
        return [$fraction, question_state::graded_state_for_fraction($fraction)];
    }

    public function check_file_access($qa, $options, $component, $filearea, $args, $forcedownload) {
        // No qtype-specific file areas (see lib.php's own comment) - only
        // ever the standard question text/generalfeedback ones, already
        // handled by the parent.
        return parent::check_file_access($qa, $options, $component, $filearea, $args, $forcedownload);
    }

    /**
     * @param question_attempt $qa
     * @param question_display_options $options
     * @return mixed
     */
    public function get_question_definition_for_external_rendering(question_attempt $qa, question_display_options $options) {
        // Same as truefalse's own - no external/mobile-app rendering
        // support for this qtype yet (it would need its own bespoke
        // mobile-side OMERO viewer regardless of what's returned here).
        return null;
    }
}
