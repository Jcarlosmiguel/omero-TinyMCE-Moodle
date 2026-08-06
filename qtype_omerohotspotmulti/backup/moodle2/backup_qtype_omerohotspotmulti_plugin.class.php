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
 * Backup code for qtype_omerohotspotmulti.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Provides the information to backup omerohotspotmulti questions -
 * identical pattern to qtype_omerohotspot's own backup class (itself
 * modelled on qtype_ddmarker's), just sourced from this qtype's own
 * options table. No get_qtype_fileareas() override needed - same
 * reasoning as qtype_omerohotspot's own equivalent.
 */
class backup_qtype_omerohotspotmulti_plugin extends backup_qtype_plugin {
    /**
     * Returns the qtype information to attach to question element.
     *
     * @return backup_plugin_element
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '../../qtype', 'omerohotspotmulti');

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $options = new backup_nested_element('omerohotspotmulti', ['id'], [
            'subjectid', 'imageid', 'datasetid', 'geometry',
        ]);
        $pluginwrapper->add_child($options);
        $options->set_source_table(
            'qtype_omerohotspotmulti_options',
            ['questionid' => backup::VAR_PARENTID]
        );

        // The subjectid field is a foreign key into local_omeroembed_subjects, owned
        // by whichever teacher created the connection - not something
        // backup/restore can meaningfully remap across a course restore
        // into a different site/owner, same known limitation
        // qtype_omerohotspot's own backup class already documents.
        return $plugin;
    }
}
