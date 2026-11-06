<?php

namespace App\Listeners;

use App\Domain\Notifications\Notifier;
use App\Events\CourseRequestDecided;
use App\Models\ClassroomCourseRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Tells the subject teacher that the homeroom teacher approved or declined
 * their course request (DESIGN §24.7, §24.8), from the cron worker.
 */
class NotifyRequesterOfCourseDecision implements ShouldQueue
{
    public string $queue = 'default';

    public int $tries = 1;

    public function __construct(private readonly Notifier $notifier) {}

    public function handle(CourseRequestDecided $event): void
    {
        $request = ClassroomCourseRequest::query()->with(['classroom', 'course'])->find($event->requestId);
        if ($request === null || ! in_array($request->status, [ClassroomCourseRequest::STATUS_APPROVED, ClassroomCourseRequest::STATUS_DECLINED], true)) {
            return;
        }

        try {
            $this->notifier->courseRequestDecided($request);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
