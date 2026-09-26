<?php

namespace App\Listeners;

use App\Domain\Mastery\MasteryCalculator;
use App\Events\AppealResolved;
use App\Events\SubmissionPublished;
use App\Models\Response;
use Throwable;

/**
 * Mastery (DESIGN §14.2) follows publishing: when a submission is published
 * its answers become skill_observations and the student's mastery of every
 * skill involved is recomputed; when an accepted appeal changed a published
 * score the same submission is recorded again (its rows are replaced).
 * Runs inline after the commit; a failure is reported, never surfaced to
 * the teacher, because the publish itself has already happened.
 */
class RecordMasteryObservations
{
    public function __construct(private readonly MasteryCalculator $mastery) {}

    public function handleSubmissionPublished(SubmissionPublished $event): void
    {
        $this->record($event->submissionId);
    }

    public function handleAppealResolved(AppealResolved $event): void
    {
        if (! $event->scoreChanged) {
            return;
        }
        $submissionId = Response::query()->whereKey($event->responseId)->value('submission_id');
        if ($submissionId !== null) {
            $this->record((int) $submissionId);
        }
    }

    private function record(int $submissionId): void
    {
        try {
            $this->mastery->recordSubmission($submissionId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
