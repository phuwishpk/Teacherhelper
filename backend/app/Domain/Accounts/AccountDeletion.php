<?php

namespace App\Domain\Accounts;

use App\Domain\Firestore\FirestoreConfig;
use App\Jobs\PurgeLiveUserJob;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * An admin deletes a teacher or student account (DESIGN §31). Deleting
 * blocks the account at once and keeps everything for RESTORE_DAYS:
 *
 * - users.deleted_at / deleted_by are set and every token is revoked;
 *   User::isActive() is false from then on, so every login path refuses it;
 * - a teacher's own classrooms (classrooms.teacher_id) get the same
 *   deleted_at and are closed ("ห้องเก่า", read-only, no joins, no sync) with
 *   closed_at = that moment, unless they were closed already.
 *
 * Restoring clears both and reopens exactly the classrooms the deletion
 * closed (closed_at equal to deleted_at); classrooms closed before stay
 * closed. Nothing is erased here: the purge after RESTORE_DAYS is §31.3.
 */
final class AccountDeletion
{
    public const RESTORE_DAYS = 30;

    /** Teachers and students, never an admin, never the acting admin, not a merged account. */
    public static function deletable(User $admin, User $target): bool
    {
        return ($target->isTeacher() || $target->isStudent())
            && (int) $target->id !== (int) $admin->id
            && ! $target->isDeleted()
            && ! $target->isMerged();
    }

    /** When the account stops being restorable and is purged. */
    public static function purgeAt(User $user): ?CarbonInterface
    {
        return $user->deleted_at?->copy()->addDays(self::RESTORE_DAYS);
    }

    /** Deleted, and the 30 days to restore are not over (AccountPurge runs after them, §31.3). */
    public static function restorable(User $user): bool
    {
        $purgeAt = self::purgeAt($user);

        return $purgeAt !== null && $purgeAt->isFuture();
    }

    /**
     * What the deletion takes away with a teacher: their own classrooms,
     * the students in them and the hand-ins there (for the confirmation).
     *
     * @return array{classrooms: int, students: int, submissions: int}
     */
    public static function teacherSummary(User $teacher): array
    {
        $rooms = Classroom::query()->where('teacher_id', $teacher->id)->whereNull('deleted_at')->pluck('id');

        return [
            'classrooms' => $rooms->count(),
            'students' => (int) DB::table('classroom_students')->whereIn('classroom_id', $rooms)->distinct()->count('student_id'),
            'submissions' => (int) DB::table('submissions')
                ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->whereIn('assignments.classroom_id', $rooms)
                ->count(),
        ];
    }

    public function delete(User $admin, User $target): void
    {
        $now = now()->startOfSecond();
        DB::transaction(function () use ($admin, $target, $now) {
            $target->forceFill(['deleted_at' => $now, 'deleted_by' => $admin->id])->save();
            $target->tokens()->delete();
            if ($target->isTeacher()) {
                $rooms = Classroom::query()->where('teacher_id', $target->id)->whereNull('deleted_at');
                (clone $rooms)->whereNull('closed_at')->update(['closed_at' => $now, 'closed_by' => $admin->id]);
                $rooms->update(['deleted_at' => $now]);
            }
            AuditLog::record($admin, AuditLog::ACCOUNT_DELETE, $target, ['purge_at' => self::purgeAt($target)?->toIso8601String()]);
        });
        Log::info('account.delete', ['admin_id' => $admin->id, 'user_id' => $target->id, 'role' => $target->role]);
        // The live layer's copy and Firebase session go now, not after 30
        // days: they are notification copies, rebuilt on a restore (§38.8).
        if (FirestoreConfig::enabled()) {
            PurgeLiveUserJob::dispatch($target->id);
        }
    }

    public function restore(User $admin, User $target): void
    {
        $deletedAt = $target->deleted_at;
        if ($deletedAt === null || ! self::restorable($target)) {
            return;
        }
        DB::transaction(function () use ($admin, $target, $deletedAt) {
            if ($target->isTeacher()) {
                $rooms = Classroom::query()->where('teacher_id', $target->id)->where('deleted_at', $deletedAt);
                (clone $rooms)->where('closed_at', $deletedAt)->update(['closed_at' => null, 'closed_by' => null]);
                $rooms->update(['deleted_at' => null]);
            }
            $target->forceFill(['deleted_at' => null, 'deleted_by' => null])->save();
            AuditLog::record($admin, AuditLog::ACCOUNT_RESTORE, $target);
        });
        Log::info('account.restore', ['admin_id' => $admin->id, 'user_id' => $target->id]);
    }
}
