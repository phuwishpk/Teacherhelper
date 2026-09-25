<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DESIGN §5.1, §9.3: POST /assignments/{id}/layout (approved rubrics, new
 * version only when the page changes, assignment ready) and
 * GET /assignments/{id}/layouts?version=.
 */
class LayoutTest extends TestCase
{
    use RefreshDatabase;

    private function assignmentWithQuestions(): array
    {
        $teacher = $this->makeTeacher();
        $assignment = Assignment::factory()->for_classroom($this->makeClassroom($teacher))->create();
        $mcq = Question::factory()->create(['assignment_id' => $assignment->id]);
        $work = Question::factory()->showWork()->create(['assignment_id' => $assignment->id]);

        return [$teacher, $assignment, $mcq, $work];
    }

    public function test_building_the_layout_creates_version_1_and_makes_the_assignment_ready(): void
    {
        [$teacher, $assignment, $mcq, $work] = $this->assignmentWithQuestions();

        $response = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")
            ->assertStatus(201)
            ->assertJsonPath('data.assignment_id', $assignment->id)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.page_count', 1)
            ->assertJsonPath('data.pages.0.version', 1)
            ->assertJsonPath('data.pages.0.regions.0.region_id', 'q'.$mcq->id)
            ->assertJsonPath('data.pages.0.regions.1.kind', 'lines')
            ->assertJsonPath('data.pages.0.regions.1.final_answer.numeric', true);
        $this->assertSame([16, 16, 178, 265], array_values($response->json('data.pages.0.frame_mm')));

        $assignment->refresh();
        $this->assertSame('ready', $assignment->status);
        $this->assertSame(1, $assignment->current_layout_version);
        $this->assertDatabaseHas('layouts', ['assignment_id' => $assignment->id, 'version' => 1]);
    }

    public function test_rebuilding_an_unchanged_assignment_keeps_the_version(): void
    {
        [$teacher, $assignment] = $this->assignmentWithQuestions();
        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertStatus(201);
        $assignment->update(['status' => 'draft']); // e.g. a rubric was re-drafted and approved again

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")
            ->assertStatus(200)
            ->assertJsonPath('data.version', 1);
        $this->assertSame('ready', $assignment->refresh()->status);
        $this->assertSame(1, $assignment->layouts()->count());
    }

    public function test_an_edit_after_printing_leads_to_a_new_version(): void
    {
        [$teacher, $assignment, , $work] = $this->assignmentWithQuestions();
        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertStatus(201);

        $this->asUser($teacher)->patchJson("/api/v1/questions/{$work->id}", ['answer_lines' => 6])->assertOk();
        $this->assertSame('draft', $assignment->refresh()->status);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")
            ->assertStatus(201)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.pages.0.regions.1.line_count', 6);
        $this->assertSame(2, $assignment->refresh()->current_layout_version);

        // Both versions stay available for sheets printed earlier.
        $this->asUser($teacher)->getJson("/api/v1/assignments/{$assignment->id}/layouts")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.version', 2)
            ->assertJsonPath('data.1.version', 1)
            ->assertJsonPath('data.1.pages.0.regions.1.line_count', 4);
        $this->asUser($teacher)->getJson("/api/v1/assignments/{$assignment->id}/layouts?version=1")
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.pages.0.version', 1);
    }

    public function test_every_rubric_must_be_approved_first(): void
    {
        [$teacher, $assignment, , $work] = $this->assignmentWithQuestions();
        $work->update(['rubric_status' => 'draft']);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")
            ->assertStatus(422)
            ->assertJsonPath('code', 'rubric_not_approved')
            ->assertJsonPath('errors.questions.0', 'ข้อ 2 ยังไม่ได้อนุมัติ rubric');
        $this->assertSame('draft', $assignment->refresh()->status);
        $this->assertSame(0, $assignment->layouts()->count());
    }

    public function test_an_empty_assignment_has_no_layout(): void
    {
        $teacher = $this->makeTeacher();
        $assignment = Assignment::factory()->for_classroom($this->makeClassroom($teacher))->create();

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")
            ->assertStatus(422)->assertJsonPath('code', 'assignment_empty');
    }

    public function test_a_question_taller_than_a_page_is_reported(): void
    {
        $teacher = $this->makeTeacher();
        $assignment = Assignment::factory()->for_classroom($this->makeClassroom($teacher))->create();
        Question::factory()->showWork(lines: 15)->create([
            'assignment_id' => $assignment->id,
            'prompt_text' => str_repeat('นักเรียนสามารถตรวจคำตอบโดยการแทนค่ากลับลงในสมการเดิม ', 40),
        ]);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")
            ->assertStatus(422)
            ->assertJsonPath('code', 'question_too_tall');
    }

    public function test_an_unknown_version_is_layout_unknown(): void
    {
        [$teacher, $assignment] = $this->assignmentWithQuestions();

        $this->asUser($teacher)->getJson("/api/v1/assignments/{$assignment->id}/layouts?version=3")
            ->assertStatus(404)->assertJsonPath('code', 'layout_unknown');
        $this->asUser($teacher)->getJson("/api/v1/assignments/{$assignment->id}/layouts")
            ->assertOk()->assertJsonPath('data', []);
    }

    public function test_a_closed_assignment_cannot_get_a_new_layout(): void
    {
        [$teacher, $assignment] = $this->assignmentWithQuestions();
        $assignment->update(['status' => 'closed']);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")
            ->assertStatus(409)->assertJsonPath('code', 'assignment_closed');
    }

    public function test_another_teacher_cannot_build_or_read_layouts(): void
    {
        [, $assignment] = $this->assignmentWithQuestions();
        $stranger = $this->makeTeacher();

        $this->asUser($stranger)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertNotFound();
        $this->asUser($stranger)->getJson("/api/v1/assignments/{$assignment->id}/layouts")->assertNotFound();
    }
}
