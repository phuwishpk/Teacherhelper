<?php

namespace App\Domain\Assignments;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use Illuminate\Support\Facades\DB;

/**
 * Serialises changes to one assignment's questions, rubric and layout: every
 * mutation runs in a transaction holding the assignment row lock, so two
 * requests cannot interleave position shifts or build two layout versions.
 */
final class AssignmentLocked
{
    /**
     * @template T
     *
     * @param  callable(Assignment): T  $work
     * @return T
     */
    public static function run(int $assignmentId, callable $work, bool $allowClosed = false): mixed
    {
        return DB::transaction(function () use ($assignmentId, $work, $allowClosed) {
            $assignment = Assignment::query()->lockForUpdate()->findOrFail($assignmentId);
            if (! $allowClosed && $assignment->isClosed()) {
                throw new ApiException('การบ้านนี้ปิดแล้ว แก้ไขไม่ได้', 'assignment_closed', 409);
            }

            return $work($assignment);
        });
    }
}
