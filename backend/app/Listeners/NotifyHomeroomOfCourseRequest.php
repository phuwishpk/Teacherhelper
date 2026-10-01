<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\CourseRequestCreated;
use App\Models\ClassroomCourseRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * "มีคำขอผูกรายวิชา {รหัส} กับห้อง {ชื่อห้อง}" to the homeroom teacher
 * (DESIGN §24.7, §24.8), from the cron worker, only while the request is
 * still pending.
 */
class NotifyHomeroomOfCourseRequest implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(CourseRequestCreated $event): void
    {
        $request = ClassroomCourseRequest::query()->with(['classroom', 'course'])->find($event->requestId);
        if ($request === null || ! $request->isPending()) {
            return;
        }

        try {
            $this->notifier->courseRequested($request);
        } catch (Throwable $e) {
            report($e); // a push outage must not fail or repeat the job
        }
    }
}
