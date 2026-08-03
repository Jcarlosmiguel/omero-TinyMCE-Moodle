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
 * OMERO multi-region hotspot question definition class.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/questionbase.php');
require_once($CFG->dirroot . '/question/type/omerohotspotmulti/lib.php');

/**
 * Represents an OMERO multi-region hotspot question - a sibling of
 * qtype_omerohotspot_question, not a mode of it. A student answers by
 * clicking directly on a whole-slide-image embed; correct/incorrect is
 * decided by qtype_omerohotspotmulti_check() (lib.php) against $regions -
 * correct if the click lands inside ANY one of them, never exposed
 * anywhere a student's browser could read it before they answer -
 * get_correct_response() below deliberately returns null rather than any
 * region's coordinates, so even the post-submission review screen doesn't
 * reveal them (see this plugin's own plan doc's "explicit scope
 * decisions").
 *
 * No question_answers row, no partial credit - extends
 * question_graded_automatically (not the _with_countback variant), same
 * plain any-of-N hit-or-miss model the standalone local_omeroembed
 * multi-region practice activity already uses.
 */
class qtype_omerohotspotmulti_question extends question_graded_automatically {
    /** @var int local_omeroembed_subjects.id - which OMERO connection. */
    public $subjectid;

    /** @var string OMERO image id. */
    public $imageid;

    /** @var string OMERO dataset id, if browsing a dataset rather than one fixed image. */
    public $datasetid;

    /** @var array Array of {type,x,y,rx,ry,rotation} - the hidden set of acceptable correct-answer regions. */
    public $regions;

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
        return get_string('clickedat', 'qtype_omerohotspotmulti',
                (object) ['x' => round($response['clickx']), 'y' => round($response['clicky'])]);
    }

    public function un_summarise_response(string $summary) {
        // Same reasoning as qtype_omerohotspot's own equivalent - not
        // attempted, nothing in core depends on this round-tripping here.
        return [];
    }

    public function classify_response(array $response) {
        if (!$this->is_complete_response($response)) {
            return [$this->id => question_classified_response::no_response()];
        }
        [$fraction] = $this->grade_response($response);
        $responseclass = $fraction > 0
            ? get_string('clickedinregion', 'qtype_omerohotspotmulti')
            : get_string('clickedoutsideregion', 'qtype_omerohotspotmulti');
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
        return get_string('pleaseclickimage', 'qtype_omerohotspotmulti');
    }

    public function is_gradable_response(array $response) {
        return $this->is_complete_response($response);
    }

    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'clickx')
            && question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'clicky');
    }

    public function grade_response(array $response) {
        $correct = qtype_omerohotspotmulti_check($this->regions, (float) $response['clickx'], (float) $response['clicky']);
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
        // Same as qtype_omerohotspot's own - no external/mobile-app
        // rendering support for this qtype yet.
        return null;
    }
}
