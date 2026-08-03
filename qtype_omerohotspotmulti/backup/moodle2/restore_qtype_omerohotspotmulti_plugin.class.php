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
 * Restore code for qtype_omerohotspotmulti.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Restore plugin class for the omerohotspotmulti question type plugin -
 * identical pattern to qtype_omerohotspot's own restore class.
 */
class restore_qtype_omerohotspotmulti_plugin extends restore_qtype_plugin {
    /**
     * Returns the paths to be handled by the plugin at question level.
     *
     * @return array
     */
    protected function define_question_plugin_structure() {
        $paths = [];

        $elename = 'omerohotspotmulti';
        $elepath = $this->get_pathfor('/omerohotspotmulti');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths;
    }

    /**
     * Process the qtype/omerohotspotmulti element.
     *
     * @param array|object $data
     */
    public function process_omerohotspotmulti($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $newquestionid = $this->get_new_parentid('question');
        $questioncreated = $this->get_mappingid('question_created', $this->get_old_parentid('question')) ? true : false;

        if ($questioncreated) {
            $data->questionid = $newquestionid;
            $newitemid = $DB->insert_record('qtype_omerohotspotmulti_options', $data);
            $this->set_mapping('qtype_omerohotspotmulti_options', $oldid, $newitemid);
        }
    }
}
