<?php

namespace App\Domain\Notifications;

use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\Submission;

/**
 * Push notifications (DESIGN §9.9). The container binds FcmNotifier when
 * FIREBASE_CREDENTIALS points at a usable service-account file, LogNotifier
 * otherwise (AppServiceProvider). Implementations extend PushNotifier, which
 * turns each event into a PushMessage (texts: NoticeTexts).
 *
 * Callers run these from queue jobs or queued listeners, never inside an
 * HTTP request, and a failing notifier must never fail the caller's work.
 */
interface Notifier
{
    /**
     * Grading of the assignment has drained (GradingNotices decides when, at
     * most once per cooldown): $awaitingReview answers wait for the teacher,
     * $awaitingAiKey of them only because no usable Gemini key was set (§13).
     */
    public function gradingFinished(Assignment $assignment, int $awaitingReview, int $awaitingAiKey): void;

    /** The submission was published: tell its student (no score, §9.9). */
    public function resultPublished(Submission $submission): void;

    /** $open appeals of the teacher's classrooms wait for an answer (§13). */
    public function appealsWaiting(int $teacherId, int $open): void;

    /** The teacher accepted or rejected the appeal: tell its student. */
    public function appealResolved(Appeal $appeal): void;
}
