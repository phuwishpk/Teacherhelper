<?php

namespace App\Jobs;

use App\Domain\Exams\ExamDocuments;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads a teacher's exam file once with exam_read (DESIGN §22.4, §22.16)
 * into document_extractions (purpose exam), then adds the sections and
 * draft questions to every exam whose import waits for that read (like
 * key_extraction_id of §19.5). Transport errors retry with backoff; after
 * the last try the read is `failed` and the teacher can ask again. Carries
 * ids only.
 */
class ReadExamDocumentJob implements ShouldQueue
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
        public readonly array $documentIds,
        public readonly ?int $pageFrom,
        public readonly ?int $pageTo,
        public readonly int $teacherId,
        public readonly ?int $assignmentId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(ExamDocuments $documents): void
    {
        $documents->process(
            $this->extractionId,
            $this->documentIds,
            $this->pageFrom,
            $this->pageTo,
            $this->teacherId,
            $this->assignmentId,
            lastAttempt: $this->attempts() >= $this->tries,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(ExamDocuments::class)->giveUp($this->extractionId);
    }
}
