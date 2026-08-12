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

use local_omeroembed\annotations_repository;

/**
 * Coverage for the first slice of the ajax.php -> External Services
 * migration (issue #8) - the 'list' action only, see this class's own
 * docblock for why it was chosen first.
 *
 * @package    local_omeroembed
 * @copyright  2026 University of Glasgow MVLS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_omeroembed\external\get_annotations::execute
 */
final class get_annotations_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * The ordinary case: an enrolled student with local/omeroembed:annotate
     * sees their own annotations, correctly shaped for the wire (geometry
     * still a JSON string, per get_annotations::execute()'s own comment -
     * cleaned through execute_returns() here too, so a structural mismatch
     * like a NULL label failing PARAM_TEXT validation would fail this test,
     * not just look fine in a bare unit-test assertion).
     */
    public function test_returns_own_annotations(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $embedid = \core\uuid::generate();
        $created = annotations_repository::create(
            (int) $course->id,
            (int) $student->id,
            $embedid,
            annotations_repository::TYPE_POINT,
            ['x' => 100.5, 'y' => 200.5],
            '#e6194B',
            'a note'
        );

        $result = get_annotations::execute((int) $course->id, $embedid);
        $cleaned = \core_external\external_api::clean_returnvalue(get_annotations::execute_returns(), $result);

        $this->assertCount(1, $cleaned);
        $this->assertSame((int) $created->id, $cleaned[0]['id']);
        $this->assertSame(annotations_repository::TYPE_POINT, $cleaned[0]['type']);
        $this->assertSame(['x' => 100.5, 'y' => 200.5], json_decode($cleaned[0]['geometry'], true));
        $this->assertSame('#e6194B', $cleaned[0]['colour']);
        $this->assertSame('a note', $cleaned[0]['label']);
    }

    /**
     * A row with no label at all (label => '', the create() default) must
     * round-trip cleanly too - this is the case that would break if
     * execute() didn't coerce a possible NULL to '' before returning.
     */
    public function test_row_with_no_label_round_trips(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $embedid = \core\uuid::generate();
        annotations_repository::create(
            (int) $course->id,
            (int) $student->id,
            $embedid,
            annotations_repository::TYPE_POINT,
            ['x' => 1.0, 'y' => 1.0],
            '#e6194B'
        );

        $result = get_annotations::execute((int) $course->id, $embedid);
        $cleaned = \core_external\external_api::clean_returnvalue(get_annotations::execute_returns(), $result);

        $this->assertSame('', $cleaned[0]['label']);
    }

    /**
     * The real point of this whole query: a second user's annotations on
     * the exact same embedid must never leak into the first user's list.
     */
    public function test_only_own_annotations_returned(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $otherstudent = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $embedid = \core\uuid::generate();

        annotations_repository::create(
            (int) $course->id,
            (int) $otherstudent->id,
            $embedid,
            annotations_repository::TYPE_POINT,
            ['x' => 1.0, 'y' => 1.0],
            '#e6194B'
        );

        $this->setUser($student);
        $result = get_annotations::execute((int) $course->id, $embedid);

        $this->assertSame([], $result);
    }

    /**
     * An embedid with nothing recorded yet - a brand new embed placement -
     * must return an empty list, not throw.
     */
    public function test_unseen_embedid_returns_empty(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $result = get_annotations::execute((int) $course->id, \core\uuid::generate());

        $this->assertSame([], $result);
    }

    /**
     * A user with no access to the course at all must be rejected before
     * ever reaching this plugin's own require_capability() check - unlike
     * ajax.php's separate require_login($course) call, self::
     * validate_context() does its own equivalent enrolment check
     * internally (confirmed live: rejects with core\exception\
     * require_login_exception / "Not enrolled", not
     * required_capability_exception - execute() never gets far enough to
     * run its own require_capability() call for a user in this state).
     */
    public function test_rejects_user_with_no_course_access(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\core\exception\require_login_exception::class);
        get_annotations::execute((int) $course->id, \core\uuid::generate());
    }

    /**
     * The capability check this class's own execute() explicitly makes:
     * an enrolled user who genuinely lacks local/omeroembed:annotate (the
     * capability is only assigned to student/teacher/editingteacher by
     * default - prohibited here directly via a role override, rather than
     * relying on an archetype that happens not to have it) must be
     * rejected too, distinctly from the no-course-access case above.
     */
    public function test_rejects_enrolled_user_without_capability(): void {
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        global $DB;
        $context = \context_course::instance($course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('local/omeroembed:annotate', CAP_PROHIBIT, $roleid, $context->id, true);
        $context->mark_dirty();
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\required_capability_exception::class);
        get_annotations::execute((int) $course->id, \core\uuid::generate());
    }
}
