<?php

namespace App\Domain\Notifications;

use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\ClassroomSubmissionImport;
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

    /**
     * The teacher sent the student's Google Classroom work back for a new
     * photo (DESIGN §18.2): tell the student why (Classroom cannot).
     */
    public function retakeRequested(ClassroomSubmissionImport $import): void;

    /**
     * The cron sync mirrored courseWork the teacher created on the Classroom
     * website (DESIGN §19.3): its answer key waits for the teacher's approval.
     */
    public function classroomWorkImported(Assignment $assignment): void;

    /**
     * The teacher's Google grant stopped working (invalid_grant, a scope
     * taken back): once per drop (google_accounts.reconnect_notified_at, §19.3).
     */
    public function googleReconnectNeeded(int $teacherId): void;
}
