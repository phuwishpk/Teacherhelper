<?php

namespace App\Jobs;

use App\Domain\Analysis\AnalysisBatches;
use App\Models\AnalysisBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * One poll of a nightly analysis batch (DESIGN §20.8 step 3): GET
 * batches/{name}, and when it succeeded, the collection of every result
 * (AnalysisBatches::poll). Queued by eduvision:queue-work for each
 * submitted/running batch last polled over a minute ago, after failing
 * the stale building/succeeded ones (AnalysisBatches::recoverStale).
 * Carries the id only; not retried (the next minute polls again). A
 * second poll of the same batch (one that waited in the queue past the
 * cache guard) is harmless: only the poll that claims the collection
 * (AnalysisBatches::collect) writes and logs the replies.
 * $timeout stays below the database queue's retry_after (300 s).
 */
class PollAnalysisBatchJob implements ShouldQueue
{
    use Queueable;

    public const POLL_SECONDS = 60;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $batchId)
    {
        $this->onQueue('default');
    }

    /** The cron's hook: a poll for each due batch, never two queued for the same one. */
    public static function dispatchDue(): int
    {
        if (AnalysisBatches::staleQuery()->exists()) {
            app(AnalysisBatches::class)->recoverStale();
        }
        $due = AnalysisBatch::query()
            ->whereIn('state', [AnalysisBatch::STATE_SUBMITTED, AnalysisBatch::STATE_RUNNING])
            ->where(fn ($q) => $q->whereNull('last_polled_at')->orWhere('last_polled_at', '<=', now()->subSeconds(self::POLL_SECONDS)))
            ->orderBy('id')
            ->pluck('id');
        $count = 0;
        foreach ($due as $id) {
            if (Cache::add('analysis-batch-poll:'.$id, true, self::POLL_SECONDS - 5)) {
                self::dispatch((int) $id);
                $count++;
            }
        }

        return $count;
    }

    public function handle(AnalysisBatches $batches): void
    {
        $batches->poll($this->batchId);
    }
}
