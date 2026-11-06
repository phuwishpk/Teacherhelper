<?php

namespace App\Jobs;

use App\Domain\Google\ClassroomAttachmentFetcher;
use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Downloads the Drive files of one Classroom hand-in on the server and
 * starts its whole-page grading (DESIGN §19.4, §19.10), one submission per
 * job. Unique per row while queued, so syncing again before the cron worker
 * ran does not queue it twice. Google unreachable: retried with backoff.
 */
class FetchClassroomAttachmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'grading';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** Downloads of up to 5 files of 10 MB fit well inside the 50 s worker pass in practice. */
    public int $timeout = 120;

    /** Seconds the uniqueness lock lives if the job never runs. */
    public int $uniqueFor = 900;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->importId;
    }

    /**
     * Queues the rows of the assignment that wait for their files and whose
     * submitter is matched to a student.
     *
     * @return int rows queued
     */
    public static function dispatchForNewRows(Assignment $assignment): int
    {
        $matched = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->whereNotNull('google_user_id')
            ->pluck('google_user_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        if ($matched === [] || $assignment->isClosed()) {
            return 0;
        }

        $ids = ClassroomSubmissionImport::query()
            ->where('assignment_id', $assignment->id)
            ->where('state', ClassroomSubmissionImport::STATE_NEW)
            ->whereIn('google_user_id', $matched)
            ->orderBy('id')
            ->pluck('id');
        foreach ($ids as $id) {
            self::dispatch((int) $id);
        }

        return $ids->count();
    }

    public function handle(ClassroomAttachmentFetcher $fetcher): void
    {
        $outcome = $fetcher->fetch($this->importId);
        Log::info('google.attachments_fetched', ['import_id' => $this->importId, 'outcome' => $outcome]);
    }

    public function failed(?Throwable $exception): void
    {
        ClassroomSubmissionImport::query()
            ->whereKey($this->importId)
            ->where('state', ClassroomSubmissionImport::STATE_NEW)
            ->update(['last_error' => 'ดึงไฟล์จาก Google ไม่สำเร็จ จะลองใหม่เมื่อกด "ดึงงานที่ส่ง"', 'updated_at' => now()]);
    }
}
