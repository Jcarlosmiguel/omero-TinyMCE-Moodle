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

namespace local_omeroembed\task;

/**
 * Cleans up local_omeroembed_embed_tracking rows for embeds that no longer
 * exist anywhere in their course - the case classes/observer.php's
 * course_deleted handler can't cover, because an individual embed is a
 * free-floating token (embedid, minted by js/author.js's generateEmbed())
 * pasted into arbitrary HTML content - a Page, a Label, a Forum post, a
 * Book chapter - with no Moodle-tracked link back to a single course_module.
 * There is no clean, reliable event to hook for "this specific embed was
 * removed": course_module_deleted fires for the *container* (e.g. a Page
 * activity), not the embed itself, and the same embedid could in principle
 * appear in more than one place, or the deleted module might not be the
 * only place it lives. So instead of an event, this is a scheduled
 * reachability sweep: for every currently-tracked embed, search its
 * course's actual content for a surviving reference, and purge the
 * tracking row if none is found. Confirmed with the user this is the
 * accepted trade-off (a periodic sweep, not real-time) given the
 * architecture.
 *
 * Deliberately scoped to local_omeroembed_embed_tracking only, not
 * view_samples/heatmap_frames - those already self-clean on an
 * age-based schedule via purge_view_samples.php regardless of whether
 * their parent tracking row still exists, so duplicating that logic here
 * would be redundant, not more correct.
 *
 * Registered in db/tasks.php with a default daily schedule.
 *
 * KNOWN LIMITATION: content is searched via a generic pass over every
 * installed activity module that follows Moodle's own course+intro column
 * convention (covers the large majority of module types, including
 * third-party ones added later, without hardcoding names), plus a
 * documented set of known rich-content fields beyond intro: mod_page's own
 * content, mod_book chapters, mod_forum posts, mod_glossary entries,
 * mod_lesson pages. NOT covered: mod_data entries (EAV field storage, no
 * single plausible content column) and versioned wiki page content
 * (mod_wiki) - an embed pasted only into one of those will not be detected
 * as still-referenced and its tracking row may be purged even though the
 * embed is technically still live. Acceptable given how unlikely those
 * activity types are as a host for a pasted iframe embed, but a real,
 * documented gap, not an oversight.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_orphaned_embed_tracking extends \core\task\scheduled_task {
    /**
     * The task's name, shown in the admin scheduled tasks list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purgeorphanedembedtracking', 'local_omeroembed');
    }

    /**
     * Sweeps every tracked embed and purges the ones no longer referenced
     * anywhere in their course's content.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $tracked = $DB->get_records('local_omeroembed_embed_tracking', null, '', 'id, courseid, embedid');
        if (!$tracked) {
            mtrace('No tracked embeds - nothing to sweep.');
            return;
        }

        $bycourse = [];
        foreach ($tracked as $row) {
            $bycourse[$row->courseid][] = $row;
        }

        $purged = 0;
        $checked = 0;
        foreach ($bycourse as $courseid => $rows) {
            if (!$DB->record_exists('course', ['id' => $courseid])) {
                // observer::course_deleted() should already have caught
                // this course's rows - belt-and-braces only, in case some
                // other deletion route didn't fire that event.
                continue;
            }
            foreach ($rows as $row) {
                $checked++;
                if (!self::embedid_still_referenced($courseid, $row->embedid)) {
                    $DB->delete_records('local_omeroembed_embed_tracking', ['id' => $row->id]);
                    $purged++;
                }
            }
        }

        mtrace("Checked {$checked} tracked embed(s), purged {$purged} whose embedid no longer appears anywhere in their course's content.");
    }

    /**
     * Whether embedid still appears in course courseid's own content -
     * see this class's own docblock for exactly what is and isn't
     * searched.
     *
     * @param int $courseid
     * @param string $embedid
     * @return bool
     */
    private static function embedid_still_referenced(int $courseid, string $embedid): bool {
        global $DB;

        // The embed's iframe src carries this as a literal query param
        // (js/author.js's generateEmbed(): iframeSrcUrl.searchParams.set(
        // 'embedid', ...)) - a substring search for this exact pair is
        // robust to whatever else surrounds it in the stored HTML
        // (attribute quoting, entity-encoded "&" before it, etc.).
        $needle = 'embedid=' . $embedid;

        // Generic pass: any installed activity module whose own table
        // follows Moodle's standard course+intro column convention -
        // covers the large majority of module types (including
        // third-party ones) without hardcoding every module name.
        $modules = $DB->get_records('modules', [], '', 'id, name');
        foreach ($modules as $module) {
            $table = $module->name;
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $columns = $DB->get_columns($table);
            if (!isset($columns['course']) || !isset($columns['intro'])) {
                continue;
            }
            if (self::table_contains($table, 'course', $courseid, 'intro', $needle)) {
                return true;
            }
        }

        // Known rich-content fields beyond the generic intro convention.
        if (self::table_contains('page', 'course', $courseid, 'content', $needle)) {
            return true;
        }
        if ($DB->get_manager()->table_exists('book_chapters')) {
            $sql = "SELECT bc.id FROM {book_chapters} bc JOIN {book} b ON b.id = bc.bookid
                    WHERE b.course = :courseid AND " . $DB->sql_like('bc.content', ':needle');
            if ($DB->record_exists_sql($sql, self::like_params($courseid, $needle))) {
                return true;
            }
        }
        if ($DB->get_manager()->table_exists('forum_posts')) {
            $sql = "SELECT fp.id FROM {forum_posts} fp
                    JOIN {forum_discussions} fd ON fd.id = fp.discussion
                    WHERE fd.course = :courseid AND " . $DB->sql_like('fp.message', ':needle');
            if ($DB->record_exists_sql($sql, self::like_params($courseid, $needle))) {
                return true;
            }
        }
        if ($DB->get_manager()->table_exists('glossary_entries')) {
            $sql = "SELECT ge.id FROM {glossary_entries} ge JOIN {glossary} g ON g.id = ge.glossaryid
                    WHERE g.course = :courseid AND " . $DB->sql_like('ge.definition', ':needle');
            if ($DB->record_exists_sql($sql, self::like_params($courseid, $needle))) {
                return true;
            }
        }
        if ($DB->get_manager()->table_exists('lesson_pages')) {
            $sql = "SELECT lp.id FROM {lesson_pages} lp JOIN {lesson} l ON l.id = lp.lessonid
                    WHERE l.course = :courseid AND " . $DB->sql_like('lp.contents', ':needle');
            if ($DB->record_exists_sql($sql, self::like_params($courseid, $needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * One table's own "does courseid's content column contain needle" check.
     *
     * @param string $table
     * @param string $coursecol
     * @param int $courseid
     * @param string $contentcol
     * @param string $needle
     * @return bool
     */
    private static function table_contains(string $table, string $coursecol, int $courseid, string $contentcol, string $needle): bool {
        global $DB;

        $select = "{$coursecol} = :courseid AND " . $DB->sql_like($contentcol, ':needle');
        return $DB->record_exists_select($table, $select, self::like_params($courseid, $needle));
    }

    /**
     * @param int $courseid
     * @param string $needle
     * @return array{courseid: int, needle: string}
     */
    private static function like_params(int $courseid, string $needle): array {
        global $DB;

        return ['courseid' => $courseid, 'needle' => '%' . $DB->sql_like_escape($needle) . '%'];
    }
}
