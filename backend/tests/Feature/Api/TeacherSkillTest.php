<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §20.2 / §20.7: skill levels in GET /skills (filter and tree), and
 * indicators a teacher adds for the school (POST / PATCH /skills).
 */
class TeacherSkillTest extends TestCase
{
    use RefreshDatabase;

    private Subject $math;

    private Skill $strand;

    private Skill $standard;

    private Skill $indicator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->math = Subject::factory()->create(['code' => 'ค', 'name' => 'คณิตศาสตร์']);
        $this->strand = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 1', 'name' => 'จำนวนและพีชคณิต', 'grade_level' => null, 'level' => Skill::LEVEL_STRAND]);
        $this->standard = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->strand->id, 'code' => 'ค 1.1', 'name' => 'มาตรฐาน', 'grade_level' => null, 'level' => Skill::LEVEL_STANDARD]);
        $this->indicator = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'code' => 'ค 1.1 ป.5/1', 'name' => 'เศษส่วน', 'grade_level' => 5]);
    }

    public function test_skills_filter_by_level_and_come_as_a_tree_with_their_ancestors(): void
    {
        $teacher = $this->makeTeacher();
        Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'code' => 'ค 1.1 ป.6/1', 'name' => 'ร้อยละ', 'grade_level' => 6]);
        Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'code' => 'ค 1.1 ป.5/10', 'name' => 'ตัวที่สิบ', 'grade_level' => 5]);

        $this->asUser($teacher)->getJson('/api/v1/skills?level=indicator,sub_indicator')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.level', 'indicator')
            ->assertJsonPath('data.0.source', 'curriculum')
            ->assertJsonPath('data.0.source_label', null);
        $this->asUser($teacher)->getJson('/api/v1/skills?level=standard')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ค 1.1');
        $this->asUser($teacher)->getJson('/api/v1/skills?level=chapter')->assertStatus(422)->assertJsonValidationErrors(['level']);

        // tree=1 needs a subject, and brings the strand and standard of each match.
        $this->asUser($teacher)->getJson('/api/v1/skills?tree=1')->assertStatus(422)->assertJsonValidationErrors(['subject']);
        $tree = $this->asUser($teacher)->getJson('/api/v1/skills?'.http_build_query(['tree' => 1, 'subject' => 'ค', 'grade' => 5]))
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $tree);
        $this->assertSame(['ค 1', 'strand'], [$tree[0]['code'], $tree[0]['level']]);
        $this->assertSame('ค 1.1', $tree[0]['children'][0]['code']);
        // Natural order: ป.5/1 before ป.5/10, and ป.6/1 is filtered out by grade.
        $this->assertSame(['ค 1.1 ป.5/1', 'ค 1.1 ป.5/10'], array_column($tree[0]['children'][0]['children'], 'code'));
        $this->assertSame([], $tree[0]['children'][0]['children'][0]['children']);
    }

    public function test_a_teacher_adds_a_missing_indicator_numbered_under_its_parent_for_the_school(): void
    {
        $teacher = $this->makeTeacher();
        $colleague = $this->makeTeacher($teacher->school);
        $otherSchool = $this->makeTeacher();

        $first = $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => ' เปรียบเทียบเศษส่วน ', 'grade_level' => 5])
            ->assertCreated()
            ->assertJsonPath('data.code', 'ค 1.1/ค1')
            ->assertJsonPath('data.level', 'indicator')
            ->assertJsonPath('data.source', 'teacher')
            ->assertJsonPath('data.source_label', 'ครูเพิ่มเอง')
            ->assertJsonPath('data.name', 'เปรียบเทียบเศษส่วน')
            ->assertJsonPath('data.school_id', $teacher->school_id)
            ->assertJsonPath('data.subject_id', $this->math->id)
            ->assertJsonPath('data.created_by', $teacher->id)
            ->json('data');
        $this->asUser($colleague)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'อีกตัว'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'ค 1.1/ค2')
            ->assertJsonPath('data.grade_level', null);
        // Under an indicator: a sub-indicator with the parent's grade.
        $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->indicator->id, 'name' => 'ย่อย', 'code' => 'ค 1.1 ป.5/1 (ก)'])
            ->assertCreated()
            ->assertJsonPath('data.level', 'sub_indicator')
            ->assertJsonPath('data.grade_level', 5)
            ->assertJsonPath('data.code', 'ค 1.1 ป.5/1 (ก)');

        // The whole school sees them; another school does not.
        $codes = fn ($user) => collect($this->asUser($user)->getJson('/api/v1/skills?level=indicator,sub_indicator')->json('data'))->pluck('code')->all();
        $this->assertContains('ค 1.1/ค1', $codes($colleague));
        $this->assertNotContains('ค 1.1/ค1', $codes($otherSchool));
        // Another school numbers its own from 1 again.
        $this->asUser($otherSchool)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'ของโรงเรียนอื่น'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'ค 1.1/ค1');

        // Codes of the curriculum or the school are taken.
        foreach (['ค 1.1 ป.5/1', 'ค 1.1/ค1'] as $taken) {
            $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'ซ้ำ', 'code' => $taken])
                ->assertStatus(422)
                ->assertJsonPath('code', 'skill_code_taken')
                ->assertJsonValidationErrors(['code']);
        }
        $this->assertSame(4, Skill::query()->where('source', Skill::SOURCE_TEACHER)->count());
        $this->assertNotNull($first['id']);
    }

    public function test_the_parent_must_be_a_standard_or_an_indicator_the_school_sees(): void
    {
        $teacher = $this->makeTeacher();
        $otherSchoolSkill = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'school_id' => $this->makeSchool()->id, 'level' => Skill::LEVEL_INDICATOR]);
        $sub = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->indicator->id, 'level' => Skill::LEVEL_SUB_INDICATOR]);
        $science = Subject::factory()->create(['code' => 'ว']);

        $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->strand->id, 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $sub->id, 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $otherSchoolSkill->id, 'name' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'x', 'subject_id' => $science->id])->assertStatus(422)->assertJsonValidationErrors(['subject_id']);
        $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id])->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'x', 'code' => str_repeat('ก', 41)])->assertStatus(422)->assertJsonValidationErrors(['code']);
        $this->assertSame(0, Skill::query()->where('source', Skill::SOURCE_TEACHER)->count());
    }

    public function test_only_the_creator_edits_a_teacher_indicator_and_only_before_any_observation(): void
    {
        $teacher = $this->makeTeacher();
        $colleague = $this->makeTeacher($teacher->school);
        $id = $this->asUser($teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'ชื่อเดิม'])->json('data.id');

        $this->asUser($teacher)->patchJson("/api/v1/skills/{$id}", ['name' => 'ชื่อใหม่', 'code' => 'ค 1.1/ใหม่', 'grade_level' => 6])
            ->assertOk()
            ->assertJsonPath('data.name', 'ชื่อใหม่')
            ->assertJsonPath('data.code', 'ค 1.1/ใหม่')
            ->assertJsonPath('data.grade_level', 6);
        $this->asUser($teacher)->patchJson("/api/v1/skills/{$id}", ['code' => 'ค 1.1 ป.5/1'])->assertStatus(422)->assertJsonPath('code', 'skill_code_taken');
        // Keeping its own code is not a clash.
        $this->asUser($teacher)->patchJson("/api/v1/skills/{$id}", ['code' => 'ค 1.1/ใหม่', 'name' => 'ชื่อใหม่'])->assertOk();

        $this->asUser($colleague)->patchJson("/api/v1/skills/{$id}", ['name' => 'แก้โดยเพื่อน'])->assertForbidden();
        $this->asUser($this->makeTeacher())->patchJson("/api/v1/skills/{$id}", ['name' => 'โรงเรียนอื่น'])->assertNotFound();
        $this->asUser($teacher)->patchJson("/api/v1/skills/{$this->indicator->id}", ['name' => 'หลักสูตร'])->assertForbidden();

        ['student' => $student] = $this->enrollStudent($this->makeClassroom($teacher));
        SkillObservation::create(['student_id' => $student->id, 'skill_id' => $id, 'source' => SkillObservation::SOURCE_HOMEWORK, 'score_ratio' => 0.5, 'observed_at' => now()]);
        $this->asUser($teacher)->patchJson("/api/v1/skills/{$id}", ['name' => 'หลังมีผล'])->assertStatus(409)->assertJsonPath('code', 'skill_in_use');
        $this->assertSame('ชื่อใหม่', Skill::query()->whereKey($id)->value('name'));
    }

    public function test_questions_take_indicators_and_sub_indicators_only(): void
    {
        $teacher = $this->makeTeacher();
        $assignment = Assignment::factory()->for_classroom($this->makeClassroom($teacher))->create(['subject_id' => $this->math->id]);
        $body = fn (array $skillIds) => ['type' => 'short', 'prompt_text' => '1 + 1', 'max_points' => 1, 'answer_key' => ['accepted' => ['2']], 'skill_ids' => $skillIds];

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", $body([$this->standard->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['skill_ids.0']);
        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", $body([$this->indicator->id]))->assertCreated();
    }
}
