<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §9.3 assignments CRUD: scoping to the teacher's classrooms, UTC
 * due dates, status transitions and delete-only-drafts.
 */
class AssignmentsTest extends TestCase
{
    use RefreshDatabase;

    private function setUpTeacher(): array
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher, ['name' => 'ป.5/2']);
        $subject = Subject::query()->updateOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์']);

        return [$teacher, $classroom, $subject];
    }

    public function test_a_teacher_creates_an_assignment_in_their_classroom(): void
    {
        [$teacher, $classroom, $subject] = $this->setUpTeacher();

        $course = $this->makeCourse($teacher, [$classroom], ['subject_id' => $subject->id]);

        $response = $this->asUser($teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $classroom->id,
            'course_id' => $course->id,
            'title' => '  เศษส่วน ชุดที่ 3 ',
            'strictness' => 'strict',
            'due_at' => '2026-10-01T09:00:00+07:00',
        ])->assertStatus(201);

        $response->assertJsonPath('data.title', 'เศษส่วน ชุดที่ 3')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.strictness', 'strict')
            ->assertJsonPath('data.current_layout_version', null)
            ->assertJsonPath('data.classroom.name', 'ป.5/2')
            ->assertJsonPath('data.subject.name', 'คณิตศาสตร์')
            ->assertJsonPath('data.questions', [])
            ->assertJsonPath('data.due_at', '2026-10-01T02:00:00+00:00'); // stored and returned in UTC

        $this->assertDatabaseHas('assignments', [
            'id' => $response->json('data.id'),
            'school_id' => $teacher->school_id,
            'created_by' => $teacher->id,
            'due_at' => '2026-10-01 02:00:00',
        ]);
    }

    public function test_the_classroom_must_be_one_the_teacher_teaches(): void
    {
        [$teacher, , $subject] = $this->setUpTeacher();
        $otherClassroom = $this->makeClassroom($this->makeTeacher($teacher->school));

        $this->asUser($teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $otherClassroom->id,
            'subject_id' => $subject->id,
            'title' => 'x',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['classroom_id']);
    }

    public function test_validation_errors_are_reported_in_thai(): void
    {
        [$teacher] = $this->setUpTeacher();

        $this->asUser($teacher)->postJson('/api/v1/assignments', ['strictness' => 'loose'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['classroom_id', 'course_id', 'title', 'strictness'])
            ->assertJsonPath('errors.title.0', 'กรุณากรอกชื่อการบ้าน');
    }

    public function test_the_list_holds_only_the_teachers_assignments_and_filters_by_classroom(): void
    {
        [$teacher, $classroom, $subject] = $this->setUpTeacher();
        $second = $this->makeClassroom($teacher);
        $mine = Assignment::factory()->for_classroom($classroom)->create(['subject_id' => $subject->id]);
        Assignment::factory()->for_classroom($second)->create(['subject_id' => $subject->id]);
        $foreign = Assignment::factory()->create(); // another school's teacher

        $all = $this->asUser($teacher)->getJson('/api/v1/assignments')->assertOk();
        $this->assertCount(2, $all->json('data'));
        $this->assertNotContains($foreign->id, array_column($all->json('data'), 'id'));

        $this->asUser($teacher)->getJson('/api/v1/assignments?classroom_id='.$classroom->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.questions_count', 0)
            ->assertJsonPath('data.0.classroom.id', $classroom->id);
    }

    public function test_the_list_counts_the_students_who_handed_in(): void
    {
        [$teacher, $classroom, $subject] = $this->setUpTeacher();
        $assignment = Assignment::factory()->for_classroom($classroom)->create(['subject_id' => $subject->id]);
        $empty = Assignment::factory()->for_classroom($classroom)->create(['subject_id' => $subject->id]);
        foreach ([1, 2] as $n) {
            $student = $this->enrollStudent($classroom, $n)['student'];
            Submission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id]);
        }

        $rows = collect($this->asUser($teacher)->getJson('/api/v1/assignments')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(2, $rows[$assignment->id]['submissions_count']);
        $this->assertSame(0, $rows[$empty->id]['submissions_count']);
    }

    public function test_another_teachers_assignment_is_not_found(): void
    {
        [$teacher] = $this->setUpTeacher();
        $sameSchoolColleague = $this->makeTeacher($teacher->school);
        $assignment = Assignment::factory()->for_classroom($this->makeClassroom($sameSchoolColleague))->create();

        $this->asUser($teacher)->getJson("/api/v1/assignments/{$assignment->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->asUser($teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['title' => 'x'])->assertNotFound();
        $this->asUser($teacher)->deleteJson("/api/v1/assignments/{$assignment->id}")->assertNotFound();
    }

    public function test_students_and_guests_cannot_use_the_endpoints(): void
    {
        [, $classroom] = $this->setUpTeacher();
        $student = $this->enrollStudent($classroom)['student'];

        $this->asUser($student)->getJson('/api/v1/assignments')->assertForbidden();
        $this->asGuest()->getJson('/api/v1/assignments')->assertUnauthorized();
    }

    public function test_a_teacher_edits_title_strictness_and_due_date(): void
    {
        [$teacher, $classroom, $subject] = $this->setUpTeacher();
        $assignment = Assignment::factory()->for_classroom($classroom)->create(['subject_id' => $subject->id, 'due_at' => '2026-10-01 02:00:00']);

        $this->asUser($teacher)->patchJson("/api/v1/assignments/{$assignment->id}", [
            'title' => 'ชุดใหม่',
            'strictness' => 'lenient',
        ])->assertOk()
            ->assertJsonPath('data.title', 'ชุดใหม่')
            ->assertJsonPath('data.strictness', 'lenient')
            ->assertJsonPath('data.due_at', '2026-10-01T02:00:00+00:00');

        $this->asUser($teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['due_at' => null])
            ->assertOk()
            ->assertJsonPath('data.due_at', null);
    }

    public function test_status_can_be_closed_and_reopened_but_ready_only_comes_from_the_layout(): void
    {
        [$teacher, $classroom] = $this->setUpTeacher();
        $assignment = Assignment::factory()->for_classroom($classroom)->create(['status' => 'ready', 'current_layout_version' => 1]);

        $this->asUser($teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['status' => 'closed'])
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->asUser($teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['status' => 'ready'])
            ->assertStatus(422)->assertJsonValidationErrors(['status']);
        $this->asUser($teacher)->patchJson("/api/v1/assignments/{$assignment->id}", ['status' => 'draft'])
            ->assertOk()->assertJsonPath('data.status', 'draft');
    }

    public function test_only_unprinted_drafts_can_be_deleted(): void
    {
        [$teacher, $classroom] = $this->setUpTeacher();
        $draft = Assignment::factory()->for_classroom($classroom)->create();
        $ready = Assignment::factory()->for_classroom($classroom)->create(['status' => 'ready', 'current_layout_version' => 1]);
        $printedDraft = Assignment::factory()->for_classroom($classroom)->create(['current_layout_version' => 1]);
        WorksheetPrint::create(['assignment_id' => $printedDraft->id, 'layout_version' => 1, 'requested_by' => $teacher->id, 'status' => 'ready']);

        $this->asUser($teacher)->deleteJson("/api/v1/assignments/{$ready->id}")->assertStatus(409)->assertJsonPath('code', 'assignment_not_draft');
        $this->asUser($teacher)->deleteJson("/api/v1/assignments/{$printedDraft->id}")->assertStatus(409)->assertJsonPath('code', 'assignment_printed');
        $this->asUser($teacher)->deleteJson("/api/v1/assignments/{$draft->id}")->assertNoContent();

        $this->assertDatabaseMissing('assignments', ['id' => $draft->id]);
        $this->assertDatabaseHas('assignments', ['id' => $ready->id]);
    }

    public function test_a_disabled_teacher_is_rejected(): void
    {
        [$teacher] = $this->setUpTeacher();
        $token = $this->tokenFor($teacher);
        $teacher->update(['status' => User::STATUS_DISABLED]);

        $this->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/assignments')->assertForbidden();
    }
}
