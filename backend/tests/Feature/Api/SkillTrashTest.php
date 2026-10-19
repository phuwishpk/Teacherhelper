<?php

namespace Tests\Feature\Api;

use App\Models\Skill;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §34.1: a teacher's own indicator goes to the trash, comes back,
 * or is deleted for good once nothing uses it.
 */
class SkillTrashTest extends TestCase
{
    use RefreshDatabase;

    private Subject $math;

    private Skill $standard;

    private User $teacher;

    private int $indicator;

    private int $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->math = Subject::query()->updateOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์']);
        $this->standard = Skill::factory()->create(['subject_id' => $this->math->id, 'code' => 'ค 1.1', 'name' => 'มาตรฐาน', 'grade_level' => null, 'level' => Skill::LEVEL_STANDARD]);
        $this->teacher = $this->makeTeacher();
        $this->indicator = $this->asUser($this->teacher)->postJson('/api/v1/skills', ['parent_id' => $this->standard->id, 'name' => 'ของฉัน', 'grade_level' => 5])->assertCreated()->json('data.id');
        $this->sub = $this->asUser($this->teacher)->postJson('/api/v1/skills', ['parent_id' => $this->indicator, 'name' => 'ทักษะย่อย'])->assertCreated()->json('data.id');
    }

    public function test_the_trash_hides_restores_and_deletes_for_good_only_when_unused(): void
    {
        $course = $this->makeCourse($this->teacher);
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/indicators", ['skill_ids' => [$this->indicator]])->assertOk();

        $this->asUser($this->teacher)->deleteJson("/api/v1/skills/{$this->indicator}")->assertNoContent();

        // Out of the pickers, with the sub-indicator under it.
        $this->assertSame([], $this->pickable());
        $trash = $this->asUser($this->teacher)->getJson('/api/v1/skills/trash')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->indicator, $this->sub], array_column($trash, 'id'));
        $row = collect($trash)->firstWhere('id', $this->indicator);
        $this->assertTrue($row['in_trash']);
        $this->assertSame(['รายวิชา' => 1], $row['uses']);

        // What uses it keeps it, and saves again as it is.
        $this->asUser($this->teacher)->getJson("/api/v1/courses/{$course->id}")->assertOk()
            ->assertJsonPath('data.indicators.0.id', $this->indicator)
            ->assertJsonPath('data.indicators.0.in_trash', true);
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/indicators", ['skill_ids' => [$this->indicator]])->assertOk();
        // Nothing new goes under it.
        $this->asUser($this->teacher)->postJson('/api/v1/skills', ['parent_id' => $this->indicator, 'name' => 'ใหม่'])->assertStatus(422);

        $this->asUser($this->teacher)->postJson("/api/v1/skills/{$this->sub}/restore")->assertStatus(409)->assertJsonPath('code', 'parent_in_trash');
        $this->asUser($this->teacher)->deleteJson("/api/v1/skills/{$this->indicator}/permanent")
            ->assertStatus(409)->assertJsonPath('code', 'skill_in_use')->assertJsonPath('uses.รายวิชา', 1);

        $this->asUser($this->teacher)->postJson("/api/v1/skills/{$this->indicator}/restore")->assertOk()->assertJsonPath('data.in_trash', false);
        $this->assertEqualsCanonicalizing([$this->indicator, $this->sub], $this->pickable());

        // Unused: gone for good, the sub-indicator with it.
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$course->id}/indicators", ['skill_ids' => []])->assertOk();
        $this->asUser($this->teacher)->deleteJson("/api/v1/skills/{$this->indicator}")->assertNoContent();
        $this->asUser($this->teacher)->deleteJson("/api/v1/skills/{$this->indicator}/permanent")->assertNoContent();
        $this->assertDatabaseMissing('skills', ['id' => $this->indicator]);
        $this->assertDatabaseMissing('skills', ['id' => $this->sub]);
        $this->asUser($this->teacher)->getJson('/api/v1/skills/trash')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_own_lists_only_what_the_teacher_added(): void
    {
        $ids = collect($this->asUser($this->teacher)->getJson('/api/v1/skills?own=1')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->indicator, $this->sub], $ids);
        $this->asUser($this->makeTeacher())->getJson('/api/v1/skills?own=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_only_the_teacher_who_added_it_and_never_the_curriculum(): void
    {
        $this->asUser($this->makeTeacher())->deleteJson("/api/v1/skills/{$this->indicator}")->assertNotFound();
        $this->asUser($this->teacher)->deleteJson("/api/v1/skills/{$this->standard->id}")->assertNotFound();
        $this->asUser($this->teacher)->deleteJson("/api/v1/skills/{$this->indicator}/permanent")->assertStatus(409)->assertJsonPath('code', 'not_in_trash');
        $this->assertDatabaseHas('skills', ['id' => $this->indicator, 'deleted_at' => null]);
    }

    /** @return list<int> */
    private function pickable(): array
    {
        return collect($this->asUser($this->teacher)->getJson('/api/v1/skills?'.http_build_query(['subject' => $this->math->id, 'level' => 'indicator,sub_indicator']))
            ->assertOk()->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
