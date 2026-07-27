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

namespace filter_omeroembed;

/**
 * Replaces {omero:IMAGE_ID:SUBJECT} (or {omero:IMAGE_ID:DATASET_ID:SUBJECT}) placeholders
 * with an iframe pointing at this plugin's own proxy.php - never at OMERO directly.
 *
 * Deliberately does not re-check enrolment/access here: by the time content reaches a
 * filter, Moodle has already decided the current user may view this page. The real
 * access gate is proxy.php itself, which runs on a *separate* HTTP request (the iframe
 * load) and independently calls require_login($courseid) before contacting OMERO at all -
 * see that file for why the courseid below has to travel with the generated URL.
 *
 * @package    filter_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /**
     * Matches {omero:IMAGE_ID:SUBJECT} or {omero:IMAGE_ID:DATASET_ID:SUBJECT}.
     * Subject keys are restricted to lowercase letters/digits/underscore, matching the
     * admin settings textarea format (see settings.php) - deliberately not free text,
     * since the subject key selects which stored OMERO credentials get used.
     */
    const PLACEHOLDER_REGEX = '/\{omero:(\d+)(?::(\d+))?:([a-z0-9_]+)\}/';

    #[\Override]
    public function filter($text, array $options = []) {
        if (strpos($text, '{omero:') === false) {
            // Cheap early exit - avoids the regex/context lookup entirely on the
            // overwhelming majority of content that has no placeholder at all.
            return $text;
        }

        $coursecontext = $this->context->get_course_context(false);
        if (!$coursecontext) {
            // No enclosing course (e.g. system-level content) - nothing to gate
            // access by, so don't embed anything rather than embed ungated.
            return $text;
        }

        return preg_replace_callback(
            self::PLACEHOLDER_REGEX,
            function ($matches) use ($coursecontext) {
                return $this->build_iframe($matches, $coursecontext);
            },
            $text
        );
    }

    /**
     * Builds the replacement iframe for a single matched placeholder.
     *
     * @param array $matches Regex matches: [0] full match, [1] image id,
     *                        [2] dataset id (or ''), [3] subject key.
     * @param \core\context $coursecontext
     * @return string
     */
    protected function build_iframe(array $matches, $coursecontext): string {
        $imageid = $matches[1];
        $datasetid = $matches[2] ?? '';
        $subject = $matches[3];

        $params = [
            'courseid' => $coursecontext->instanceid,
            'images' => $imageid,
            'subject' => $subject,
        ];
        if ($datasetid !== '') {
            $params['dataset'] = $datasetid;
        }

        $url = new \moodle_url('/filter/omeroembed/proxy.php', $params);

        return \html_writer::tag('iframe', '', [
            'src' => $url->out(false),
            'class' => 'filter_omeroembed-iframe',
            'allowfullscreen' => 'allowfullscreen',
            'loading' => 'lazy',
        ]);
    }
}
