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
 * Backup code for qtype_omerohotspot.
 *
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Provides the information to backup omerohotspot questions - modelled on
 * qtype_ddmarker's own backup class (a data-heavy qtype with its own extra
 * table), not qtype_truefalse's (which only reuses core question_answers) -
 * this qtype's "answer" doesn't fit that core table at all. No
 * get_qtype_fileareas() override needed - unlike ddmarker's background
 * image, OMERO hosts this question's slide, nothing here is ever stored
 * as a Moodle file.
 */
class backup_qtype_omerohotspot_plugin extends backup_qtype_plugin {
    /**
     * Returns the qtype information to attach to question element.
     *
     * @return backup_plugin_element
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '../../qtype', 'omerohotspot');

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $options = new backup_nested_element('omerohotspot', ['id'], [
            'subjectid', 'imageid', 'datasetid', 'geometry',
        ]);
        $pluginwrapper->add_child($options);
        $options->set_source_table(
            'qtype_omerohotspot_options',
            ['questionid' => backup::VAR_PARENTID]
        );

        // The subjectid field is a foreign key into local_omeroembed_subjects, owned
        // by whichever teacher created the connection - not something
        // backup/restore can meaningfully remap across a course restore
        // into a different site/owner, so it's left as a plain value, not
        // annotated as an id reference. A restored question whose original
        // subjectid no longer resolves on the destination site will simply
        // need its subject re-picked in the question bank - the same
        // manual step already required when local_omeroembed_hotspots
        // itself moves between sites.
        return $plugin;
    }
}
