<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Jobs\ImportCourseWorkJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use App\Models\GoogleAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * One round of the two-way sync with Google Classroom (DESIGN §19.3), run by
 * ClassroomSyncJob from the every-minute cron (at most every 5 minutes) or
 * for one classroom by "ซิงก์ตอนนี้" (POST /classrooms/{id}/google-sync).
 * No Pub/Sub, no daemon: shared hosting.
 *
 * Scope: every linked classroom whose linking teacher's Google account works
 * (an account marked needs_reconnect is skipped until it connects again).
 *
 * 1. courseWork.list per course: new courseWork created on the Classroom
 *    website is mirrored (ImportCourseWorkJob, one per courseWork);
 * 2. the submissions of posted or imported assignments that are not closed
 *    (GoogleSubmissionSync: new hand-ins, late policy, Classroom's grades
 *    and grade conflicts, auto roster sync for unknown submitters), the
 *    assignment synced longest ago first, at most
 *    CLASSROOM_SYNC_MAX_COURSEWORK per round.
 *
 * The round stops starting new work after CLASSROOM_SYNC_BUDGET_SECONDS
 * (40 s, inside the 50-second worker pass); what is left goes first next
 * round. A Google error of one course or assignment is logged and the round
 * goes on.
 */
final class ClassroomSync
{
    public function __construct(
        private readonly GoogleSubmissionSync $submissions,
        private readonly GoogleAccessTokens $tokens,
    ) {}

    /**
     * @return array{courses: int, imports: int, assignments: int, failed: int}
     */
    public function run(?int $classroomId = null): array
    {
        $deadline = microtime(true) + max(1, (int) config('eduvision.classroom_sync.budget_seconds'));
        $stats = ['courses' => 0, 'imports' => 0, 'assignments' => 0, 'failed' => 0];

        $links = $this->links($classroomId);
        foreach ($links as $link) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $account = GoogleAccount::query()->find($link->owner_user_id);
            if ($account === null || $account->needsReconnect()) {
                continue;
            }
            try {
                foreach (CourseWorkImporter::newCourseWork(GoogleApi::forAccount($account, $this->tokens), $link) as $work) {
                    ImportCourseWorkJob::dispatch($link->classroom_id, $work);
                    $stats['imports']++;
                }
                $stats['courses']++;
            } catch (GoogleApiException $e) {
                $stats['failed']++;
                Log::warning('google.coursework_list_failed', ['classroom_id' => $link->classroom_id, 'kind' => $e->kind]);
            }
            $link->work_synced_at = now();
            $link->save();
        }

        $posted = AssignmentGoogleLink::query()
            ->whereIn('assignment_id', Assignment::query()
                ->whereIn('classroom_id', $links->modelKeys() === [] ? [0] : $links->modelKeys())
                ->where('status', '!=', Assignment::STATUS_CLOSED)
                ->select('id'))
            ->orderBy('last_synced_at')
            ->orderBy('assignment_id')
            ->limit(max(1, (int) config('eduvision.classroom_sync.max_coursework')))
            ->get();
        foreach ($posted as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $assignment = Assignment::query()->find($row->assignment_id);
            if ($assignment === null) {
                continue;
            }
            try {
                $this->submissions->syncAsOwner($assignment);
                $stats['assignments']++;
            } catch (ApiException $e) {
                $stats['failed']++;
                Log::warning('google.submission_sync_failed', ['assignment_id' => $assignment->id, 'code' => $e->errorCode]);
                // Rotate: the next round starts with the others.
                $row->last_synced_at = now();
                $row->save();
            }
        }

        Log::info('google.sync_round', ['classroom_id' => $classroomId] + $stats);

        return $stats;
    }

    /**
     * @return Collection<int, ClassroomGoogleLink>
     */
    private function links(?int $classroomId): Collection
    {
        return ClassroomGoogleLink::query()
            ->when($classroomId !== null, fn ($q) => $q->where('classroom_id', $classroomId))
            ->whereIn('owner_user_id', GoogleAccount::query()->whereNull('last_error')->select('user_id'))
            ->orderBy('work_synced_at')
            ->orderBy('classroom_id')
            ->get();
    }
}
