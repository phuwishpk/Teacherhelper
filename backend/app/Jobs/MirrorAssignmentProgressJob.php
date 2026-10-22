<?php

namespace App\Jobs;

use App\Domain\Firestore\FirestoreUid;
use App\Domain\Firestore\LiveGrading;
use App\Domain\Firestore\LiveStore;
use App\Models\Assignment;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies an assignment's grading counts to users/{teacher}/assignments/{id}
 * for every teacher of its classroom, in one commit (DESIGN §38.9). Unique
 * per assignment until it starts: changes that come in while it waits ride
 * along, changes after it started queue the next one.
 */
class MirrorAssignmentProgressJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $assignmentId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->assignmentId;
    }

    public function handle(LiveStore $store): void
    {
        $assignment = Assignment::query()->with('classroom:id,teacher_id')->find($this->assignmentId);
        if ($assignment === null) {
            return;
        }
        $fields = LiveGrading::assignmentFields($assignment);
        $ops = [];
        foreach (LiveGrading::teacherIds($assignment) as $teacherId) {
            $ops[] = ['path' => FirestoreUid::path($teacherId).'/assignments/'.$assignment->id, 'fields' => $fields];
        }
        if ($ops !== []) {
            $store->batch($ops);
        }
    }
}
