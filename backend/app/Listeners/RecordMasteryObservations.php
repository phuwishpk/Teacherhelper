<?php

namespace App\Listeners;

use App\Domain\Analysis\StudentAnalyses;
use App\Domain\Mastery\MasteryCalculator;
use App\Events\AppealResolved;
use App\Events\SubmissionPublished;
use App\Events\SubmissionReopened;
use App\Models\Response;
use App\Models\Submission;
use Throwable;

/**
 * Mastery (DESIGN §14.2) follows publishing: when a submission is published
 * its answers become skill_observations and the student's mastery of every
 * skill involved is recomputed; when an accepted appeal changed a published
 * score the same submission is recorded again (its rows are replaced); when
 * a confirmed rescan reopens a published submission its rows are removed
 * until it is published again.
 *
 * The three events implement ShouldDispatchAfterCommit, so this synchronous
 * listener runs right after the transaction that published, resolved or
 * reopened has committed: what it reads is final and its own transaction is
 * not nested in that one. Right after the mastery, the code-computed part
 * of the student's analyses (strengths, areas, computed_input_hash; DESIGN
 * §20.5) follows the new mastery; Gemini's texts wait for the night. A failure is reported, never surfaced to the
 * teacher, because the publish itself has already happened.
 */
class RecordMasteryObservations
{
    public function __construct(
        private readonly MasteryCalculator $mastery,
        private readonly StudentAnalyses $analyses,
    ) {}

    public function handleSubmissionPublished(SubmissionPublished $event): void
    {
        $this->record($event->submissionId);
    }

    public function handleSubmissionReopened(SubmissionReopened $event): void
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

            return;
        }

        try {
            $studentId = Submission::query()->whereKey($submissionId)->value('student_id');
            if ($studentId !== null) {
                $this->analyses->refreshStudent((int) $studentId);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
