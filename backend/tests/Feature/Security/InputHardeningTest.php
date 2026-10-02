<?php

namespace Tests\Feature\Security;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Review\ReviewFixtures;
use Tests\TestCase;

/**
 * Mass assignment and malformed input: ownership columns come from the
 * token, never from the body; AI columns cannot be written through the
 * review endpoint; and odd ids or bodies end as 404/422 in the API
 * envelope, never as a 500.
 */
class InputHardeningTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private User $teacherB;

    private Classroom $classroomB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(1);
        $this->teacherB = $this->makeTeacher();
        $this->classroomB = $this->makeClassroom($this->teacherB);
    }

    public function test_a_classroom_always_belongs_to_the_caller(): void
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/classrooms', [
            'name' => 'ป.4/2', 'grade_level' => 4, 'academic_year' => 2569,
            'id' => 9999, 'school_id' => $this->teacherB->school_id, 'teacher_id' => $this->teacherB->id, 'class_code' => 'HACKED',
        ])->assertCreated()->json('data.id');

        $classroom = Classroom::query()->findOrFail($id);
        $this->assertSame([$this->teacher->school_id, $this->teacher->id], [$classroom->school_id, $classroom->teacher_id]);
        $this->assertNotSame('HACKED', $classroom->class_code);
        $this->assertNotSame(9999, $classroom->id);

        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$id}", [
            'name' => 'ป.4/3', 'teacher_id' => $this->teacherB->id, 'school_id' => $this->teacherB->school_id, 'class_code' => 'HACKED',
        ])->assertOk();
        $classroom->refresh();
        $this->assertSame(['ป.4/3', $this->teacher->id, $this->teacher->school_id], [$classroom->name, $classroom->teacher_id, $classroom->school_id]);
        $this->assertNotSame('HACKED', $classroom->class_code);
    }

    public function test_an_assignment_cannot_be_created_in_someone_elses_classroom_or_with_a_forged_state(): void
    {
        $course = $this->makeCourse($this->teacher, [$this->classroom], ['subject_id' => $this->assignment->subject_id]);
        $status = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroomB->id, 'course_id' => $course->id, 'title' => 'x',
        ])->getStatusCode();
        $this->assertContains($status, [403, 404, 422]);
        $this->assertSame(0, Assignment::query()->where('classroom_id', $this->classroomB->id)->count());

        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $course->id, 'title' => 'การบ้านใหม่',
            'school_id' => $this->teacherB->school_id, 'created_by' => $this->teacherB->id, 'status' => 'ready', 'current_layout_version' => 7,
        ])->assertCreated()->json('data.id');
        $created = Assignment::query()->findOrFail($id);
        $this->assertSame([$this->teacher->school_id, $this->teacher->id, 'draft', null], [$created->school_id, $created->created_by, $created->status, $created->current_layout_version]);
    }

    public function test_bulk_added_students_ignore_role_status_school_and_pin(): void
    {
        $row = $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students", [
            'students' => [[
                'name' => 'เด็กใหม่', 'student_number' => 40,
                'role' => 'teacher', 'status' => 'disabled', 'school_id' => $this->teacherB->school_id, 'pin' => '000000', 'email' => 'x@example.com', 'password' => 'p',
            ]],
        ])->assertCreated()->json('data.0');

        $student = User::query()->findOrFail($row['student_id']);
        $this->assertSame(['student', 'active', $this->teacher->school_id, null], [$student->role, $student->status, $student->school_id, $student->email]);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $row['pin']);
        $this->assertNotSame('000000', $row['pin']);
    }

    /**
     * school_id picks the school (since 2 Oct 2569 teachers choose it and an
     * admin's approval is the gate), but role, status and id are never taken
     * from the body: the account is always a pending teacher.
     */
    public function test_registration_ignores_role_and_status(): void
    {
        $school = $this->teacherB->school;
        $user = $this->postJson('/api/v1/auth/teacher/register', [
            'school_id' => $school->id, 'name' => 'ครูใหม่', 'email' => 'new@example.com', 'password' => 'secret1234',
            'role' => 'admin', 'status' => 'active', 'approved_by' => $this->teacher->id, 'id' => 1,
        ])->assertCreated()->json('user');

        $this->assertSame(['teacher', 'pending', $school->id], [$user['role'], $user['status'], $user['school']['id']]);
        $this->assertNull(User::query()->where('email', 'new@example.com')->value('approved_by'));
        $this->assertSame(['teacher', 'pending'], User::query()->where('email', 'new@example.com')->get(['role', 'status'])->map(fn ($u) => [$u->role, $u->status])->first());
    }

    public function test_the_review_endpoint_cannot_touch_ai_columns_or_move_the_answer(): void
    {
        $response = $this->answer($this->students[0], 'q1'); // ai_score 1 of 2
        $before = $response->only(['ai_score', 'ai_understanding', 'review_priority', 'priority_band', 'grading_state', 'submission_id', 'question_id', 'extraction', 'fuzzy_trace']);

        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", [
            'final_score' => 2, 'final_understanding' => 'good', 'reason' => 'อ่านลายมือได้',
            'ai_score' => 0, 'ai_understanding' => 'not_yet', 'review_priority' => 0, 'priority_band' => 'confident', 'grading_state' => 'manual',
            'submission_id' => 999, 'question_id' => 999, 'extraction' => ['blank' => true], 'fuzzy_trace' => [], 'reviewed_by' => $this->teacherB->id,
        ])->assertOk();

        $response->refresh();
        $this->assertSame($before, $response->only(array_keys($before)));
        $this->assertSame([2.0, 'good', $this->teacher->id], [$response->final_score, $response->final_understanding, $response->reviewed_by]);
    }

    public function test_ids_that_are_not_plain_integers_are_404_in_the_envelope(): void
    {
        foreach ([
            'abc', '1e3', '-1', '1.0', '0x1', '%00', '99999999999999999999999', '1;DROP TABLE classrooms', '1 OR 1=1', "1\n", ' ', '๑',
        ] as $id) {
            $response = $this->asUser($this->teacher)->getJson('/api/v1/classrooms/'.rawurlencode($id));
            $this->assertContains($response->getStatusCode(), [404, 405], "classrooms/{$id}");
            $this->assertSame(['message', 'errors', 'code'], array_keys($response->json()), "classrooms/{$id}");
        }
        $this->assertSame(0, Classroom::query()->where('id', '<', 0)->count());
    }

    public function test_malformed_bodies_are_client_errors_in_the_envelope(): void
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($this->teacher)];
        $raw = $this->call('POST', '/api/v1/classrooms', [], [], [], $headers, '{"name": "ป.4/2", "grade_level": ');
        $this->assertContains($raw->getStatusCode(), [400, 422], 'invalid JSON');
        $this->assertSame(['message', 'errors', 'code'], array_keys($raw->json()));

        $cases = [
            'name as array' => ['name' => ['ป.4/2'], 'grade_level' => 4, 'academic_year' => 2569],
            'name as object' => ['name' => ['a' => ['b' => 'c']], 'grade_level' => 4, 'academic_year' => 2569],
            'grade as injection' => ['name' => 'ป.4/2', 'grade_level' => '4; DROP TABLE users', 'academic_year' => 2569],
            'grade as float string' => ['name' => 'ป.4/2', 'grade_level' => '4.5', 'academic_year' => 2569],
            'year as array' => ['name' => 'ป.4/2', 'grade_level' => 4, 'academic_year' => [2569]],
            'oversized name' => ['name' => str_repeat('ก', 20000), 'grade_level' => 4, 'academic_year' => 2569],
            'empty body' => [],
            'null everywhere' => ['name' => null, 'grade_level' => null, 'academic_year' => null],
        ];
        foreach ($cases as $label => $body) {
            $this->asUser($this->teacher)->postJson('/api/v1/classrooms', $body)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonStructure(['message', 'errors', 'code']);
        }
        $this->assertSame(1, Classroom::query()->where('teacher_id', $this->teacher->id)->count(), 'nothing was created');

        // Students: a string instead of the list, a list of scalars, a giant list.
        foreach ([
            ['students' => 'x'],
            ['students' => ['x', 'y']],
            ['students' => [['name' => ['nested'], 'student_number' => 1]]],
            ['students' => array_fill(0, 2000, ['name' => 'x', 'student_number' => 1])],
        ] as $body) {
            $this->asUser($this->teacher)->postJson("/api/v1/classrooms/{$this->classroom->id}/students", $body)->assertStatus(422);
        }
    }

    public function test_unknown_fields_are_ignored_and_control_characters_do_not_crash(): void
    {
        $status = $this->asUser($this->teacher)->postJson('/api/v1/devices', ['fcm_token' => 'token-1', 'user_id' => 999, 'platform' => 'ios', 'admin' => true])->getStatusCode();
        $this->assertContains($status, [200, 201]);

        $response = $this->asUser($this->teacher)->postJson('/api/v1/classrooms', ['name' => "ป.4/2\0\u{200B}<script>", 'grade_level' => 4, 'academic_year' => 2569]);
        $this->assertContains($response->getStatusCode(), [201, 422]);
    }
}
