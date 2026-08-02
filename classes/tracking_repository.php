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
 * Plain $DB wrapper for the teacher heatmap feature's two tables -
 * local_omeroembed_embed_tracking (a teacher's on/off + gather-window
 * setting for one embed placement) and local_omeroembed_view_samples (the
 * actual periodic viewport samples that setting produces). Kept separate
 * from annotations_repository since these are a genuinely different
 * feature with a different capability (local/omeroembed:viewheatmap, not
 * :annotate) - see db/access.php.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tracking_repository {
    /** @var int Default gather window offered on a never-configured embed - a single class session. */
    const DEFAULT_GATHERMINUTES = 60;

    /** @var int Hard cap on how many samples heatmap.php ever renders/fetches in one go. */
    const MAX_SAMPLES = 5000;

    /**
     * The current tracking configuration for one embed placement - the
     * defaults below (not a DB row at all) if a teacher has never touched
     * the tracking panel for it.
     *
     * @param string $embedid
     * @return array {enabled: bool, gatherminutes: int, trackinguntil: int|null, sourceurl: string}
     */
    public static function get_settings(string $embedid): array {
        global $DB;

        $record = $DB->get_record('local_omeroembed_embed_tracking', ['embedid' => $embedid]);
        if (!$record) {
            return [
                'enabled' => false,
                'gatherminutes' => self::DEFAULT_GATHERMINUTES,
                'trackinguntil' => null,
                'sourceurl' => '',
            ];
        }

        $gatherminutes = self::DEFAULT_GATHERMINUTES;
        if ($record->trackinguntil) {
            // gatherminutes itself isn't stored (only its consequence,
            // trackinguntil, is) - reconstructed here purely so the
            // tracking panel can show teachers roughly what they last
            // entered rather than always resetting the input back to the
            // default. Rounded, not exact, since it's derived from
            // timemodified rather than a stored value.
            $gatherminutes = max(1, (int) round(($record->trackinguntil - $record->timemodified) / MINSECS));
        }

        return [
            'enabled' => (bool) $record->enabled,
            'gatherminutes' => $gatherminutes,
            'trackinguntil' => $record->trackinguntil ? (int) $record->trackinguntil : null,
            'sourceurl' => (string) $record->sourceurl,
        ];
    }

    /**
     * Upserts a teacher's tracking configuration for one embed placement.
     * Every save recomputes trackinguntil fresh from "now" whenever
     * $enabled is true - so changing gatherminutes, or simply re-saving
     * while already enabled, always restarts the gather window from the
     * current moment rather than trying to reason about a partially-elapsed
     * one.
     *
     * @param int $courseid
     * @param string $embedid
     * @param bool $enabled
     * @param int $gatherminutes Must be >= 1 - caller (heatmap.php) validates this.
     * @param string $sourceurl config.baseProxyUrl (see author.js) - the
     *                          same base proxy.php URL the real embed's own
     *                          iframe src is built from. heatmap.php needs
     *                          this to build its own iframe, since nothing
     *                          else records which OMERO subject/image an
     *                          embedid points to - see this table's own
     *                          COMMENT in db/install.xml.
     * @return \stdClass The upserted row.
     */
    public static function set_settings(
        int $courseid,
        string $embedid,
        bool $enabled,
        int $gatherminutes,
        string $sourceurl
    ): \stdClass {
        global $DB;

        $now = time();
        $record = $DB->get_record('local_omeroembed_embed_tracking', ['embedid' => $embedid]);

        if (!$record) {
            $record = (object) [
                'courseid' => $courseid,
                'embedid' => $embedid,
                'enabled' => $enabled ? 1 : 0,
                'trackinguntil' => $enabled ? ($now + $gatherminutes * MINSECS) : null,
                'sourceurl' => $sourceurl,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $record->id = $DB->insert_record('local_omeroembed_embed_tracking', $record);
            return $record;
        }

        $record->enabled = $enabled ? 1 : 0;
        $record->trackinguntil = $enabled ? ($now + $gatherminutes * MINSECS) : $record->trackinguntil;
        // Only overwritten with a non-empty value - a re-save from a page
        // that, for whatever reason, didn't have config.baseProxyUrl handy
        // shouldn't blank out a previously-recorded one.
        if ($sourceurl !== '') {
            $record->sourceurl = $sourceurl;
        }
        $record->timemodified = $now;
        $DB->update_record('local_omeroembed_embed_tracking', $record);
        return $record;
    }

    /**
     * How many samples exist for one embed placement, without touching
     * them - used by heatmap.php to decide whether the download/delete
     * buttons should be enabled at all (confirmed with the user: an embed
     * that's stopped tracking but never actually gathered anything
     * shouldn't offer to download or delete nothing).
     *
     * @param string $embedid
     * @return int
     */
    public static function count_samples(string $embedid): int {
        global $DB;

        return $DB->count_records('local_omeroembed_view_samples', ['embedid' => $embedid]);
    }

    /**
     * Deletes all gathered samples for one embed placement and turns
     * tracking off for it (confirmed with the user - otherwise a forgotten
     * still-enabled embed would quietly start gathering fresh data again
     * right after being cleared). A single atomic action from the caller's
     * point of view - see heatmap.php's delete form, the only caller.
     *
     * @param string $embedid
     * @return int The number of sample rows deleted.
     */
    public static function delete_samples(string $embedid): int {
        global $DB;

        $count = $DB->count_records('local_omeroembed_view_samples', ['embedid' => $embedid]);
        $DB->delete_records('local_omeroembed_view_samples', ['embedid' => $embedid]);
        $DB->set_field('local_omeroembed_embed_tracking', 'enabled', 0, ['embedid' => $embedid]);
        return $count;
    }

    /**
     * All gathered samples for one embed placement, for export.php's CSV
     * download - deliberately uncapped, unlike get_samples() below (which
     * exists specifically to bound the heatmap *render*, not to be a
     * complete record). Ordered by userid then timecreated so export.php
     * can group consecutive rows into one "Student N" per userid in a
     * single pass.
     *
     * @param string $embedid
     * @return array Rows as stdClass (userid, x, y, zoompercent, timecreated).
     */
    public static function get_all_samples_for_export(string $embedid): array {
        global $DB;

        return array_values($DB->get_records(
            'local_omeroembed_view_samples',
            ['embedid' => $embedid],
            'userid ASC, timecreated ASC',
            'id, userid, x, y, zoompercent, timecreated'
        ));
    }

    /**
     * Site-wide retention purge - deletes any view_samples row older than
     * $cutoff, regardless of embedid. Called by
     * \local_omeroembed\task\purge_view_samples on whatever schedule
     * db/tasks.php registers (admin-editable via Site administration >
     * Server > Scheduled tasks) - this is the backstop that keeps the
     * table bounded even if a teacher never manually deletes anything.
     *
     * @param int $cutoff Unix timestamp - rows older than this are deleted.
     * @return int The number of rows deleted.
     */
    public static function purge_older_than(int $cutoff): int {
        global $DB;

        $count = $DB->count_records_select('local_omeroembed_view_samples', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        $DB->delete_records_select('local_omeroembed_view_samples', 'timecreated < :cutoff', ['cutoff' => $cutoff]);
        return $count;
    }

    /**
     * Whether samples should currently be recorded for this embed placement -
     * the single source of truth checked both by proxy.php (to decide
     * whether to inject js/track.js at all) and ajax.php's action=sample
     * (re-checked server-side, never trusting that the client only calls
     * this because proxy.php injected the tracker - same "never trust the
     * client" posture the rest of this plugin already uses).
     *
     * @param string $embedid
     * @return bool
     */
    public static function is_active(string $embedid): bool {
        global $DB;

        $record = $DB->get_record('local_omeroembed_embed_tracking', ['embedid' => $embedid]);
        if (!$record || !$record->enabled || !$record->trackinguntil) {
            return false;
        }
        return $record->trackinguntil > time();
    }

    /**
     * Records one viewport sample, but only if tracking is actually active
     * for this embed right now - see is_active()'s own comment for why
     * this is re-checked here rather than trusted from the caller.
     *
     * @param int $courseid
     * @param string $embedid
     * @param int $userid
     * @param float $x Image-pixel x of the viewport centre.
     * @param float $y Image-pixel y of the viewport centre.
     * @param float $zoompercent
     * @return bool True if a sample was actually recorded.
     */
    public static function record_sample(
        int $courseid,
        string $embedid,
        int $userid,
        float $x,
        float $y,
        float $zoompercent
    ): bool {
        global $DB;

        if (!self::is_active($embedid)) {
            return false;
        }

        $DB->insert_record('local_omeroembed_view_samples', (object) [
            'courseid' => $courseid,
            'embedid' => $embedid,
            'userid' => $userid,
            'x' => $x,
            'y' => $y,
            'zoompercent' => $zoompercent,
            'timecreated' => time(),
        ]);
        return true;
    }

    /**
     * The most recent samples gathered for one embed placement, for
     * heatmap.php's density render - capped at MAX_SAMPLES rather than
     * returning an unbounded, potentially huge result set.
     *
     * @param string $embedid
     * @return array Rows as stdClass (id, x, y, zoompercent, timecreated).
     */
    public static function get_samples(string $embedid): array {
        global $DB;

        return array_values($DB->get_records(
            'local_omeroembed_view_samples',
            ['embedid' => $embedid],
            'timecreated DESC',
            'id, x, y, zoompercent, timecreated',
            0,
            self::MAX_SAMPLES
        ));
    }
}
