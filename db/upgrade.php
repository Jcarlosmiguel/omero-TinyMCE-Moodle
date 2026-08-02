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
 * Plugin upgrade code.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Function to upgrade local_omeroembed.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool result
 */
function xmldb_local_omeroembed_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026073002) {
        // Define table local_omeroembed_annotations to be created - see
        // db/install.xml's own COMMENT on this table for what it's for.
        $table = new xmldb_table('local_omeroembed_annotations');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'point');
        $table->add_field('geometry', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('colour', XMLDB_TYPE_CHAR, '7', null, XMLDB_NOTNULL, null, '#ff0000');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('embedid', XMLDB_INDEX_NOTUNIQUE, ['embedid']);
        $table->add_index('embedid-userid', XMLDB_INDEX_NOTUNIQUE, ['embedid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026073002, 'local', 'omeroembed');
    }

    if ($oldversion < 2026073004) {
        // Optional short student-entered note, shown when a pin is clicked -
        // see db/install.xml's own COMMENT on this field.
        $table = new xmldb_table('local_omeroembed_annotations');
        $field = new xmldb_field('label', XMLDB_TYPE_TEXT, null, null, null, null, null, 'colour');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026073004, 'local', 'omeroembed');
    }

    if ($oldversion < 2026073006) {
        // 'circle' is superseded by 'ellipse' (a circle is just the rx===ry
        // case, drawn by holding Shift - see js/annotate.js and
        // annotations_repository::TYPE_ELLIPSE's own comment) - migrate any
        // existing rows' type and geometry {x,y,r} -> {x,y,rx,ry} rather
        // than leaving old data in a shape this version no longer renders.
        $rs = $DB->get_recordset('local_omeroembed_annotations', ['type' => 'circle']);
        foreach ($rs as $record) {
            $geometry = json_decode($record->geometry, true);
            $record->type = 'ellipse';
            $record->geometry = json_encode([
                'x' => $geometry['x'],
                'y' => $geometry['y'],
                'rx' => $geometry['r'],
                'ry' => $geometry['r'],
            ]);
            $DB->update_record('local_omeroembed_annotations', $record);
        }
        $rs->close();

        upgrade_plugin_savepoint(true, 2026073006, 'local', 'omeroembed');
    }

    if ($oldversion < 2026073010) {
        // Teacher heatmap feature: per-embed tracking on/off + gather-window -
        // see db/install.xml's own COMMENT on this table for what it's for.
        $table = new xmldb_table('local_omeroembed_embed_tracking');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('trackinguntil', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('sourceurl', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        $table->add_index('embedid', XMLDB_INDEX_UNIQUE, ['embedid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026073010, 'local', 'omeroembed');
    }

    if ($oldversion < 2026073012) {
        // Periodic viewport-position samples feeding the heatmap - see
        // db/install.xml's own COMMENT on this table for what it's for and
        // classes/privacy/provider.php for why this is real personal data.
        $table = new xmldb_table('local_omeroembed_view_samples');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('x', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('y', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('zoompercent', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('embedid-timecreated', XMLDB_INDEX_NOTUNIQUE, ['embedid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026073012, 'local', 'omeroembed');
    }

    if ($oldversion < 2026073015) {
        // Periodic server-side heatmap frames feeding the downloadable video
        // - see db/install.xml's own COMMENT on this table for what it's for.
        $table = new xmldb_table('local_omeroembed_heatmap_frames');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('framedata', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);

        $table->add_index('embedid-timecreated', XMLDB_INDEX_NOTUNIQUE, ['embedid', 'timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026073015, 'local', 'omeroembed');
    }

    if ($oldversion < 2026080100) {
        // Teacher-owned OMERO connections, replacing the old site-wide
        // settings.php 'subjects' admin textarea - see db/install.xml's own
        // COMMENT on this table for the full reasoning.
        $table = new xmldb_table('local_omeroembed_subjects');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('omerousername', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('omeropassword', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('userid-name', XMLDB_INDEX_UNIQUE, ['userid', 'name']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // One-time migration: every "name|username|password" line from the
        // old site-wide textarea becomes an admin-owned row, name preserved
        // as-is - see omero_session.php's get_credentials_for_subject() for
        // why keeping the original name matters (it's the permanent
        // fallback lookup for every embed already pasted into course
        // content, which only ever knew that name, never a row id).
        $raw = (string) get_config('local_omeroembed', 'subjects');
        $adminid = get_admin()->id;
        $now = time();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode('|', $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$name, $username, $password] = array_map('trim', $parts);
            if ($name === '' || $DB->record_exists('local_omeroembed_subjects', ['userid' => $adminid, 'name' => $name])) {
                continue;
            }
            $DB->insert_record('local_omeroembed_subjects', (object) [
                'userid' => $adminid,
                'name' => $name,
                'omerousername' => $username,
                'omeropassword' => \core\encryption::encrypt($password),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        unset_config('subjects', 'local_omeroembed');

        upgrade_plugin_savepoint(true, 2026080100, 'local', 'omeroembed');
    }

    if ($oldversion < 2026080200) {
        // Hidden correct-answer region for the click-to-answer hotspot
        // feature - see db/install.xml's own COMMENT on this table for
        // what it's for and classes/hotspot_repository.php for how it's
        // used. Geometry never leaves the server.
        $table = new xmldb_table('local_omeroembed_hotspots');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('geometry', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('createdby', XMLDB_KEY_FOREIGN, ['createdby'], 'user', ['id']);

        $table->add_index('embedid', XMLDB_INDEX_UNIQUE, ['embedid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080200, 'local', 'omeroembed');
    }

    if ($oldversion < 2026080201) {
        // Every hotspot attempt, right or wrong - see db/install.xml's own
        // COMMENT on this table for what it's for.
        $table = new xmldb_table('local_omeroembed_hotspot_attempts');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('embedid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('x', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('y', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('correct', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('embedid-userid', XMLDB_INDEX_NOTUNIQUE, ['embedid', 'userid']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080201, 'local', 'omeroembed');
    }

    return true;
}
