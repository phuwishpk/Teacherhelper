<?php

namespace Tests\Feature\Api;

use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §9.3 GET /skills?subject=&grade=&q= and GET /subjects.
 */
class SkillTest extends TestCase
{
    use RefreshDatabase;

    public function test_skills_can_be_filtered_by_subject_grade_and_text(): void
    {
        $teacher = $this->makeTeacher();
        $math = Subject::query()->updateOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์']);
        $sci = Subject::query()->updateOrCreate(['code' => 'ว'], ['name' => 'วิทยาศาสตร์']);
        Skill::factory()->create(['subject_id' => $math->id, 'code' => 'ค 1.1 ป.5/1', 'grade_level' => 5, 'name' => 'เศษส่วนและจำนวนคละ']);
        Skill::factory()->create(['subject_id' => $math->id, 'code' => 'ค 1.1 ป.5/2', 'grade_level' => 5, 'name' => 'ทศนิยม']);
        Skill::factory()->create(['subject_id' => $math->id, 'code' => 'ค 1.1 ป.6/1', 'grade_level' => 6, 'name' => 'ร้อยละ']);
        Skill::factory()->create(['subject_id' => $sci->id, 'code' => 'ว 1.1 ป.5/1', 'grade_level' => 5, 'name' => 'สิ่งมีชีวิต']);

        $this->asUser($teacher)->getJson('/api/v1/skills')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'subject_id', 'parent_id', 'school_id', 'grade_level']], 'meta' => ['next_cursor']]);

        $this->asUser($teacher)->getJson('/api/v1/skills?subject='.$math->id.'&grade=5')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'ค 1.1 ป.5/1');

        // subject may also be the code
        $this->asUser($teacher)->getJson('/api/v1/skills?'.http_build_query(['subject' => 'ว']))->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($teacher)->getJson('/api/v1/skills?'.http_build_query(['subject' => 'ไม่มี']))->assertOk()->assertJsonCount(0, 'data');

        // Clients percent-encode query values (Dio does); the test client must too.
        $this->asUser($teacher)->getJson('/api/v1/skills?'.http_build_query(['q' => 'ทศนิยม']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'ทศนิยม');
        $this->asUser($teacher)->getJson('/api/v1/skills?'.http_build_query(['q' => 'ป.6']))->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($teacher)->getJson('/api/v1/skills?'.http_build_query(['q' => '1.1 ป.5']))->assertOk()->assertJsonCount(3, 'data');
        $this->asUser($teacher)->getJson('/api/v1/skills?q=%25')->assertOk()->assertJsonCount(0, 'data'); // LIKE wildcards are escaped

        $this->asUser($teacher)->getJson('/api/v1/skills?grade=13')->assertStatus(422)->assertJsonValidationErrors(['grade']);
    }

    public function test_teachers_see_curriculum_skills_plus_their_own_schools_sub_skills(): void
    {
        $teacher = $this->makeTeacher();
        $otherSchoolTeacher = $this->makeTeacher();
        $subject = Subject::factory()->create();
        $indicator = Skill::factory()->create(['subject_id' => $subject->id, 'code' => 'ค 1.1 ป.5/1', 'grade_level' => 5]);
        Skill::factory()->create(['subject_id' => $subject->id, 'parent_id' => $indicator->id, 'school_id' => $teacher->school_id, 'code' => 'ค 1.1 ป.5/1 ก', 'grade_level' => 5]);
        Skill::factory()->create(['subject_id' => $subject->id, 'parent_id' => $indicator->id, 'school_id' => $otherSchoolTeacher->school_id, 'code' => 'ค 1.1 ป.5/1 ข', 'grade_level' => 5]);

        $codes = collect($this->asUser($teacher)->getJson('/api/v1/skills')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.1 ป.5/1 ก'], $codes);

        $codes = collect($this->asUser($otherSchoolTeacher)->getJson('/api/v1/skills')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.1 ป.5/1 ข'], $codes);
    }

    public function test_skills_are_paginated_with_a_cursor(): void
    {
        $teacher = $this->makeTeacher();
        $subject = Subject::factory()->create();
        Skill::factory()->count(101)->create(['subject_id' => $subject->id, 'grade_level' => 5]);

        $first = $this->asUser($teacher)->getJson('/api/v1/skills')->assertOk()->assertJsonCount(100, 'data');
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $this->asUser($teacher)->getJson('/api/v1/skills?cursor='.$cursor)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    }

    public function test_subjects_are_listed_for_the_picker(): void
    {
        $teacher = $this->makeTeacher();

        // The eight learning areas come with the migration (DESIGN §29.4).
        $this->asUser($teacher)->getJson('/api/v1/subjects')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.code', 'ค')
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'is_own']]]);
    }

    public function test_skills_require_a_teacher(): void
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher);
        ['student' => $student] = $this->enrollStudent($classroom);

        $this->asUser($student, ['student'])->getJson('/api/v1/skills')->assertForbidden();
        $this->asUser($student, ['student'])->getJson('/api/v1/subjects')->assertForbidden();
        $this->asGuest()->getJson('/api/v1/skills')->assertUnauthorized();
    }
}
