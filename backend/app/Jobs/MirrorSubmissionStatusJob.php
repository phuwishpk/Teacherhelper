<?php

namespace App\Jobs;

use App\Domain\Firestore\FirestoreUid;
use App\Domain\Firestore\LiveGrading;
use App\Domain\Firestore\LiveStore;
use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies one hand-in's status to users/{student}/submissions/{id}
 * (DESIGN §38.9). Reads the row when it runs, so a burst of changes leaves
 * one pending job that writes the latest status. Never fails: LiveStore
 * swallows Firestore errors.
 */
class MirrorSubmissionStatusJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $submissionId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->submissionId;
    }

    public function handle(LiveStore $store): void
    {
        $submission = Submission::query()->with('assignment:id,title')->find($this->submissionId);
        if ($submission === null || $submission->student_id === null) {
            return;
        }
        $store->set(
            FirestoreUid::path((int) $submission->student_id).'/submissions/'.$submission->id,
            LiveGrading::submissionFields($submission),
        );
    }
}
