<?php

namespace App\Domain\Analysis;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiBatch;
use App\Domain\Gemini\GeminiBatchClient;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\GeminiReply;
use App\Models\AnalysisBatch;
use App\Models\Classroom;
use App\Models\StudentAnalysis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The nightly analysis round through the Gemini Batch API (DESIGN §20.8).
 *
 * build(): recomputes strengths, areas and computed_input_hash of every
 * (student, classroom) from the current mastery, picks the rows whose
 * generated_input_hash is empty or differs and that are not in a pending
 * batch, marks them queued (queued_input_hash = computed_input_hash) and
 * submits them as inline requests, one batch per key (the classroom
 * teacher's key, or the server key), at most ANALYSIS_BATCH_MAX each.
 * Classrooms without any key are skipped until a key exists.
 *
 * poll(): asks Gemini for the batch's state. When it succeeded, every
 * reply is checked like a call (schema, the forbidden word) and logged to
 * ai_calls with batch = TRUE; invalid output is asked once more as an
 * ordinary call (at most RETRY_MAX per collection, the rest fail and go
 * again the next night). Only the poll that moves the batch from
 * submitted/running to succeeded collects it. Texts are written with generated_input_hash =
 * queued_input_hash of that request, so a student whose mastery changed
 * while waiting is written again the next night. Failed, expired or
 * cancelled batches (and single failed requests) leave their rows failed
 * with queued_input_hash cleared: the next night tries again.
 *
 * recoverStale(): a batch left 'building' (the build job died between
 * creating it and the submit) or 'succeeded' (the collection died part
 * way) for STALE_MINUTES, far past the jobs' 240 s timeout, is failed so
 * its remaining queued rows are free for the next round. Called by
 * build() and by the minute cron (PollAnalysisBatchJob::dispatchDue).
 */
final class AnalysisBatches
{
    /** A batch Gemini has not finished after this long is given up locally (Gemini itself expires them after 48 h). */
    public const GIVE_UP_HOURS = 72;

    /** A 'building' or 'succeeded' batch untouched this long belongs to a job that died (their timeout is 240 s). */
    public const STALE_MINUTES = 10;

    /** Plain-call retries of invalid batch replies per collection (each may take 30 s; the job has 240 s). */
    public const RETRY_MAX = 5;

    public function __construct(
        private readonly AnalysisInputs $inputs,
        private readonly StudentAnalyses $analyses,
        private readonly StudentAnalysisRequests $requests,
        private readonly GeminiBatchClient $client,
        private readonly GeminiGateway $gateway,
        private readonly GeminiKeyResolver $keys,
    ) {}

    public static function maxPerBatch(): int
    {
        return max(1, min(1000, (int) config('eduvision.analysis.batch_max', 200)));
    }

    /**
     * @return array{recorded: int, queued: int, batches: int, skipped_no_key: int}
     */
    public function build(): array
    {
        $stats = ['recorded' => 0, 'queued' => 0, 'batches' => 0, 'skipped_no_key' => 0];
        $this->recoverStale();
        $pendingBatchIds = AnalysisBatch::query()->whereIn('state', AnalysisBatch::PENDING_STATES)->pluck('id')->map(fn ($id) => (int) $id)->all();

        /** @var array<string, array{key: GeminiKey, owner: int|null, rows: list<array{0: StudentAnalysis, 1: AnalysisInput}>}> $groups */
        $groups = [];
        foreach (Classroom::query()->orderBy('id')->cursor() as $classroom) {
            $key = null;
            foreach ($this->inputs->forClassroom($classroom) as $input) {
                $row = $this->analyses->record($input);
                if ($row === null) {
                    continue;
                }
                $stats['recorded']++;
                if ($input->isEmpty() || $row->generated_input_hash === $row->computed_input_hash) {
                    continue;
                }
                if ($row->status === StudentAnalysis::STATUS_QUEUED && in_array($row->batch_id, $pendingBatchIds, true)) {
                    continue;
                }
                $key ??= $this->keys->forTeacher($classroom->teacher_id) ?? false;
                if ($key === false) {
                    $stats['skipped_no_key']++;

                    continue;
                }
                $owner = $key->source === GeminiKey::SOURCE_TEACHER ? $classroom->teacher_id : null;
                $group = $owner === null ? 'server' : 'teacher:'.$owner;
                $groups[$group] ??= ['key' => $key, 'owner' => $owner, 'rows' => []];
                $groups[$group]['rows'][] = [$row, $input];
            }
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group['rows'], self::maxPerBatch()) as $chunk) {
                if ($this->submit($chunk, $group['key'], $group['owner'])) {
                    $stats['queued'] += count($chunk);
                }
                $stats['batches']++;
            }
        }

        return $stats;
    }

    /**
     * @param  list<array{0: StudentAnalysis, 1: AnalysisInput}>  $chunk
     */
    private function submit(array $chunk, GeminiKey $key, ?int $owner): bool
    {
        $batch = AnalysisBatch::create([
            'key_owner_id' => $owner,
            'state' => AnalysisBatch::STATE_BUILDING,
            'request_count' => count($chunk),
        ]);

        try {
            $requests = [];
            foreach ($chunk as [$row, $input]) {
                $row->forceFill([
                    'queued_input_hash' => $row->computed_input_hash,
                    'status' => StudentAnalysis::STATUS_QUEUED,
                    'batch_id' => $batch->id,
                ])->save();
                $requests[self::requestKey($row->id)] = $this->requests->call($input, StudentAnalyses::FEATURE_NIGHTLY)->request;
            }
            $remote = $this->client->submitBatch($requests, 'eduvision-analysis-'.$batch->id, $key->apiKey);
        } catch (GeminiException $e) {
            $this->fail($batch, AnalysisBatch::STATE_FAILED, $e->getMessage(), $key);

            return false;
        } catch (\Throwable $e) {
            // Never leave a batch 'building': its rows would be skipped every night.
            report($e);
            $this->fail($batch, AnalysisBatch::STATE_FAILED, 'the batch could not be built: '.$e->getMessage(), $key);

            return false;
        }

        $batch->forceFill([
            'batch_name' => $remote->name,
            'state' => $remote->state === GeminiBatch::RUNNING ? AnalysisBatch::STATE_RUNNING : AnalysisBatch::STATE_SUBMITTED,
            'submitted_at' => now(),
        ])->save();

        return true;
    }

    /** One poll of a submitted or running batch (PollAnalysisBatchJob). */
    public function poll(int $batchId): void
    {
        $batch = AnalysisBatch::query()->find($batchId);
        if ($batch === null || ! in_array($batch->state, [AnalysisBatch::STATE_SUBMITTED, AnalysisBatch::STATE_RUNNING], true)) {
            return;
        }
        $batch->forceFill(['last_polled_at' => now()])->save();

        $key = $batch->key_owner_id === null ? $this->keys->serverKey() : $this->keys->teacherKey($batch->key_owner_id);
        if ($key === null || $batch->batch_name === null) {
            $this->fail($batch, AnalysisBatch::STATE_FAILED, $key === null ? 'the key of this batch is gone' : 'the batch has no name');

            return;
        }

        try {
            $remote = $this->client->batchStatus($batch->batch_name, $key->apiKey);
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::KEY_INVALID || str_starts_with($e->getMessage(), 'HTTP 404')) {
                $this->fail($batch, AnalysisBatch::STATE_FAILED, $e->getMessage(), $key);
            } elseif ($batch->submitted_at !== null && $batch->submitted_at->lt(now()->subHours(self::GIVE_UP_HOURS))) {
                $this->fail($batch, AnalysisBatch::STATE_EXPIRED, 'no answer for '.self::GIVE_UP_HOURS.' hours: '.$e->getMessage(), $key);
            } else {
                Log::warning('analysis.batch_poll_failed', ['batch_id' => $batch->id, 'message' => Str::limit($e->getMessage(), 200)]);
            }

            return;
        }

        match ($remote->state) {
            GeminiBatch::SUCCEEDED => $this->collect($batch, $remote, $key),
            GeminiBatch::FAILED => $this->fail($batch, AnalysisBatch::STATE_FAILED, $remote->error ?? 'the batch failed', $key),
            GeminiBatch::EXPIRED => $this->fail($batch, AnalysisBatch::STATE_EXPIRED, $remote->error ?? 'the batch expired', $key),
            GeminiBatch::CANCELLED => $this->fail($batch, AnalysisBatch::STATE_CANCELLED, $remote->error ?? 'the batch was cancelled', $key),
            GeminiBatch::RUNNING => $batch->forceFill(['state' => AnalysisBatch::STATE_RUNNING])->save(),
            default => $batch->submitted_at !== null && $batch->submitted_at->lt(now()->subHours(self::GIVE_UP_HOURS))
                ? $this->fail($batch, AnalysisBatch::STATE_EXPIRED, 'no result for '.self::GIVE_UP_HOURS.' hours', $key)
                : null,
        };
    }

    private function collect(AnalysisBatch $batch, GeminiBatch $remote, GeminiKey $key): void
    {
        // Claim the collection: of two polls that both saw 'succeeded' (a
        // poll job waited in the queue past the cache guard), only the one
        // that moves the state collects, so nothing is logged or retried twice.
        $claimed = AnalysisBatch::query()
            ->whereKey($batch->id)
            ->whereIn('state', [AnalysisBatch::STATE_SUBMITTED, AnalysisBatch::STATE_RUNNING])
            ->update(['state' => AnalysisBatch::STATE_SUCCEEDED, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $batch->refresh();

        $rows = StudentAnalysis::query()
            ->where('batch_id', $batch->id)
            ->where('status', StudentAnalysis::STATUS_QUEUED)
            ->with('classroom')
            ->get();
        $failed = 0;
        $retries = 0;
        foreach ($rows as $row) {
            $classroom = $row->classroom;
            $input = $classroom === null ? null : $this->inputs->forStudent($row->student_id, $classroom);
            if ($input === null) {
                $this->analyses->markFailed($row->id, $batch->id);
                $failed++;

                continue;
            }
            $call = $this->requests->call($input, StudentAnalyses::FEATURE_NIGHTLY);
            $reply = $remote->replies[self::requestKey($row->id)] ?? GeminiReply::error('no result for this request in the batch');
            $outcome = $this->gateway->judgeBatchReply($call, $reply, $key);
            $hash = (string) $row->queued_input_hash;

            if ($outcome->status === CallOutcome::INVALID_OUTPUT && ! $input->isEmpty() && $retries < self::RETRY_MAX) {
                // DESIGN §20.5: invalid output (e.g. "อ่อน" in the student text) is asked once more, now as a plain call.
                // Past RETRY_MAX the row fails and goes in the next night's batch, so the job stays inside its timeout.
                $retries++;
                $outcome = $this->gateway->run(['retry' => $call], $key, passes: 1)['retry'];
                $hash = $input->hash();
            }

            if ($outcome->isOk()) {
                $this->analyses->applyText($row->id, (array) $outcome->data, StudentAnalysis::VIA_BATCH, $hash, $batch->id);
            } else {
                $this->analyses->markFailed($row->id, $batch->id);
                $failed++;
            }
        }

        $this->logTakenOver($batch, $remote, $rows->pluck('id')->all(), $key);

        $batch->forceFill([
            'state' => AnalysisBatch::STATE_COLLECTED,
            'completed_at' => now(),
            'error' => $failed === 0 ? null : Str::limit("{$failed} of {$rows->count()} requests failed", 250),
        ])->save();
    }

    /**
     * Google bills every reply of the batch, also those of rows that
     * "วิเคราะห์ตอนนี้" took over (or a later batch re-queued) while it
     * waited: they are only logged to ai_calls (§21.8), never written.
     *
     * @param  list<int>  $collectedIds
     */
    private function logTakenOver(AnalysisBatch $batch, GeminiBatch $remote, array $collectedIds, GeminiKey $key): void
    {
        $ids = [];
        foreach (array_keys($remote->replies) as $requestKey) {
            if (preg_match('/^analysis-(\d{1,19})$/', (string) $requestKey, $m) === 1 && ! in_array((int) $m[1], $collectedIds, true)) {
                $ids[] = (int) $m[1];
            }
        }
        if ($ids === []) {
            return;
        }
        $rows = StudentAnalysis::query()->whereKey($ids)->with('classroom')->get();
        foreach ($rows as $row) {
            $input = $row->classroom === null ? null : $this->inputs->forStudent($row->student_id, $row->classroom);
            if ($input === null) {
                continue;
            }
            $this->gateway->judgeBatchReply($this->requests->call($input, StudentAnalyses::FEATURE_NIGHTLY), $remote->replies[self::requestKey($row->id)], $key);
        }
    }

    /** The batches whose job died while they were 'building' or 'succeeded'. */
    public static function staleQuery(): Builder
    {
        return AnalysisBatch::query()
            ->whereIn('state', [AnalysisBatch::STATE_BUILDING, AnalysisBatch::STATE_SUCCEEDED])
            ->where('updated_at', '<=', now()->subMinutes(self::STALE_MINUTES));
    }

    /** Fails every stale batch so its queued rows are tried again (DESIGN §20.8 step 4). */
    public function recoverStale(): int
    {
        $count = 0;
        foreach (self::staleQuery()->orderBy('id')->get() as $batch) {
            $this->fail($batch, AnalysisBatch::STATE_FAILED, $batch->state === AnalysisBatch::STATE_BUILDING
                ? 'the build stopped before the batch was sent'
                : 'the collection stopped part way');
            $count++;
        }

        return $count;
    }

    private function fail(AnalysisBatch $batch, string $state, string $error, ?GeminiKey $key = null): void
    {
        if ($key !== null && $key->apiKey !== '') {
            $error = str_replace($key->apiKey, '[redacted]', $error);
        }
        $batch->forceFill(['state' => $state, 'completed_at' => now(), 'error' => Str::limit($error, 250)])->save();
        StudentAnalysis::query()
            ->where('batch_id', $batch->id)
            ->where('status', StudentAnalysis::STATUS_QUEUED)
            ->update(['status' => StudentAnalysis::STATUS_FAILED, 'queued_input_hash' => null, 'updated_at' => now()]);
    }

    /** The metadata key of a row's request: the analysis row id, never a student id (§20.9). */
    public static function requestKey(int $analysisId): string
    {
        return 'analysis-'.$analysisId;
    }
}
