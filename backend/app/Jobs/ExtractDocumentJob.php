<?php

namespace App\Jobs;

use App\Domain\AnswerKeys\AnswerKeyResult;
use App\Domain\AnswerKeys\AnswerKeyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads a teacher's answer key from their documents once (DESIGN §19.5,
 * §19.10, prompt answer_key_read) into document_extractions, then fills the
 * waiting assignment's questions (AnswerKeyService::process). Transport
 * errors retry with backoff; after the last try the extraction is `failed`
 * and the teacher can ask again. Carries ids only.
 */
class ExtractDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    /** Below the database queue's retry_after (300 s): one document call may take 150 s. */
    public int $timeout = 240;

    /**
     * Not readonly, unlike the other jobs: DraftAnswerKeyJob extends this
     * class, and PHP 8.3 (the server) refuses to unserialize a parent's
     * readonly property from the child's scope.
     *
     * @param  list<int>  $documentIds
     */
    public function __construct(
        public int $extractionId,
        public int $assignmentId,
        public array $documentIds,
        public ?int $pageFrom = null,
        public ?int $pageTo = null,
        public string $coursework = '',
    ) {
        $this->onQueue('default');
    }

    protected function kind(): string
    {
        return AnswerKeyResult::KIND_READ;
    }

    public function handle(AnswerKeyService $service): void
    {
        $service->process(
            $this->kind(),
            $this->extractionId,
            $this->assignmentId,
            $this->documentIds,
            $this->pageFrom,
            $this->pageTo,
            lastAttempt: $this->attempts() >= $this->tries,
            coursework: $this->coursework,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(AnswerKeyService::class)->giveUp($this->extractionId);
    }
}
