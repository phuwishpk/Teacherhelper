<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\RetakeRequested;
use App\Models\ClassroomSubmissionImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/** The Thai reason of a retake request to the student (DESIGN §18.2, §9.9). */
class NotifyStudentOfRetakeRequest implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(RetakeRequested $event): void
    {
        $import = ClassroomSubmissionImport::query()->with('assignment')->find($event->importId);
        if ($import === null || $import->student_id === null
            || $import->state !== ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE) {
            return;
        }

        try {
            $this->notifier->retakeRequested($import);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
