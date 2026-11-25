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
 * Question type class for the OMERO multi-region hotspot question type.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/omerohotspotmulti/lib.php');

/**
 * The OMERO multi-region hotspot question type class - no question_answers
 * involved (unlike qtype_truefalse), this qtype's entire "answer" is the
 * one row in qtype_omerohotspotmulti_options, so save/load/initialise are
 * simpler than truefalse's own equivalents, not because less care is
 * needed but because there's genuinely only one thing to persist (a JSON
 * array, rather than qtype_omerohotspot's single object).
 */
class qtype_omerohotspotmulti extends question_type {
    /**
     * Validates and persists the drawn region array alongside the
     * subject/image/dataset selection.
     *
     * @param object $question
     * @return bool
     */
    public function save_question_options($question) {
        global $DB;

        $regions = json_decode($question->geometry ?? '', true);
        if (!is_array($regions) || count($regions) < 1) {
            // At least one region required - a question with none is
            // unwinnable (see this plugin's own plan doc's "explicit scope
            // decisions"). The edit form's own JS is what normally
            // populates this - refuse rather than silently save a
            // question with no real answer region, same "never trust the
            // client, even our own form's hidden field" stance
            // qtype_omerohotspot's own save_question_options() already
            // takes.
            throw new \moodle_exception('missinggeometry', 'qtype_omerohotspotmulti');
        }
        // Every region through the same shared rule the edit form's
        // validation() already applied - repeated here for the same
        // "reachable without that form" reason qtype_omerohotspot's own
        // save_question_options() gives. One bad region refuses the whole
        // save rather than being quietly dropped, so the teacher never
        // ends up with fewer regions than they drew without being told.
        $cleanregions = [];
        foreach ($regions as $region) {
            $cleanregion = qtype_omerohotspotmulti_clean_region($region);
            if ($cleanregion === null) {
                throw new \moodle_exception('invalidgeometry', 'qtype_omerohotspotmulti');
            }
            $cleanregions[] = $cleanregion;
        }

        // Optional, unlike $regions above - same reasoning
        // qtype_omerohotspot's own save_question_options() gives on this
        // same line.
        $openingview = '';
        $decodedopeningview = qtype_omerohotspotmulti_clean_openingview(json_decode($question->openingview ?? '', true));
        if ($decodedopeningview !== null) {
            $openingview = json_encode($decodedopeningview);
        }

        $options = $DB->get_record('qtype_omerohotspotmulti_options', ['questionid' => $question->id]);
        $record = new \stdClass();
        $record->questionid = $question->id;
        $record->subjectid = (int) $question->subjectid;
        $record->imageid = $question->imageid ?? '';
        $record->datasetid = $question->datasetid ?? '';
        $record->geometry = json_encode($cleanregions);
        $record->openingview = $openingview;

        if ($options) {
            $record->id = $options->id;
            $DB->update_record('qtype_omerohotspotmulti_options', $record);
        } else {
            $DB->insert_record('qtype_omerohotspotmulti_options', $record);
        }

        $this->save_hints($question);

        return true;
    }

    /**
     * Loads the saved options row for this question.
     *
     * @param object $question
     * @return bool
     */
    public function get_question_options($question) {
        global $DB, $OUTPUT;
        parent::get_question_options($question);

        if (
            !$question->options = $DB->get_record(
                'qtype_omerohotspotmulti_options',
                ['questionid' => $question->id]
            )
        ) {
            echo $OUTPUT->notification('Error: Missing question options for omerohotspotmulti question ' .
                    $question->id . '!');
            return false;
        }

        return true;
    }

    /**
     * Copies the saved subject/image/dataset/regions onto the runtime
     * question instance.
     *
     * @param question_definition $question
     * @param object $questiondata
     */
    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);

        /** @var qtype_omerohotspotmulti_question $question */
        $question->subjectid = (int) $questiondata->options->subjectid;
        $question->imageid = $questiondata->options->imageid;
        $question->datasetid = $questiondata->options->datasetid;
        // Same "validate on load too" reasoning as qtype_omerohotspot's own
        // initialise_question_instance() - unusable regions are dropped
        // here (qtype_omerohotspotmulti_check() would skip them anyway),
        // the rest still count.
        $decodedregions = json_decode($questiondata->options->geometry ?? '', true);
        $question->regions = is_array($decodedregions)
            ? array_values(array_filter(array_map('qtype_omerohotspotmulti_clean_region', $decodedregions)))
            : [];
        $question->openingview = !empty($questiondata->options->openingview)
            ? qtype_omerohotspotmulti_clean_openingview(json_decode($questiondata->options->openingview, true))
            : null;
    }

    /**
     * Deletes this question's saved options alongside the base question.
     *
     * @param int $questionid
     * @param int $contextid
     */
    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_omerohotspotmulti_options', ['questionid' => $questionid]);

        parent::delete_question($questionid, $contextid);
    }

    // No question_answers involved, so move_files()/delete_files() need no
    // override beyond the parent's own handling of the standard question
    // text/generalfeedback file areas - same reasoning qtype_omerohotspot's
    // own questiontype.php already gives.

    /**
     * Same reasoning as qtype_omerohotspot's own equivalent - a click
     * anywhere on a whole-slide image landing inside a small marked region
     * by pure chance is negligible, reported as 0 rather than attempting a
     * genuine area-ratio estimate.
     *
     * @param object $questiondata
     * @return float
     */
    public function get_random_guess_score($questiondata) {
        return 0;
    }

    /**
     * The set of possible response classifications for reports.
     *
     * @param object $questiondata
     * @return array
     */
    public function get_possible_responses($questiondata) {
        return [
            $questiondata->id => [
                1 => new question_possible_response(get_string('clickedinregion', 'qtype_omerohotspotmulti'), 1),
                0 => new question_possible_response(get_string('clickedoutsideregion', 'qtype_omerohotspotmulti'), 0),
                null => question_possible_response::no_response(),
            ],
        ];
    }
}
