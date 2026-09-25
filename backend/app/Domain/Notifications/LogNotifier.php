<?php

namespace App\Domain\Notifications;

use App\Models\Assignment;
use Illuminate\Support\Facades\Log;

/** Notifier that only logs (no FCM yet). Carries ids and counts, never student data. */
final class LogNotifier implements Notifier
{
    public function gradingFinished(Assignment $assignment, int $awaitingReview, int $awaitingAiKey): void
    {
        Log::info('notify.grading_finished', [
            'teacher_id' => $assignment->classroom?->teacher_id,
            'assignment_id' => $assignment->id,
            'awaiting_review' => $awaitingReview,
            'awaiting_ai_key' => $awaitingAiKey,
            'text' => NoticeTexts::gradingFinished((string) $assignment->title, $awaitingReview, $awaitingAiKey),
        ]);
    }
}
