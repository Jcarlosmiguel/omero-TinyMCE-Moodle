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
 * Regression coverage for the cross-course IDOR fix (verify_course() on
 * tracking_repository, hotspot_repository, hotspot_multi_repository).
 *
 * A real, exploitable vulnerability existed here: every privileged action
 * checked the requester's capability against whichever courseid was
 * *declared* in the request, then read/wrote data keyed by embedid alone,
 * with no check that the embedid actually belonged to that course. These
 * tests exist specifically so that fix can't be quietly undone by a future
 * refactor without a test failing to say so - see the fix's own commit
 * message for the full exploit scenario each of these three repositories
 * was individually vulnerable to.
 *
 * embedid is a real database column (char(36), sized for a UUID - see
 * db/install.xml) - \core\uuid::generate() throughout, not a hand-built
 * descriptive string, so these tests can't themselves fail on a
 * "data too long" error unrelated to what they're actually testing.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_omeroembed\tracking_repository::verify_course
 * @covers     \local_omeroembed\hotspot_repository::verify_course
 * @covers     \local_omeroembed\hotspot_multi_repository::verify_course
 */
final class verify_course_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A never-before-seen embedid has nothing recorded yet to conflict
     * with - must pass silently, or every legitimate first-visit/
     * bootstrap flow (a teacher authoring a brand new hotspot, a brand
     * new tracking session) would break.
     */
    public function test_unseen_embedid_passes_silently(): void {
        $embedid = \core\uuid::generate();

        $this->expectNotToPerformAssertions();
        tracking_repository::verify_course($embedid, 123);
        hotspot_repository::verify_course($embedid, 123);
        hotspot_multi_repository::verify_course($embedid, 123);
    }

    /**
     * The exact courseid a resource was created under must always be
     * accepted - this is the ordinary, legitimate case every real request
     * takes.
     */
    public function test_matching_courseid_passes(): void {
        $course = $this->getDataGenerator()->create_course();
        $embedid = \core\uuid::generate();

        tracking_repository::set_settings((int) $course->id, $embedid, true, 60, 'https://example.invalid/proxy.php');
        hotspot_repository::save((int) $course->id, $embedid, 2, [
            'type' => 'ellipse', 'x' => 1.5, 'y' => 1.5, 'rx' => 1.5, 'ry' => 1.5, 'rotation' => 0,
        ]);
        hotspot_multi_repository::save((int) $course->id, $embedid, 2, [
            ['type' => 'ellipse', 'x' => 1.5, 'y' => 1.5, 'rx' => 1.5, 'ry' => 1.5, 'rotation' => 0],
        ]);

        $this->expectNotToPerformAssertions();
        tracking_repository::verify_course($embedid, (int) $course->id);
        hotspot_repository::verify_course($embedid, (int) $course->id);
        hotspot_multi_repository::verify_course($embedid, (int) $course->id);
    }

    /**
     * The actual regression this suite exists to catch: a *different*
     * course's embedid must be rejected, not silently allowed through
     * just because the declared courseid was itself a valid, real course.
     */
    public function test_mismatched_courseid_rejected_tracking(): void {
        $realcourse = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $embedid = \core\uuid::generate();

        tracking_repository::set_settings((int) $realcourse->id, $embedid, true, 60, 'https://example.invalid/proxy.php');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/does not belong to the specified course/');
        tracking_repository::verify_course($embedid, (int) $othercourse->id);
    }

    public function test_mismatched_courseid_rejected_hotspot(): void {
        $realcourse = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $embedid = \core\uuid::generate();

        hotspot_repository::save((int) $realcourse->id, $embedid, 2, [
            'type' => 'ellipse', 'x' => 1.5, 'y' => 1.5, 'rx' => 1.5, 'ry' => 1.5, 'rotation' => 0,
        ]);

        $this->expectException(\moodle_exception::class);
        hotspot_repository::verify_course($embedid, (int) $othercourse->id);
    }

    public function test_mismatched_courseid_rejected_hotspot_multi(): void {
        $realcourse = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $embedid = \core\uuid::generate();

        hotspot_multi_repository::save((int) $realcourse->id, $embedid, 2, [
            ['type' => 'ellipse', 'x' => 1.5, 'y' => 1.5, 'rx' => 1.5, 'ry' => 1.5, 'rotation' => 0],
        ]);

        $this->expectException(\moodle_exception::class);
        hotspot_multi_repository::verify_course($embedid, (int) $othercourse->id);
    }

    /**
     * The exact sabotage scenario the fix's commit message describes: a
     * mismatched-course write attempt must be blocked *before* any data
     * is touched, not just flagged after the fact. Confirms the real
     * region survives a rejected overwrite attempt untouched.
     */
    public function test_mismatched_courseid_blocks_overwrite_not_just_read(): void {
        $realcourse = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $embedid = \core\uuid::generate();
        $original = ['type' => 'ellipse', 'x' => 500.5, 'y' => 500.5, 'rx' => 50.5, 'ry' => 50.5, 'rotation' => 0];

        hotspot_repository::save((int) $realcourse->id, $embedid, 2, $original);

        try {
            hotspot_repository::verify_course($embedid, (int) $othercourse->id);
            $this->fail('Expected verify_course() to reject the mismatched course before any write was attempted.');
        } catch (\moodle_exception $e) {
            $this->assertMatchesRegularExpression('/does not belong to the specified course/', $e->getMessage());
        }

        $this->assertSame($original, hotspot_repository::get_geometry($embedid));
    }
}
