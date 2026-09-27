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
 * Defines the editing form for the OMERO hotspot question type.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/edit_question_form.php');

/**
 * OMERO hotspot question editing form. The hidden correct-answer region is
 * never typed in as a form field a teacher could read/copy - it's drawn
 * live on an embedded proxy.php preview (reusing local_omeroembed's own
 * locked-down embedding and js/hotspot-author.js's drag-to-draw gesture),
 * which posts the finished geometry up to this page via postMessage (see
 * amd/src/editform.js) into the one hidden 'geometry' field below. There is
 * deliberately no server round-trip during authoring itself (no embedid,
 * no ajax.php call) - the geometry only gets persisted when the whole
 * question form is submitted, same as every other field on this form.
 */
class qtype_omerohotspot_edit_form extends question_edit_form {
    /**
     * Builds the subject/image pickers and the hidden geometry field, plus
     * the live proxy.php preview used to draw the hidden correct-answer
     * region (see this class's own docblock for why there's no server
     * round-trip during authoring itself).
     *
     * @param MoodleQuickForm $mform
     */
    protected function definition_inner($mform) {
        global $OUTPUT, $PAGE;

        $courseid = $this->get_preview_courseid();

        $subjects = \local_omeroembed\subject_repository::get_for_user($this->qtypeobj_userid());
        $subjectchoices = [0 => get_string('choosesubject', 'local_omeroembed')];
        foreach ($subjects as $subject) {
            $subjectchoices[$subject->id] = $subject->name;
        }
        $mform->addElement('select', 'subjectid', get_string('subjectlabel', 'local_omeroembed'), $subjectchoices);
        $mform->addRule('subjectid', null, 'required', null, 'client');

        $mform->addElement('text', 'imageid', get_string('imageidlabel', 'qtype_omerohotspot'));
        $mform->setType('imageid', PARAM_ALPHANUMEXT);

        $mform->addElement('text', 'datasetid', get_string('datasetidlabel', 'qtype_omerohotspot'));
        $mform->setType('datasetid', PARAM_ALPHANUMEXT);

        $mform->addElement('static', 'loadslidehelp', '', get_string('loadslidehelp', 'qtype_omerohotspot'));

        $mform->addElement('hidden', 'geometry', '', ['id' => 'id_geometry']);
        $mform->setType('geometry', PARAM_RAW);

        // Optional - unlike geometry above, a student can be shown a
        // hotspot question with no opening view set at all (OMERO's own
        // default: whole slide, default zoom). Same {x,y,zm} shape and
        // "doesn't touch the live preview when set" behaviour as
        // local_omeroembed's own author.js setOpeningView() - see
        // amd/src/editform.js's own docblock on its opening-view button.
        $mform->addElement('hidden', 'openingview', '', ['id' => 'id_openingview']);
        $mform->setType('openingview', PARAM_RAW);

        if ($courseid === null) {
            // No course to build a locked-down proxy.php URL against (this
            // question category isn't scoped to a course/activity) - refuse
            // to render a broken preview rather than a confusing one.
            $mform->addElement(
                'static',
                'nopreview',
                '',
                $OUTPUT->notification(get_string('needscoursecontext', 'qtype_omerohotspot'), 'warning')
            );
        } else {
            $mform->addElement(
                'static',
                'preview',
                get_string('regionpreview', 'qtype_omerohotspot'),
                // The default mform grid splits this row col-md-3 (label) /
                // col-md-9 (field), leaving the actual drawing area only
                // 75% of the row's width - cramped compared to
                // local_omeroembed's own author.php preview, which isn't
                // fighting a label column for space at all. Stack the
                // label above the field instead (same as Bootstrap's own
                // narrow-viewport behaviour, just forced at every width)
                // so the preview gets the full row.
                // Moodle's own .form-control-static wrapper (which the
                // 'static' element's raw HTML always lands inside) has no
                // explicit width and, as a flex item of .felement above,
                // shrinks to fit its content - live-verified via a real
                // headless-browser check that without this second rule
                // the preview div's own width:100% has nothing real to
                // resolve against and collapses to ~94px regardless of
                // the row-width fix above.
                \html_writer::tag(
                    'style',
                    '#fitem_id_preview .col-md-3, #fitem_id_preview .col-md-9 { flex: 0 0 100%; max-width: 100%; }'
                    . ' #fitem_id_preview .form-control-static { width: 100%; }'
                ) .
                \html_writer::tag(
                    'div',
                    '',
                    ['id' => 'qtype_omerohotspot_preview_wrap', 'data-courseid' => $courseid,
                    'style' => 'width:100%; max-width:900px; height:550px; border:1px solid #ccc;']
                )
                // Beside the preview, always shown here (unlike
                // author.php's own equivalent link, which only appears
                // while hotspot mode is active - this whole form already
                // is one) - deep-links straight to local_omeroembed's own
                // drawing walkthrough (mode buttons, lock, rotate/resize,
                // the centre-out draw gesture, having to switch a mode off
                // again before a region can be selected), the same shared
                // drawing UI this form's own preview uses.
                . \html_writer::link(
                    new \moodle_url(
                        '/local/omeroembed/guide.php',
                        ['courseid' => $courseid],
                        'drawing-and-adjusting-a-region-step-by-step'
                    ),
                    get_string('hotspotdrawinghelplink', 'local_omeroembed'),
                    ['target' => '_blank', 'rel' => 'noopener', 'style' => 'display:inline-block; margin-top:0.4rem;']
                )
            );
            $mform->addElement('static', 'openingviewhelp', '', get_string('openingviewhelp', 'qtype_omerohotspot'));
            $PAGE->requires->js_call_amd(
                'qtype_omerohotspot/editform',
                'init',
                ['qtype_omerohotspot_preview_wrap']
            );
        }
    }

