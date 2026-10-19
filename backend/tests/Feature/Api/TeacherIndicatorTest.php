<?php

namespace Tests\Feature\Api;

use App\Models\Skill;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §34: "ตัวชี้วัดของฉัน", the indicators a teacher teaches, and
 * GET /skills?mine=1 that the pickers use.
 */
class TeacherIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private Subject $math;

    private Skill $standard;

    private Skill $p5a;

    private Skill $p5b;

    private Skill $p6;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->math = Subject::query()->updateOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์']);
        $this->standard = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 1.1', 'name' => 'มาตรฐาน', 'grade_level' => null, 'level' => Skill::LEVEL_STANDARD]);
        $this->p5a = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'code' => 'ค 1.1 ป.5/1', 'name' => 'เศษส่วน', 'grade_level' => 5]);
        $this->p5b = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'code' => 'ค 1.1 ป.5/2', 'name' => 'ทศนิยม', 'grade_level' => 5]);
        $this->p6 = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'code' => 'ค 1.1 ป.6/1', 'name' => 'ร้อยละ', 'grade_level' => 6]);
        $this->teacher = $this->makeTeacher();
    }

    public function test_a_teacher_keeps_a_set_and_the_pickers_show_it_with_their_own_indicators(): void
    {
        $this->asUser($this->teacher)->getJson('/api/v1/me/indicators')->assertOk()->assertExactJson(['data' => []]);
        // Nothing picked: mine=1 is the whole curriculum, as before.
        $this->assertEqualsCanonicalizing([$this->p5a->id, $this->p5b->id], $this->mine(5));

        $this->asUser($this->teacher)->putJson('/api/v1/me/indicators', ['skill_ids' => [$this->p5b->id, $this->p5a->id, $this->p5a->id]])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'ค 1.1 ป.5/1');
        $own = $this->asUser($this->teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'ของฉันเอง', 'grade_level' => 5])
            ->assertCreated()->json('data.id');

        $this->assertEqualsCanonicalizing([$this->p5a->id, $this->p5b->id, $own], $this->mine(5));
        $this->assertSame([], $this->mine(6), 'the grade filter still applies');
        // Without mine=1 the picker's "แสดงทั้งหลักสูตร" sees everything.
        $this->assertEqualsCanonicalizing([$this->p5a->id, $this->p5b->id, $this->p6->id, $own], $this->mine(null, all: true));

        // Another teacher's set and indicators are not this teacher's.
        $other = $this->makeTeacher();
        $this->asUser($other)->putJson('/api/v1/me/indicators', ['skill_ids' => [$this->p6->id]])->assertOk();
        $this->assertSame([$this->p6->id], $this->mine(null, as: $other));

        // An empty list clears the set: back to the whole curriculum.
        $this->asUser($this->teacher)->putJson('/api/v1/me/indicators', ['skill_ids' => []])->assertOk()->assertJsonCount(0, 'data');
        $this->assertCount(4, $this->mine(null));
    }

    public function test_only_visible_indicators_and_sub_indicators_are_kept(): void
    {
        $elsewhere = Skill::factory()->create(['subject_id' => $this->math->id, 'parent_id' => $this->standard->id, 'code' => 'ค 1.1 ป.5/ค9', 'grade_level' => 5, 'school_id' => $this->makeSchool()->id, 'source' => Skill::SOURCE_TEACHER]);

        $this->asUser($this->teacher)->putJson('/api/v1/me/indicators', ['skill_ids' => [$this->p5a->id, $this->standard->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['skill_ids.1']);
        $this->asUser($this->teacher)->putJson('/api/v1/me/indicators', ['skill_ids' => [$elsewhere->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['skill_ids.0']);
        $this->asUser($this->teacher)->putJson('/api/v1/me/indicators', ['skill_ids' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['skill_ids']);
        $this->asUser($this->teacher)->getJson('/api/v1/me/indicators')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_students_cannot_keep_a_set(): void
    {
        $student = $this->enrollStudent($this->makeClassroom($this->teacher))['student'];

        $this->asUser($student)->getJson('/api/v1/me/indicators')->assertForbidden();
        $this->asUser($student)->putJson('/api/v1/me/indicators', ['skill_ids' => []])->assertForbidden();
    }

    /** @return list<int> */
    private function mine(?int $grade, bool $all = false, ?User $as = null): array
    {
        $query = array_filter(['grade' => $grade, 'level' => 'indicator,sub_indicator', 'mine' => $all ? null : 1], fn ($v) => $v !== null);

        return collect($this->asUser($as ?? $this->teacher)->getJson('/api/v1/skills?'.http_build_query($query))->assertOk()->json('data'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
