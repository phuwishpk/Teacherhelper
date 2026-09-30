<?php

namespace Tests\Feature\Analysis;

use App\Domain\Analysis\AnalysisBatches;
use App\Domain\Analysis\StudentAnalyses;
use App\Domain\Gemini\GeminiBatch;
use App\Domain\Gemini\GeminiBatchClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\HttpGeminiClient;
use App\Jobs\BuildAnalysisBatchesJob;
use App\Jobs\PollAnalysisBatchJob;
use App\Models\AiCall;
use App\Models\AnalysisBatch;
use App\Models\StudentAnalysis;
use App\Models\TeacherApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * DESIGN §20.8, §20.10 (nightly round): once a day after 01:00
 * Asia/Bangkok, only the rows whose texts are missing or older than the
 * mastery, one batch per key, polled until collected, mastery changing
 * while waiting, failed batches, and the Batch API's REST shapes (Http::fake).
 */
class NightlyAnalysisTest extends TestCase
{
    use AnalysisWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeAnalysisWorld();
    }

    private function batches(): AnalysisBatches
    {
        return app(AnalysisBatches::class);
    }

    private function row(string $student): StudentAnalysis
    {
        return StudentAnalysis::query()->where('student_id', $this->students[$student]->id)->sole();
    }

    public function test_the_round_is_queued_once_a_day_after_one_in_the_morning_bangkok_time(): void
    {
        Queue::fake();

        // 00:30 in Bangkok = 17:30 UTC the day before.
        $this->assertFalse(BuildAnalysisBatchesJob::dispatchIfDue(Carbon::parse('2026-09-30 17:30:00', 'UTC')));
        $this->assertTrue(BuildAnalysisBatchesJob::dispatchIfDue(Carbon::parse('2026-09-30 18:05:00', 'UTC')));
        $this->assertFalse(BuildAnalysisBatchesJob::dispatchIfDue(Carbon::parse('2026-10-01 10:00:00', 'UTC')), 'still 1 Oct in Bangkok');
        $this->assertTrue(BuildAnalysisBatchesJob::dispatchIfDue(Carbon::parse('2026-10-01 18:00:00', 'UTC')), '2 Oct 01:00 in Bangkok');

        Queue::assertPushed(BuildAnalysisBatchesJob::class, 2);
    }

    public function test_the_cron_queues_the_round_and_the_polls(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-09-30 20:00:00', 'UTC')); // 03:00 in Bangkok
        $due = AnalysisBatch::create(['state' => AnalysisBatch::STATE_SUBMITTED, 'batch_name' => 'batches/a', 'request_count' => 1, 'last_polled_at' => now()->subMinutes(2)]);
        AnalysisBatch::create(['state' => AnalysisBatch::STATE_RUNNING, 'batch_name' => 'batches/b', 'request_count' => 1, 'last_polled_at' => now()->subSeconds(20)]);
        AnalysisBatch::create(['state' => AnalysisBatch::STATE_COLLECTED, 'batch_name' => 'batches/c', 'request_count' => 1]);

        $this->artisan('eduvision:queue-work', ['--memory' => 1024])->assertSuccessful();
        $this->artisan('eduvision:queue-work', ['--memory' => 1024])->assertSuccessful();

        Queue::assertPushed(BuildAnalysisBatchesJob::class, 1);
        Queue::assertPushed(PollAnalysisBatchJob::class, 1);
        Queue::assertPushed(PollAnalysisBatchJob::class, fn (PollAnalysisBatchJob $job) => $job->batchId === $due->id);
    }

    public function test_a_night_round_submits_changed_students_and_the_poll_collects_their_texts(): void
    {
        $stats = $this->batches()->build();

        $this->assertSame(['recorded' => 2, 'queued' => 2, 'batches' => 1, 'skipped_no_key' => 0], $stats);
        $batch = AnalysisBatch::query()->sole();
        $this->assertSame([AnalysisBatch::STATE_SUBMITTED, null, 2], [$batch->state, $batch->key_owner_id, $batch->request_count]);
        $this->assertStringStartsWith('batches/', (string) $batch->batch_name);
        foreach (['A', 'B'] as $n) {
            $row = $this->row($n);
            $this->assertSame([StudentAnalysis::STATUS_QUEUED, $row->computed_input_hash, $batch->id], [$row->status, $row->queued_input_hash, $row->batch_id]);
        }
        $this->assertSame(0, AiCall::query()->count());

        // No student data in any request (§20.9).
        foreach ($this->fake()->requests as $request) {
            $this->assertStringNotContainsString('สม', $request->userText);
        }

        $this->batches()->poll($batch->id);

        $this->assertSame(AnalysisBatch::STATE_COLLECTED, $batch->refresh()->state);
        $this->assertNotNull($batch->completed_at);
        foreach (['A', 'B'] as $n) {
            $row = $this->row($n);
            $this->assertSame([StudentAnalysis::STATUS_DRAFTED, StudentAnalysis::VIA_BATCH, null], [$row->status, $row->generated_via, $row->queued_input_hash]);
            $this->assertSame($row->computed_input_hash, $row->generated_input_hash);
            $this->assertNotNull($row->student_text);
            $this->assertNull($row->shared_student_text, 'students wait for approval');
        }
        $calls = AiCall::query()->get();
        $this->assertCount(2, $calls);
        $this->assertTrue($calls->every(fn (AiCall $c) => $c->batch && $c->feature === 'analysis_nightly' && $c->purpose === 'student_analysis'));

        // The next night: nothing changed, nothing is sent.
        $this->assertSame(['recorded' => 2, 'queued' => 0, 'batches' => 0, 'skipped_no_key' => 0], $this->batches()->build());
    }

    public function test_publishing_changes_the_hash_and_the_next_night_writes_again(): void
    {
        $this->batches()->build();
        $this->batches()->poll(AnalysisBatch::query()->sole()->id);

        // Published later: code updates the strengths right away, the text is stale.
        $this->mastery('A', 'i3', 0.9, 5);
        app(StudentAnalyses::class)->refreshStudent($this->students['A']->id);
        $row = $this->row('A');
        $this->assertTrue($row->isStale());
        $this->assertSame(['ค 1.1 ป.5/4'], $this->codes($row->areas));

        $this->assertSame(1, $this->batches()->build()['queued']);
        $this->assertSame(StudentAnalysis::STATUS_QUEUED, $this->row('A')->status);
        $this->assertSame(StudentAnalysis::STATUS_DRAFTED, $this->row('B')->status);
    }

    public function test_mastery_changing_while_the_batch_waits_is_written_again_the_next_night(): void
    {
        $this->batches()->build();
        $queued = $this->row('A')->queued_input_hash;

        $this->mastery('A', 'i4', 0.95, 3);
        app(StudentAnalyses::class)->refreshStudent($this->students['A']->id);
        // A second round the same night leaves rows of the pending batch alone.
        $this->assertSame(0, $this->batches()->build()['queued']);

        $this->batches()->poll(AnalysisBatch::query()->sole()->id);
        $row = $this->row('A');
        $this->assertSame($queued, $row->generated_input_hash, 'the hash of the input the text was written from');
        $this->assertNotSame($row->computed_input_hash, $row->generated_input_hash);

        $this->assertSame(1, $this->batches()->build()['queued']);
    }

    public function test_one_batch_per_key_and_classrooms_without_a_key_wait(): void
    {
        $other = $this->makeTeacher($this->teacher->school);
        TeacherApiKey::create(['user_id' => $other->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000077', 'key_last4' => '0077']);
        $room2 = $this->makeClassroom($other, ['grade_level' => 5]);
        $this->course->classrooms()->attach($room2->id);
        $room2->students()->attach($this->students['A']->id, ['student_number' => 1]);

        $this->assertSame(['recorded' => 3, 'queued' => 3, 'batches' => 2, 'skipped_no_key' => 0], $this->batches()->build());
        $this->assertEqualsCanonicalizing([null, $other->id], AnalysisBatch::query()->pluck('key_owner_id')->all());
        $this->assertSame(1, AnalysisBatch::query()->where('key_owner_id', $other->id)->value('request_count'));

        foreach (AnalysisBatch::query()->pluck('id') as $id) {
            $this->batches()->poll($id);
        }
        $this->assertSame(['teacher', 'server', 'server'], AiCall::query()->orderBy('key_source', 'desc')->pluck('key_source')->all());

        // Without any key the rows stay computed until a key exists.
        StudentAnalysis::query()->delete();
        AnalysisBatch::query()->delete();
        config(['services.gemini.api_key' => '']);
        $this->assertSame(['recorded' => 3, 'queued' => 1, 'batches' => 1, 'skipped_no_key' => 2], $this->batches()->build());
    }

    public function test_auto_share_copies_the_batch_text_for_the_student(): void
    {
        $this->room->forceFill(['auto_share_analysis' => true])->save();
        $this->batches()->build();
        $this->batches()->poll(AnalysisBatch::query()->sole()->id);

        $row = $this->row('A');
        $this->assertSame($row->student_text, $row->shared_student_text);
        $this->assertSame($row->student_text, $this->asUser($this->students['A'])->getJson('/api/v1/student/analysis')->json('data.0.text'));
    }

    public function test_the_forbidden_word_in_a_batch_result_is_asked_again_as_one_call(): void
    {
        $this->s['i4']->forceFill(['name' => 'เศษส่วน [fake:weak-word-once]'])->save();
        $this->batches()->build();
        $this->batches()->poll(AnalysisBatch::query()->sole()->id);

        // Both students have i4; each batch reply said "อ่อน", each retry is clean.
        $this->assertSame([StudentAnalysis::STATUS_DRAFTED, StudentAnalysis::STATUS_DRAFTED], [$this->row('A')->status, $this->row('B')->status]);
        $this->assertSame(
            [['invalid_output', true], ['invalid_output', true], ['ok', false], ['ok', false]],
            AiCall::query()->orderBy('batch', 'desc')->orderBy('id')->get()->map(fn (AiCall $c) => [$c->status, (bool) $c->batch])->all(),
        );
    }

    public function test_analyse_now_during_a_pending_batch_wins_over_the_batch_result(): void
    {
        $this->batches()->build();
        $this->asUser($this->teacher)->postJson("/api/v1/students/{$this->students['A']->id}/analysis/run", ['classroom_id' => $this->room->id])->assertOk();
        $now = $this->row('A');
        $this->assertSame([StudentAnalysis::VIA_NOW, null], [$now->generated_via, $now->batch_id]);

        $this->batches()->poll(AnalysisBatch::query()->sole()->id);
        $this->assertSame(StudentAnalysis::VIA_NOW, $this->row('A')->generated_via);
        $this->assertSame(StudentAnalysis::VIA_BATCH, $this->row('B')->generated_via);

        // Google bills A's batch reply too: it is logged, not written (§21.8).
        $this->assertSame(2, AiCall::query()->where('feature', 'analysis_nightly')->where('batch', true)->count());
    }

    /**
     * The real transport against Http::fake: the create body, a running
     * poll, then a succeeded poll whose inline responses carry the keys
     * (one request failed inside the batch).
     */
    public function test_the_batch_api_rest_shapes(): void
    {
        $this->app->instance(GeminiClient::class, new HttpGeminiClient('gemini-3.8-flash'));
        $keys = [];
        $polls = 0;
        Http::fake(function (Request $request) use (&$keys, &$polls) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/models/gemini-3.8-flash:batchGenerateContent')) {
                $keys = array_map(fn (array $r) => $r['metadata']['key'], $request['batch']['input_config']['requests']['requests']);

                return Http::response(['name' => 'batches/abc123', 'metadata' => ['state' => 'BATCH_STATE_PENDING']]);
            }
            if ($request->method() === 'GET' && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/batches/abc123') {
                $polls++;
                if ($polls === 1) {
                    return Http::response(['name' => 'batches/abc123', 'metadata' => ['state' => 'BATCH_STATE_RUNNING']]);
                }
                $text = json_encode(['teacher_text' => 'จุดเด่นคือบวกเลข', 'student_text' => 'เก่งมาก', 'next_step_skill_codes' => ['ค 1.1 ป.5/4', 'ไม่มีรหัสนี้']], JSON_UNESCAPED_UNICODE);

                return Http::response(['name' => 'batches/abc123', 'done' => true, 'metadata' => ['state' => 'JOB_STATE_SUCCEEDED'], 'response' => ['inlinedResponses' => ['inlinedResponses' => [
                    ['metadata' => ['key' => $keys[1]], 'error' => ['code' => 500, 'message' => 'internal']],
                    ['metadata' => ['key' => $keys[0]], 'response' => [
                        'candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']],
                        'usageMetadata' => ['promptTokenCount' => 300, 'candidatesTokenCount' => 80, 'thoughtsTokenCount' => 20],
                    ]],
                ]]]]);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$request->url()]], 500);
        });

        $this->batches()->build();
        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $inline = $request['batch']['input_config']['requests']['requests'][0];

            return $request->hasHeader('x-goog-api-key', 'testing-server-gemini-key-not-real')
                && ! str_contains($request->url(), 'key=')
                && str_starts_with((string) $request['batch']['display_name'], 'eduvision-analysis-')
                && isset($inline['request']['contents'][0]['parts'][0]['text'], $inline['request']['systemInstruction'])
                && $inline['request']['generationConfig']['maxOutputTokens'] === 1536
                && str_starts_with($inline['metadata']['key'], 'analysis-');
        });
        $batch = AnalysisBatch::query()->sole();
        $this->assertSame(['batches/abc123', AnalysisBatch::STATE_SUBMITTED], [$batch->batch_name, $batch->state]);

        $this->batches()->poll($batch->id);
        $this->assertSame(AnalysisBatch::STATE_RUNNING, $batch->refresh()->state);

        $this->batches()->poll($batch->id);
        $this->assertSame(AnalysisBatch::STATE_COLLECTED, $batch->refresh()->state);
        $this->assertSame('1 of 2 requests failed', $batch->error);

        $first = StudentAnalysis::query()->where('id', (int) substr($keys[0], strlen('analysis-')))->sole();
        $second = StudentAnalysis::query()->where('id', (int) substr($keys[1], strlen('analysis-')))->sole();
        $this->assertSame([StudentAnalysis::STATUS_DRAFTED, 'เก่งมาก', [$this->s['i4']->id]], [$first->status, $first->student_text, $first->next_step_skill_ids]);
        $this->assertSame([StudentAnalysis::STATUS_FAILED, null, null], [$second->status, $second->queued_input_hash, $second->generated_input_hash]);

        $ok = AiCall::query()->where('status', 'ok')->sole();
        $this->assertSame([300, 100, 20, true], [$ok->input_tokens, $ok->output_tokens, $ok->thinking_tokens, (bool) $ok->batch]);
        $this->assertSame('error', AiCall::query()->where('id', '!=', $ok->id)->value('status'));
    }

    public function test_a_failed_batch_leaves_its_rows_for_the_next_night(): void
    {
        $this->app->instance(GeminiClient::class, new HttpGeminiClient('gemini-3.8-flash'));
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['name' => 'batches/xyz', 'metadata' => ['state' => 'JOB_STATE_PENDING']]),
            'generativelanguage.googleapis.com/v1beta/batches/xyz' => Http::response(['name' => 'batches/xyz', 'done' => true, 'metadata' => ['state' => 'JOB_STATE_FAILED'], 'error' => ['code' => 13, 'message' => 'batch failed']]),
        ]);

        $this->batches()->build();
        $batch = AnalysisBatch::query()->sole();
        $this->batches()->poll($batch->id);

        $this->assertSame([AnalysisBatch::STATE_FAILED, 'batch failed'], [$batch->refresh()->state, $batch->error]);
        foreach (['A', 'B'] as $n) {
            $this->assertSame([StudentAnalysis::STATUS_FAILED, null, null], [$this->row($n)->status, $this->row($n)->queued_input_hash, $this->row($n)->generated_input_hash]);
        }
        $this->assertSame(2, $this->batches()->build()['queued'], 'tried again');
    }

    public function test_a_rejected_submit_fails_the_batch_at_once(): void
    {
        $this->app->instance(GeminiClient::class, new HttpGeminiClient('gemini-3.8-flash'));
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 400)]);

        $this->assertSame(0, $this->batches()->build()['queued']);
        $batch = AnalysisBatch::query()->sole();
        $this->assertSame(AnalysisBatch::STATE_FAILED, $batch->state);
        $this->assertStringNotContainsString('testing-server-gemini-key-not-real', (string) $batch->error);
        $this->assertSame(StudentAnalysis::STATUS_FAILED, $this->row('A')->status);
    }

    public function test_a_batch_left_building_by_a_dead_job_is_failed_and_its_rows_go_again(): void
    {
        $this->batches()->build();
        $batch = AnalysisBatch::query()->sole();
        // As if the job died after creating the batch and marking the rows, before the submit.
        $batch->forceFill(['state' => AnalysisBatch::STATE_BUILDING, 'batch_name' => null, 'submitted_at' => null])->save();

        $this->travel(AnalysisBatches::STALE_MINUTES - 1)->minutes();
        $this->assertSame(0, $this->batches()->recoverStale(), 'a build may still be running');
        $this->assertSame(0, $this->batches()->build()['queued'], 'rows of a live building batch wait');

        $this->travel(2)->minutes();
        Queue::fake();
        PollAnalysisBatchJob::dispatchDue();

        $this->assertSame(AnalysisBatch::STATE_FAILED, $batch->refresh()->state);
        $this->assertSame('the build stopped before the batch was sent', $batch->error);
        $this->assertSame([StudentAnalysis::STATUS_FAILED, null], [$this->row('A')->status, $this->row('A')->queued_input_hash]);
        Queue::assertNothingPushed();
        $this->assertSame(2, $this->batches()->build()['queued'], 'tried again');
    }

    public function test_a_collection_that_died_part_way_is_failed_by_the_next_round(): void
    {
        $this->batches()->build();
        $batch = AnalysisBatch::query()->sole();
        $this->batches()->poll($batch->id);
        // As if the collection died after writing A: B is still queued in a 'succeeded' batch.
        $b = $this->row('B');
        $b->forceFill(['status' => StudentAnalysis::STATUS_QUEUED, 'queued_input_hash' => $b->computed_input_hash, 'generated_input_hash' => null, 'batch_id' => $batch->id])->save();
        $batch->forceFill(['state' => AnalysisBatch::STATE_SUCCEEDED, 'completed_at' => null])->save();

        $this->travel(AnalysisBatches::STALE_MINUTES + 1)->minutes();
        $stats = $this->batches()->build();

        $this->assertSame([AnalysisBatch::STATE_FAILED, 'the collection stopped part way'], [$batch->refresh()->state, $batch->error]);
        $this->assertSame(StudentAnalysis::STATUS_DRAFTED, $this->row('A')->status, 'written texts stay');
        $this->assertSame(1, $stats['queued'], 'B goes in the new batch');
        $this->assertSame(StudentAnalysis::STATUS_QUEUED, $this->row('B')->status);
        $this->assertNotSame($batch->id, $this->row('B')->batch_id);
    }

    public function test_an_unexpected_error_while_building_fails_the_batch_instead_of_leaving_it_building(): void
    {
        $this->app->instance(GeminiBatchClient::class, new class implements GeminiBatchClient
        {
            public function submitBatch(array $requests, string $displayName, #[\SensitiveParameter] string $apiKey): GeminiBatch
            {
                throw new \RuntimeException('boom');
            }

            public function batchStatus(string $name, #[\SensitiveParameter] string $apiKey): GeminiBatch
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->assertSame(0, $this->batches()->build()['queued']);

        $batch = AnalysisBatch::query()->sole();
        $this->assertSame([AnalysisBatch::STATE_FAILED, 'the batch could not be built: boom'], [$batch->state, $batch->error]);
        $this->assertSame(StudentAnalysis::STATUS_FAILED, $this->row('A')->status);
    }

    /**
     * @param  list<array{skill_id: int}>  $items
     * @return list<string>
     */
    private function codes(array $items): array
    {
        $byId = [];
        foreach ($this->s as $skill) {
            $byId[$skill->id] = $skill->code;
        }

        return array_map(fn (array $i) => $byId[$i['skill_id']], $items);
    }
}
