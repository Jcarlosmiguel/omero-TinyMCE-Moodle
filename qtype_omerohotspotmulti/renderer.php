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
 * OMERO multi-region hotspot question renderer class.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Generates the output for OMERO multi-region hotspot questions. Embeds
 * the same locked-down local_omeroembed/proxy.php iframe the standalone
 * multi-region hotspot activity already uses, in its "qtype attempt" mode
 * (see that file's own inject_hotspot_multi_qtype_attempt_script()) - the
 * student's click never triggers an AJAX grading call the way the
 * standalone activity's does; it only ever writes into this question's own
 * two hidden qt fields (clickx/clicky), submitted with the rest of the
 * attempt form exactly like qtype_omerohotspot's own equivalent.
 */
class qtype_omerohotspotmulti_renderer extends qtype_renderer {
    /**
     * Renders the question text, the hidden click-position fields, and the
     * locked-down proxy.php preview the student clicks on.
     *
     * @param question_attempt $qa
     * @param question_display_options $options
     * @return string
     */
    public function formulation_and_controls(
        question_attempt $qa,
        question_display_options $options
    ) {

        /** @var qtype_omerohotspotmulti_question $question */
        $question = $qa->get_question();

        $result = html_writer::tag('div', $question->format_questiontext($qa), ['class' => 'qtext']);

        $clickxname = $qa->get_qt_field_name('clickx');
        $clickyname = $qa->get_qt_field_name('clicky');
        $clickx = $qa->get_last_qt_var('clickx', '');
        $clicky = $qa->get_last_qt_var('clicky', '');

        // Classed (not just id'd) so amd/src/question.js can find "its
        // own" pair scoped within this question's own outer div - same
        // reasoning qtype_omerohotspot's own renderer gives. Kept a
        // distinct class name from that qtype's own (rather than reusing
        // it) purely for isolation, in case both ever render on the same
        // attempt page.
        $result .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'id' => str_replace(':', '_', $clickxname),
            'name' => $clickxname, 'value' => $clickx, 'class' => 'qtype_omerohotspotmulti_clickx',
        ]);
        $result .= html_writer::empty_tag('input', [
            'type' => 'hidden', 'id' => str_replace(':', '_', $clickyname),
            'name' => $clickyname, 'value' => $clicky, 'class' => 'qtype_omerohotspotmulti_clicky',
        ]);

        $courseid = $this->page->course->id ?? SITEID;
        $proxyurl = new \moodle_url("/local/omeroembed/proxy.php/{$courseid}/{$question->subjectid}", array_filter([
            'images' => $question->imageid,
            'dataset' => $question->datasetid,
            'enablehotspotmulti' => 1,
            'hotspotmode' => 'qtypemulti',
        ]));

        $wrapid = 'qtype_omerohotspotmulti_attempt_' . $qa->get_slot();
        $result .= html_writer::start_div('qtype_omerohotspotmulti_answer', ['id' => $wrapid]);
        if ($clickx !== '' && $clicky !== '') {
            $result .= html_writer::div(
                get_string(
                    'currentanswer',
                    'qtype_omerohotspotmulti',
                    (object) ['x' => round((float) $clickx), 'y' => round((float) $clicky)]
                ),
                'qtype_omerohotspotmulti_currentanswer'
            );
        }
        $result .= html_writer::tag('iframe', '', [
            'src' => $proxyurl->out(false),
            'style' => 'width:100%; max-width:900px; height:550px; border:1px solid #ccc; display:block;',
            'allowfullscreen' => 'allowfullscreen',
        ]);
        $result .= html_writer::end_div();

        $this->page->requires->js_call_amd('qtype_omerohotspotmulti/question', 'init', [
            $qa->get_outer_question_div_unique_id(),
            $wrapid,
            (bool) $options->readonly,
        ]);

        if ($qa->get_state() == question_state::$invalid) {
            $result .= html_writer::nonempty_tag(
                'div',
                $question->get_validation_error($qa->get_last_qt_data()),
                ['class' => 'validationerror']
            );
        }

        return $result;
    }

    /**
     * No per-answer feedback text stored (no question_answers row at all
     * for this qtype) - only the generic combined feedback
     * (correctfeedback/incorrectfeedback question-level fields) core
     * already renders for any question_graded_automatically, which this
     * class doesn't need to override.
     *
     * @param question_attempt $qa
     * @return string
     */
    public function specific_feedback(question_attempt $qa) {
        return '';
    }

    /**
     * Deliberately blank - see
     * qtype_omerohotspotmulti_question::get_correct_response()'s own
     * docblock for why the regions stay undescribed even here.
     *
     * @param question_attempt $qa
     * @return string
     */
    public function correct_response(question_attempt $qa) {
        return '';
    }
}
