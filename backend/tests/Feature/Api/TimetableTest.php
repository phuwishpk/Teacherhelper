<?php

namespace Tests\Feature\Api;

use App\Models\Classroom;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §29.12: the weekly timetable of a teacher and a student.
 */
class TimetableTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $subject;

    private Classroom $classroom;

    private Course $course;

    private Course $subjectCourse;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->subject = $this->makeTeacher($this->teacher->school);
        $this->subjectCourse = $this->makeCourse($this->subject, [$this->classroom], ['code' => 'ว15101', 'name' => 'วิทยาศาสตร์']);
        $this->student = $this->enrollStudent($this->classroom, 1, 'นักเรียน 1')['student'];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function slot(User $as, Course $course, array $body = []): TestResponse
    {
        return $this->asUser($as)->postJson("/api/v1/courses/{$course->id}/timetable-slots", $body + ['classroom_id' => $this->classroom->id, 'weekday' => 1, 'period_no' => 1]);
    }

    public function test_slots_are_added_once_per_period_edited_and_removed_and_shared_rooms_show_as_busy(): void
    {
        $id = $this->slot($this->teacher, $this->course, ['starts_at' => '08:30', 'ends_at' => '09:20'])->assertCreated()
            ->assertJsonPath('data.course.code', $this->course->code)
            ->assertJsonPath('data.classroom.id', $this->classroom->id)
            ->assertJsonPath('data.weekday', 1)->assertJsonPath('data.period_no', 1)
            ->assertJsonPath('data.starts_at', '08:30')->assertJsonPath('data.ends_at', '09:20')->assertJsonPath('data.own', true)
            ->json('data.id');
        $this->slot($this->subject, $this->subjectCourse)->assertStatus(422)->assertJsonPath('code', 'timetable_period_taken');
        $this->slot($this->teacher, $this->course, ['period_no' => 2, 'starts_at' => '10:00', 'ends_at' => '09:00'])->assertStatus(422)->assertJsonValidationErrors(['ends_at']);
        $this->slot($this->teacher, $this->course, ['weekday' => 9])->assertStatus(422)->assertJsonValidationErrors(['weekday']);
        $this->slot($this->teacher, $this->subjectCourse)->assertNotFound();

        $this->asUser($this->teacher)->putJson("/api/v1/timetable-slots/{$id}", ['period_no' => 3, 'weekday' => 2])->assertOk()->assertJsonPath('data.period_no', 3)->assertJsonPath('data.weekday', 2);
        $this->slot($this->subject, $this->subjectCourse, ['period_no' => 1])->assertCreated();

        $mine = $this->asUser($this->teacher)->getJson('/api/v1/timetable')->assertOk()->json('data');
        $this->assertCount(1, $mine['slots']);
        $this->assertCount(1, $mine['busy']);
        $this->assertNull($mine['busy'][0]['course'], 'a colleague\'s slot shows the room only');
        $this->assertSame($this->classroom->name, $mine['busy'][0]['classroom']['name']);
        $this->assertFalse($mine['busy'][0]['own']);
        $theirs = $this->asUser($this->subject)->getJson('/api/v1/timetable')->assertOk()->json('data');
        $this->assertCount(1, $theirs['slots']);
        $this->assertCount(1, $theirs['busy']);
        // A teacher with no part in the classroom sees nothing of it.
        $this->assertSame([], $this->asUser($this->makeTeacher($this->teacher->school))->getJson('/api/v1/timetable')->assertOk()->json('data.busy'));

        $this->asUser($this->subject)->deleteJson("/api/v1/timetable-slots/{$id}")->assertNotFound();
        $this->asUser($this->teacher)->deleteJson("/api/v1/timetable-slots/{$id}")->assertNoContent();
    }

    public function test_a_period_is_cancelled_for_one_date_and_the_student_reads_the_week(): void
    {
        $id = $this->slot($this->teacher, $this->course, ['starts_at' => '08:30', 'ends_at' => '09:20'])->json('data.id');
        $monday = Carbon::now('Asia/Bangkok')->next(Carbon::MONDAY);
        $tuesday = $monday->copy()->addDay();
        $this->asUser($this->teacher)->postJson("/api/v1/timetable-slots/{$id}/cancellations", ['held_on' => $tuesday->toDateString()])->assertStatus(422)->assertJsonValidationErrors(['held_on']);
        $cancellation = $this->asUser($this->teacher)->postJson("/api/v1/timetable-slots/{$id}/cancellations", ['held_on' => $monday->toDateString(), 'note' => ' กีฬาสี '])->assertCreated()
            ->assertJsonPath('data.held_on', $monday->toDateString())->assertJsonPath('data.note', 'กีฬาสี')->json('data.id');
        $this->asUser($this->teacher)->postJson("/api/v1/timetable-slots/{$id}/cancellations", ['held_on' => $monday->toDateString()])->assertStatus(409)->assertJsonPath('code', 'timetable_already_cancelled');

        $week = $this->asUser($this->teacher)->getJson('/api/v1/timetable?week='.$monday->copy()->addDays(3)->toDateString())->assertOk()->json('data');
        $this->assertSame($monday->toDateString(), $week['week_start']);
        $this->assertCount(1, $week['cancellations']);
        $this->assertSame([], $this->asUser($this->teacher)->getJson('/api/v1/timetable?week='.$monday->copy()->addDays(7)->toDateString())->json('data.cancellations'));

        $mine = $this->asUser($this->student)->getJson('/api/v1/student/timetable?week='.$monday->toDateString())->assertOk()->json('data');
        $this->assertCount(1, $mine['slots']);
        $this->assertSame($this->course->code, $mine['slots'][0]['course']['code']);
        $this->assertSame($this->teacher->name, $mine['slots'][0]['teacher_name']);
        $this->assertSame('08:30', $mine['slots'][0]['starts_at']);
        $this->assertCount(1, $mine['cancellations']);
        // A student of another classroom sees nothing.
        $other = $this->enrollStudent($this->makeClassroom($this->teacher, ['name' => 'ป.6/1']), 1, 'คนอื่น')['student'];
        $this->assertSame([], $this->asUser($other)->getJson('/api/v1/student/timetable')->assertOk()->json('data.slots'));

        $this->asUser($this->subject)->deleteJson("/api/v1/timetable-cancellations/{$cancellation}")->assertNotFound();
        $this->asUser($this->teacher)->deleteJson("/api/v1/timetable-cancellations/{$cancellation}")->assertNoContent();
    }

    public function test_the_term_dates_live_on_the_course(): void
    {
        $this->asUser($this->teacher)->patchJson("/api/v1/courses/{$this->course->id}", ['term_starts_on' => '2026-11-01', 'term_ends_on' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors(['term_ends_on']);
        $this->asUser($this->teacher)->patchJson("/api/v1/courses/{$this->course->id}", ['term_starts_on' => '2026-11-01', 'term_ends_on' => '2027-03-15'])->assertOk()
            ->assertJsonPath('data.term_starts_on', '2026-11-01')->assertJsonPath('data.term_ends_on', '2027-03-15');
        $terms = $this->asUser($this->teacher)->getJson('/api/v1/timetable')->assertOk()->json('data.terms');
        $this->assertSame([['course_id' => $this->course->id, 'code' => $this->course->code, 'term_starts_on' => '2026-11-01', 'term_ends_on' => '2027-03-15']], $terms);
    }
}
