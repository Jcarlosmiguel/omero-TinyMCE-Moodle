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
 * Defines the editing form for the OMERO multi-region hotspot question type.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/edit_question_form.php');

/**
 * OMERO multi-region hotspot question editing form - a sibling of
 * qtype_omerohotspot_edit_form, not a mode of it. The hidden set of
 * correct-answer regions is never typed in as a form field a teacher could
 * read/copy - it's drawn live on an embedded proxy.php preview (reusing
 * local_omeroembed's own locked-down embedding and
 * js/hotspot-multi-qtype-author.js's drag-to-draw/select/delete gestures),
 * which posts the whole current array up to this page via postMessage (see
 * amd/src/editform.js) into the one hidden 'geometry' field below. There is
 * deliberately no server round-trip during authoring itself (no embedid,
 * no ajax.php call) - the regions only get persisted when the whole
 * question form is submitted, same as every other field on this form.
 */
class qtype_omerohotspotmulti_edit_form extends question_edit_form {
    /**
     * Builds the subject/image pickers and the hidden geometry field, plus
     * the live proxy.php preview used to draw the hidden correct-answer
     * regions (see this class's own docblock for why there's no server
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

        $mform->addElement('text', 'imageid', get_string('imageidlabel', 'qtype_omerohotspotmulti'));
        $mform->setType('imageid', PARAM_ALPHANUMEXT);

        $mform->addElement('text', 'datasetid', get_string('datasetidlabel', 'qtype_omerohotspotmulti'));
        $mform->setType('datasetid', PARAM_ALPHANUMEXT);

        $mform->addElement('static', 'loadslidehelp', '', get_string('loadslidehelp', 'qtype_omerohotspotmulti'));

        // A JSON-encoded array (never a single object) - see this class's
        // own docblock. Explicit 'id' attribute needed for the same reason
        // qtype_omerohotspot's own edit form gives: Moodle mform hidden
        // fields don't auto-get an id, and amd/src/editform.js needs one
        // to find this field.
        $mform->addElement('hidden', 'geometry', '', ['id' => 'id_geometry']);
        $mform->setType('geometry', PARAM_RAW);

        if ($courseid === null) {
            // No course to build a locked-down proxy.php URL against (this
            // question category isn't scoped to a course/activity) - refuse
            // to render a broken preview rather than a confusing one.
            $mform->addElement(
                'static',
                'nopreview',
                '',
                $OUTPUT->notification(get_string('needscoursecontext', 'qtype_omerohotspotmulti'), 'warning')
            );
        } else {
            $mform->addElement(
                'static',
                'preview',
                get_string('regionpreview', 'qtype_omerohotspotmulti'),
                \html_writer::tag(
                    'div',
                    '',
                    ['id' => 'qtype_omerohotspotmulti_preview_wrap', 'data-courseid' => $courseid,
                    'style' => 'width:100%; max-width:900px; height:550px; border:1px solid #ccc;']
                )
            );
            $PAGE->requires->js_call_amd(
                'qtype_omerohotspotmulti/editform',
                'init',
                ['qtype_omerohotspotmulti_preview_wrap']
            );
        }
    }

    /**
     * The course this question's category is scoped to, or null if it
     * isn't scoped to a course at all - identical reasoning to
     * qtype_omerohotspot's own equivalent.
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
     * Copies the saved subject/image/dataset/geometry options onto the
     * question object so the form fields above pre-fill on edit.
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
        }

        return $question;
    }

    /**
     * Requires a subject, an image or dataset, and at least one
     * well-formed drawn region before the question can be saved.
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
        $regions = json_decode($data['geometry'] ?? '', true);
        if (!is_array($regions) || count($regions) < 1) {
            $errors['loadslidehelp'] = get_string('missinggeometry', 'qtype_omerohotspotmulti');
        } else {
            foreach ($regions as $region) {
                if (!is_array($region) || !isset($region['type'], $region['x'], $region['y'], $region['rx'], $region['ry'])) {
                    $errors['loadslidehelp'] = get_string('missinggeometry', 'qtype_omerohotspotmulti');
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * The question type name this form edits.
     *
     * @return string
     */
    public function qtype() {
        return 'omerohotspotmulti';
    }
}
