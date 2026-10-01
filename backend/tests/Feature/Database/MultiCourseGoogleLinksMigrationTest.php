<?php

namespace Tests\Feature\Database;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration D (DESIGN §24.3 D, §24.15 step 5): classroom_google_links gets
 * its own id, app_course_id and one course per teacher per classroom; every
 * row is copied and app_course_id is set when the owner has exactly one
 * course bound to the classroom. The rollback keeps one course per classroom
 * (the homeroom teacher's).
 */
class MultiCourseGoogleLinksMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_are_copied_and_the_rollback_keeps_the_homeroom_course(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // DDL commits the test's transaction on MariaDB (RefreshDatabase could not roll
            // the rows back). Migration D was run up, down and up again by hand on MariaDB 11
            // (DESIGN §24.24); this test covers the copy rules on SQLite.
            $this->markTestSkipped('migration rollback inside the test transaction needs SQLite');
        }
        $teacher = $this->makeTeacher();
        $subject = $this->makeTeacher($teacher->school);
        $one = $this->makeClassroom($teacher);
        $two = $this->makeClassroom($teacher);
        $none = $this->makeClassroom($teacher);
        $only = $this->makeCourse($teacher, [$one, $two]);
        $this->makeCourse($teacher, [$two], ['code' => 'ค15102']);
        // A subject teacher's course bound to classroom one is not the owner's.
        $this->makeCourse($subject, [$one], ['code' => 'ว15101']);

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('classroom_google_links', 'app_course_id'));
        foreach ([[$one, 'c-one'], [$two, 'c-two'], [$none, 'c-none']] as [$room, $course]) {
            DB::table('classroom_google_links')->insert(['classroom_id' => $room->id, 'course_id' => $course, 'course_name' => $course, 'owner_user_id' => $teacher->id, 'linked_at' => now(), 'roster_synced_at' => '2026-09-30 01:00:00']);
        }

        $this->artisan('migrate')->assertSuccessful();

        $rows = DB::table('classroom_google_links')->orderBy('id')->get()->keyBy('course_id');
        $this->assertCount(3, $rows);
        $this->assertSame([(int) $only->id, null, null], [(int) $rows['c-one']->app_course_id, $rows['c-two']->app_course_id, $rows['c-none']->app_course_id]);
        $this->assertNotNull($rows['c-one']->id);
        $this->assertSame('2026-09-30 01:00:00', (string) $rows['c-one']->roster_synced_at);

        // One course per teacher per classroom, one classroom per course.
        DB::table('classroom_google_links')->insert(['classroom_id' => $one->id, 'course_id' => 'c-science', 'course_name' => 'วิทย์', 'owner_user_id' => $subject->id, 'linked_at' => now()]);
        foreach ([
            ['classroom_id' => $one->id, 'course_id' => 'c-again', 'owner_user_id' => $teacher->id],
            ['classroom_id' => $two->id, 'course_id' => 'c-science', 'owner_user_id' => $subject->id],
        ] as $row) {
            try {
                DB::transaction(fn () => DB::table('classroom_google_links')->insert($row + ['course_name' => 'x', 'linked_at' => now()]));
                $this->fail('the unique key let a second row in: '.json_encode($row));
            } catch (UniqueConstraintViolationException) {
                // expected
            }
        }

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertSame(
            [[$one->id, 'c-one'], [$two->id, 'c-two'], [$none->id, 'c-none']],
            DB::table('classroom_google_links')->orderBy('classroom_id')->get()->map(fn ($r) => [(int) $r->classroom_id, $r->course_id])->all(),
        );

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('classroom_google_links', 'app_course_id'));
    }
}
