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
 * Privacy Subsystem implementation for qtype_omerohotspotmulti.
 *
 * @package    qtype_omerohotspotmulti
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace qtype_omerohotspotmulti\privacy;

/**
 * This qtype stores no personal data of its own - a student's clicked
 * coordinates and grade are recorded by Moodle's own question engine
 * (question_attempt_step_data), already covered by that subsystem's own
 * privacy provider, the same way every other qtype's actual response data
 * is. qtype_omerohotspotmulti_options (the hidden set of regions + which
 * OMERO subject/slide) is teacher-authored question content, not a
 * personal data trail about that teacher - same reasoning
 * qtype_omerohotspot_options and local_omeroembed_hotspot_multi are each
 * excluded from their own respective plugin's privacy provider for.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Get the language string identifier with the component's language
     * file to explain why this plugin stores no data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
