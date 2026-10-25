<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Jobs\ImportCourseWorkJob;
use App\Jobs\SyncClassroomRosterJob;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\GoogleAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One round of the two-way sync with Google Classroom (DESIGN §19.3), run by
 * ClassroomSyncJob from the every-minute cron (at most every 5 minutes) or
 * for one classroom by "ซิงก์ตอนนี้" (POST /classrooms/{id}/google-sync).
 * No Pub/Sub, no daemon: shared hosting.
 *
 * Scope: every course linked to an open classroom (one per teacher since
 * build 4, DESIGN §24.10) whose linking teacher's Google account works (an
 * account marked needs_reconnect is skipped until it connects again). Each
 * assignment syncs through the course of the teacher who manages it.
 *
 * 1. courseWork.list per course: new courseWork created on the Classroom
 *    website is mirrored (ImportCourseWorkJob, one per courseWork);
 * 2. the submissions of posted or imported assignments that are not closed
 *    (GoogleSubmissionSync: new hand-ins, late policy, Classroom's grades
 *    and grade conflicts, auto roster sync for unknown submitters), the
 *    assignment synced longest ago first, at most
 *    CLASSROOM_SYNC_MAX_COURSEWORK per round;
 * 3. the cron round only: a SyncClassroomRosterJob for each classroom whose
 *    roster was not synced for CLASSROOM_ROSTER_SYNC_MINUTES (queueRosterSyncs).
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
     * @return array{courses: int, imports: int, assignments: int, failed: int, rosters: int}
     */
    public function run(?int $classroomId = null): array
    {
        $deadline = microtime(true) + max(1, (int) config('eduvision.classroom_sync.budget_seconds'));
        $stats = ['courses' => 0, 'imports' => 0, 'assignments' => 0, 'failed' => 0, 'rosters' => 0];

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
                    ImportCourseWorkJob::dispatch($link->classroom_id, $work, $link->id);
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
                ->whereIn('classroom_id', $links->isEmpty() ? [0] : $links->pluck('classroom_id')->unique()->values()->all())
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

        if ($classroomId === null) {
            $stats['rosters'] = $this->queueRosterSyncs($links);
        }

        Log::info('google.sync_round', ['classroom_id' => $classroomId] + $stats);

        return $stats;
    }

    /**
     * Rosters follow Classroom without the teacher (DESIGN §19.3): a classroom
     * whose courses were not synced for CLASSROOM_ROSTER_SYNC_MINUTES gets a
     * SyncClassroomRosterJob, so a student who joins the homeroom course is
     * added and matched on their own, and their first Google sign-in needs no
     * PIN (§24.9.3). Oldest first, at most CLASSROOM_SYNC_MAX_ROSTERS per
     * round. A classroom is tried at most once per interval even when its
     * sync fails, so a course that cannot be read does not hold the slots.
     *
     * @param  Collection<int, ClassroomGoogleLink>  $links  the round's links (open classrooms, working accounts)
     * @return int jobs queued
     */
    private function queueRosterSyncs(Collection $links): int
    {
        $minutes = max(1, (int) config('eduvision.classroom_sync.roster_minutes'));
        $limit = max(1, (int) config('eduvision.classroom_sync.max_rosters'));
        $dueBefore = now()->subMinutes($minutes)->getTimestamp();

        $oldest = $links->groupBy('classroom_id')
            ->map(fn (Collection $rows) => (int) $rows->min(fn (ClassroomGoogleLink $link) => $link->roster_synced_at?->getTimestamp() ?? 0))
            ->filter(fn (int $syncedAt) => $syncedAt <= $dueBefore)
            ->sort();

        $queued = 0;
        foreach ($oldest->keys() as $classroomId) {
            if ($queued >= $limit) {
                break;
            }
            if (! Cache::add("classroom-roster-sync:due:{$classroomId}", now()->toIso8601String(), $minutes * 60)) {
                continue;
            }
            if (SyncClassroomRosterJob::dispatchOncePerRound((int) $classroomId)) {
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * @return Collection<int, ClassroomGoogleLink>
     */
    private function links(?int $classroomId): Collection
    {
        return ClassroomGoogleLink::query()
            ->when($classroomId !== null, fn ($q) => $q->where('classroom_id', $classroomId))
            // A closed classroom is read-only (DESIGN §24.6): the sync passes it by.
            ->whereIn('classroom_id', Classroom::query()->select('id')->whereNull('closed_at'))
            ->whereIn('owner_user_id', GoogleAccount::query()->whereNull('last_error')->select('user_id'))
            ->orderBy('work_synced_at')
            ->orderBy('classroom_id')
            ->orderBy('id')
            ->get();
    }
}
