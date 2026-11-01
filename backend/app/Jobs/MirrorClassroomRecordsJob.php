<?php

namespace App\Jobs;

use App\Domain\Firestore\FirestoreClient;
use App\Domain\Firestore\LiveRecords;
use App\Models\Classroom;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes a classroom's copy in Firestore equal to MariaDB (DESIGN §38.10):
 * writes what is there and deletes what is not, so a removed student, a
 * merged account or a deleted assignment leaves nothing behind. A classroom
 * that was deleted loses its whole copy. Carries ids only. Unlike the
 * per-record job it talks to the client directly: removals must not be lost,
 * so Firestore errors fail the job and it retries.
 */
class MirrorClassroomRecordsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Essays can reach 60 KB a hand-in and a commit takes 10 MiB. */
    private const COMMIT_SIZE = 50;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public int $timeout = 240;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $classroomId, public readonly bool $rosterOnly = false)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->classroomId.($this->rosterOnly ? ':roster' : ':all');
    }

    public function handle(): void
    {
        if (! LiveRecords::enabled()) {
            return;
        }
        $client = FirestoreClient::fromConfig();
        $classroom = Classroom::query()->find($this->classroomId);
        if ($classroom === null || $classroom->deleted_at !== null) {
            $client->deleteSubcollections(LiveRecords::classroomPath($this->classroomId));

            return;
        }
        $this->replace($client, LiveRecords::STUDENTS, LiveRecords::rosterDocuments($classroom));
        if ($this->rosterOnly) {
            return;
        }
        $this->replace($client, LiveRecords::SUBMISSIONS, LiveRecords::submissionDocuments($classroom));
        $this->replace($client, LiveRecords::GRADEBOOK, LiveRecords::gradebookDocuments($classroom));
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('firestore.records_mirror_failed', ['classroom_id' => $this->classroomId, 'message' => $e?->getMessage()]);
    }

    /** @param  iterable<int, array<string, mixed>>  $documents  fields by document id */
    private function replace(FirestoreClient $client, string $collection, iterable $documents): void
    {
        $kept = [];
        $writes = [];
        foreach ($documents as $id => $fields) {
            $path = LiveRecords::path($this->classroomId, $collection, $id);
            $kept[$path] = true;
            $writes[] = $client->setWrite($path, $fields);
            if (count($writes) === self::COMMIT_SIZE) {
                $client->commit($writes);
                $writes = [];
            }
        }
        $client->commit($writes);

        $pageToken = null;
        do {
            [$paths, $pageToken] = $client->documentPaths(
                LiveRecords::collectionPath($this->classroomId, $collection),
                FirestoreClient::MAX_WRITES,
                $pageToken,
            );
            $stale = array_values(array_filter($paths, fn (string $path) => ! isset($kept[$path])));
            $client->commit(array_map($client->deleteWrite(...), $stale));
        } while ($pageToken !== null);
    }
}
