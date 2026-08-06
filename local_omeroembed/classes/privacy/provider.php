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

/**
 * This plugin stores five kinds of personal data: a student's own point
 * annotations on an embedded OMERO slide (local_omeroembed_annotations),
 * periodic samples of a student's own viewport position while viewing a
 * tracked embed for the teacher heatmap feature
 * (local_omeroembed_view_samples), every student's own attempt (click
 * position + right/wrong) at a click-to-answer hotspot question
 * (local_omeroembed_hotspot_attempts), the identical shape of attempt
 * for the multi-region hotspot sibling feature
 * (local_omeroembed_hotspot_multi_attempts), and a teacher's own OMERO
 * service-account connections (local_omeroembed_subjects) - a login
 * credential tied to a specific identified person, not course content,
 * so (unlike local_omeroembed_hotspots/local_omeroembed_hotspot_multi
 * below) it is genuinely personal data and is fully covered: exported on
 * request (omerousername and the connection's own name, never the
 * encrypted password itself - see get_metadata() below) and deleted on
 * request. Deleting it does mean any embed still relying on that specific
 * connection stops working for students until the teacher registers a
 * replacement - an expected, not avoided, consequence of the teacher's
 * own credential being removed, the same way deleting their forum posts
 * as part of an erasure request also breaks continuity of a discussion.
 *
 * Everything else this plugin stores - the short-lived OMERO session
 * cache (classes/omero_session.php), local_omeroembed_embed_tracking (a
 * teacher's on/off + gather-window setting, keyed only by embedid - no
 * userid), local_omeroembed_hotspots, and local_omeroembed_hotspot_multi
 * (the hidden answer region(s) themselves, plus which teacher defined
 * them - treated the same as authorship on any other piece of course
 * content, e.g. a Page's own author, not as a personal data trail about
 * that teacher; deleting it on a data-removal request would also destroy
 * the actual quiz content for every student, which is never the right
 * outcome) - is not personal data and is intentionally not covered here.
 *
 * The first four tables are scoped to CONTEXT_COURSE, not CONTEXT_MODULE -
 * an embed lives inside arbitrary course content (a Page, a quiz
 * question, ...) with no course-module of its own, so the course itself
 * is the only meaningful context to hang this on (matches how
 * local/omeroembed:annotate, local/omeroembed:viewheatmap, and
 * local/omeroembed:hotspotauthor are also all checked at course context).
 * local_omeroembed_subjects is scoped to CONTEXT_USER instead - it isn't
 * tied to any one course at all, it belongs to the teacher directly.
 */
