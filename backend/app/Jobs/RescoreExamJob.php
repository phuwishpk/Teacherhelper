<?php

namespace App\Jobs;

use App\Domain\Exams\ExamRegrade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "ตรวจใหม่ทั้งห้อง" of an exam (DESIGN §22.16): every answer scored again
 * by code against the current key (ExamRegrade::apply), no Gemini. Safe to
 * run again: answers whose score would not change are left alone.
 *
 * $timeout stays below the database queue's retry_after (300 s).
 */
class RescoreExamJob implements ShouldQueue
{
    use Queueable;

    public const QUEUE = 'grading';

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(
        public readonly int $assignmentId,
        public readonly ?int $teacherId,
        public readonly bool $includeOverridden,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ExamRegrade $regrade): void
    {
        $regrade->apply($this->assignmentId, $this->teacherId, $this->includeOverridden);
    }
}
