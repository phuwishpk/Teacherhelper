<?php

namespace Tests\Feature\Api;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiReply;
use App\Jobs\DraftRubricJob;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Subject;
use App\Models\TeacherApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DESIGN §9.3, §10.4: AI rubric drafts (DraftRubricJob behind the
 * GeminiClient interface, fake by default) and teacher approval rules.
 */
class RubricTest extends TestCase
{
    use RefreshDatabase;

    private function question(string $state = 'open', array $assignmentAttributes = []): array
    {
        $teacher = $this->makeTeacher();
        $classroom = $this->makeClassroom($teacher, ['grade_level' => 5]);
        $assignment = Assignment::factory()->for_classroom($classroom)->create([
            'subject_id' => Subject::factory()->create(['name' => 'วิทยาศาสตร์'])->id,
            ...$assignmentAttributes,
        ]);
        $question = Question::factory()->{$state}(Question::RUBRIC_DRAFT)->create(['assignment_id' => $assignment->id]);

        return [$teacher, $question, $assignment];
    }

    public function test_requesting_a_draft_queues_the_job_on_the_default_queue(): void
    {
        Queue::fake();
        [$teacher, $question] = $this->question();

        $this->asUser($teacher)->postJson("/api/v1/questions/{$question->id}/rubric/draft")
            ->assertStatus(202)
            ->assertJsonPath('data.id', $question->id);

        Queue::assertPushedOn('default', DraftRubricJob::class, fn (DraftRubricJob $job) => $job->questionId === $question->id);
    }

    public function test_the_fake_client_drafts_criteria_for_an_open_question(): void
    {
        [$teacher, $question] = $this->question('open');

        // QUEUE_CONNECTION=sync: the job runs inline with FakeGeminiClient.
        $this->asUser($teacher)->postJson("/api/v1/questions/{$question->id}/rubric/draft")->assertStatus(202);

        $question->refresh()->load('rubricCriteria');
        $this->assertSame('draft', $question->rubric_status);
        $this->assertCount(3, $question->rubricCriteria);
        $this->assertSame(['ai'], $question->rubricCriteria->pluck('source')->unique()->values()->all());
        $this->assertSame(1, $question->rubricCriteria->where('is_core', true)->count());
        $this->assertEqualsWithDelta(4.0, $question->rubricCriteria->sum('points'), 0.001);
    }

    public function test_the_fake_client_drafts_reference_steps_for_show_work(): void
    {
        [$teacher, $question] = $this->question('showWork');

        $this->asUser($teacher)->postJson("/api/v1/questions/{$question->id}/rubric/draft")->assertStatus(202);

        $question->refresh();
        $this->assertSame('draft', $question->rubric_status);
        $this->assertCount(4, $question->answer_key['reference_steps']);
        $this->assertSame(['x = 5', '5'], $question->answer_key['final']['accepted'], 'the final answer is kept');
    }

    public function test_the_draft_job_sends_the_question_context_to_gemini(): void
    {
        [, $question] = $this->question('open');
        $fake = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $fake);

        DraftRubricJob::dispatchSync($question->id);

        $this->assertCount(1, $fake->requests);
        $request = $fake->requests[0];
        $this->assertSame(['rubric_draft', 'open', 'v1'], [$request->purpose, $request->type, $request->promptVersion]);
        $this->assertStringContainsString('subject: วิทยาศาสตร์, grade: ป.5, max points: 4', $request->userText);
        $this->assertStringContainsString($question->prompt_text, $request->userText);
        $this->assertStringContainsString('Points must sum to 4', $request->userText);
        $this->assertSame([], $request->images, 'a rubric draft is text only');
        $this->assertSame(0.2, $request->temperature);

