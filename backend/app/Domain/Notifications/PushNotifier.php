<?php

namespace App\Domain\Notifications;

use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\ClassroomSubmissionImport;
use App\Models\Submission;

/**
 * Builds the PushMessage of each §9.9 event and its recipients; subclasses
 * only deliver (FcmNotifier to the users' registered devices, LogNotifier to
 * the log).
 */
abstract class PushNotifier implements Notifier
{
    /**
     * @param  list<int>  $userIds
     */
    abstract protected function push(array $userIds, PushMessage $message): void;

    public function gradingFinished(Assignment $assignment, int $awaitingReview, int $awaitingAiKey): void
    {
        $teacherId = $assignment->classroom?->teacher_id;
        if ($teacherId === null) {
            return;
        }
        $this->push([$teacherId], new PushMessage(
            PushMessage::GRADING_DONE,
            NoticeTexts::gradingFinished((string) $assignment->title, $awaitingReview, $awaitingAiKey),
            ['assignment_id' => $assignment->id],
        ));
    }

    public function resultPublished(Submission $submission): void
    {
        $title = (string) $submission->assignment?->title;
        $this->push([$submission->student_id], new PushMessage(
            PushMessage::RESULTS_PUBLISHED,
            NoticeTexts::resultsPublished($title),
            ['submission_id' => $submission->id, 'assignment_id' => $submission->assignment_id],
        ));
    }

    public function appealsWaiting(int $teacherId, int $open): void
    {
        if ($open <= 0) {
            return;
        }
        $this->push([$teacherId], new PushMessage(PushMessage::APPEAL_OPENED, NoticeTexts::appealsWaiting($open)));
    }

    public function appealResolved(Appeal $appeal): void
    {
        $submissionId = $appeal->response?->submission_id;
        $this->push([$appeal->student_id], new PushMessage(
            PushMessage::APPEAL_RESOLVED,
            NoticeTexts::appealResolved(),
            array_filter(['submission_id' => $submissionId, 'appeal_id' => $appeal->id], fn ($v) => $v !== null),
        ));
    }

    public function retakeRequested(ClassroomSubmissionImport $import): void
    {
        if ($import->student_id === null) {
            return;
        }
        $this->push([$import->student_id], new PushMessage(
            PushMessage::RETAKE_REQUESTED,
            NoticeTexts::retakeRequested((string) $import->assignment?->title, (string) $import->retake_reason),
            ['assignment_id' => $import->assignment_id],
        ));
    }

    public function classroomWorkImported(Assignment $assignment): void
    {
        $teacherId = $assignment->classroom?->teacher_id;
        if ($teacherId === null) {
            return;
        }
        $this->push([$teacherId], new PushMessage(
            PushMessage::CLASSROOM_WORK_IMPORTED,
            NoticeTexts::classroomWorkImported((string) $assignment->title),
            ['assignment_id' => $assignment->id],
        ));
    }

    public function googleReconnectNeeded(int $teacherId): void
    {
        $this->push([$teacherId], new PushMessage(PushMessage::GOOGLE_RECONNECT, NoticeTexts::googleReconnectNeeded()));
    }
}
