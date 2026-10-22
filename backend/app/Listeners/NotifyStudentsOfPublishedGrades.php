<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\GradesPublished;
use App\Models\GradebookPublication;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * "ประกาศเกรด {รหัสวิชา} แล้ว" to every student of the publication (DESIGN
 * §23.7, §9.9: never the grade), from the cron worker (queue `default`),
 * only if the publication was not withdrawn in the meantime.
 */
class NotifyStudentsOfPublishedGrades implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(GradesPublished $event): void
    {
        $publication = GradebookPublication::query()->with('course')->find($event->publicationId);
        if ($publication === null || $publication->withdrawn_at !== null) {
            return;
        }

        try {
            $this->notifier->gradesPublished($publication);
        } catch (Throwable $e) {
            report($e); // a push outage must not fail or repeat the job
        }
    }
}
