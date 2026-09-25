<?php

namespace App\Domain\Notifications;

use App\Models\Assignment;
use Illuminate\Support\Facades\Log;

/** Notifier that only logs (no FCM yet). Carries ids and counts, never student data. */
final class LogNotifier implements Notifier
{
    public function gradingFinished(Assignment $assignment, int $awaitingReview): void
    {
        Log::info('notify.grading_finished', [
            'teacher_id' => $assignment->classroom?->teacher_id,
            'assignment_id' => $assignment->id,
            'awaiting_review' => $awaitingReview,
            'text' => "ตรวจ {$assignment->title} เสร็จแล้ว มี {$awaitingReview} ข้อรอตรวจทาน",
        ]);
    }
}
