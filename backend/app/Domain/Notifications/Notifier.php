<?php

namespace App\Domain\Notifications;

use App\Models\Assignment;

/**
 * Push notifications (DESIGN §9.9). LogNotifier writes them to the log until
 * the FCM notifier of Phase 4 replaces the binding. Texts: NoticeTexts.
 */
interface Notifier
{
    /**
     * Grading of the assignment has drained (GradingNotices decides when, at
     * most once per cooldown): $awaitingReview answers wait for the teacher,
     * $awaitingAiKey of them only because no usable Gemini key was set (§13).
     */
    public function gradingFinished(Assignment $assignment, int $awaitingReview, int $awaitingAiKey): void;
}
