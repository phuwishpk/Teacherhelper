<?php

namespace App\Jobs;

use App\Domain\Courses\CourseDocuments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads a course description, course structure or lesson plans once
 * (DESIGN §20.1, §19.10 ExtractDocumentJob's twin for courses) into
 * document_extractions (purpose course | lesson_plan). Transport errors
 * retry with backoff; after the last try the read is `failed` and the
 * teacher can ask again. Carries ids only.
 */
class ReadCourseDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    /** Below the database queue's retry_after (300 s): one document call may take 150 s. */
    public int $timeout = 240;

    /**
     * @param  list<int>  $documentIds
     */
    public function __construct(
        public readonly int $extractionId,
        public readonly string $kind,
        public readonly array $documentIds,
        public readonly ?int $pageFrom,
        public readonly ?int $pageTo,
        public readonly int $teacherId,
    ) {
        $this->onQueue('default');
    }

    public function handle(CourseDocuments $documents): void
    {
        $documents->process(
            $this->extractionId,
            $this->kind,
            $this->documentIds,
            $this->pageFrom,
            $this->pageTo,
            $this->teacherId,
            lastAttempt: $this->attempts() >= $this->tries,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(CourseDocuments::class)->giveUp($this->extractionId);
    }
}
