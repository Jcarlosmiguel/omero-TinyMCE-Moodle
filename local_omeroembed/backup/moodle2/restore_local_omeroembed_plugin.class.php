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
 * Restore code for local_omeroembed.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore counterpart of backup_local_omeroembed_plugin - see that class's
 * own docblock for the overall shape (8 tables, embedid always copied
 * through unchanged as an opaque grouping token - never a foreign key to any
 * Moodle entity, same rationale as local_omeroembed_embed_tracking's own
 * table comment - courseid always remapped to the restore target course,
 * userid/createdby remapped via get_mappingid('user', ...) and the row
 * skipped if that mapping can't be found, e.g. a personal-data row present
 * in a backup that excluded user data shouldn't ever happen, but skipping
 * defensively is safer than inserting a row with userid=0).
 *
 * SCOPE NOTE: this only restores the 8 database rows. The actual
 * <iframe src="…/proxy.php/{courseid}/{subject}?…embedid=…"> HTML a teacher
 * pasted into a Label/Page is plain content core's own link-decoder doesn't
 * know how to rewrite (it's not a pluginfile.php/@@PLUGINFILE@@/course-
 * module-id pattern core recognises) - it gets copied into the restored
 * course verbatim, old courseid and all. That's a real, separate gap (a
 * duplicated/restored embed's iframe still points at the *original*
 * course's proxy.php path), pre-existing and unrelated to whether these 8
 * tables round-trip - out of scope here, since the reviewer's report was
 * specifically about the rows being silently dropped, not about rewriting
 * embed HTML. Would need its own restore_decode_content()/content-decoder
 * pass if ever tackled.
 */
class restore_local_omeroembed_plugin extends restore_local_plugin {
    /**
     * Returns the paths to be handled by this plugin at course level.
     *
     * @return array
     */
    protected function define_course_plugin_structure() {
        $paths = [];

        $paths[] = new restore_path_element('local_omeroembed_embed_tracking', $this->get_pathfor('/embed_trackings/embed_tracking'));
        $paths[] = new restore_path_element('local_omeroembed_heatmap_frame', $this->get_pathfor('/heatmap_frames/heatmap_frame'));
        $paths[] = new restore_path_element('local_omeroembed_hotspot', $this->get_pathfor('/hotspots/hotspot'));
        $paths[] = new restore_path_element('local_omeroembed_hotspot_multi', $this->get_pathfor('/hotspot_multis/hotspot_multi'));
        $paths[] = new restore_path_element('local_omeroembed_annotation', $this->get_pathfor('/annotations/annotation'));
        $paths[] = new restore_path_element('local_omeroembed_view_sample', $this->get_pathfor('/view_samples/view_sample'));
        $paths[] = new restore_path_element('local_omeroembed_hotspot_attempt', $this->get_pathfor('/hotspot_attempts/hotspot_attempt'));
        $paths[] = new restore_path_element(
            'local_omeroembed_hotspot_multi_attempt',
            $this->get_pathfor('/hotspot_multi_attempts/hotspot_multi_attempt')
        );

        return $paths;
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_embed_tracking($data) {
        global $DB;

        $data = (object) $data;
        if ($DB->record_exists('local_omeroembed_embed_tracking', ['embedid' => $data->embedid])) {
            // embedid has a site-wide UNIQUE index (one tracking row per
            // embed placement) - see this class's own SCOPE NOTE for why it
            // is never remapped. Confirmed live: restoring/duplicating a
            // course whose original (still-present) copy has the same
            // embedid throws an uncaught dml_write_exception here, which
            // aborts the *entire* course restore, not just this plugin's
            // own data - far worse than silently skipping one row. This
            // can only ever collide with the row this same embedid already
            // legitimately owns (embedid values are randomly-generated
            // per-placement tokens - see js/author.js's generateEmbed()),
            // so skipping is always the correct choice, never a
            // false-positive dedup against unrelated data.
            return;
        }
        $data->courseid = $this->task->get_courseid();
        $DB->insert_record('local_omeroembed_embed_tracking', $data);
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_heatmap_frame($data) {
        global $DB;

        $data = (object) $data;
        $data->courseid = $this->task->get_courseid();
        $DB->insert_record('local_omeroembed_heatmap_frames', $data);
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_hotspot($data) {
        global $DB;

        $data = (object) $data;
        // See process_local_omeroembed_embed_tracking()'s own comment - same
        // site-wide UNIQUE-on-embedid collision risk, same defensive skip.
        if ($DB->record_exists('local_omeroembed_hotspots', ['embedid' => $data->embedid])) {
            return;
        }
        $newcreatedby = $this->get_mappingid('user', $data->createdby);
        if (!$newcreatedby) {
            return;
        }
        $data->courseid = $this->task->get_courseid();
        $data->createdby = $newcreatedby;
        $DB->insert_record('local_omeroembed_hotspots', $data);
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_hotspot_multi($data) {
        global $DB;

        $data = (object) $data;
        // See process_local_omeroembed_embed_tracking()'s own comment - same
        // site-wide UNIQUE-on-embedid collision risk, same defensive skip.
        if ($DB->record_exists('local_omeroembed_hotspot_multi', ['embedid' => $data->embedid])) {
            return;
        }
        $newcreatedby = $this->get_mappingid('user', $data->createdby);
        if (!$newcreatedby) {
            return;
        }
        $data->courseid = $this->task->get_courseid();
        $data->createdby = $newcreatedby;
        $DB->insert_record('local_omeroembed_hotspot_multi', $data);
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_annotation($data) {
        global $DB;

        $data = (object) $data;
        $newuserid = $this->get_mappingid('user', $data->userid);
        if (!$newuserid) {
            return;
        }
        $data->courseid = $this->task->get_courseid();
        $data->userid = $newuserid;
        $DB->insert_record('local_omeroembed_annotations', $data);
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_view_sample($data) {
        global $DB;

        $data = (object) $data;
        $newuserid = $this->get_mappingid('user', $data->userid);
        if (!$newuserid) {
            return;
        }
        $data->courseid = $this->task->get_courseid();
        $data->userid = $newuserid;
        $DB->insert_record('local_omeroembed_view_samples', $data);
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_hotspot_attempt($data) {
        global $DB;

        $data = (object) $data;
        $newuserid = $this->get_mappingid('user', $data->userid);
        if (!$newuserid) {
            return;
        }
        $data->courseid = $this->task->get_courseid();
        $data->userid = $newuserid;
        $DB->insert_record('local_omeroembed_hotspot_attempts', $data);
    }

    /**
     * @param array|object $data
     */
    public function process_local_omeroembed_hotspot_multi_attempt($data) {
        global $DB;

        $data = (object) $data;
        $newuserid = $this->get_mappingid('user', $data->userid);
        if (!$newuserid) {
            return;
        }
        $data->courseid = $this->task->get_courseid();
        $data->userid = $newuserid;
        $DB->insert_record('local_omeroembed_hotspot_multi_attempts', $data);
    }
}
