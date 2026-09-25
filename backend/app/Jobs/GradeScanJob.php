<?php

namespace App\Jobs;

use App\Domain\Grading\ScanGrader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Grades the non-mcq answers of one scan (DESIGN §7.2) with ScanGrader:
 * Gemini extraction (Http::pool, 8 at a time), fuzzy systems 1 and 2,
 * explanations, ai_calls, attempts, and the "grading done" notification.
 *
 * Answers that failed but have attempts left make the job release itself
 * with backoff 60 / 180 / 600 s; after 3 failed attempts an answer is
 * `manual`. The job carries only the scan id: no key, no student data.
 *
 * $timeout stays below the database queue's retry_after (300 s,
 * config/queue.php) so a second cron worker never picks up a running job.
 */
class GradeScanJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'grading';

    /** @var list<int> */
    public const BACKOFF = [60, 180, 600];

    /** Three failing runs make every answer `manual`; the rest is headroom for crashes. */
    public int $tries = 5;

    public int $timeout = 240;

    /** @var list<int> backoff after an unexpected exception */
    public array $backoff = self::BACKOFF;

    public function __construct(public readonly int $scanId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ScanGrader $grader): void
    {
        $result = $grader->grade($this->scanId);

        if ($result->retry) {
            $this->release(self::backoffFor($this->attempts()));
        }
    }

    /** Delay before run n + 1 after run n had failures: 60, 180, then 600 s. */
    public static function backoffFor(int $attempt): int
    {
        return self::BACKOFF[min(max($attempt, 1), count(self::BACKOFF)) - 1];
    }

    /** Out of tries (or a crash on the last one): the teacher grades what is left. */
    public function failed(?Throwable $exception): void
    {
        app(ScanGrader::class)->giveUp($this->scanId);
    }
}
