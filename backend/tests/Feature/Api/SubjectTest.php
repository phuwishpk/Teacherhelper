<?php

namespace Tests\Feature\Api;

use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §29.4: the eight learning areas exist from the first migrate, and a
 * teacher adds subject groups only they can see.
 */
class SubjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_eight_learning_areas_exist_after_migrating(): void
    {
        $this->assertSame(
            ['ค', 'ง', 'ต', 'ท', 'พ', 'ว', 'ศ', 'ส'],
            Subject::query()->whereNull('owner_user_id')->orderBy('code')->pluck('code')->all(),
        );
        $this->assertSame('คณิตศาสตร์', Subject::query()->where('code', 'ค')->value('name'));
    }

    public function test_a_teacher_adds_a_subject_group_only_they_see(): void
    {
        $teacher = $this->makeTeacher();
        $other = $this->makeTeacher();

        $id = $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => '  หน้าที่   พลเมือง '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'หน้าที่ พลเมือง')
            ->assertJsonPath('data.is_own', true)
            ->json('data.id');

        $mine = $this->asUser($teacher)->getJson('/api/v1/subjects')->assertOk()->json('data');
        $this->assertCount(9, $mine);
        $this->assertSame($id, $mine[8]['id']); // own groups come after the shared ones
        $this->assertFalse($mine[0]['is_own']);

        $this->asUser($other)->getJson('/api/v1/subjects')->assertOk()->assertJsonCount(8, 'data');
        // Both teachers may use the same name: each sees only their own.
        $this->asUser($other)->postJson('/api/v1/subjects', ['name' => 'หน้าที่ พลเมือง'])->assertCreated();
    }

    public function test_a_name_already_in_the_teachers_list_is_refused(): void
    {
        $teacher = $this->makeTeacher();

        $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => 'คณิตศาสตร์'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => 'ลูกเสือ'])->assertCreated();
        $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => 'ลูกเสือ'])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => ' '])
            ->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_a_teacher_renames_and_deletes_only_their_own_subject_group(): void
    {
        $teacher = $this->makeTeacher();
        $other = $this->makeTeacher();
        $id = $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => 'ลูกเสือ'])->json('data.id');
        $shared = Subject::query()->where('code', 'ค')->value('id');

        $this->asUser($other)->patchJson("/api/v1/subjects/{$id}", ['name' => 'x'])->assertNotFound();
        $this->asUser($other)->deleteJson("/api/v1/subjects/{$id}")->assertNotFound();
        $this->asUser($teacher)->patchJson("/api/v1/subjects/{$shared}", ['name' => 'x'])->assertNotFound();
        $this->asUser($teacher)->deleteJson("/api/v1/subjects/{$shared}")->assertNotFound();

        $this->asUser($teacher)->patchJson("/api/v1/subjects/{$id}", ['name' => 'ลูกเสือ-เนตรนารี'])
            ->assertOk()->assertJsonPath('data.name', 'ลูกเสือ-เนตรนารี');
        $this->asUser($teacher)->deleteJson("/api/v1/subjects/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('subjects', ['id' => $id]);
    }

    public function test_a_subject_group_in_use_cannot_be_deleted(): void
    {
        $teacher = $this->makeTeacher();
        $id = $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => 'ลูกเสือ'])->json('data.id');
        $this->makeCourse($teacher, [], ['subject_id' => $id]);

        $this->asUser($teacher)->deleteJson("/api/v1/subjects/{$id}")
            ->assertStatus(409)->assertJsonPath('code', 'subject_in_use');
    }

    public function test_a_course_uses_an_own_subject_group_but_not_another_teachers(): void
    {
        $teacher = $this->makeTeacher();
        $other = $this->makeTeacher();
        $id = $this->asUser($teacher)->postJson('/api/v1/subjects', ['name' => 'ลูกเสือ'])->json('data.id');
        $course = ['code' => 'ก15101', 'name' => 'ลูกเสือ 5', 'subject_id' => $id, 'grade_level' => 5, 'academic_year' => 2569];

        $this->asUser($other)->postJson('/api/v1/courses', $course)
            ->assertStatus(422)->assertJsonValidationErrors(['subject_id']);
        $this->asUser($teacher)->postJson('/api/v1/courses', $course)
            ->assertCreated()->assertJsonPath('data.subject.name', 'ลูกเสือ');
    }

    public function test_students_and_guests_cannot_add_subject_groups(): void
    {
        $student = User::factory()->student()->create();

        $this->asUser($student, ['student'])->postJson('/api/v1/subjects', ['name' => 'x'])->assertForbidden();
        $this->asGuest()->postJson('/api/v1/subjects', ['name' => 'x'])->assertUnauthorized();
    }
}
