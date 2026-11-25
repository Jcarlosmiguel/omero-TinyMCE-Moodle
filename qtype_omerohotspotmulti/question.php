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

    /** @var array|null {x,y,zm} - the position/zoom the slide opens on for a student, or null for OMERO's own default. */
    public $openingview;

    /**
     * The response fields this question expects: the raw click position.
     *
     * PARAM_RAW_TRIMMED, not PARAM_FLOAT - same choice (and reasoning) as
     * qtype_omerohotspot's own get_expected_data(), itself following
     * qtype_numerical: PARAM_FLOAT turned an unclicked (empty) field into
     * "0" and '1e999' into INF. Numeric validation happens in
     * is_complete_response() instead.
     *
     * @return array
     */
    public function get_expected_data() {
        return ['clickx' => PARAM_RAW_TRIMMED, 'clicky' => PARAM_RAW_TRIMMED];
    }

    /**
     * Deliberately not coordinates - see this class's own docblock.
     *
     * @return array|null
     */
    public function get_correct_response() {
        return null;
    }

    /**
     * A human-readable summary of where the student clicked.
     *
     * @param array $response
     * @return string|null
     */
    public function summarise_response(array $response) {
        // Validated first, not just key-checked - same round() TypeError
        // reasoning as qtype_omerohotspot's own summarise_response().
        if (!$this->is_complete_response($response)) {
            return null;
        }
        return get_string(
            'clickedat',
            'qtype_omerohotspotmulti',
            (object) ['x' => round((float) $response['clickx']), 'y' => round((float) $response['clicky'])]
        );
    }

    /**
     * Same reasoning as qtype_omerohotspot's own equivalent - not
     * attempted, nothing in core depends on this round-tripping here.
     *
     * @param string $summary
     * @return array
     */
    public function un_summarise_response(string $summary) {
        return [];
    }

    /**
     * Classifies the response as inside or outside every hidden region.
     *
     * @param array $response
     * @return array
     */
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

    /**
     * Whether a real click position was submitted - both coordinates
     * present and finite numbers. Empty, non-numeric or non-finite all
     * count as "not answered" - same rule as qtype_omerohotspot's own
     * is_complete_response().
     *
     * @param array $response
     * @return bool
     */
    public function is_complete_response(array $response) {
        return array_key_exists('clickx', $response) && array_key_exists('clicky', $response)
            && qtype_omerohotspotmulti_is_finite_number($response['clickx'])
            && qtype_omerohotspotmulti_is_finite_number($response['clicky']);
    }

    /**
     * The validation error to show when no click was submitted.
     *
     * @param array $response
     * @return string
     */
    public function get_validation_error(array $response) {
        if ($this->is_gradable_response($response)) {
            return '';
        }
        return get_string('pleaseclickimage', 'qtype_omerohotspotmulti');
    }

    /**
     * Whether the response is complete enough to grade.
     *
     * @param array $response
     * @return bool
     */
    public function is_gradable_response(array $response) {
        return $this->is_complete_response($response);
    }

    /**
     * Whether two responses represent the same click position.
     *
     * @param array $prevresponse
     * @param array $newresponse
     * @return bool
     */
    public function is_same_response(array $prevresponse, array $newresponse) {
        return question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'clickx')
            && question_utils::arrays_same_at_key_missing_is_blank($prevresponse, $newresponse, 'clicky');
    }

    /**
     * Grades the response by testing the click against every hidden
     * region, correct if it lands inside any one of them.
     *
     * @param array $response
     * @return array
     */
    public function grade_response(array $response) {
        // Invalid response graded as a miss rather than cast blind - same
        // reasoning as qtype_omerohotspot's own grade_response().
        $correct = $this->is_complete_response($response)
            && qtype_omerohotspotmulti_check($this->regions, (float) $response['clickx'], (float) $response['clicky']);
        $fraction = $correct ? 1 : 0;
        return [$fraction, question_state::graded_state_for_fraction($fraction)];
    }

    /**
     * Same as qtype_omerohotspot's own - no external/mobile-app rendering
     * support for this qtype yet.
     *
     * @param question_attempt $qa
     * @param question_display_options $options
     * @return mixed
     */
    public function get_question_definition_for_external_rendering(question_attempt $qa, question_display_options $options) {
        return null;
    }
}
