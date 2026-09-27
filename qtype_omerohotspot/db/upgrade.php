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
 * @package    qtype_omerohotspot
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Function to upgrade qtype_omerohotspot.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool result
 */
function xmldb_qtype_omerohotspot_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092600) {
        // Optional opening-view position - see db/install.xml's own
        // COMMENT on this field for what it's for.
        $table = new xmldb_table('qtype_omerohotspot_options');
        $field = new xmldb_field('openingview', XMLDB_TYPE_TEXT, null, null, null, null, null, 'geometry');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026092600, 'qtype', 'omerohotspot');
    }

    return true;
}
