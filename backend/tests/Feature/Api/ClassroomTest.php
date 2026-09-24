<?php

namespace Tests\Feature\Api;

use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §9.2 classrooms CRUD and the authorization matrix
 * (own teacher / other teacher / student / unauthenticated).
 */
class ClassroomTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = $this->makeTeacher();
    }

    public function test_create_returns_the_classroom_with_a_six_character_class_code(): void
    {
        $response = $this->asUser($this->teacher)->postJson('/api/v1/classrooms', [
            'name' => 'ป.5/2',
            'grade_level' => 5,
            'academic_year' => 2569,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ป.5/2')
            ->assertJsonPath('data.grade_level', 5)
            ->assertJsonPath('data.academic_year', 2569)
            ->assertJsonPath('data.students_count', 0);

        $code = $response->json('data.class_code');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{6}$/', $code);
        $this->assertDatabaseHas('classrooms', [
            'id' => $response->json('data.id'),
            'school_id' => $this->teacher->school_id,
            'teacher_id' => $this->teacher->id,
            'class_code' => $code,
        ]);
    }

    public function test_create_validates_the_body(): void
    {
        $this->asUser($this->teacher)->postJson('/api/v1/classrooms', [
            'name' => '',
            'grade_level' => 13,
            'academic_year' => 2026,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'grade_level', 'academic_year']);
    }

    public function test_index_lists_only_the_teachers_own_classrooms_with_cursor_pagination(): void
    {
        $mine = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1', 'grade_level' => 5]);
        $this->makeClassroom($this->teacher, ['name' => 'ป.6/1', 'grade_level' => 6]);
        $this->enrollStudent($mine, 1);
        $this->enrollStudent($mine, 2);

        $colleague = $this->makeTeacher($this->teacher->school);
        $this->makeClassroom($colleague, ['name' => 'ป.4/1', 'grade_level' => 4]);
        $this->makeClassroom($this->makeTeacher(), ['name' => 'อื่น']);

        $response = $this->asUser($this->teacher)->getJson('/api/v1/classrooms')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'ป.5/1')
            ->assertJsonPath('data.0.students_count', 2)
            ->assertJsonPath('data.1.name', 'ป.6/1')
            ->assertJsonStructure(['data', 'links', 'meta' => ['next_cursor', 'prev_cursor']]);

        $this->assertNull($response->json('meta.next_cursor'));
    }

    public function test_show_and_update_work_for_the_owner(): void
    {
        $classroom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2', 'grade_level' => 5, 'academic_year' => 2568]);

        $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$classroom->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $classroom->id)
            ->assertJsonPath('data.class_code', $classroom->class_code);

        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$classroom->id}", ['academic_year' => 2569])
            ->assertOk()
            ->assertJsonPath('data.academic_year', 2569)
            ->assertJsonPath('data.name', 'ป.5/2');

        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$classroom->id}", ['grade_level' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['grade_level']);

        // class_code is not editable
        $this->asUser($this->teacher)->patchJson("/api/v1/classrooms/{$classroom->id}", ['class_code' => 'HACKED'])
            ->assertOk()
            ->assertJsonPath('data.class_code', $classroom->class_code);
    }

    public function test_another_teacher_gets_404_for_a_classroom_that_is_not_theirs(): void
    {
        $classroom = $this->makeClassroom($this->teacher);
        $colleague = $this->makeTeacher($this->teacher->school);
        $stranger = $this->makeTeacher();

        foreach ([$colleague, $stranger] as $other) {
            $this->asUser($other)->getJson("/api/v1/classrooms/{$classroom->id}")
                ->assertNotFound()
                ->assertJsonPath('code', 'not_found');
            $this->asUser($other)->patchJson("/api/v1/classrooms/{$classroom->id}", ['name' => 'x'])->assertNotFound();
            $this->asUser($other)->getJson("/api/v1/classrooms/{$classroom->id}/roster")->assertNotFound();
            $this->asUser($other)->postJson("/api/v1/classrooms/{$classroom->id}/students", ['students' => [['name' => 'x', 'student_number' => 1]]])->assertNotFound();
            $this->asUser($other)->postJson("/api/v1/classrooms/{$classroom->id}/login-cards")->assertNotFound();
        }

        $this->assertSame($classroom->name, $classroom->fresh()->name);
    }

    public function test_students_and_guests_cannot_use_classroom_routes(): void
    {
        $classroom = $this->makeClassroom($this->teacher);
        ['student' => $student] = $this->enrollStudent($classroom);

        $this->asUser($student, ['student'])->getJson('/api/v1/classrooms')->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->asUser($student, ['student'])->postJson('/api/v1/classrooms', ['name' => 'x', 'grade_level' => 1, 'academic_year' => 2569])->assertForbidden();
        $this->asUser($student, ['student'])->getJson("/api/v1/classrooms/{$classroom->id}")->assertForbidden();

        $this->asGuest()->getJson('/api/v1/classrooms')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->asGuest()->postJson('/api/v1/classrooms', [])->assertUnauthorized();
        $this->asGuest()->getJson("/api/v1/classrooms/{$classroom->id}")->assertUnauthorized();
    }

    public function test_a_teacher_token_without_the_teacher_ability_is_forbidden(): void
    {
        // A token minted with the wrong ability (e.g. a student token reused) must not pass.
        $this->asUser($this->teacher, ['student'])->getJson('/api/v1/classrooms')->assertForbidden();
        $this->asUser($this->teacher, [])->getJson('/api/v1/classrooms')->assertForbidden();
    }

    public function test_a_pending_teacher_cannot_create_classrooms(): void
    {
        $pending = User::factory()->teacher($this->teacher->school)->pending()->create();

        $this->asUser($pending)->postJson('/api/v1/classrooms', ['name' => 'x', 'grade_level' => 1, 'academic_year' => 2569])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_not_active');

        $this->assertSame(0, Classroom::query()->count());
    }
}
