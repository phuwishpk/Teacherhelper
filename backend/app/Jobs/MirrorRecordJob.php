<?php

namespace App\Jobs;

use App\Domain\Firestore\LiveRecords;
use App\Domain\Firestore\LiveStore;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies one hand-in or gradebook score to classrooms/{id}/… (DESIGN §38.10),
 * or removes the copy when the row is gone. Reads the row when it runs, so
 * grading a whole paper leaves one pending job per hand-in. Never fails:
 * LiveStore swallows Firestore errors and eduvision:mirror-records repairs.
 */
class MirrorRecordJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $collection,
        public readonly int $recordId,
        public readonly int $classroomId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->collection.':'.$this->recordId;
    }

    public function handle(LiveStore $store): void
    {
        if (! LiveRecords::enabled()) {
            return;
        }
        $path = LiveRecords::path($this->classroomId, $this->collection, $this->recordId);
        $fields = LiveRecords::fields($this->collection, $this->recordId);
        if ($fields === null) {
            $store->delete($path);

            return;
        }
        $store->set($path, $fields);
    }
}
