<?php

namespace Tests\Feature\Grading;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Grading\ClassRegrade;
use App\Events\SubmissionReopened;
use App\Jobs\GradeScanJob;
use App\Jobs\GradeSubmissionPageJob;
use App\Models\AiCall;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\SubmissionPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * DESIGN §21.13 "ตรวจใหม่ทั้งห้อง": after the answer key changed, POST
 * /assignments/{id}/regrade grades every graded submission again with the
 * current approved key: mcq by code, the other crop answers read again by
 * GradeScanJob, whole-page hand-ins read again page by page. Overridden
 * answers are skipped unless include_overridden; published submissions are
 * reopened like a confirmed rescan; a second run while the first one's
 * jobs are queued is 409 regrade_in_progress. Gemini is the
 * FakeGeminiClient, the queue is faked and its jobs run by hand.
 *
 * World (ScanFixtures): Q1 mcq (key B, 1 pt), Q2 short "20" (2 pts), Q3
 * show_work (5 pts), Q4 open (4 pts); student 12 filled bubble B.
 */
class ClassRegradeTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $this->open->rubricCriteria()->create(['position' => 1, 'description' => 'แก่นของคำตอบ', 'points' => 4, 'is_core' => true, 'source' => 'teacher']);
        $this->gemini = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $this->gemini);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/assignments/{$this->assignment->id}/regrade{$suffix}";
    }

    private function regrade(array $body = [], ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->teacher)->postJson($this->url(), $body);
    }

    private function estimate(array $body = []): TestResponse
    {
        return $this->asUser($this->teacher)->postJson($this->url('/estimate'), $body);
    }

    /** Scans both pages and grades them. @return list<int> scan ids */
    private function scanAndGrade(): array
    {
        $ids = [];
        foreach ([1, 2] as $page) {
            $ids[] = $id = (int) $this->postScan($this->metaFor($page))->assertStatus(201)->json('scan_id');
            $this->app->call([new GradeScanJob($id), 'handle']);
        }

        return $ids;
    }

    /** The teacher confirms every AI value, then publishes. */
    private function reviewAndPublish(): Submission
    {
        foreach (Response::query()->get() as $response) {
            if ($response->reviewed_at === null) {
                $response->forceFill([
                    'final_score' => $response->ai_score ?? 0,
                    'final_understanding' => $response->ai_understanding ?? 'not_yet',
                    'reviewed_at' => now(),
                    'reviewed_by' => $this->teacher->id,
                ])->save();
            }
        }
        $submission = Submission::query()->sole();
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

        return $submission->refresh();
    }

    private function response(int $questionId): Response
    {
        return Response::query()->where('question_id', $questionId)->sole();
    }

    /** Runs the grading jobs pushed since the last Queue::fake(). */
    private function runPushedJobs(): void
    {
        foreach (Queue::pushed(GradeScanJob::class) as $job) {
            $this->app->call([$job, 'handle']);
        }
        foreach (Queue::pushed(GradeSubmissionPageJob::class) as $job) {
            $this->app->call([$job, 'handle']);
        }
    }

    public function test_the_whole_class_is_graded_again_with_the_changed_key(): void
    {
        [$page1, $page2] = $this->scanAndGrade();
        // The teacher overrode Q3 with a reason: kept as it is.
        $work = $this->response($this->work->id);
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$work->id}", [
            'final_score' => 1, 'final_understanding' => 'partial', 'reason' => 'ครูตรวจเอง',
        ])->assertOk();
        $submission = $this->reviewAndPublish();
        $this->assertSame(1.0, $this->response($this->mcq->id)->ai_score);

        // The key changes: Q1 is C now, Q2 accepts 21.
        $this->mcq->update(['answer_key' => ['correct' => 'C']]);
        $this->short->update(['answer_key' => ['accepted' => ['21'], 'numeric' => ['value' => 21, 'abs_tol' => 0]]]);
        Event::fake([SubmissionReopened::class]);
        Queue::fake();
        $callsBefore = AiCall::query()->count();

        $this->estimate()->assertOk()
            ->assertJsonPath('data.submissions', 1)
            ->assertJsonPath('data.queued_responses', 2)
            ->assertJsonPath('data.mcq_by_code', 1)
            ->assertJsonPath('data.skipped_overridden', 1)
            ->assertJsonPath('data.published_submissions', 1)
            ->assertJsonPath('data.in_progress', false);
        $this->assertGreaterThan(0, $this->estimate()->json('data.estimate.input_tokens'));
        $this->estimate(['include_overridden' => true])->assertOk()->assertJsonPath('data.queued_responses', 3)->assertJsonPath('data.skipped_overridden', 0);

        $this->regrade()->assertStatus(202)
            ->assertJsonPath('data.queued_submissions', 1)
            ->assertJsonPath('data.skipped_overridden', 1)
            ->assertJsonPath('data.queued_responses', 2)
            ->assertJsonPath('data.rescored_by_code', 1)
            ->assertJsonPath('data.reopened_submissions', 1);
        $this->assertSame($callsBefore, AiCall::query()->count(), 'nothing called Gemini in the request');

        // Q1: scored again by code at once, back to review, logged.
        $mcq = $this->response($this->mcq->id);
        $this->assertSame([Response::STATE_SCORED, 0.0, null, null], [$mcq->grading_state, $mcq->ai_score, $mcq->final_score, $mcq->reviewed_at]);
        $this->assertSame(
            [[ScoreEvent::ACTION_RESCAN, 1.0, null, ClassRegrade::REASON], [ScoreEvent::ACTION_AI_SCORED, null, 0.0, ClassRegrade::REASON]],
            ScoreEvent::query()->where('response_id', $mcq->id)->where('reason', ClassRegrade::REASON)->orderBy('id')->get()
                ->map(fn (ScoreEvent $e) => [$e->action, $e->old_score === null ? null : (float) $e->old_score, $e->new_score === null ? null : (float) $e->new_score, $e->reason])->all(),
        );
        // Q2 and Q4: queued for Gemini with their crops kept; Q3 (overridden) untouched.
        foreach ([$this->short->id, $this->open->id] as $questionId) {
            $r = $this->response($questionId);
            $this->assertSame([Response::STATE_QUEUED, null, null, 0], [$r->grading_state, $r->extraction, $r->reviewed_at, $r->attempts]);
            $this->assertNotNull($r->crop_path);
        }
        $work->refresh();
        $this->assertSame([Response::STATE_SCORED, 1.0, 'partial'], [$work->grading_state, $work->final_score, $work->final_understanding]);
        $this->assertNotNull($work->reviewed_at);

        // Published -> reopened like a confirmed rescan.
        $submission->refresh();
        $this->assertSame([Submission::STATUS_GRADING, null], [$submission->status, $submission->published_at]);
        Event::assertDispatched(SubmissionReopened::class, fn (SubmissionReopened $e) => $e->submissionId === $submission->id);
        Queue::assertPushed(GradeScanJob::class, 2);
        Queue::assertPushed(GradeScanJob::class, fn (GradeScanJob $j) => $j->scanId === $page1);
        Queue::assertPushed(GradeScanJob::class, fn (GradeScanJob $j) => $j->scanId === $page2);

        // A second run while those jobs are queued.
        $this->regrade()->assertStatus(409)->assertJsonPath('code', 'regrade_in_progress');
        $this->estimate()->assertOk()->assertJsonPath('data.in_progress', true);

        $this->runPushedJobs();
        $this->assertSame(0, Response::query()->whereIn('grading_state', Response::IN_PROGRESS_STATES)->count());
        $this->assertSame(Submission::STATUS_NEEDS_REVIEW, $submission->refresh()->status);
        // The re-read used the new key and never carries guidance (DESIGN §21.12).
        $extracts = array_filter($this->gemini->requests, fn (GeminiRequest $r) => str_starts_with($r->purpose, 'extract'));
        $this->assertNotEmpty($extracts);
        foreach ($extracts as $request) {
            $this->assertStringNotContainsString('TEACHER GUIDANCE', $request->systemInstruction.$request->userText);
        }
        $this->assertSame(0, AiCall::query()->whereNotNull('teacher_guidance')->count());

        // Done: a new run is allowed; include_overridden resets the teacher's Q3 too.
        Queue::fake();
        $this->regrade(['include_overridden' => true])->assertStatus(202)
            ->assertJsonPath('data.skipped_overridden', 0)
            ->assertJsonPath('data.queued_responses', 3);
        $this->assertSame([Response::STATE_QUEUED, null], [$work->refresh()->grading_state, $work->final_score]);
        $this->regrade(['include_overridden' => 'maybe'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_an_unchanged_mcq_stays_reviewed_and_nothing_is_reopened(): void
    {
        $this->postScan($this->metaFor(1))->assertStatus(201);
        $this->app->call([new GradeScanJob((int) Response::query()->whereNotNull('scan_id')->value('scan_id')), 'handle']);
        $short = $this->response($this->short->id);
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$short->id}", ['final_score' => 1, 'final_understanding' => 'partial', 'reason' => 'ครึ่งคะแนน'])->assertOk();
        $mcq = $this->response($this->mcq->id);
        $mcq->forceFill(['final_score' => $mcq->ai_score, 'final_understanding' => $mcq->ai_understanding, 'reviewed_at' => now(), 'reviewed_by' => $this->teacher->id])->save();
        Queue::fake();

        // An mcq that code would score the same is not work: the key is unchanged, so nothing to do.
        $this->estimate()->assertOk()
            ->assertJsonPath('data.submissions', 0)
            ->assertJsonPath('data.mcq_by_code', 0)
            ->assertJsonPath('data.published_submissions', 0)
            ->assertJsonPath('data.skipped_overridden', 1);
        $this->regrade()->assertOk()
            ->assertJsonPath('data.queued_submissions', 0)
            ->assertJsonPath('data.rescored_by_code', 0)
            ->assertJsonPath('data.skipped_overridden', 1);
        $this->assertNotNull($mcq->refresh()->reviewed_at);
        Queue::assertNothingPushed();
    }

    public function test_it_needs_an_approved_key_and_a_gemini_key(): void
    {
        $this->scanAndGrade();
        Queue::fake();

        $this->assignment->forceFill(['key_approved_at' => null])->save();
        $this->regrade()->assertStatus(409)->assertJsonPath('code', 'answer_key_not_approved');
        $this->estimate()->assertStatus(409)->assertJsonPath('code', 'answer_key_not_approved');

        $this->assignment->forceFill(['key_approved_at' => now()])->save();
        config(['services.gemini.api_key' => null]);
        $this->regrade()->assertStatus(422)->assertJsonPath('code', 'ai_key_missing');
        $this->assertSame(0, Response::query()->where('grading_state', Response::STATE_QUEUED)->count(), 'nothing changed');
        Queue::assertNothingPushed();
    }

    /** The full actor matrix (guest, disabled, admin, ...) is AuthorizationMatrixTest's. */
    public function test_only_the_classroom_teacher_may_regrade(): void
    {
        $this->scanAndGrade();
        Queue::fake();

        $colleague = $this->makeTeacher($this->teacher->school);
        $this->regrade([], $colleague)->assertNotFound();
        $this->asUser($colleague)->postJson($this->url('/estimate'))->assertNotFound();
        $this->regrade([], $this->makeTeacher())->assertNotFound();
        $this->regrade([], $this->student)->assertForbidden();
        $this->assertSame(0, Response::query()->where('grading_state', Response::STATE_QUEUED)->count());
    }

    public function test_answers_whose_crop_was_purged_are_skipped(): void
    {
        $this->scanAndGrade();
        Storage::disk('local')->delete($this->response($this->open->id)->crop_path);
        Queue::fake();

        $this->regrade()->assertStatus(202)->assertJsonPath('data.skipped_missing_image', 1);
        $this->assertSame(Response::STATE_SCORED, $this->response($this->open->id)->grading_state);
    }

    public function test_a_whole_page_hand_in_is_read_again_page_by_page_and_overrides_stay(): void
    {
        $bytes = "\xFF\xD8\xFF\xE0JFIF page ".bin2hex(random_bytes(4));
        $this->asUser($this->teacher)->post(
            "/api/v1/assignments/{$this->assignment->id}/students/{$this->student->id}/pages",
            ['files' => [UploadedFile::fake()->createWithContent('page.jpg', $bytes)]],
            ['Accept' => 'application/json'],
        )->assertSuccessful();
        $this->runPushedJobs();
        $this->assertSame(0, Response::query()->whereIn('grading_state', Response::IN_PROGRESS_STATES)->count());
        $open = $this->response($this->open->id);
        $open->forceFill(['final_score' => 3, 'final_understanding' => 'partial', 'reviewed_at' => now(), 'reviewed_by' => $this->teacher->id])->save();
        $page = SubmissionPage::query()->sole();
        $readsBefore = count(array_filter($this->gemini->requests, fn (GeminiRequest $r) => $r->purpose === 'extract_page'));
        Queue::fake();

        $this->estimate()->assertOk()->assertJsonPath('data.whole_page_pages', 1)->assertJsonPath('data.queued_responses', 3)->assertJsonPath('data.mcq_by_code', 0);
        $this->regrade()->assertStatus(202)
            ->assertJsonPath('data.queued_responses', 3)
            ->assertJsonPath('data.skipped_overridden', 1)
            ->assertJsonPath('data.rescored_by_code', 0);
        $this->assertSame(SubmissionPage::STATE_GRADING, $page->refresh()->state);
        Queue::assertPushed(GradeSubmissionPageJob::class, fn (GradeSubmissionPageJob $j) => $j->pageId === $page->id);
        Queue::assertNotPushed(GradeScanJob::class);
        $this->assertSame(Response::STATE_QUEUED, $this->response($this->mcq->id)->grading_state, 'a whole-page mcq is read again with the page');

        $this->runPushedJobs();
        $this->assertGreaterThan($readsBefore, count(array_filter($this->gemini->requests, fn (GeminiRequest $r) => $r->purpose === 'extract_page')));
        $this->assertSame(0, Response::query()->whereIn('grading_state', Response::IN_PROGRESS_STATES)->count());
        $open->refresh();
        $this->assertSame([3.0, 'partial'], [$open->final_score, $open->final_understanding], 'the override stays');
    }
}
