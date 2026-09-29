<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Jobs\PushClassroomGradeJob;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomSubmissionImport;
use App\Models\GradeConflict;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "คะแนนไม่ตรงกัน" (DESIGN §19.3): the app's total is the real one, and a
 * grade the teacher changed on the Classroom website is noticed at the next
 * sync and put before the teacher.
 *
 * detect() compares Classroom's assignedGrade of the last sync
 * (classroom_submission_imports.classroom_grade) with the app's base value:
 * the grade the app pushed (pushed_grade, courseWork the app posted) or the
 * effective total of the published submission (courseWork created on the
 * Classroom website, which never gets a pushed grade). Only two numbers are
 * compared (rounded to 2 places): an empty assignedGrade, a grade never
 * pushed or a submission not published is no conflict. At most one open row
 * per import (kept up to date with the latest values, and dropped when the
 * two sides agree again); a dismissed row stops a new one while both sides
 * still have the values the teacher dismissed.
 *
 * resolve() is POST /grade-conflicts/{id}/resolve:
 *   push_app          send the app's effective total to Classroom again
 *                     (409 coursework_not_owned for website courseWork)
 *   accept_classroom  submissions.total_override = Classroom's grade, reason
 *                     "รับคะแนนจาก Classroom"; pushed_grade follows for app
 *                     courseWork. Per-question scores and mastery stay.
 *   dismiss           nothing changes; the row keeps the values seen
 * A row that is not open any more answers 409 conflict_resolved.
 */
final class GradeConflicts
{
    public const ACTIONS = ['push_app', 'accept_classroom', 'dismiss'];

    /**
     * Called by the sync after it stored classroom_grade on the row.
     */
    public static function detect(ClassroomSubmissionImport $import, AssignmentGoogleLink $posted): ?GradeConflict
    {
        $submission = $import->student_id === null ? null : Submission::query()
            ->where('assignment_id', $import->assignment_id)
            ->where('student_id', $import->student_id)
            ->first();
        $app = $submission === null ? null : self::base($import, $submission, $posted);
        $classroom = $import->classroom_grade === null ? null : round((float) $import->classroom_grade, 2);

        $open = GradeConflict::query()
            ->where('import_id', $import->id)
            ->where('status', GradeConflict::STATUS_OPEN)
            ->orderByDesc('id')
            ->first();

        if ($submission === null || $app === null || $classroom === null || self::same($app, $classroom)) {
            if ($open !== null && $app !== null && $classroom !== null) {
                // The two sides agree again (fixed on either side): nothing is left to resolve.
                $open->delete();
            }

            return null;
        }

        if ($open !== null) {
            $open->app_score = $app;
            $open->classroom_score = $classroom;
            $open->save();

            return $open;
        }

        $latest = GradeConflict::query()->where('import_id', $import->id)->orderByDesc('id')->first();
        if ($latest !== null && $latest->status === GradeConflict::STATUS_DISMISSED
            && $latest->app_score !== null && $latest->classroom_score !== null
            && self::same($latest->app_score, $app) && self::same($latest->classroom_score, $classroom)) {
            return null; // the teacher already chose to ignore exactly this difference
        }

        $conflict = GradeConflict::create([
            'submission_id' => $submission->id,
            'import_id' => $import->id,
            'app_score' => $app,
            'classroom_score' => $classroom,
            'status' => GradeConflict::STATUS_OPEN,
            'detected_at' => now(),
        ]);
        Log::info('google.grade_conflict', ['conflict_id' => $conflict->id, 'import_id' => $import->id, 'submission_id' => $submission->id]);

        return $conflict;
    }

    /**
     * @throws ApiException 409 conflict_resolved / coursework_not_owned, 422 validation_failed
     */
    public function resolve(GradeConflict $conflict, string $action, User $teacher): GradeConflict
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new ApiException('เลือกวิธีแก้ไม่ถูกต้อง', 'validation_failed', 422, ['action' => ['ต้องเป็น push_app, accept_classroom หรือ dismiss']]);
        }

        $push = false;
        $resolved = DB::transaction(function () use ($conflict, $action, $teacher, &$push) {
            $locked = GradeConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if (! $locked->isOpen()) {
                throw new ApiException('รายการนี้จัดการไปแล้ว', 'conflict_resolved', 409);
            }
            $import = ClassroomSubmissionImport::query()->lockForUpdate()->findOrFail($locked->import_id);
            $submission = Submission::query()->lockForUpdate()->findOrFail($locked->submission_id);
            $posted = AssignmentGoogleLink::query()->findOrFail($import->assignment_id);
            $classroom = $import->classroom_grade !== null ? round((float) $import->classroom_grade, 2) : $locked->classroom_score;

            switch ($action) {
                case 'push_app':
                    if ($posted->isFromClassroomWeb()) {
                        throw self::notOwned();
                    }
                    $locked->status = GradeConflict::STATUS_PUSHED_APP;
                    $locked->app_score = $submission->effectiveTotal();
                    $locked->classroom_score = $classroom;
                    $push = true;
                    break;
                case 'accept_classroom':
                    $locked->app_score = $submission->effectiveTotal();
                    $locked->classroom_score = $classroom;
                    $locked->status = GradeConflict::STATUS_ACCEPTED_CLASSROOM;
                    $locked->reason = GradeConflict::ACCEPT_REASON;
                    $submission->total_override = $classroom;
                    $submission->save();
                    if (! $posted->isFromClassroomWeb()) {
                        // The base of the next comparison is what Classroom now shows.
                        $import->pushed_grade = $classroom;
                        $import->save();
                    }
                    break;
                default:
                    $locked->app_score = self::base($import, $submission, $posted) ?? $locked->app_score;
                    $locked->classroom_score = $classroom;
                    $locked->status = GradeConflict::STATUS_DISMISSED;
            }
            $locked->resolved_by = $teacher->id;
            $locked->resolved_at = now();
            $locked->save();

            return $locked;
        });

        if ($push) {
            PushClassroomGradeJob::dispatch($resolved->submission_id);
        }
        Log::info('google.grade_conflict_resolved', ['conflict_id' => $resolved->id, 'status' => $resolved->status]);

        return $resolved;
    }

    public static function notOwned(): ApiException
    {
        return new ApiException(
            'งานนี้สร้างในเว็บ Classroom แอปส่งคะแนนกลับให้ไม่ได้ ใช้ปุ่ม "เปิดใน Classroom" หรือ "คัดลอกคะแนน" แทน',
            'coursework_not_owned',
            409,
        );
    }

    /** The app's side of the comparison, or null when there is nothing to compare yet. */
    private static function base(ClassroomSubmissionImport $import, Submission $submission, AssignmentGoogleLink $posted): ?float
    {
        if ($posted->isFromClassroomWeb()) {
            return $submission->isPublished() ? $submission->effectiveTotal() : null;
        }

        return $import->pushed_grade === null ? null : round((float) $import->pushed_grade, 2);
    }

    private static function same(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) < 0.005;
    }
}
