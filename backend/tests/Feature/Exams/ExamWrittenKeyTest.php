<?php

namespace Tests\Feature\Exams;

use App\Domain\Assignments\RubricService;
use App\Domain\Gemini\RubricDraft;
use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.20.2: the key of a written question is its rubric. An exam is
 * ready only when every written question has an approved rubric (and a
 * show_work question a final answer); the homework rubric endpoints do the
 * approving, and a blank question is approved by its rubric.
 */
class ExamWrittenKeyTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $exam;

    /** @var array<string, mixed> */
    private array $mcq;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
        $this->exam = $this->createExam();
        $this->mcq = $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 1]);
        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$this->exam->id}/answer-key", [
            'answers' => [['question_id' => $this->mcq['questions'][0]['id'], 'accepted_options' => [1]]],
        ])->assertOk();
    }

    /** @return list<array<string, mixed>> */
    private function twoCriteria(): array
    {
        return [
            ['description' => 'ตอบถูก', 'points' => 3, 'is_core' => true],
            ['description' => 'มีเหตุผล', 'points' => 1, 'is_core' => false],
        ];
    }

    private function approveKey(): \Illuminate\Testing\TestResponse
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->exam->id}/answer-key/approve");
    }

    public function test_written_question_without_rubric_blocks_key_approval(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open']);
        $written = $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$open['id']}/questions", ['max_points' => 4])
            ->assertCreated()->json('data');

        $json = $this->examJson($this->exam);
        $this->assertFalse($json['key_complete']);
        $this->assertSame([['question_id' => $written['id'], 'position' => 2, 'reasons' => ['no_rubric']]], $json['incomplete_questions']);

        $this->approveKey()->assertStatus(422)->assertJsonPath('code', 'answer_key_incomplete')
            ->assertJsonPath('errors.questions.0', 'ข้อ 2: rubric ยังไม่อนุมัติ');
    }

    public function test_rubric_put_completes_the_key_and_approves_a_blank_question(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1, 'default_points' => 4]);
        $id = $open['questions'][0]['id'];
        $this->assertNull($open['questions'][0]['approved_at']);

        $this->asUser($this->teacher)->putJson("/api/v1/questions/{$id}/rubric", ['criteria' => $this->twoCriteria()])
            ->assertOk()->assertJsonPath('data.rubric_status', 'approved');

        $question = Question::query()->findOrFail($id);
        $this->assertNotNull($question->approved_at);
        $json = $this->examJson($this->exam);
        $this->assertTrue($json['key_complete']);
        $this->assertSame([], $json['incomplete_questions']);
        $this->approveKey()->assertOk();
    }

    public function test_show_work_needs_a_final_answer_as_well(): void
    {
        $section = $this->addSection($this->exam, ['type' => 'show_work']);
        $written = $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$section['id']}/questions", ['max_points' => 4])
            ->assertCreated()->json('data');
        $this->asUser($this->teacher)->putJson("/api/v1/questions/{$written['id']}/rubric", ['reference_steps' => ['คูณทั้งสองข้าง', 'ย้ายข้าง']])
            ->assertOk();

        $json = $this->examJson($this->exam);
        $this->assertSame(['no_key'], $json['incomplete_questions'][0]['reasons']);

        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$written['id']}", ['answer_key' => ['final' => ['accepted' => ['12']]]])
            ->assertOk();

        $this->assertTrue($this->examJson($this->exam)['key_complete']);
        $this->assertSame(['คูณทั้งสองข้าง', 'ย้ายข้าง'], Question::query()->findOrFail($written['id'])->answer_key['reference_steps']);
    }

    public function test_a_new_rubric_draft_after_approval_takes_the_key_approval_back(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1, 'default_points' => 4]);
        $id = $open['questions'][0]['id'];
        $this->asUser($this->teacher)->putJson("/api/v1/questions/{$id}/rubric", ['criteria' => $this->twoCriteria()])->assertOk();
        $this->approveKey()->assertOk();
        $this->assertSame('ready', $this->exam->refresh()->status);

        app(RubricService::class)->applyDraft(Question::query()->findOrFail($id), new RubricDraft(criteria: [
            ['description' => 'ตอบตรงประเด็น', 'points' => 4.0, 'is_core' => true],
        ]));

        $this->exam->refresh();
        $this->assertSame('draft', $this->exam->status);
        $this->assertNull($this->exam->key_approved_at);
        $this->assertSame('draft', Question::query()->findOrFail($id)->rubric_status);
    }

    public function test_the_choice_key_table_refuses_a_written_question(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1]);
        $id = $open['questions'][0]['id'];

        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$this->exam->id}/answer-key", [
            'answers' => [['question_id' => $id, 'accepted_options' => [1]]],
        ])->assertStatus(422)->assertJsonValidationErrors('answers.0.question_id');

        $this->assertNull(Question::query()->findOrFail($id)->answer_key);
    }

    public function test_the_rubric_draft_works_for_exam_questions_with_no_prompt(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1, 'default_points' => 4]);
        $showWork = $this->addSection($this->exam, ['type' => 'show_work', 'question_count' => 1, 'default_points' => 4]);
        $openId = $open['questions'][0]['id'];
        $showWorkId = $showWork['questions'][0]['id'];

        // QUEUE_CONNECTION=sync: DraftRubricJob runs inline with FakeGeminiClient.
        $this->asUser($this->teacher)->postJson("/api/v1/questions/{$openId}/rubric/draft")->assertStatus(202);
        $this->asUser($this->teacher)->postJson("/api/v1/questions/{$showWorkId}/rubric/draft")->assertStatus(202);

        $openQuestion = Question::query()->with('rubricCriteria')->findOrFail($openId);
        $this->assertSame('draft', $openQuestion->rubric_status);
        $this->assertCount(3, $openQuestion->rubricCriteria);
        $this->assertSame(['ai'], $openQuestion->rubricCriteria->pluck('source')->unique()->values()->all());
        $showWorkQuestion = Question::query()->findOrFail($showWorkId);
        $this->assertSame('draft', $showWorkQuestion->rubric_status);
        $this->assertCount(4, $showWorkQuestion->answer_key['reference_steps']);
        $this->assertNull($this->exam->refresh()->key_approved_at);
        $this->assertFalse($this->examJson($this->exam)['key_complete']);
    }

    public function test_another_teacher_cannot_draft_a_rubric_for_an_exam_question(): void
    {
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 1, 'default_points' => 4]);
        $stranger = $this->makeTeacher();

        $this->asUser($stranger)->postJson("/api/v1/questions/{$open['questions'][0]['id']}/rubric/draft")->assertNotFound();
        $this->asUser($stranger)->putJson("/api/v1/questions/{$open['questions'][0]['id']}/rubric", ['criteria' => $this->twoCriteria()])->assertNotFound();
    }
}