        $call = AiCall::query()->sole();
        $this->assertSame(['rubric_draft', 'ok', 'server', $question->id, 'fake:gemini-3.8-flash', 'v1'], [
            $call->purpose, $call->status, $call->key_source, $call->question_id, $call->model, $call->prompt_version,
        ]);
        $this->assertSame('draft', $question->refresh()->rubric_status);
    }

    public function test_an_invalid_ai_draft_is_retried_once_then_not_stored(): void
    {
        [, $question] = $this->question('open');
        $question->update(['prompt_text' => 'อธิบายการสังเคราะห์แสง [fake:rubric-invalid]']);

        DraftRubricJob::dispatchSync($question->id);

        $this->assertSame(0, $question->rubricCriteria()->count());
        $this->assertSame('draft', $question->refresh()->rubric_status);
        $this->assertSame(['invalid_output', 'invalid_output'], AiCall::query()->orderBy('id')->pluck('status')->all());
        $this->assertStringContainsString('core criteria', (string) AiCall::query()->value('error'));
    }

    public function test_a_transport_error_is_left_to_the_queue_retry(): void
    {
        [, $question] = $this->question('open');
        $this->app->instance(GeminiClient::class, new class extends FakeGeminiClient
        {
            public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array
            {
                return array_map(fn () => GeminiReply::error('HTTP 503: overloaded', 5, 503), $requests);
            }
        });

        try {
            DraftRubricJob::dispatchSync($question->id);
            $this->fail('expected the job to throw for a retry');
        } catch (GeminiException $e) {
            $this->assertSame(GeminiException::ERROR, $e->status);
        }
        $this->assertSame(['error'], AiCall::query()->pluck('status')->all(), 'transport errors are not retried inside the job');
    }

    public function test_a_teacher_key_pays_for_the_draft(): void
    {
        [$teacher, $question] = $this->question('open');
        TeacherApiKey::create(['user_id' => $teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherKey0000000000000000001234', 'key_last4' => '1234']);

        DraftRubricJob::dispatchSync($question->id);

        $this->assertSame('teacher', AiCall::query()->sole()->key_source);
    }

    public function test_drafting_needs_a_gemini_key(): void
    {
        Queue::fake();
        config(['services.gemini.api_key' => null]);
        [$teacher, $question] = $this->question('open');

        $this->asUser($teacher)->postJson("/api/v1/questions/{$question->id}/rubric/draft")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_key_missing');
        Queue::assertNothingPushed();

        DraftRubricJob::dispatchSync($question->id); // queued earlier, key removed since: nothing happens
        $this->assertSame(0, AiCall::query()->count());
    }

    public function test_a_redraft_of_an_approved_rubric_sends_a_ready_assignment_back_to_draft(): void
    {
        [$teacher, $question, $assignment] = $this->question('open', ['status' => 'ready', 'current_layout_version' => 1]);
        $question->update(['rubric_status' => 'approved']);

        $this->asUser($teacher)->postJson("/api/v1/questions/{$question->id}/rubric/draft")->assertStatus(202);

        $this->assertSame('draft', $question->refresh()->rubric_status);
        $this->assertSame('draft', $assignment->refresh()->status);
    }

    public function test_mcq_and_short_questions_have_no_rubric(): void
    {
        [$teacher, $question] = $this->question();
        $question->update(['type' => 'mcq', 'answer_key' => ['correct' => 'A'], 'answer_lines' => null, 'rubric_status' => 'not_needed']);

        $this->asUser($teacher)->postJson("/api/v1/questions/{$question->id}/rubric/draft")
            ->assertStatus(422)->assertJsonPath('code', 'rubric_not_needed');
        $this->asUser($teacher)->putJson("/api/v1/questions/{$question->id}/rubric", ['criteria' => []])
            ->assertStatus(422)->assertJsonPath('code', 'rubric_not_needed');
    }

    public function test_the_teacher_approves_an_open_rubric(): void
    {
        [$teacher, $question] = $this->question('open');
        $question->rubricCriteria()->create(['position' => 1, 'description' => 'อธิบายแนวคิดหลักได้ถูกต้อง', 'points' => 2, 'is_core' => true, 'source' => 'ai']);

        $this->asUser($teacher)->putJson("/api/v1/questions/{$question->id}/rubric", ['criteria' => [
            ['position' => 1, 'description' => 'อธิบายแนวคิดหลักได้ถูกต้อง', 'points' => 2, 'is_core' => true],
            ['position' => 2, 'description' => 'ยกตัวอย่างประกอบ', 'points' => 1.5, 'is_core' => false],
            ['position' => 3, 'description' => 'ใช้คำศัพท์ถูกต้อง', 'points' => 0.5],
        ]])
            ->assertOk()
            ->assertJsonPath('data.rubric_status', 'approved')
            ->assertJsonCount(3, 'data.rubric_criteria')
            ->assertJsonPath('data.rubric_criteria.0.source', 'ai') // kept word for word
            ->assertJsonPath('data.rubric_criteria.1.source', 'teacher')
            ->assertJsonPath('data.rubric_criteria.1.points', 1.5)
            ->assertJsonPath('data.rubric_criteria.2.is_core', false)
            ->assertJsonPath('data.rubric_criteria.2.position', 3);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidRubrics(): array
    {
        return [
            'no criteria' => [['criteria' => []], 'criteria'],
            'no core' => [['criteria' => [['description' => 'a', 'points' => 4, 'is_core' => false]]], 'criteria'],
            'two cores' => [['criteria' => [['description' => 'a', 'points' => 2, 'is_core' => true], ['description' => 'b', 'points' => 2, 'is_core' => true]]], 'criteria'],
            'sum too low' => [['criteria' => [['description' => 'a', 'points' => 3, 'is_core' => true]]], 'criteria'],
            'blank description' => [['criteria' => [['description' => ' ', 'points' => 4, 'is_core' => true]]], 'criteria.0.description'],
            'negative points' => [['criteria' => [['description' => 'a', 'points' => -1, 'is_core' => true]]], 'criteria.0.points'],
            'reference steps on open' => [['criteria' => [['description' => 'a', 'points' => 4, 'is_core' => true]], 'reference_steps' => ['x']], 'reference_steps'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidRubrics')]
    public function test_invalid_rubrics_are_rejected(array $body, string $field): void
    {
        [$teacher, $question] = $this->question('open');

        $this->asUser($teacher)->putJson("/api/v1/questions/{$question->id}/rubric", $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
        $this->assertSame('draft', $question->refresh()->rubric_status);
    }

    public function test_the_sum_error_names_both_totals_in_thai(): void
    {
        [$teacher, $question] = $this->question('open');

        $this->asUser($teacher)->putJson("/api/v1/questions/{$question->id}/rubric", [
            'criteria' => [['description' => 'a', 'points' => 3.5, 'is_core' => true]],
        ])->assertStatus(422)
            ->assertJsonPath('errors.criteria.0', 'คะแนนรวมของเกณฑ์ (3.5) ต้องเท่ากับคะแนนเต็มของข้อ (4)');
    }

    public function test_show_work_approval_stores_reference_steps_and_optional_criteria(): void
    {
        [$teacher, $question] = $this->question('showWork');

        $this->asUser($teacher)->putJson("/api/v1/questions/{$question->id}/rubric", [
            'criteria' => [],
            'reference_steps' => ['3x + 5 = 20', ' 3x = 15 ', '', 'x = 5'],
        ])->assertOk()
            ->assertJsonPath('data.rubric_status', 'approved')
            ->assertJsonPath('data.answer_key.reference_steps', ['3x + 5 = 20', '3x = 15', 'x = 5'])
            ->assertJsonPath('data.answer_key.final.accepted', ['x = 5', '5']);

        // Criteria sent for show_work follow the same rules.
        $this->asUser($teacher)->putJson("/api/v1/questions/{$question->id}/rubric", [
            'criteria' => [['description' => 'วิธีทำถูก', 'points' => 4, 'is_core' => true]],
        ])->assertStatus(422)->assertJsonValidationErrors(['criteria']);
    }

    public function test_another_teacher_cannot_touch_the_rubric(): void
    {
        [, $question] = $this->question('open');
        $stranger = $this->makeTeacher();

        $this->asUser($stranger)->postJson("/api/v1/questions/{$question->id}/rubric/draft")->assertNotFound();
        $this->asUser($stranger)->putJson("/api/v1/questions/{$question->id}/rubric", ['criteria' => []])->assertNotFound();
    }
}
