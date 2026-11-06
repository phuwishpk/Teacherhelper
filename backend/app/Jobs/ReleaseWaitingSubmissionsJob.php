<?php

namespace App\Jobs;

use App\Domain\Pages\WholePageSubmissions;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ClassroomSubmissionImport;
use App\Models\Submission;
use App\Models\SubmissionPage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After the teacher approved the answer key (DESIGN §19.5, §19.10): every
 * hand-in that waited for it is graded from the pages already stored (no
 * new download). Classroom rows in `waiting_key` become `imported`, and
 * each submission that has stored pages but was never graded starts its
 * grading round (WholePageSubmissions::start -> GradeSubmissionPageJob).
 * A new hand-in of an already graded submission keeps waiting for the
 * teacher's "ตรวจ" (regrade_pending), as always.
 */
class ReleaseWaitingSubmissionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $assignmentId)
    {
        $this->onQueue('grading');
    }

    public function handle(WholePageSubmissions $pages): void
    {
        $assignment = Assignment::query()->find($this->assignmentId);
        if ($assignment === null || ! $assignment->keyApproved() || $assignment->isClosed()) {
            return;
        }

        ClassroomSubmissionImport::query()
            ->where('assignment_id', $assignment->id)
            ->where('state', ClassroomSubmissionImport::STATE_WAITING_KEY)
            ->update(['state' => ClassroomSubmissionImport::STATE_IMPORTED, 'updated_at' => now()]);

        $released = 0;
        Submission::query()
            ->where('assignment_id', $assignment->id)
            ->where('regrade_pending', false)
            ->whereHas('pages', fn ($q) => $q->where('state', SubmissionPage::STATE_STORED))
            ->whereDoesntHave('responses')
            ->each(function (Submission $submission) use ($pages, &$released) {
                try {
                    $pages->start($submission);
                    $released++;
                } catch (ApiException) {
                    // nothing_to_grade: started meanwhile
                }
            });

        Log::info('answer_key.released', ['assignment_id' => $assignment->id, 'submissions' => $released]);
    }
}
