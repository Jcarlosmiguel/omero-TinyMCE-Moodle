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

namespace local_omeroembed;

/**
 * $DB wrapper for local_omeroembed_subjects - teacher-owned OMERO
 * service-account connections, replacing the old site-wide settings.php
 * 'subjects' admin textarea (see db/install.xml's own comment on this
 * table for the full reasoning).
 *
 * Every method that reads/edits/deletes a *specific* row takes the
 * requesting $userid and checks it against the row's own owner - a
 * mismatch behaves exactly like "no such row" (mysubjects.php's 404),
 * never "forbidden", so guessing another teacher's id doesn't even
 * confirm it exists. The one exception is get_credentials_by_id(),
 * deliberately unscoped - proxy.php serving a student's request has no
 * reason to know or care which teacher owns the entry being used, same
 * trust model the old global list already had.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subject_repository {
    /**
     * This teacher's own entries (id + name only) - for author.php's
     * dropdown and mysubjects.php's listing.
     *
     * @param int $userid
     * @return \stdClass[] Rows with ->id, ->name, ->omerousername (never the password), ordered by name.
     */
    public static function get_for_user(int $userid): array {
        global $DB;

        return array_values($DB->get_records(
            'local_omeroembed_subjects',
            ['userid' => $userid],
            'name ASC',
            'id, name, omerousername'
        ));
    }

    /**
     * Ownership-checked single lookup, for mysubjects.php's edit form -
     * never includes the password (see get_credentials_by_id() for that).
     *
     * @param int $id
     * @param int $userid
     * @return \stdClass|null Row with ->id, ->name, ->omerousername, or null if not found/not owned by $userid.
     */
    public static function get_by_id_for_user(int $id, int $userid): ?object {
        global $DB;

        $record = $DB->get_record('local_omeroembed_subjects', ['id' => $id, 'userid' => $userid], 'id, name, omerousername');
        return $record ?: null;
    }

    /**
     * Creates a new OMERO subject connection for a teacher.
     *
     * @param int $userid
     * @param string $name
     * @param string $omerousername
     * @param string $omeropassword Plain text - encrypted before storage.
     * @return int The new row's id.
     * @throws \dml_exception If $userid already has an entry named $name (the userid-name unique index).
     */
    public static function create(int $userid, string $name, string $omerousername, string $omeropassword): int {
        global $DB;

        $now = time();
        return $DB->insert_record('local_omeroembed_subjects', (object) [
            'userid' => $userid,
            'name' => $name,
            'omerousername' => $omerousername,
            'omeropassword' => \core\encryption::encrypt($omeropassword),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Updates an existing OMERO subject connection.
     *
     * @param int $id
     * @param int $userid
     * @param string $name
     * @param string $omerousername
     * @param string|null $omeropassword Plain text, or null/'' to keep the
     *        existing password unchanged - mysubjects.php's edit form
     *        never re-displays a stored secret, so a blank submission
     *        means "nothing typed", not "clear the password".
     * @return void
     */
    public static function update(int $id, int $userid, string $name, string $omerousername, ?string $omeropassword): void {
        global $DB;

        $existing = $DB->get_record('local_omeroembed_subjects', ['id' => $id, 'userid' => $userid], '*', MUST_EXIST);

        $existing->name = $name;
        $existing->omerousername = $omerousername;
        if ($omeropassword !== null && $omeropassword !== '') {
            $existing->omeropassword = \core\encryption::encrypt($omeropassword);
        }
        $existing->timemodified = time();

        $DB->update_record('local_omeroembed_subjects', $existing);
    }

    /**
     * Deletes an OMERO subject connection.
     *
     * @param int $id
     * @param int $userid
     * @return void
     */
    public static function delete(int $id, int $userid): void {
        global $DB;

        $DB->delete_records('local_omeroembed_subjects', ['id' => $id, 'userid' => $userid]);
    }

    /**
     * The unscoped lookup omero_session.php itself needs to actually
     * authenticate as this subject - deliberately not ownership-checked,
     * see this class's own docblock for why.
     *
     * @param int $id
     * @return array{username: string, password: string}|null
     */
    public static function get_credentials_by_id(int $id): ?array {
        global $DB;

        $record = $DB->get_record('local_omeroembed_subjects', ['id' => $id]);
        if (!$record) {
            return null;
        }
        return [
            'username' => $record->omerousername,
            'password' => \core\encryption::decrypt($record->omeropassword),
        ];
    }

    /**
     * Permanent backward-compat path for embeds pasted into course content
     * before this table existed, which only ever knew a plain string name
     * (the old site-wide list's own key) - never a row id. Matches
     * *any* owner, since the old list had no concept of ownership; the
     * migration in db/upgrade.php assigns these rows to the site admin,
     * but that's an implementation detail this lookup doesn't depend on.
     *
     * @param string $name
     * @return array{username: string, password: string}|null
     */
    public static function get_credentials_by_legacy_name(string $name): ?array {
        global $DB;

        $record = $DB->get_record('local_omeroembed_subjects', ['name' => $name], '*', IGNORE_MULTIPLE);
        if (!$record) {
            return null;
        }
        return [
            'username' => $record->omerousername,
            'password' => \core\encryption::decrypt($record->omeropassword),
        ];
    }
}
