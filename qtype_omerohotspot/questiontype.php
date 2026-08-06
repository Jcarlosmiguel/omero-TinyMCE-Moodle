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
 * Question type class for the OMERO hotspot question type.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * The OMERO hotspot question type class - no question_answers involved
 * (unlike qtype_truefalse), this qtype's entire "answer" is the one row in
 * qtype_omerohotspot_options, so save/load/initialise are simpler than
 * truefalse's own equivalents, not because less care is needed but because
 * there's genuinely only one thing to persist.
 */
class qtype_omerohotspot extends question_type {
    /**
     * Validates and persists the drawn geometry alongside the subject/
     * image/dataset selection.
     *
     * @param object $question
     * @return bool
     */
    public function save_question_options($question) {
        global $DB;

        $geometry = json_decode($question->geometry, true);
        if (
            !is_array($geometry) || !isset(
                $geometry['type'],
                $geometry['x'],
                $geometry['y'],
                $geometry['rx'],
                $geometry['ry']
            )
        ) {
            // The edit form's own JS is what normally populates this -
            // refuse rather than silently save a question with no real
            // answer region, same "never trust the client, even our own
            // form's hidden field" stance local_omeroembed's ajax.php
            // already takes for its own hotspot_save action.
            throw new \moodle_exception('missinggeometry', 'qtype_omerohotspot');
        }

        $options = $DB->get_record('qtype_omerohotspot_options', ['questionid' => $question->id]);
        $record = new \stdClass();
        $record->questionid = $question->id;
        $record->subjectid = (int) $question->subjectid;
        $record->imageid = $question->imageid ?? '';
        $record->datasetid = $question->datasetid ?? '';
        $record->geometry = json_encode($geometry);

        if ($options) {
            $record->id = $options->id;
            $DB->update_record('qtype_omerohotspot_options', $record);
        } else {
            $DB->insert_record('qtype_omerohotspot_options', $record);
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
                'qtype_omerohotspot_options',
                ['questionid' => $question->id]
            )
        ) {
            echo $OUTPUT->notification('Error: Missing question options for omerohotspot question ' .
                    $question->id . '!');
            return false;
        }

        return true;
    }

    /**
     * Copies the saved subject/image/dataset/geometry onto the runtime
     * question instance.
     *
     * @param question_definition $question
     * @param object $questiondata
     */
    protected function initialise_question_instance(question_definition $question, $questiondata) {
        parent::initialise_question_instance($question, $questiondata);

        /** @var qtype_omerohotspot_question $question */
        $question->subjectid = (int) $questiondata->options->subjectid;
        $question->imageid = $questiondata->options->imageid;
        $question->datasetid = $questiondata->options->datasetid;
        $question->geometry = json_decode($questiondata->options->geometry, true);
    }

    /**
     * Deletes this question's saved options alongside the base question.
     *
     * @param int $questionid
     * @param int $contextid
     */
    public function delete_question($questionid, $contextid) {
        global $DB;
        $DB->delete_records('qtype_omerohotspot_options', ['questionid' => $questionid]);

        parent::delete_question($questionid, $contextid);
    }

    // No question_answers involved, so move_files()/delete_files() need no
    // override beyond the parent's own handling of the standard question
    // text/generalfeedback file areas - unlike truefalse, which also has
    // to move/delete its per-answer feedback file areas.

    /**
     * A click anywhere on a whole-slide image landing inside a small
     * marked region by pure chance is negligible - reported as 0 rather
     * than attempting a genuine area-ratio estimate (which would need the
     * full slide's real pixel dimensions, not available here), matching
     * how question_type's own default already treats "true random-guess
     * score genuinely unknown" for other free-response qtypes such as
     * shortanswer.
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
                1 => new question_possible_response(get_string('clickedinregion', 'qtype_omerohotspot'), 1),
                0 => new question_possible_response(get_string('clickedoutsideregion', 'qtype_omerohotspot'), 0),
                null => question_possible_response::no_response(),
            ],
        ];
    }
}
