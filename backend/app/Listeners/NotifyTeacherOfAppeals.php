<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Domain\Review\Appeals;
use App\Events\AppealOpened;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * "มีคำขอให้ตรวจใหม่ {n} รายการ" to the teacher (DESIGN §9.9).
 *
 * A student often appeals several answers in a row, so the job waits
 * DELAY_SECONDS and then only the job of the newest open appeal of that
 * teacher sends, with the count of every open appeal at that moment: one
 * push per burst instead of one per appeal.
 */
class NotifyTeacherOfAppeals implements ShouldQueue
{
    public const DELAY_SECONDS = 60;

    public string $queue = 'default';

    public int $delay = self::DELAY_SECONDS;

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(AppealOpened $event): void
    {
        $open = Appeals::openForTeacher($event->teacherId);
        $newest = (clone $open)->max('appeals.id');
        if ($newest === null || (int) $newest > $event->appealId) {
            return; // resolved already, or a newer appeal's job will send
        }

        try {
            $this->notifier->appealsWaiting($event->teacherId, $open->count());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
