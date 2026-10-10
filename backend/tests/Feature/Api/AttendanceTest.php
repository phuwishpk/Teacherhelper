<?php

namespace Tests\Feature\Api;

use App\Domain\Gradebook\GradebookSettings;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\GradebookEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §29.5: attendance per course and period, its history, the values of
 * the statuses and the "การเข้าเรียน" gradebook item.
 */
class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $classroom;

    private Course $course;

    /** @var list<User> */
    private array $students;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $this->students = [];
        foreach ([1, 2, 3, 4] as $n) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, "นักเรียน {$n}")['student'];
        }
    }

    /**
     * @param  array<int, string>  $statuses  student index => status
     * @param  array<string, mixed>  $extra
     */
    private function check(string $date, array $statuses = [], array $extra = []): int
    {
        $records = [];
        foreach ($statuses as $i => $status) {
            $records[] = ['student_id' => $this->students[$i]->id, 'status' => $status];
        }

        return $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/attendance-sessions", $extra + [
            'classroom_id' => $this->classroom->id, 'held_on' => $date, 'records' => $records,
        ])->assertCreated()->json('data.id');
    }

    private function autoItem(float $max = 10): int
    {
        GradebookSettings::applyTemplate($this->course, 'collect_final');
        $categoryId = GradebookCategory::query()->where('course_id', $this->course->id)->orderBy('position')->value('id');

        return $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/gradebook-items", [
            'classroom_ids' => [$this->classroom->id], 'category_id' => $categoryId, 'name' => 'การเข้าเรียน', 'max_points' => $max, 'auto_attendance' => true,
        ])->assertCreated()->assertJsonPath('data.0.auto_attendance', true)->assertJsonPath('data.0.is_attendance', true)->json('data.0.id');
    }

    public function test_checking_a_period_marks_everyone_present_unless_told_otherwise(): void
    {
        $response = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/attendance-sessions", [
            'classroom_id' => $this->classroom->id, 'held_on' => '2026-10-05', 'period_no' => 2, 'note' => ' คาบแรก ',
            'records' => [
                ['student_id' => $this->students[1]->id, 'status' => 'late', 'note' => 'รถติด'],
                ['student_id' => $this->students[2]->id, 'status' => 'sick_leave'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.held_on', '2026-10-05')
            ->assertJsonPath('data.period_no', 2)
            ->assertJsonPath('data.note', 'คาบแรก')
            ->assertJsonPath('data.counts', ['present' => 2, 'late' => 1, 'absent' => 0, 'personal_leave' => 0, 'sick_leave' => 1])
            ->assertJsonCount(4, 'data.records')
            ->assertJsonPath('data.records.0.status', 'present')
            ->assertJsonPath('data.records.0.student_number', 1)
            ->assertJsonPath('data.records.1.status', 'late')
            ->assertJsonPath('data.records.1.note', 'รถติด');

        $this->asUser($this->teacher)->getJson('/api/v1/attendance-sessions/'.$response->json('data.id'))
            ->assertOk()->assertJsonPath('data.records.2.status', 'sick_leave');
    }

    public function test_the_history_lists_sessions_and_each_students_rate(): void
    {
        $this->check('2026-10-05', [1 => 'late', 2 => 'sick_leave', 3 => 'absent']);
        $this->check('2026-10-06', [2 => 'personal_leave'], ['period_no' => 1]);

        $data = $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/attendance?classroom_id={$this->classroom->id}")
            ->assertOk()
            ->assertJsonPath('data.scores', ['present' => 1, 'late' => 0.5, 'absent' => 0])
            ->assertJsonPath('data.auto_item', null)
            ->assertJsonCount(2, 'data.sessions')
            ->assertJsonPath('data.sessions.0.held_on', '2026-10-06')
            ->assertJsonPath('data.sessions.1.counts.late', 1)
            ->json('data.students');

        $this->assertSame([1, 2, 3, 4], array_column($data, 'student_number'));
        $this->assertEquals(1.0, $data[0]['rate']);
        $this->assertEquals(0.75, $data[1]['rate']); // late + present
        $this->assertNull($data[2]['rate']); // only leaves: nothing counted
        $this->assertSame(0, $data[2]['counted']);
        $this->assertSame(1, $data[2]['counts']['sick_leave']);
        $this->assertEquals(0.5, $data[3]['rate']); // absent + present
    }

    public function test_a_period_is_checked_once_a_day(): void
    {
        $this->check('2026-10-05', [], ['period_no' => 1]);
        $this->check('2026-10-05', [], ['period_no' => 2]);
        $unnumbered = $this->check('2026-10-05');

        foreach ([['period_no' => 1], []] as $extra) {
            $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/attendance-sessions", $extra + ['classroom_id' => $this->classroom->id, 'held_on' => '2026-10-05'])
                ->assertStatus(422)->assertJsonPath('code', 'attendance_period_taken');
        }
        $this->asUser($this->teacher)->putJson("/api/v1/attendance-sessions/{$unnumbered}", ['period_no' => 2])
            ->assertStatus(422)->assertJsonPath('code', 'attendance_period_taken');
        $this->asUser($this->teacher)->putJson("/api/v1/attendance-sessions/{$unnumbered}", ['period_no' => 3])
            ->assertOk()->assertJsonPath('data.period_no', 3);
    }

    public function test_a_session_is_edited_afterwards_and_deleted(): void
    {
        $id = $this->check('2026-10-05', [0 => 'absent']);

        $this->asUser($this->teacher)->putJson("/api/v1/attendance-sessions/{$id}", [
            'held_on' => '2026-10-04',
            'records' => [['student_id' => $this->students[0]->id, 'status' => 'personal_leave', 'note' => 'ไปธุระกับผู้ปกครอง']],
        ])->assertOk()
            ->assertJsonPath('data.held_on', '2026-10-04')
            ->assertJsonPath('data.records.0.status', 'personal_leave')
            ->assertJsonPath('data.records.1.status', 'present') // students not sent keep their status
            ->assertJsonPath('data.counts.personal_leave', 1);

        $this->asUser($this->teacher)->deleteJson("/api/v1/attendance-sessions/{$id}")->assertNoContent();
        $this->assertSame(0, AttendanceSession::query()->count());
        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_bad_input_is_refused(): void
    {
        $outsider = $this->enrollStudent($this->makeClassroom($this->teacher), 1, 'คนนอกห้อง')['student'];
        $url = "/api/v1/courses/{$this->course->id}/attendance-sessions";
        $base = ['classroom_id' => $this->classroom->id, 'held_on' => '2026-10-05'];

        $this->asUser($this->teacher)->postJson($url, ['classroom_id' => $this->classroom->id])->assertStatus(422)->assertJsonValidationErrors(['held_on']);
        $this->asUser($this->teacher)->postJson($url, ['held_on' => '05/10/2026'] + $base)->assertStatus(422)->assertJsonValidationErrors(['held_on']);
        $this->asUser($this->teacher)->postJson($url, $base + ['period_no' => 21])->assertStatus(422)->assertJsonValidationErrors(['period_no']);
        $this->asUser($this->teacher)->postJson($url, $base + ['records' => [['student_id' => $this->students[0]->id, 'status' => 'holiday']]])
            ->assertStatus(422)->assertJsonValidationErrors(['records.0.status']);
        $this->asUser($this->teacher)->postJson($url, $base + ['records' => [['student_id' => $outsider->id, 'status' => 'late']]])
            ->assertStatus(422)->assertJsonValidationErrors(['records.0.student_id']);
        // A classroom the course is not bound to.
        $this->asUser($this->teacher)->postJson($url, ['classroom_id' => $this->makeClassroom($this->teacher)->id] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->assertSame(0, AttendanceSession::query()->count());
    }

    public function test_only_the_courses_teacher_reads_and_writes_attendance(): void
    {
        $id = $this->check('2026-10-05');
        $other = $this->makeTeacher($this->teacher->school);

        $this->asUser($other)->getJson("/api/v1/courses/{$this->course->id}/attendance?classroom_id={$this->classroom->id}")->assertNotFound();
        $this->asUser($other)->getJson("/api/v1/attendance-sessions/{$id}")->assertNotFound();
        $this->asUser($other)->putJson("/api/v1/attendance-sessions/{$id}", ['note' => 'x'])->assertNotFound();
        $this->asUser($other)->deleteJson("/api/v1/attendance-sessions/{$id}")->assertNotFound();
        $this->asUser($this->students[0], ['student'])->getJson("/api/v1/attendance-sessions/{$id}")->assertForbidden();
    }

    public function test_the_attendance_item_of_the_gradebook_follows_the_records(): void
    {
        $first = $this->check('2026-10-05', [1 => 'late', 2 => 'sick_leave', 3 => 'absent']);
        $itemId = $this->autoItem(10);

        $scores = fn () => GradebookEntry::query()->where('gradebook_item_id', $itemId)->orderBy('student_id')->pluck('score', 'student_id')->map(fn ($s) => (float) $s)->all();
        // Created after the first session: filled at once. The student on leave has no score.
        $this->assertSame([$this->students[0]->id => 10.0, $this->students[1]->id => 5.0, $this->students[3]->id => 0.0], $scores());

        $this->check('2026-10-06');
        $this->assertSame([$this->students[0]->id => 10.0, $this->students[1]->id => 7.5, $this->students[2]->id => 10.0, $this->students[3]->id => 5.0], $scores());

        $this->asUser($this->teacher)->putJson("/api/v1/attendance-sessions/{$first}", ['records' => [['student_id' => $this->students[3]->id, 'status' => 'present']]])->assertOk();
        $this->assertSame(10.0, $scores()[$this->students[3]->id]);

        // Late counts in full from now on; the full marks change too.
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/attendance/scores", ['present' => 1, 'late' => 1, 'absent' => 0])
            ->assertOk()->assertJsonPath('data.scores.late', 1);
        $this->assertSame(10.0, $scores()[$this->students[1]->id]);
        $this->asUser($this->teacher)->patchJson("/api/v1/gradebook-items/{$itemId}", ['max_points' => 5])->assertOk();
        $this->assertSame(5.0, $scores()[$this->students[0]->id]);

        $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/attendance?classroom_id={$this->classroom->id}")
            ->assertOk()->assertJsonPath('data.auto_item.id', $itemId);
        $column = collect($this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/gradebook?classroom_id={$this->classroom->id}")->assertOk()->json('data.columns'))
            ->firstWhere('id', $itemId);
        $this->assertTrue($column['auto_attendance']);
        $this->assertFalse($column['editable']);
    }

    public function test_the_attendance_item_cannot_be_typed_and_there_is_one_per_classroom(): void
    {
        $itemId = $this->autoItem();

        $this->asUser($this->teacher)->putJson("/api/v1/gradebook-items/{$itemId}/scores", ['scores' => [['student_id' => $this->students[0]->id, 'score' => 3]]])
            ->assertStatus(422)->assertJsonPath('code', 'score_from_attendance');
        $this->asUser($this->teacher)->postJson("/api/v1/gradebook-items/{$itemId}/fill-full")
            ->assertStatus(422)->assertJsonPath('code', 'score_from_attendance');
        $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/gradebook-items", [
            'classroom_ids' => [$this->classroom->id], 'category_id' => GradebookCategory::query()->where('course_id', $this->course->id)->value('id'),
            'name' => 'การเข้าเรียน', 'max_points' => 10, 'auto_attendance' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['classroom_ids.0']);

        // Deleting every session empties the column again.
        $id = $this->check('2026-10-05');
        $this->assertSame(4, GradebookEntry::query()->where('gradebook_item_id', $itemId)->count());
        $this->asUser($this->teacher)->deleteJson("/api/v1/attendance-sessions/{$id}")->assertNoContent();
        $this->assertSame(0, GradebookEntry::query()->where('gradebook_item_id', $itemId)->count());
    }

    public function test_the_values_of_the_statuses_are_between_zero_and_one(): void
    {
        $url = "/api/v1/courses/{$this->course->id}/attendance/scores";
        $this->asUser($this->teacher)->putJson($url, ['present' => 1, 'late' => 1.5, 'absent' => 0])->assertStatus(422)->assertJsonValidationErrors(['late']);
        $this->asUser($this->teacher)->putJson($url, ['present' => 1, 'late' => 0.5])->assertStatus(422)->assertJsonValidationErrors(['absent']);
        $this->asUser($this->teacher)->putJson($url, ['present' => 1, 'late' => 0.75, 'absent' => 0.25])->assertOk()
            ->assertJsonPath('data.scores', ['present' => 1, 'late' => 0.75, 'absent' => 0.25]);
    }

    public function test_a_student_reads_their_own_attendance(): void
    {
        $this->check('2026-10-05', [0 => 'late', 1 => 'absent'], ['period_no' => 1]);
        $this->check('2026-10-06', [0 => 'sick_leave'], ['note' => 'สอบย่อย']);

        $this->asUser($this->students[0], ['student'])->getJson('/api/v1/student/attendance')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course.id', $this->course->id)
            ->assertJsonPath('data.0.classroom.id', $this->classroom->id)
            ->assertJsonPath('data.0.counts.late', 1)
            ->assertJsonPath('data.0.counts.sick_leave', 1)
            ->assertJsonPath('data.0.counted', 1)
            ->assertJsonPath('data.0.rate', 0.5)
            ->assertJsonCount(2, 'data.0.sessions')
            ->assertJsonPath('data.0.sessions.0.held_on', '2026-10-06')
            ->assertJsonPath('data.0.sessions.0.status', 'sick_leave')
            ->assertJsonPath('data.0.sessions.1.period_no', 1)
            ->assertJsonMissingPath('data.0.sessions.0.student_id');

        $this->asUser($this->students[0], ['student'])->getJson('/api/v1/student/attendance?course_id=999999')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($this->teacher)->getJson('/api/v1/student/attendance')->assertForbidden();
    }
}
