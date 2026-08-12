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

namespace local_omeroembed\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_omeroembed\annotations_repository;

/**
 * First slice of the ajax.php -> External Services migration (issue #8):
 * only this one read-only action moves here so far, deliberately not all
 * 14 at once. Chosen over the other 13 for two reasons: it's the only one
 * of the annotation group with a single call site fired once per page
 * load (js/annotate.js's init()), not polled (action=heatmap) or posted
 * every few seconds (action=sample, action=hotspot_attempt) - and
 * core_external\external_api::call_external_function() takes a full
 * session write lock on every call unless the site has opted into
 * $CFG->enable_read_only_sessions (off by default, config-dist.php), even
 * for a function declaring readonlysession=true below. That's exactly the
 * serialisation cost proxy.php's own \core\session\manager::write_close()
 * exists to avoid (see that file's own comment - a measured 6.04s->3.03s
 * difference for concurrent tile requests sharing a session). Migrating a
 * polled or high-frequency action first would risk reintroducing that
 * contention; this one, firing once per page load, is the safe place to
 * find out.
 *
 * The other 13 actions stay on ajax.php entirely unchanged. The 'list'
 * branch there is left in place too (dead but correct) rather than
 * removed this release - annotate.js isn't a long-lived cached asset with
 * its own version-busting, so a stale client mid-deploy is a small but
 * real risk, and removing a working branch buys nothing this slice. Plan
 * to remove it once create/update/delete migrate too and there's one
 * clean cutover point for annotate.js as a whole.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_annotations extends external_api {
    /**
     * Parameter definition for execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id, for the capability check'),
            'embedid' => new external_value(PARAM_ALPHANUMEXT, 'Embed placement id'),
        ]);
    }

    /**
     * Returns the current user's own annotations for one embed placement -
     * the same query and the same per-user row scoping as ajax.php's own
     * action=list branch (annotations_repository::list_for_user()), just
     * reached through the External Services endpoint instead.
     *
     * @param int $courseid
     * @param string $embedid
     * @return array
     */
    public static function execute(int $courseid, string $embedid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'embedid' => $embedid,
        ]);

        $context = \context_course::instance($params['courseid']);
        self::validate_context($context);
        // The 'capabilities' key in db/services.php is documentation
        // only - this call is what actually enforces it, same as
        // ajax.php's own require_capability() call for this action.
        require_capability('local/omeroembed:annotate', $context);

        $records = annotations_repository::list_for_user($params['embedid'], $USER->id);

        return array_map(function ($record) {
            return [
                'id' => (int) $record->id,
                'type' => $record->type,
                // Still the raw JSON string from the DB column, same as
                // the repository method itself returns it - the caller
                // (js/annotate.js) decodes it, matching PARAM_RAW's own
                // precedent for a variant-shape JSON blob (see
                // lib/xapi/classes/external/get_state.php).
                'geometry' => $record->geometry,
                'colour' => $record->colour,
                // Label is NOTNULL=false in the DB (db/install.xml) - a
                // real PHP null here would fail PARAM_TEXT validation in
                // execute_returns() (external_value rejects null unless
                // explicitly declared NULL_ALLOWED), so coerce to '' to
                // match "optional, '' for none" rather than widen the
                // return structure to accept null.
                'label' => $record->label ?? '',
            ];
        }, $records);
    }

    /**
     * Return definition for execute().
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Annotation id'),
            'type' => new external_value(PARAM_ALPHA, 'point, ellipse, rectangle, or polygon'),
            'geometry' => new external_value(
                PARAM_RAW,
                'Shape-specific geometry, JSON-encoded (variant shape per type)'
            ),
            'colour' => new external_value(PARAM_RAW, 'Hex colour, e.g. #ff0000'),
            'label' => new external_value(PARAM_TEXT, 'Optional short note - \'\' for none'),
        ]));
    }
}
