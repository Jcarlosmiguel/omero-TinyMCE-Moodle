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
 * Restore code for qtype_omerohotspot.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/type/omerohotspot/lib.php');

/**
 * Restore plugin class for the omerohotspot question type plugin -
 * modelled on qtype_ddmarker's own restore class.
 */
class restore_qtype_omerohotspot_plugin extends restore_qtype_plugin {
    /**
     * Returns the paths to be handled by the plugin at question level.
     *
     * @return array
     */
    protected function define_question_plugin_structure() {
        $paths = [];

        $elename = 'omerohotspot';
        $elepath = $this->get_pathfor('/omerohotspot');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths;
    }

    /**
     * Process the qtype/omerohotspot element.
     *
     * A backup file is just as untrusted as the edit form's hidden fields
     * (crafted, corrupt, or made before this plugin validated geometry at
     * all), so geometry and openingview go through the same shared lib.php
     * validation saving does. A failure doesn't abort the restore - same
     * "log a warning and carry on with what's safe" approach core's own
     * restore_qtype_multianswer_plugin takes for an invalid sequence -
     * it stores the documented empty defaults instead: no geometry (the
     * question loads, grades every click as a miss, and its edit form
     * then demands a region be drawn) and no opening view (OMERO's own
     * default view).
     *
     * @param array|object $data
     */
    public function process_omerohotspot($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $newquestionid = $this->get_new_parentid('question');
        $questioncreated = $this->get_mappingid('question_created', $this->get_old_parentid('question')) ? true : false;

        if ($questioncreated) {
            $data->questionid = $newquestionid;

            $geometry = qtype_omerohotspot_clean_geometry(json_decode($data->geometry ?? '', true));
            if ($geometry === null) {
                $this->task->log('qtype_omerohotspot: invalid correct-answer region in restored question ' .
                    $newquestionid . ' - restored with no region, redraw it before use', backup::LOG_WARNING);
                $data->geometry = '';
            } else {
                $data->geometry = json_encode($geometry);
            }

            // Absent entirely in a backup made before openingview existed
            // (see version.php's 2026092604 note) - left alone in that case.
            if (!empty($data->openingview)) {
                $openingview = qtype_omerohotspot_clean_openingview(json_decode($data->openingview, true));
                if ($openingview === null) {
                    $this->task->log('qtype_omerohotspot: invalid opening view in restored question ' .
                        $newquestionid . ' - restored with none (OMERO default view)', backup::LOG_WARNING);
                    $data->openingview = '';
                } else {
                    $data->openingview = json_encode($openingview);
                }
            }

            $newitemid = $DB->insert_record('qtype_omerohotspot_options', $data);
            $this->set_mapping('qtype_omerohotspot_options', $oldid, $newitemid);
        }
    }
}
