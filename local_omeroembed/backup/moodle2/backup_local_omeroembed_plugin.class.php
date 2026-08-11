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
 * Backup code for local_omeroembed.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Attaches this plugin's 8 courseid-scoped tables to course backup, via the
 * generic 'local' plugin course connectionpoint (backup_course_structure_step
 * calls add_plugin_structure('local', $course, true) for every local_ plugin -
 * see backup/moodle2/backup_stepslib.php). local_omeroembed_subjects is
 * deliberately NOT included here - it's owned by the teacher, not the course
 * (see its own table comment in db/install.xml), and isn't something a course
 * backup/restore/duplicate should copy or fork.
 *
 * The 4 tables with no userid column (embed_tracking, heatmap_frames,
 * hotspots, hotspot_multi - note hotspots/hotspot_multi's own createdby IS a
 * user reference, handled below) are always included. The 4 genuinely
 * personal-data tables (annotations, view_samples, hotspot_attempts,
 * hotspot_multi_attempts) are gated behind the root "Include enrolled users"
 * backup setting, same as core's own per-activity userinfo gating.
 */
class backup_local_omeroembed_plugin extends backup_local_plugin {
    /**
     * Returns the local_omeroembed information to attach to the course element.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $trackings = new backup_nested_element('embed_trackings');
        $tracking = new backup_nested_element('embed_tracking', ['id'], [
            'embedid', 'enabled', 'trackinguntil', 'sourceurl', 'timecreated', 'timemodified',
        ]);
        $pluginwrapper->add_child($trackings);
        $trackings->add_child($tracking);
        $tracking->set_source_table('local_omeroembed_embed_tracking', ['courseid' => backup::VAR_COURSEID]);

        $frames = new backup_nested_element('heatmap_frames');
        $frame = new backup_nested_element('heatmap_frame', ['id'], [
            'embedid', 'framedata', 'timecreated',
        ]);
        $pluginwrapper->add_child($frames);
        $frames->add_child($frame);
        $frame->set_source_table('local_omeroembed_heatmap_frames', ['courseid' => backup::VAR_COURSEID]);

        $hotspots = new backup_nested_element('hotspots');
        $hotspot = new backup_nested_element('hotspot', ['id'], [
            'embedid', 'createdby', 'geometry', 'timecreated', 'timemodified',
        ]);
        $pluginwrapper->add_child($hotspots);
        $hotspots->add_child($hotspot);
        $hotspot->set_source_table('local_omeroembed_hotspots', ['courseid' => backup::VAR_COURSEID]);
        $hotspot->annotate_ids('user', 'createdby');

        $hotspotmultis = new backup_nested_element('hotspot_multis');
        $hotspotmulti = new backup_nested_element('hotspot_multi', ['id'], [
            'embedid', 'createdby', 'geometry', 'timecreated', 'timemodified',
        ]);
        $pluginwrapper->add_child($hotspotmultis);
        $hotspotmultis->add_child($hotspotmulti);
        $hotspotmulti->set_source_table('local_omeroembed_hotspot_multi', ['courseid' => backup::VAR_COURSEID]);
        $hotspotmulti->annotate_ids('user', 'createdby');

        // The 4 personal-data tables below are only attached to the tree at
        // all when the "Include enrolled users" backup setting is on -
        // omitted entirely (not just left empty) when it's off, matching how
        // core's own activity backups gate personal fields on their
        // per-activity userinfo setting. There's no per-activity equivalent
        // here (this is course-level, not per-module), so the root 'users'
        // setting is the right one to check - base_task::get_setting()
        // falls back to plan-level settings when a name isn't found on the
        // immediate task, which is how a course-connectionpoint plugin can
        // see a root-level setting at all.
        if ($this->get_setting_value('users')) {
            $annotations = new backup_nested_element('annotations');
            $annotation = new backup_nested_element('annotation', ['id'], [
                'userid', 'embedid', 'type', 'geometry', 'colour', 'label', 'timecreated', 'timemodified',
            ]);
            $pluginwrapper->add_child($annotations);
            $annotations->add_child($annotation);
            $annotation->set_source_table('local_omeroembed_annotations', ['courseid' => backup::VAR_COURSEID]);
            $annotation->annotate_ids('user', 'userid');

            $samples = new backup_nested_element('view_samples');
            $sample = new backup_nested_element('view_sample', ['id'], [
                'userid', 'embedid', 'x', 'y', 'zoompercent', 'timecreated',
            ]);
            $pluginwrapper->add_child($samples);
            $samples->add_child($sample);
            $sample->set_source_table('local_omeroembed_view_samples', ['courseid' => backup::VAR_COURSEID]);
            $sample->annotate_ids('user', 'userid');

            $hotspotattempts = new backup_nested_element('hotspot_attempts');
            $hotspotattempt = new backup_nested_element('hotspot_attempt', ['id'], [
                'userid', 'embedid', 'x', 'y', 'correct', 'timecreated',
            ]);
            $pluginwrapper->add_child($hotspotattempts);
            $hotspotattempts->add_child($hotspotattempt);
            $hotspotattempt->set_source_table('local_omeroembed_hotspot_attempts', ['courseid' => backup::VAR_COURSEID]);
            $hotspotattempt->annotate_ids('user', 'userid');

            $hotspotmultiattempts = new backup_nested_element('hotspot_multi_attempts');
            $hotspotmultiattempt = new backup_nested_element('hotspot_multi_attempt', ['id'], [
                'userid', 'embedid', 'x', 'y', 'correct', 'timecreated',
            ]);
            $pluginwrapper->add_child($hotspotmultiattempts);
            $hotspotmultiattempts->add_child($hotspotmultiattempt);
            $hotspotmultiattempt->set_source_table(
                'local_omeroembed_hotspot_multi_attempts',
                ['courseid' => backup::VAR_COURSEID]
            );
            $hotspotmultiattempt->annotate_ids('user', 'userid');
        }

        return $plugin;
    }
}