class provider implements
        // This plugin stores personal data.
    \core_privacy\local\metadata\provider,

        // This plugin is capable of determining which users have data within it.
    \core_privacy\local\request\core_userlist_provider,

        // This plugin is a core_user_data_provider.
    \core_privacy\local\request\plugin\provider {
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
                'label' => 'privacy:metadata:local_omeroembed_annotations:label',
                'timecreated' => 'privacy:metadata:local_omeroembed_annotations:timecreated',
            ],
            'privacy:metadata:local_omeroembed_annotations'
        );

        $items->add_database_table(
            'local_omeroembed_view_samples',
            [
                'courseid' => 'privacy:metadata:local_omeroembed_view_samples:courseid',
                'userid' => 'privacy:metadata:local_omeroembed_view_samples:userid',
                'embedid' => 'privacy:metadata:local_omeroembed_view_samples:embedid',
                'x' => 'privacy:metadata:local_omeroembed_view_samples:x',
                'y' => 'privacy:metadata:local_omeroembed_view_samples:y',
                'zoompercent' => 'privacy:metadata:local_omeroembed_view_samples:zoompercent',
                'timecreated' => 'privacy:metadata:local_omeroembed_view_samples:timecreated',
            ],
            'privacy:metadata:local_omeroembed_view_samples'
        );

        $items->add_database_table(
            'local_omeroembed_hotspot_attempts',
            [
                'courseid' => 'privacy:metadata:local_omeroembed_hotspot_attempts:courseid',
                'userid' => 'privacy:metadata:local_omeroembed_hotspot_attempts:userid',
                'embedid' => 'privacy:metadata:local_omeroembed_hotspot_attempts:embedid',
                'x' => 'privacy:metadata:local_omeroembed_hotspot_attempts:x',
                'y' => 'privacy:metadata:local_omeroembed_hotspot_attempts:y',
                'correct' => 'privacy:metadata:local_omeroembed_hotspot_attempts:correct',
                'timecreated' => 'privacy:metadata:local_omeroembed_hotspot_attempts:timecreated',
            ],
            'privacy:metadata:local_omeroembed_hotspot_attempts'
        );

        $items->add_database_table(
            'local_omeroembed_hotspot_multi_attempts',
            [
                'courseid' => 'privacy:metadata:local_omeroembed_hotspot_multi_attempts:courseid',
                'userid' => 'privacy:metadata:local_omeroembed_hotspot_multi_attempts:userid',
                'embedid' => 'privacy:metadata:local_omeroembed_hotspot_multi_attempts:embedid',
                'x' => 'privacy:metadata:local_omeroembed_hotspot_multi_attempts:x',
                'y' => 'privacy:metadata:local_omeroembed_hotspot_multi_attempts:y',
                'correct' => 'privacy:metadata:local_omeroembed_hotspot_multi_attempts:correct',
                'timecreated' => 'privacy:metadata:local_omeroembed_hotspot_multi_attempts:timecreated',
            ],
            'privacy:metadata:local_omeroembed_hotspot_multi_attempts'
        );

        $items->add_database_table(
            'local_omeroembed_subjects',
            [
                'userid' => 'privacy:metadata:local_omeroembed_subjects:userid',
                'name' => 'privacy:metadata:local_omeroembed_subjects:name',
                'omerousername' => 'privacy:metadata:local_omeroembed_subjects:omerousername',
                'omeropassword' => 'privacy:metadata:local_omeroembed_subjects:omeropassword',
                'timecreated' => 'privacy:metadata:local_omeroembed_subjects:timecreated',
                'timemodified' => 'privacy:metadata:local_omeroembed_subjects:timemodified',
            ],
            'privacy:metadata:local_omeroembed_subjects'
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
        global $DB;

        $contextlist = new contextlist();

        $contextlist->add_from_sql(
            "SELECT c.id
               FROM {context} c
         INNER JOIN {local_omeroembed_annotations} a
                 ON a.courseid = c.instanceid AND c.contextlevel = :contextlevel1
              WHERE a.userid = :userid1",
            ['contextlevel1' => CONTEXT_COURSE, 'userid1' => $userid]
        );

        $contextlist->add_from_sql(
            "SELECT c.id
               FROM {context} c
         INNER JOIN {local_omeroembed_view_samples} s
                 ON s.courseid = c.instanceid AND c.contextlevel = :contextlevel2
              WHERE s.userid = :userid2",
            ['contextlevel2' => CONTEXT_COURSE, 'userid2' => $userid]
        );

        $contextlist->add_from_sql(
            "SELECT c.id
               FROM {context} c
         INNER JOIN {local_omeroembed_hotspot_attempts} h
                 ON h.courseid = c.instanceid AND c.contextlevel = :contextlevel3
              WHERE h.userid = :userid3",
            ['contextlevel3' => CONTEXT_COURSE, 'userid3' => $userid]
        );

        $contextlist->add_from_sql(
            "SELECT c.id
               FROM {context} c
         INNER JOIN {local_omeroembed_hotspot_multi_attempts} hm
                 ON hm.courseid = c.instanceid AND c.contextlevel = :contextlevel4
              WHERE hm.userid = :userid4",
            ['contextlevel4' => CONTEXT_COURSE, 'userid4' => $userid]
        );

        if ($DB->record_exists('local_omeroembed_subjects', ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {local_omeroembed_subjects} WHERE userid = :userid', [
                'userid' => $context->instanceid,
            ]);
            return;
        }

        if (!$context instanceof \context_course) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_omeroembed_annotations} WHERE courseid = :courseid', [
            'courseid' => $context->instanceid,
        ]);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_omeroembed_view_samples} WHERE courseid = :courseid', [
            'courseid' => $context->instanceid,
        ]);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_omeroembed_hotspot_attempts} WHERE courseid = :courseid', [
            'courseid' => $context->instanceid,
        ]);
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {local_omeroembed_hotspot_multi_attempts} WHERE courseid = :courseid',
            ['courseid' => $context->instanceid]
        );
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
            if ($context instanceof \context_user && $context->instanceid == $user->id) {
                $subjects = $DB->get_records('local_omeroembed_subjects', ['userid' => $user->id]);
                if ($subjects) {
                    $data = array_map(function ($subject) {
                        return [
                            'name' => $subject->name,
                            'omerousername' => $subject->omerousername,
                            'timecreated' => \core_privacy\local\request\transform::datetime($subject->timecreated),
                            'timemodified' => \core_privacy\local\request\transform::datetime($subject->timemodified),
                        ];
                    }, array_values($subjects));

                    writer::with_context($context)->export_data(
                        [
                            get_string('pluginname', 'local_omeroembed'),
                            get_string('pluginname', 'local_omeroembed') . ' - OMERO connections',
                        ],
                        (object) ['subjects' => $data]
                    );
                }
                continue;
            }

            if (!$context instanceof \context_course) {
                continue;
            }

            $annotations = $DB->get_records('local_omeroembed_annotations', [
                'courseid' => $context->instanceid,
                'userid' => $user->id,
            ]);
            if ($annotations) {
                $data = array_map(function ($annotation) {
                    return [
                        'embedid' => $annotation->embedid,
                        'type' => $annotation->type,
                        'geometry' => $annotation->geometry,
                        'colour' => $annotation->colour,
                        'label' => $annotation->label,
                        'timecreated' => \core_privacy\local\request\transform::datetime($annotation->timecreated),
                    ];
                }, array_values($annotations));

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_omeroembed'), get_string('pluginname', 'local_omeroembed') . ' - annotations'],
                    (object) ['annotations' => $data]
                );
            }

            $samples = $DB->get_records('local_omeroembed_view_samples', [
                'courseid' => $context->instanceid,
                'userid' => $user->id,
            ]);
            if ($samples) {
                $data = array_map(function ($sample) {
                    return [
                        'embedid' => $sample->embedid,
                        'x' => $sample->x,
                        'y' => $sample->y,
                        'zoompercent' => $sample->zoompercent,
                        'timecreated' => \core_privacy\local\request\transform::datetime($sample->timecreated),
                    ];
                }, array_values($samples));

                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_omeroembed'),
                        get_string('pluginname', 'local_omeroembed') . ' - heatmap samples',
                    ],
                    (object) ['viewsamples' => $data]
                );
            }

            $attempts = $DB->get_records('local_omeroembed_hotspot_attempts', [
                'courseid' => $context->instanceid,
                'userid' => $user->id,
            ]);
            if ($attempts) {
                $data = array_map(function ($attempt) {
                    return [
                        'embedid' => $attempt->embedid,
                        'x' => $attempt->x,
                        'y' => $attempt->y,
                        'correct' => (bool) $attempt->correct,
                        'timecreated' => \core_privacy\local\request\transform::datetime($attempt->timecreated),
                    ];
                }, array_values($attempts));

                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_omeroembed'),
                        get_string('pluginname', 'local_omeroembed') . ' - hotspot attempts',
                    ],
                    (object) ['hotspotattempts' => $data]
                );
            }

            $multiattempts = $DB->get_records('local_omeroembed_hotspot_multi_attempts', [
                'courseid' => $context->instanceid,
                'userid' => $user->id,
            ]);
            if ($multiattempts) {
                $data = array_map(function ($attempt) {
                    return [
                        'embedid' => $attempt->embedid,
                        'x' => $attempt->x,
                        'y' => $attempt->y,
                        'correct' => (bool) $attempt->correct,
                        'timecreated' => \core_privacy\local\request\transform::datetime($attempt->timecreated),
                    ];
                }, array_values($multiattempts));

                writer::with_context($context)->export_data(
                    [
                        get_string('pluginname', 'local_omeroembed'),
                        get_string('pluginname', 'local_omeroembed') . ' - multi-region hotspot attempts',
                    ],
                    (object) ['hotspotmultiattempts' => $data]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context the context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context instanceof \context_user) {
            $DB->delete_records('local_omeroembed_subjects', ['userid' => $context->instanceid]);
            return;
        }

        if (!$context instanceof \context_course) {
            return;
        }

        $DB->delete_records('local_omeroembed_annotations', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_omeroembed_view_samples', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_omeroembed_hotspot_attempts', ['courseid' => $context->instanceid]);
        $DB->delete_records('local_omeroembed_hotspot_multi_attempts', ['courseid' => $context->instanceid]);
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
            if ($context instanceof \context_user && $context->instanceid == $userid) {
                $DB->delete_records('local_omeroembed_subjects', ['userid' => $userid]);
                continue;
            }

            if (!$context instanceof \context_course) {
                continue;
            }
            $DB->delete_records('local_omeroembed_annotations', ['courseid' => $context->instanceid, 'userid' => $userid]);
            $DB->delete_records('local_omeroembed_view_samples', ['courseid' => $context->instanceid, 'userid' => $userid]);
            $DB->delete_records('local_omeroembed_hotspot_attempts', ['courseid' => $context->instanceid, 'userid' => $userid]);
            $DB->delete_records('local_omeroembed_hotspot_multi_attempts', [
                'courseid' => $context->instanceid, 'userid' => $userid,
            ]);
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

        if ($context instanceof \context_user) {
            [$usersql, $userparams] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            $DB->delete_records_select('local_omeroembed_subjects', "userid $usersql", $userparams);
            return;
        }

        if (!$context instanceof \context_course) {
            return;
        }

        $userids = $userlist->get_userids();
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        $select = "courseid = :courseid AND userid $usersql";
        $params = ['courseid' => $context->instanceid] + $userparams;
        $DB->delete_records_select('local_omeroembed_annotations', $select, $params);
        $DB->delete_records_select('local_omeroembed_view_samples', $select, $params);
        $DB->delete_records_select('local_omeroembed_hotspot_attempts', $select, $params);
        $DB->delete_records_select('local_omeroembed_hotspot_multi_attempts', $select, $params);
    }
}
