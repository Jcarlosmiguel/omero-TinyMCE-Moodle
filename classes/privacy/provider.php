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
 * Privacy Subsystem implementation for local_omeroembed.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_omeroembed\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * This plugin stores one kind of personal data: a student's own point
 * annotations on an embedded OMERO slide (local_omeroembed_annotations).
 * Everything else this plugin stores - OMERO subject-account credentials
 * (admin-configured plugin settings) and the short-lived OMERO session
 * cache (classes/omero_session.php) - belongs to shared service accounts,
 * not to any individual user, and is intentionally not covered here.
 *
 * Annotations are scoped to CONTEXT_COURSE, not CONTEXT_MODULE - an embed
 * lives inside arbitrary course content (a Page, a quiz question, ...)
 * with no course-module of its own, so the course itself is the only
 * meaningful context to hang this on (matches how
 * local/omeroembed:annotate is also checked at course context).
 */
class provider implements
        // This plugin stores personal data.
        \core_privacy\local\metadata\provider,

        // This plugin is a core_user_data_provider.
        \core_privacy\local\request\plugin\provider,

        // This plugin is capable of determining which users have data within it.
        \core_privacy\local\request\core_userlist_provider {

    /**
     * Return the fields which contain personal data.
     *
     * @param collection $items a reference to the collection to use to store the metadata.
     * @return collection the updated collection of metadata items.
     */
    public static function get_metadata(collection $items): collection {
        $items->add_database_table(
            'local_omeroembed_annotations',
            [
                'courseid' => 'privacy:metadata:local_omeroembed_annotations:courseid',
                'userid' => 'privacy:metadata:local_omeroembed_annotations:userid',
                'embedid' => 'privacy:metadata:local_omeroembed_annotations:embedid',
                'geometry' => 'privacy:metadata:local_omeroembed_annotations:geometry',
                'colour' => 'privacy:metadata:local_omeroembed_annotations:colour',
                'timecreated' => 'privacy:metadata:local_omeroembed_annotations:timecreated',
            ],
            'privacy:metadata:local_omeroembed_annotations'
        );

        return $items;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid the userid.
     * @return contextlist the list of contexts containing user info for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT c.id
                  FROM {context} c
            INNER JOIN {local_omeroembed_annotations} a
                    ON a.courseid = c.instanceid AND c.contextlevel = :contextlevel
                 WHERE a.userid = :userid";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof \context_course) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_omeroembed_annotations} WHERE courseid = :courseid', [
            'courseid' => $context->instanceid,
        ]);
    }

    /**
     * Export personal data for the given approved_contextlist.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for export.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }

            $annotations = $DB->get_records('local_omeroembed_annotations', [
                'courseid' => $context->instanceid,
                'userid' => $user->id,
            ]);
            if (!$annotations) {
                continue;
            }

            $data = array_map(function ($annotation) {
                return [
                    'embedid' => $annotation->embedid,
                    'type' => $annotation->type,
                    'geometry' => $annotation->geometry,
                    'colour' => $annotation->colour,
                    'timecreated' => \core_privacy\local\request\transform::datetime($annotation->timecreated),
                ];
            }, array_values($annotations));

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_omeroembed')],
                (object) ['annotations' => $data]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if (!$context instanceof \context_course) {
            return;
        }

        $DB->delete_records('local_omeroembed_annotations', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist a list of contexts approved for deletion.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $DB->delete_records('local_omeroembed_annotations', ['courseid' => $context->instanceid, 'userid' => $userid]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }

        $userids = $userlist->get_userids();
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $select = "courseid = :courseid AND userid $usersql";
        $params = ['courseid' => $context->instanceid] + $userparams;
        $DB->delete_records_select('local_omeroembed_annotations', $select, $params);
    }
}
