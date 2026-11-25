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

    /** @var array|null {type,x,y,rx,ry,rotation} - the hidden correct-answer region, null if the stored one isn't usable. */
    public $geometry;

    /** @var array|null {x,y,zm} - the position/zoom the slide opens on for a student, or null for OMERO's own default. */
    public $openingview;

    /**
     * The response fields this question expects: the raw click position.
     *
     * PARAM_RAW_TRIMMED, not PARAM_FLOAT - same choice qtype_numerical
     * makes for its own answer field, for the same reason: PARAM_FLOAT is
     * just (float) $param, which turns the always-posted empty hidden
     * field (nothing clicked yet) into "0", indistinguishable from a real
     * click at (0,0), and turns '1e999' into INF. Keeping the raw string
     * lets is_complete_response() tell all of those apart; numeric
     * validation happens there (and everywhere else these are used), not
     * at clean time.
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
        // Validated first, not just key-checked - round() on a stored
        // non-numeric string such as "INF" throws a TypeError on PHP 8
        // (see qtype_omerohotspot_is_finite_number()'s own docblock).
        if (!$this->is_complete_response($response)) {
            return null;
        }
        return get_string(
            'clickedat',
            'qtype_omerohotspot',
            (object) ['x' => round((float) $response['clickx']), 'y' => round((float) $response['clicky'])]
        );
    }

    /**
     * Reverse of summarise_response() above is not attempted - the exact
     * click position isn't recoverable from the localised summary string,
     * and nothing in core actually depends on this round-tripping for
     * questions where it isn't meaningful (matches truefalse's own
     * equivalent, which only round-trips because true/false genuinely has
     * just two fixed strings to match against).
     *
     * @param string $summary
     * @return array
     */
    public function un_summarise_response(string $summary) {
        return [];
    }

    /**
     * Classifies the response as inside or outside the hidden region.
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
            ? get_string('clickedinregion', 'qtype_omerohotspot')
            : get_string('clickedoutsideregion', 'qtype_omerohotspot');
        return [$this->id => new question_classified_response($fraction > 0 ? 1 : 0, $responseclass, $fraction)];
    }

    /**
     * Whether a real click position was submitted - both coordinates
     * present and finite numbers. An empty string (nothing clicked yet),
     * anything non-numeric, or a non-finite value (INF from '1e999') all
     * count as "not answered", never as a click (see
     * get_expected_data()'s own docblock for how each one used to slip
     * through).
     *
     * @param array $response
     * @return bool
     */
    public function is_complete_response(array $response) {
        return array_key_exists('clickx', $response) && array_key_exists('clicky', $response)
            && qtype_omerohotspot_is_finite_number($response['clickx'])
            && qtype_omerohotspot_is_finite_number($response['clicky']);
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
        return get_string('pleaseclickimage', 'qtype_omerohotspot');
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
     * Grades the response by testing the click against the hidden region.
     *
     * @param array $response
     * @return array
     */
    public function grade_response(array $response) {
        // Core only calls this for a gradable response, but an invalid one
        // is graded as a miss here rather than cast blind - (float) 'abc'
        // would silently become a click at 0.
        $correct = $this->is_complete_response($response)
            && qtype_omerohotspot_check($this->geometry, (float) $response['clickx'], (float) $response['clicky']);
        $fraction = $correct ? 1 : 0;
        return [$fraction, question_state::graded_state_for_fraction($fraction)];
    }

    /**
     * Same as truefalse's own - no external/mobile-app rendering support
     * for this qtype yet (it would need its own bespoke mobile-side OMERO
     * viewer regardless of what's returned here).
     *
     * @param question_attempt $qa
     * @param question_display_options $options
     * @return mixed
     */
    public function get_question_definition_for_external_rendering(question_attempt $qa, question_display_options $options) {
        return null;
    }
}
