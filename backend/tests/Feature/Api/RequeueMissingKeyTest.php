<?php

namespace Tests\Feature\Api;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Jobs\GradeScanJob;
use App\Models\AiCall;
use App\Models\Response;
use App\Models\Submission;
use App\Models\TeacherApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * DESIGN §13: answers that went `manual` with ai_key_missing wait for the
 * teacher's key; POST /assignments/{id}/requeue-missing-key sends them back
 * to grading once one exists.
 */
class RequeueMissingKeyTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $this->open->rubricCriteria()->create(['position' => 1, 'description' => 'แก่นของคำตอบ', 'points' => 4, 'is_core' => true, 'source' => 'teacher']);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        config(['services.gemini.api_key' => null]);
    }

    /** Scans both pages and runs their jobs without any key. */
    private function gradeWithoutKey(): array
    {
        $ids = [];
        foreach ([1, 2] as $page) {
            $ids[] = $id = (int) $this->postScan($this->metaFor($page))->assertStatus(201)->json('scan_id');
            $this->app->call([new GradeScanJob($id), 'handle']);
        }

        return $ids;
    }

    public function test_without_a_key_nothing_is_requeued(): void
    {
        $this->gradeWithoutKey();

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/requeue-missing-key")
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_key_missing');
        $this->assertSame(3, Response::query()->awaitingAiKey()->count());
    }

    public function test_after_the_teacher_saves_a_key_the_waiting_answers_are_graded(): void
    {
        [$page1, $page2] = $this->gradeWithoutKey();
        $this->assertSame('needs_review', Submission::query()->sole()->status);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}")->assertJsonPath('data.missing_ai_key_count', 3);

        $this->asUser($this->teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => 'TESTSyTeacherKeyForRequeue00000000abcd'])->assertOk();
        Queue::fake(); // forget the pushes made while scanning

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/requeue-missing-key")
            ->assertStatus(202)
            ->assertJsonPath('data.requeued', 3);

        $this->assertSame(['queued'], Response::query()->where('grading_state', '!=', 'scored')->distinct()->pluck('grading_state')->all());
        $this->assertSame(0, Response::query()->where('grading_state', 'queued')->where('attempts', '>', 0)->count());
        $this->assertSame('grading', Submission::query()->sole()->status);
        Queue::assertPushedOn('grading', GradeScanJob::class, fn (GradeScanJob $job) => $job->scanId === $page1);
        Queue::assertPushedOn('grading', GradeScanJob::class, fn (GradeScanJob $job) => $job->scanId === $page2);
        Queue::assertPushed(GradeScanJob::class, 2);

        foreach ([$page1, $page2] as $scanId) {
            $this->app->call([new GradeScanJob($scanId), 'handle']);
        }
        $this->assertSame(0, Response::query()->whereIn('grading_state', ['queued', 'failed', 'manual'])->count());
        $this->assertSame(['teacher'], AiCall::query()->distinct()->pluck('key_source')->all());
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}")->assertJsonPath('data.missing_ai_key_count', 0);

        // Nothing left: 200 with 0.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/requeue-missing-key")
            ->assertOk()
            ->assertJsonPath('data.requeued', 0);
    }

    public function test_answers_the_teacher_already_graded_by_hand_stay(): void
    {
        $this->gradeWithoutKey();
        $graded = Response::query()->awaitingAiKey()->firstOrFail();
        $graded->update(['final_score' => 1, 'reviewed_at' => now(), 'reviewed_by' => $this->teacher->id]);
        config(['services.gemini.api_key' => 'server-key-now-set-00000000000000']);

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/requeue-missing-key")
            ->assertStatus(202)
            ->assertJsonPath('data.requeued', 2);
        $this->assertSame('manual', $graded->refresh()->grading_state);
    }

    public function test_a_rejected_key_is_requeued_too_once_fixed(): void
    {
        config(['services.gemini.api_key' => 'server-key-google-rejected-00000000']);
        $id = (int) $this->postScan($this->metaFor(1))->assertStatus(201)->json('scan_id');
        $this->app->call([new GradeScanJob($id), 'handle']);
        $this->assertSame('ai_key_invalid', Response::query()->where('question_id', $this->short->id)->sole()->manualReason());

        TeacherApiKey::create(['user_id' => $this->teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTSyGoodTeacherKey0000000000000000ok', 'key_last4' => '00ok']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/requeue-missing-key")
            ->assertStatus(202)
            ->assertJsonPath('data.requeued', 1);
    }

    public function test_other_teachers_cannot_requeue(): void
    {
        $this->gradeWithoutKey();
        $other = $this->makeTeacher($this->teacher->school);

        $this->asUser($other)->postJson("/api/v1/assignments/{$this->assignment->id}/requeue-missing-key")->assertStatus(404);
        $this->assertSame(3, Response::query()->awaitingAiKey()->count());
    }
}
