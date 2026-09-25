<?php

namespace Tests\Feature\Review;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Models\Response;
use App\Models\ScoreEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * Phase 4 end to end on the real pipeline (sync queue, offline Gemini fake):
 * scan -> AI grading -> review queue -> bulk approve + overrides -> publish
 * -> the student's result -> appeal -> answer, with every push (§9.9).
 */
class ReviewFlowTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    public function test_a_sheet_is_published_only_after_every_page_is_scanned(): void
    {
        Storage::fake('local');
        $this->makeScanWorld();
        $this->open->rubricCriteria()->create(['position' => 1, 'description' => 'บอกได้ว่าคลอโรฟิลล์สะท้อนแสงสีเขียว', 'points' => 4, 'is_core' => true, 'source' => 'teacher']);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $notifier);
        $reviewEverything = function (): void {
            foreach (Response::query()->whereNull('reviewed_at')->get() as $response) {
                $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", [
                    'final_score' => $response->ai_score ?? 0,
                    'final_understanding' => $response->ai_understanding ?? 'not_yet',
                ])->assertOk();
            }
        };

        // Page 1 only (mcq + short), graded and fully reviewed.
        $this->postScan($this->metaFor(1))->assertStatus(201);
        $reviewEverything();
        $submissionId = (int) Response::query()->value('submission_id');
        $this->assertSame(2, Response::query()->count());

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submissionId}/publish")
            ->assertStatus(409)
            ->assertJsonPath('code', 'submission_not_reviewed')
            ->assertJsonPath('errors.missing_pages', ['2']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published', 0)
            ->assertJsonPath('data.skipped', 1);
        $this->assertSame([], $notifier->ofType(PushMessage::RESULTS_PUBLISHED));
        $this->asUser($this->student)->getJson("/api/v1/student/results/{$submissionId}")->assertNotFound();

        // Page 2 arrives: once reviewed, the whole sheet is published with its full total.
        $this->postScan($this->metaFor(2))->assertStatus(201);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/review-queue")
            ->assertJsonPath('meta.submissions.0.missing_pages', [])
            ->assertJsonPath('meta.submissions.0.publishable', false);
        $reviewEverything();
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submissionId}/publish")->assertOk();
        $this->assertEquals(
            round((float) Response::query()->sum('final_score'), 2),
            $this->asUser($this->student)->getJson("/api/v1/student/results/{$submissionId}")->assertOk()->json('data.total_score'),
        );
        $this->assertSame(4, Response::query()->count());
        $this->assertCount(1, $notifier->ofType(PushMessage::RESULTS_PUBLISHED));
    }

    public function test_from_scan_to_the_students_answered_appeal(): void
    {
        Storage::fake('local');
        $this->makeScanWorld();
        $this->open->rubricCriteria()->create(['position' => 1, 'description' => 'บอกได้ว่าคลอโรฟิลล์สะท้อนแสงสีเขียว', 'points' => 4, 'is_core' => true, 'source' => 'teacher']);
        $this->app->instance(GeminiClient::class, new FakeGeminiClient);
        $notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $notifier);
        $this->short->update(['prompt_text' => $this->short->prompt_text.' [fake:correct]']);
        $this->work->update(['prompt_text' => $this->work->prompt_text.' [fake:partial]']);
        $this->open->update(['prompt_text' => $this->open->prompt_text.' [fake:wrong]']);

        // Both pages: GradeScanJob runs on the sync queue.
        $this->postScan($this->metaFor(1))->assertStatus(201);
        $this->postScan($this->metaFor(2))->assertStatus(201);
        $this->assertSame(0, Response::query()->whereIn('grading_state', Response::IN_PROGRESS_STATES)->count());
        $this->assertCount(1, $notifier->ofType(PushMessage::GRADING_DONE));

        $queueUrl = "/api/v1/assignments/{$this->assignment->id}/review-queue";
        $queue = $this->asUser($this->teacher)->getJson($queueUrl)->assertOk();
        $this->assertCount(4, $queue->json('data'));
        $queue->assertJsonPath('meta.missing_ai_key_count', 0)
            ->assertJsonPath('meta.submissions.0.response_count', 4)
            ->assertJsonPath('meta.submissions.0.reviewed_count', 0);
        $priorities = array_map(fn (array $row) => $row['review_priority'], $queue->json('data'));
        $sorted = $priorities;
        rsort($sorted);
        $this->assertSame($sorted, $priorities, 'no manual or flagged rows here: priority descending');

        // The teacher gives the open answer one more point, approves the confident rest, confirms the others.
        $openAnswer = Response::query()->where('question_id', $this->open->id)->sole();
        $this->asUser($this->teacher)->getJson("/api/v1/responses/{$openAnswer->id}")->assertOk()->assertJsonPath('data.question.rubric_criteria.0.criterion_id', 1);
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$openAnswer->id}", [
            'final_score' => $openAnswer->ai_score + 1,
            'final_understanding' => 'partial',
            'reason' => 'เกณฑ์เข้มเกินไป',
        ])->assertOk();

        $bulk = $this->asUser($this->teacher)->getJson($queueUrl)->json('meta.bulk_approvable_count');
        $approved = $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/approve-confident")->assertOk()->json('data.approved');
        $this->assertSame($bulk, $approved);

        foreach (Response::query()->whereNull('reviewed_at')->get() as $response) {
            $detail = $this->asUser($this->teacher)->getJson("/api/v1/responses/{$response->id}")->assertOk();
            $this->assertNotEmpty($detail->json('data.why'));
            $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", [
                'final_score' => $response->ai_score,
                'final_understanding' => $response->ai_understanding,
            ])->assertOk();
        }
        $this->assertSame(1, ScoreEvent::query()->where('action', 'override')->count());

        $submissionId = (int) $queue->json('meta.submissions.0.id');
        $this->asUser($this->student)->getJson("/api/v1/student/results/{$submissionId}")->assertNotFound();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published', 1);
        $this->assertCount(1, $notifier->ofType(PushMessage::RESULTS_PUBLISHED));

        $expectedTotal = round((float) Response::query()->sum('final_score'), 2);
        $result = $this->asUser($this->student)->getJson("/api/v1/student/results/{$submissionId}")->assertOk();
        $this->assertEquals($expectedTotal, $result->json('data.total_score'));
        $this->assertEquals(12, $result->json('data.max_score'));
        $openRow = collect($result->json('data.responses'))->firstWhere('type', 'open');

        $appealId = $this->asUser($this->student)->postJson("/api/v1/student/responses/{$openRow['id']}/appeal", ['reason' => 'หนูเขียนเรื่องแสงสีเขียวไว้แล้ว'])
            ->assertCreated()
            ->json('data.id');
        $this->assertSame('มีคำขอให้ตรวจใหม่ 1 รายการ', $notifier->ofType(PushMessage::APPEAL_OPENED)[0][1]->body);

        $this->asUser($this->teacher)->getJson('/api/v1/appeals?status=open')->assertJsonPath('data.0.id', $appealId);
        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$appealId}", ['status' => 'accepted', 'final_score' => 4, 'teacher_note' => 'ถูกต้อง'])
            ->assertOk();
        $this->assertCount(1, $notifier->ofType(PushMessage::APPEAL_RESOLVED));

        $after = $this->asUser($this->student)->getJson("/api/v1/student/results/{$submissionId}")->assertOk();
        $this->assertEquals(4, collect($after->json('data.responses'))->firstWhere('type', 'open')['final_score']);
        $this->assertEquals(round($expectedTotal - $openRow['final_score'] + 4, 2), $after->json('data.total_score'));
        $this->assertSame(
            ['ai_scored', 'appeal_accepted', 'bulk_approve', 'override'],
            ScoreEvent::query()->distinct()->orderBy('action')->pluck('action')->intersect(['ai_scored', 'appeal_accepted', 'bulk_approve', 'override'])->values()->all(),
        );
    }
}
