<?php

namespace App\Console\Commands;

use App\Domain\Firestore\FirestoreConfig;
use App\Domain\Firestore\LiveRecords;
use App\Models\Classroom;
use Illuminate\Console\Command;

/**
 * Queues every classroom's copy of the student records (Plesk: Scheduled Task
 * "Run now"; DESIGN §38.10). Run once after turning FIRESTORE_MIRROR_RECORDS
 * on, because earlier data is not copied by itself, and again to repair
 * copies a failed write left behind.
 */
class MirrorRecordsCommand extends Command
{
    protected $signature = 'eduvision:mirror-records';

    protected $description = 'Copy student names, numbers, codes, scores and answers to Firestore and remove stale copies (DESIGN §38.10)';

    public function handle(): int
    {
        if (! FirestoreConfig::recordsEnabled()) {
            $this->warn('FIRESTORE_MIRROR_RECORDS is off (it also needs FIRESTORE_ENABLED and FIREBASE_CREDENTIALS): nothing was queued.');

            return self::FAILURE;
        }
        $classroomIds = Classroom::query()->whereNull('deleted_at')->pluck('id');
        foreach ($classroomIds as $classroomId) {
            LiveRecords::queueClassroom((int) $classroomId);
        }
        $this->info("queued {$classroomIds->count()} classroom(s): the queue worker copies them over the next minutes");

        return self::SUCCESS;
    }
}
