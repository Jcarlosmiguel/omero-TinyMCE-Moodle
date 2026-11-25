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
 * confirm it exists. get_credentials_by_id() is deliberately not
 * scoped to the *requesting* user - proxy.php serving a student's request
 * legitimately authenticates as a teacher's entry the student doesn't own.
 * But it is no longer unscoped altogether: proxy.php (and the heatmap
 * capture task) resolve the {subject} path segment through
 * resolve_for_course() first, which ties the entry to the course being
 * requested via its owner's own role there - see that method's docblock
 * for the real, confirmed gap that closes (the old "same trust model the
 * old global list already had" reasoning stopped holding the moment
 * entries became per-teacher: "only you can see or use them" was the
 * promise, and any logged-in user could use any of them by id).
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subject_repository {
    /**
     * @var string What an entry's owner must hold in a course for that
     * entry to be usable from it at all - see resolve_for_course(). The
     * same capability author.php itself requires before it will build an
     * embed (or render the subject dropdown an entry is picked from) in a
     * course, so "the owner could have legitimately authored an embed
     * here" is exactly the condition being checked, nothing broader.
     */
    const OWNER_CAPABILITY = 'moodle/course:manageactivities';

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
     * Resolves a {subject} path segment (proxy.php's own URL, or a
     * tracking row's stored sourceurl) to the one row id that should
     * actually be authenticated as for a request scoped to $courseid - or
     * null if there's no such row, OR there is one but its owner holds no
     * OWNER_CAPABILITY in $courseid. Callers treat null exactly like
     * "no such subject" (unknownsubject), never "forbidden" - same
     * don't-confirm-it-exists convention as every other lookup here.
     *
     * SECURITY: confirmed real gap this closes - {subject} is a plain,
     * sequential local_omeroembed_subjects.id in every embed's own iframe
     * src (visible via view-source), and proxy.php used to hand it straight
     * to omero_session::get_session() with no check beyond require_login()
     * on the *declared* courseid. Any user enrolled in any course could
     * swap in another id and browse, through /api/ and /webgateway/,
     * whatever that teacher's OMERO account can see - from their own
     * course's proxy.php path, against another teacher's credentials.
     *
     * Why "owner has a teaching role in this course" and not something
     * per-embed: nothing in this plugin's data model records which subject
     * a given embed uses - embedid is optional (embeds from before the
     * annotation feature have none), the qtype modes never have one, and
     * the only table that does store a subject (embed_tracking.sourceurl)
     * only exists for embeds a teacher has opened heatmap.php for. An
     * entry's owner (userid, already on every row) is the one binding
     * every legitimate mode shares: author.php only ever offers the
     * current teacher's own entries, in a course they already hold
     * OWNER_CAPABILITY in, and the qtype edit forms do the same. Known,
     * accepted consequence: an embed stops working in a course once its
     * entry's owner no longer holds OWNER_CAPABILITY there (e.g. they left
     * the course) - another teacher re-picks one of their own entries.
     * has_capability() rather than is_enrolled() so category-level
     * managers and site admins (the owner of every entry migrated from the
     * old site-wide list - see db/upgrade.php's 2026080100 step) still
     * count, and so a deleted owner fails closed (has_capability() returns
     * false for a deleted user).
     *
     * Legacy plain-name segments (see omero_session::
     * get_credentials_for_subject()) are resolved here too, not just
     * numeric ids - otherwise a name would be a second, unchecked way to
     * reach exactly the same rows, since get_credentials_by_legacy_name()
     * matches any owner, including a teacher's own new-style entry that
     * happens to share an old name. Every matching row is considered,
     * oldest first (the migrated admin-owned original always predates any
     * teacher's later same-named entry), and the first one whose owner is
     * actually authorised wins - deterministic, unlike the bare
     * IGNORE_MULTIPLE pick get_credentials_by_legacy_name() itself makes.
     * Returning the resolved *id* (not a yes/no) is deliberate: callers
     * then authenticate as exactly the row that was checked, rather than
     * re-resolving the name a second time and possibly landing on a
     * different one.
     *
     * @param string $subject Raw {subject} path segment.
     * @param int $courseid The course the request is scoped to.
     * @return int|null The local_omeroembed_subjects.id to authenticate as, or null.
     */
    public static function resolve_for_course(string $subject, int $courseid): ?int {
        global $DB;

        if ($subject === '') {
            return null;
        }
        $conditions = ctype_digit($subject) ? ['id' => (int) $subject] : ['name' => $subject];
        $candidates = $DB->get_records('local_omeroembed_subjects', $conditions, 'id ASC', 'id, userid');

        $context = \context_course::instance($courseid);
        foreach ($candidates as $candidate) {
            if (has_capability(self::OWNER_CAPABILITY, $context, (int) $candidate->userid)) {
                return (int) $candidate->id;
            }
        }
        return null;
    }

    /**
     * The lookup omero_session.php itself needs to actually authenticate
     * as this subject - deliberately not checked against the *requesting*
     * user, see this class's own docblock for why. Callers serving a
     * course-scoped request must resolve the id via resolve_for_course()
     * first, not pass a raw request value straight through to here.
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
