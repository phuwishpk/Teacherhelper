<?php

namespace Tests\Feature\Gemini;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Jobs\DraftRubricJob;
use App\Jobs\GeneratePracticeItemsJob;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Skill;
use App\Models\TeacherApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * DESIGN §36.1: queued AI work without a row of its own (rubric drafts,
 * practice generation) keeps its last failure with the code.
 */
class AiJobFailuresTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $this->teacher = $this->makeTeacher();
        TeacherApiKey::query()->create(['user_id' => $this->teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000071', 'key_last4' => '0071']);
    }

    public function test_a_failed_rubric_draft_shows_its_code_until_the_next_try(): void
    {
        $classroom = $this->makeClassroom($this->teacher);
        $course = $this->makeCourse($this->teacher, [$classroom]);
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', ['classroom_id' => $classroom->id, 'course_id' => $course->id, 'title' => 'เรียงความ'])->assertCreated()->json('data.id');
        $question = Question::create(['assignment_id' => $id, 'position' => 1, 'type' => 'open', 'prompt_text' => 'เขียนเรียงความ [fake:rubric-invalid]', 'max_points' => 4, 'answer_lines' => 5, 'rubric_status' => 'draft']);

        app()->call([new DraftRubricJob($question->id), 'handle']);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$id}")->assertOk()
            ->assertJsonPath('data.questions.0.rubric_draft_error.ai_error_code', 'AI-OUTPUT')
            ->assertJsonPath('data.questions.0.rubric_draft_error.ai_call_id', AiCall::query()->max('id'))
            ->assertJsonPath('data.questions.0.rubric_draft_error.message', DraftRubricJob::MESSAGE);

        // Every transient try failed: the queue's failed() keeps a code too.
        (new DraftRubricJob($question->id))->failed(new RuntimeException('worker killed'));
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$id}")->assertJsonPath('data.questions.0.rubric_draft_error.ai_error_code', 'AI-OUTPUT');

        // A new try clears it.
        $question->update(['prompt_text' => 'เขียนเรียงความ']);
        app()->call([new DraftRubricJob($question->id), 'handle']);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$id}")->assertJsonPath('data.questions.0.rubric_draft_error', null);
        $this->assertSame(Assignment::STATUS_DRAFT, Assignment::query()->findOrFail($id)->status);
    }

    public function test_a_failed_practice_generation_shows_on_the_bank_for_that_indicator(): void
    {
        $skill = Skill::factory()->create(['name' => 'บวกเศษส่วน [fake:practice-bad-key]', 'level' => 'indicator']);
        app()->call([new GeneratePracticeItemsJob($skill->id, (int) $this->teacher->school_id, $this->teacher->id, 3), 'handle']);

        $this->asUser($this->teacher)->getJson("/api/v1/practice-items?skill={$skill->id}")->assertOk()
            ->assertJsonPath('generation_error.ai_error_code', 'AI-OUTPUT')
            ->assertJsonPath('generation_error.message', GeneratePracticeItemsJob::MESSAGE);
        // Only for the teacher who asked.
        $this->asUser($this->makeTeacher($this->teacher->school))->getJson("/api/v1/practice-items?skill={$skill->id}")->assertJsonPath('generation_error', null);

        // No key at all.
        TeacherApiKey::query()->delete();
        config(['services.gemini.api_key' => null]);
        app()->call([new GeneratePracticeItemsJob($skill->id, (int) $this->teacher->school_id, $this->teacher->id, 3), 'handle']);
        $this->asUser($this->teacher)->getJson("/api/v1/practice-items?skill={$skill->id}")->assertJsonPath('generation_error.ai_error_code', 'AI-KEY-MISSING');
    }
}
