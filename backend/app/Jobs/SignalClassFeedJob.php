<?php

namespace App\Jobs;

use App\Domain\Firestore\FirestoreClient;
use App\Domain\Firestore\FirestoreUid;
use App\Domain\Firestore\LiveClassroom;
use App\Domain\Firestore\LiveStore;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Signals that a classroom's feed changed (DESIGN §38.11, step 4).
 * Writes a minimal signal under users/{uid}/class_feed/{classroomId} for
 * every student and teacher of the classroom. The document holds only ids and
 * timestamps, no body or title; REST filters visibility per student so a
 * tailored post never leaks to other students.
 */
class SignalClassFeedJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $classroomId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->classroomId;
    }

    public function handle(LiveStore $store): void
    {
        $fields = LiveClassroom::feedFields($this->classroomId);
        $userIds = [...LiveClassroom::studentIds($this->classroomId), ...LiveClassroom::teacherIds($this->classroomId)];
        $ops = array_map(fn (int $id) => [
            'path' => FirestoreUid::path($id).'/class_feed/'.$this->classroomId,
            'fields' => $fields,
        ], array_values(array_unique($userIds)));

        foreach (array_chunk($ops, FirestoreClient::MAX_WRITES) as $chunk) {
            $store->batch($chunk);
        }
    }
}
