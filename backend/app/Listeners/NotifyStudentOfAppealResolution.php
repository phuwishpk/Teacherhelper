<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\AppealResolved;
use App\Models\Appeal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/** "ครูตอบคำขอตรวจใหม่แล้ว" to the student (DESIGN §9.9). */
class NotifyStudentOfAppealResolution implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(AppealResolved $event): void
    {
        $appeal = Appeal::query()->with('response')->find($event->appealId);
        if ($appeal === null || $appeal->status === Appeal::STATUS_OPEN) {
            return;
        }

        try {
            $this->notifier->appealResolved($appeal);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
