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
 * Event observers.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Purges every row this plugin holds for a deleted course, across all
     * eight of its own tables - unlike an individual embed being abandoned
     * (see classes/task/purge_orphaned_embed_tracking.php's own docblock
     * for why that case can't be handled this cleanly), a whole course
     * being deleted is unambiguous: nothing scoped to that courseid should
     * survive it, full stop, regardless of table.
     *
     * Deliberately course_deleted only, not course_content_deleted (course
     * reset) - a reset keeps the course itself and is a distinct, narrower
     * operation this plugin doesn't attempt to hook today.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;

        $courseid = $event->objectid;

        $tables = [
            'local_omeroembed_annotations',
            'local_omeroembed_embed_tracking',
            'local_omeroembed_view_samples',
            'local_omeroembed_heatmap_frames',
            'local_omeroembed_hotspots',
            'local_omeroembed_hotspot_attempts',
            'local_omeroembed_hotspot_multi',
            'local_omeroembed_hotspot_multi_attempts',
        ];

        $total = 0;
        foreach ($tables as $table) {
            $total += $DB->count_records($table, ['courseid' => $courseid]);
            $DB->delete_records($table, ['courseid' => $courseid]);
        }

        if ($total > 0) {
            mtrace(get_string('mtrace_purgedcoursedata', 'local_omeroembed', (object) [
                'total' => $total,
                'tables' => count($tables),
                'courseid' => $courseid,
            ]));
        }
    }

    /** @var string[] The two hotspot question type names this plugin ships alongside. */
    private const HOTSPOT_QTYPES = ['omerohotspot', 'omerohotspotmulti'];

    /**
     * A newly-added slot might be a hotspot question sharing a page with
     * whatever was already there - enforce its own page. Fires for both a
     * single "Add to quiz" click and a bulk "Select multiple items" add
     * (core loops one quiz_add_quiz_question() call per question either
     * way, each firing its own slot_created).
     *
     * @param \mod_quiz\event\slot_created $event
     * @return void
     */
    public static function quiz_slot_created(\mod_quiz\event\slot_created $event): void {
        self::enforce_hotspot_own_page((int) $event->other['quizid']);
    }

    /**
     * Deleting a question can leave two other slots (one of them a
     * hotspot) newly adjacent and sharing a page where they weren't
     * before - re-check the whole quiz rather than trying to reason about
     * just the gap left behind.
     *
     * @param \mod_quiz\event\slot_deleted $event
     * @return void
     */
    public static function quiz_slot_deleted(\mod_quiz\event\slot_deleted $event): void {
        self::enforce_hotspot_own_page((int) $event->other['quizid']);
    }

    /**
     * Dragging a slot to a new position can land it next to a hotspot
     * question on the same page.
     *
     * @param \mod_quiz\event\slot_moved $event
     * @return void
     */
    public static function quiz_slot_moved(\mod_quiz\event\slot_moved $event): void {
        self::enforce_hotspot_own_page((int) $event->other['quizid']);
    }

    /**
     * The quiz's own bulk "Repaginate" action (a fixed N-questions-per-page
     * pass over every slot) would otherwise merge a hotspot question back
     * onto a shared page even after slot_created's own fix already gave it
     * one, since repagination rewrites every slot's page number outright -
     * this is exactly the case shown live: 3 questions (including a
     * hotspot) landing together on "Page 1" after a bulk add, each needing
     * its own page restored.
     *
     * @param \mod_quiz\event\quiz_repaginated $event
     * @return void
     */
    public static function quiz_repaginated(\mod_quiz\event\quiz_repaginated $event): void {
        self::enforce_hotspot_own_page((int) $event->objectid);
    }

    /**
     * The question type a quiz slot currently points at - resolved through
     * question_references/question_versions (a slot references a question
     * bank *entry*, at a specific version or "always latest"), not a plain
     * questionid column - quiz_slots hasn't stored one directly since
     * Moodle's question-versioning changes. A qtype never varies between
     * versions of the same entry, so picking the latest version whenever
     * qr.version is null (rather than resolving "the exact version this
     * slot pins") is safe here even though it would matter for the
     * question's actual content.
     *
     * @param int $slotid
     * @return string|null
     */
    private static function slot_qtype(int $slotid): ?string {
        global $DB;

        $sql = "SELECT q.qtype
                  FROM {question_references} qr
                  JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                       AND (qr.version IS NULL OR qv.version = qr.version)
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qr.itemid = :slotid
                   AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
              ORDER BY qv.version DESC";
        $qtype = $DB->get_field_sql($sql, ['slotid' => $slotid], IGNORE_MULTIPLE);

        return $qtype !== false ? $qtype : null;
    }

    /**
     * Ensures every hotspot-qtype slot in this quiz has no other slot
     * sharing its page, on either side - both hotspot qtypes load a full
     * OMERO slide viewer with its own click/drawing surface, which reads
     * as genuinely confusing (competing click targets, cramped side-by-
     * side viewers) rather than just untidy when more than one shares a
     * page, confirmed directly: "for these type of question a page break
     * in between is better".
     *
     * Implemented as a fixed-point loop rather than reasoning about one
     * slot in isolation: inserting a break renumbers every later page via
     * structure::update_page_break()'s own refresh_page_numbers_and_update_db(),
     * which would make a second slot's already-fetched page number stale -
     * simplest and most robust is to re-fetch fresh, fix one violation,
     * and re-scan from scratch, until nothing is left to fix. Quiz
     * structure edits are rare/low-volume, so the extra re-scans cost
     * nothing that matters.
     *
     * Never throws - wrapped defensively (see break_before_slot()'s own
     * comment) so this enhancement can never turn into a fatal error on
     * whatever real teacher action (add/delete/move/repaginate) triggered
     * it.
     *
     * @param int $quizid
     * @return void
     */
    private static function enforce_hotspot_own_page(int $quizid): void {
        global $DB;

        for ($i = 0; $i < 100; $i++) {
            $slots = array_values($DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot'));

            $fixed = false;
            foreach ($slots as $index => $slot) {
                if (!in_array(self::slot_qtype((int) $slot->id), self::HOTSPOT_QTYPES, true)) {
                    continue;
                }

                $previous = $slots[$index - 1] ?? null;
                $next = $slots[$index + 1] ?? null;

                if ($previous && (int) $previous->page === (int) $slot->page) {
                    self::break_before_slot($quizid, (int) $slot->id);
                    $fixed = true;
                    break;
                }
                if ($next && (int) $next->page === (int) $slot->page) {
                    self::break_before_slot($quizid, (int) $next->id);
                    $fixed = true;
                    break;
                }
            }

            if (!$fixed) {
                return;
            }
        }
    }

    /**
     * Inserts a page break immediately before the given slot, reusing
     * core's own mod_quiz\structure/repaginate machinery rather than
     * writing to quiz_slots directly - real page-renumbering logic
     * (refresh_page_numbers_and_update_db()) and the matching
     * page_break_created event live there, not worth re-deriving.
     *
     * Silently gives up rather than throwing - structure::check_can_be_edited()
     * throws if the quiz already has attempts, which would otherwise turn
     * this enhancement into a fatal error on whatever real action (a
     * course restore replaying old events, for instance) triggered the
     * observer that called this.
     *
     * @param int $quizid
     * @param int $slotid
     * @return void
     */
    private static function break_before_slot(int $quizid, int $slotid): void {
        try {
            $quizobj = \mod_quiz\quiz_settings::create($quizid);
            $structure = \mod_quiz\structure::create_for_quiz($quizobj);
            $structure->update_page_break($slotid, \mod_quiz\repaginate::UNLINK);
        } catch (\Throwable $e) {
            // See this method's own docblock for why this is swallowed,
            // not rethrown.
            return;
        }
    }
}
