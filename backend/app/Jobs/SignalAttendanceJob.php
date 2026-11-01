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
 * Signals that an attendance session changed (DESIGN §38.11, step 4).
 * Writes a minimal signal under users/{studentUid}/attendance/{courseId}_{classroomId}
 * for students only. Holds ids and timestamps only; the student's status is
 * read through REST so peers never see each other's status.
 */
class SignalAttendanceJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $courseId,
        public readonly int $classroomId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "{$this->courseId}:{$this->classroomId}";
    }

    public function handle(LiveStore $store): void
    {
        $fields = LiveClassroom::attendanceFields($this->courseId, $this->classroomId);
        $studentIds = LiveClassroom::studentIds($this->classroomId);
        $pathSuffix = '/attendance/'.$this->courseId.'_'.$this->classroomId;

        $ops = array_map(fn (int $id) => [
            'path' => FirestoreUid::path($id).$pathSuffix,
            'fields' => $fields,
        ], $studentIds);

        foreach (array_chunk($ops, FirestoreClient::MAX_WRITES) as $chunk) {
            $store->batch($chunk);
        }
    }
}
