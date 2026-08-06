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
 * Downloads every gathered viewport sample for one embed placement as a
 * CSV, so a teacher can analyse the raw data offline before it's deleted
 * (see heatmap.php's delete button) or purged by the site's retention
 * task (see classes/task/purge_view_samples.php). Gated identically to
 * heatmap.php - same capability, same course-scoped context.
 *
 * Deliberately anonymised (confirmed with the user): once downloaded, this
 * file is a portable copy of personal data outside Moodle's own access
 * controls, so real usernames/names are never included - each distinct
 * student becomes "Student N", numbered by when they first appear in this
 * embed's data. That numbering is generated fresh per export (not stored
 * anywhere), so it's only a within-file grouping key, not a durable
 * identity - "Student 3" in one export isn't guaranteed to be the same
 * real student in a later one.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_omeroembed\tracking_repository;

$courseid = required_param('courseid', PARAM_INT);
$embedid = required_param('embedid', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/omeroembed:viewheatmap', $context);
// SECURITY: see tracking_repository::verify_course()'s own docblock -
// without this, local/omeroembed:viewheatmap in ANY course would let a
// teacher export another course's gathered student behavioural data by
// supplying that course's embedid alongside their own courseid.
tracking_repository::verify_course($embedid, $courseid);
// PERFORMANCE: nothing below writes to $_SESSION - see proxy.php's own
// write_close() comment.
\core\session\manager::write_close();

$samples = tracking_repository::get_all_samples_for_export($embedid);

// Rows already arrive ordered by userid, timecreated (see
// get_all_samples_for_export()) - a single pass assigns "Student N" the
// first time each userid is seen, in that same first-appearance order.
$studentnumbers = [];
$nextnumber = 1;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="heatmap-data-' . $embedid . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['student', 'x', 'y', 'zoompercent', 'recorded_at']);

foreach ($samples as $sample) {
    if (!isset($studentnumbers[$sample->userid])) {
        $studentnumbers[$sample->userid] = $nextnumber++;
    }
    fputcsv($out, [
        'Student ' . $studentnumbers[$sample->userid],
        $sample->x,
        $sample->y,
        $sample->zoompercent,
        userdate($sample->timecreated, '%Y-%m-%d %H:%M:%S'),
    ]);
}

fclose($out);