    /**
     * The course this question's category is scoped to, or null if it
     * isn't scoped to a course at all (category/system-level question
     * bank) - proxy.php's own security model requires a real course to
     * check capabilities against (see resolve/require_capability calls
     * throughout that file), so there's no meaningful preview to offer
     * without one.
     *
     * @return int|null
     */
    protected function get_preview_courseid(): ?int {
        $coursecontext = $this->categorycontext->get_course_context(false);
        return $coursecontext ? (int) $coursecontext->instanceid : null;
    }

    /**
     * The current user's id, as used to scope the subject picker.
     *
     * @return int
     */
    protected function qtypeobj_userid(): int {
        global $USER;
        return (int) $USER->id;
    }

    /**
     * Copies the saved subject/image/dataset/geometry/openingview options
     * onto the question object so the form fields above pre-fill on edit.
     *
     * @param object $question
     * @return object
     */
    public function data_preprocessing($question) {
        $question = parent::data_preprocessing($question);

        if (!empty($question->options)) {
            $question->subjectid = $question->options->subjectid;
            $question->imageid = $question->options->imageid;
            $question->datasetid = $question->options->datasetid;
            $question->geometry = $question->options->geometry;
            // Round-trips into the hidden 'openingview' field untouched -
            // a re-edit that never clicks "Set as opening view" again
            // keeps whatever was saved before, same as geometry above.
            $question->openingview = $question->options->openingview ?? '';
        }

        return $question;
    }

    /**
     * Requires a subject, an image or dataset, and a drawn geometry before
     * the question can be saved.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['subjectid'])) {
            $errors['subjectid'] = get_string('required');
        }
        if (empty($data['imageid']) && empty($data['datasetid'])) {
            $errors['imageid'] = get_string('missingimageordataset', 'local_omeroembed');
        }
        $geometry = json_decode($data['geometry'] ?? '', true);
        if (!is_array($geometry) || !isset($geometry['type'], $geometry['x'], $geometry['y'], $geometry['rx'], $geometry['ry'])) {
            $errors['loadslidehelp'] = get_string('missinggeometry', 'qtype_omerohotspot');
        }

        return $errors;
    }

    /**
     * The question type name this form edits.
     *
     * @return string
     */
    public function qtype() {
        return 'omerohotspot';
    }
}
