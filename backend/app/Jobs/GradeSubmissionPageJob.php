<?php

namespace App\Jobs;

use App\Domain\Grading\WholePageGrader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads one file of a whole-page submission (DESIGN §19.4, §19.10) with
 * WholePageGrader: one `extract_page` call with every question, one retry
 * per question that came back missing or invalid, then, once every page of
 * the submission is read, the merge, fuzzy grading, explanations and the
 * "grading done" notification.
 *
 * A Gemini transport error makes the job release itself (60 / 180 / 600 s,
 * like GradeScanJob); after 3 failing runs the page is `failed` and its
 * questions go to the teacher. The job carries only the page id.
 */
class GradeSubmissionPageJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'grading';

    /** Three failing runs fail the page; the rest is headroom for crashes. */
    public int $tries = 5;

    /** Below the database queue's retry_after (300 s): a page call may take 90 s, a retry round as long. */
    public int $timeout = 240;

    /** @var list<int> */
    public array $backoff = GradeScanJob::BACKOFF;

    public function __construct(public readonly int $pageId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(WholePageGrader $grader): void
    {
        if ($grader->gradePage($this->pageId, $this->attempts())) {
            $this->release(GradeScanJob::backoffFor($this->attempts()));
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(WholePageGrader::class)->giveUp($this->pageId);
    }
}
