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

require_once($CFG->dirroot . '/question/type/omerohotspotmulti/lib.php');

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
     * Same "backup is untrusted input" validation and log-and-carry-on
     * fallback as qtype_omerohotspot's own restore class (see its
     * docblock), adapted for an array of regions: each region is checked
     * individually and only the unusable ones are dropped, so one bad
     * region doesn't cost the teacher every good one. If none survive,
     * an empty array is stored - the question then grades every click as
     * a miss until regions are redrawn.
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

            $decodedregions = json_decode($data->geometry ?? '', true);
            $cleanregions = [];
            $droppedcount = 0;
            if (is_array($decodedregions)) {
                foreach ($decodedregions as $region) {
                    $cleanregion = qtype_omerohotspotmulti_clean_region($region);
                    if ($cleanregion === null) {
                        $droppedcount++;
                    } else {
                        $cleanregions[] = $cleanregion;
                    }
                }
            }
            if (!is_array($decodedregions) || $droppedcount > 0 || !$cleanregions) {
                $this->task->log('qtype_omerohotspotmulti: restored question ' . $newquestionid . ' kept ' .
                    count($cleanregions) . ' valid correct-answer region(s), dropped ' .
                    (is_array($decodedregions) ? $droppedcount : 'unreadable geometry') .
                    ' - redraw any missing regions before use', backup::LOG_WARNING);
            }
            $data->geometry = json_encode($cleanregions);

            // Same "absent in older backups, left alone" handling as
            // qtype_omerohotspot's own restore class.
            if (!empty($data->openingview)) {
                $openingview = qtype_omerohotspotmulti_clean_openingview(json_decode($data->openingview, true));
                if ($openingview === null) {
                    $this->task->log('qtype_omerohotspotmulti: invalid opening view in restored question ' .
                        $newquestionid . ' - restored with none (OMERO default view)', backup::LOG_WARNING);
                    $data->openingview = '';
                } else {
                    $data->openingview = json_encode($openingview);
                }
            }

            $newitemid = $DB->insert_record('qtype_omerohotspotmulti_options', $data);
            $this->set_mapping('qtype_omerohotspotmulti_options', $oldid, $newitemid);
        }
    }
}
