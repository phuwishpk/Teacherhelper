<?php

namespace Tests\Feature\Exams;

use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.20.2: a written question has a prompt (optional), points, lines
 * for the writing box, and for show_work a final answer; options and
 * "ห้ามสลับตัวเลือก" do not exist for it. Changing its points sends an
 * approved rubric back to draft because the criteria no longer add up.
 */
class ExamWrittenQuestionTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $exam;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
        $this->exam = $this->createExam();
    }

    /** @return array<string, mixed> */
    private function addQuestion(int $sectionId, array $fields = []): array
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$sectionId}/questions", $fields)
            ->assertCreated()->json('data');
    }

    private function approveRubric(Question $question, float $points): void
    {
        $question->rubricCriteria()->create(['position' => 1, 'description' => 'ตอบถูก', 'points' => $points, 'is_core' => true, 'source' => 'teacher']);
        $question->forceFill(['rubric_status' => Question::RUBRIC_APPROVED])->save();
    }

    public function test_written_question_create_and_patch(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open']);

        $created = $this->addQuestion($open['id'], ['prompt_text' => 'อธิบายวัฏจักรของน้ำ', 'max_points' => 4, 'answer_lines' => 6]);

        $this->assertSame('open', $created['type']);
        $this->assertSame(6, $created['answer_lines']);
        $this->assertSame('draft', $created['rubric_status']);
        $this->assertNotNull($created['approved_at']);
        $this->assertFalse($created['key_complete']);

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$created['id']}", ['answer_lines' => 20])
            ->assertStatus(422)->assertJsonValidationErrors('answer_lines');
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$created['id']}", ['answer_lines' => 9])
            ->assertOk()->assertJsonPath('data.answer_lines', 9);
        $this->assertSame(9, Question::query()->findOrFail($created['id'])->answer_lines);
    }

    public function test_written_question_without_lines_gets_the_default(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open']);

        $created = $this->addQuestion($open['id'], ['prompt_text' => 'อธิบาย']);

        $this->assertSame(8, $created['answer_lines']);
    }

    public function test_choice_questions_have_no_answer_lines(): void
    {
        $mcq = $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1]);

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$mcq['questions'][0]['id']}", ['answer_lines' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('answer_lines');
        $this->assertNull($mcq['questions'][0]['answer_lines']);
    }

    public function test_show_work_key_final_answer(): void
    {
        $showWork = $this->addSection($this->exam, ['type' => 'show_work', 'question_count' => 1]);
        $id = $showWork['questions'][0]['id'];

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['answer_key' => ['final' => ['accepted' => ['12', '๑๒']]]])
            ->assertOk();
        $this->assertSame(['12', '๑๒'], Question::query()->findOrFail($id)->answer_key['final']['accepted']);

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['answer_key' => ['accepted_options' => [1]]])
            ->assertStatus(422)->assertJsonValidationErrors('answer_key.final');
        $this->assertSame(['12', '๑๒'], Question::query()->findOrFail($id)->answer_key['final']['accepted']);

        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1]);
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$open['questions'][0]['id']}", ['answer_key' => ['final' => ['accepted' => ['x']]]])
            ->assertStatus(422)->assertJsonValidationErrors('answer_key');
    }

    public function test_options_and_lock_are_refused_on_written(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1]);
        $id = $open['questions'][0]['id'];

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['options' => [['text' => 'x']]])
            ->assertStatus(422)->assertJsonValidationErrors('options');
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$id}", ['lock_options' => true])
            ->assertStatus(422)->assertJsonValidationErrors('lock_options');
    }

    public function test_points_change_withdraws_the_rubric(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 2, 'default_points' => 4]);
        [$first, $second] = Question::query()->where('section_id', $open['id'])->orderBy('position')->get()->all();
        $this->approveRubric($first, 4);
        $this->approveRubric($second, 4);

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$first->id}", ['max_points' => 5])->assertOk();
        $this->assertSame(Question::RUBRIC_DRAFT, $first->refresh()->rubric_status);
        $this->assertSame(Question::RUBRIC_APPROVED, $second->refresh()->rubric_status);

        $this->asUser($this->teacher)->patchJson("/api/v1/exam-sections/{$open['id']}", ['default_points' => 6])->assertOk();
        $this->assertEquals(6, $second->refresh()->max_points);
        $this->assertSame(Question::RUBRIC_DRAFT, $second->rubric_status);
    }

    public function test_points_edit_that_keeps_the_points_keeps_the_rubric(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1, 'default_points' => 4]);
        $question = Question::query()->where('section_id', $open['id'])->firstOrFail();
        $this->approveRubric($question, 4);

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$question->id}", ['max_points' => 4, 'prompt_text' => 'โจทย์ใหม่'])->assertOk();

        $this->assertSame(Question::RUBRIC_APPROVED, $question->refresh()->rubric_status);
    }

    public function test_sheet_capacity_ignores_written_questions(): void
    {
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 100]);
        $this->addSection($this->exam, ['type' => 'open', 'question_count' => 3]);

        $json = $this->examJson($this->exam);

        $this->assertSame(1, $json['sheet']['pages']);
        $this->assertFalse($json['sheet']['overflow']);
    }
}
