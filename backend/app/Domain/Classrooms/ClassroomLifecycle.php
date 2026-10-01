<?php

namespace App\Domain\Classrooms;

use App\Domain\Exams\ExamImages;
use App\Domain\Worksheets\WorksheetFiles;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Closing, reopening and deleting a classroom (DESIGN §24.6).
 *
 * A closed classroom ("ห้องเก่า") stays readable and exportable and its
 * class code still logs students in, but takes no write (ClosedClassrooms).
 * A classroom is deleted only while it holds nothing worth keeping: no
 * submission of any status, no gradebook entry and no gradebook publication.
 * Student accounts always stay.
 */
final class ClassroomLifecycle
{
    public function close(Classroom $classroom, User $actor): Classroom
    {
        if (! $classroom->isClosed()) {
            DB::transaction(function () use ($classroom, $actor) {
                $classroom->forceFill(['closed_at' => now(), 'closed_by' => $actor->id])->save();
                // Pending course requests of a closed classroom are cancelled (§24.6).
                CourseRequests::cancelAllPending($classroom, $actor);
            });
        }

        return $classroom;
    }

    public function reopen(Classroom $classroom): Classroom
    {
        if ($classroom->isClosed()) {
            $classroom->forceFill(['closed_at' => null, 'closed_by' => null])->save();
        }

        return $classroom;
    }

    /**
     * What keeps the classroom from being deleted (all zero = deletable).
     *
     * @return array{submissions: int, gradebook_entries: int, gradebook_publications: int}
     */
    public static function blockers(Classroom $classroom): array
    {
        return [
            'submissions' => DB::table('submissions')
                ->whereIn('assignment_id', DB::table('assignments')->select('id')->where('classroom_id', $classroom->id))
                ->count(),
            'gradebook_entries' => DB::table('gradebook_entries')->where('classroom_id', $classroom->id)->count(),
            'gradebook_publications' => DB::table('gradebook_publications')->where('classroom_id', $classroom->id)->count(),
        ];
    }

    /**
     * Deletes the classroom with its assignments and exams (and their printed
     * files), roster rows, course bindings, Google links and analyses.
     *
     * @throws ApiException 409 classroom_has_data {counts}
     */
    public function delete(Classroom $classroom): void
    {
        $files = [];
        $directories = [];
        $exams = [];

        DB::transaction(function () use ($classroom, &$files, &$directories, &$exams) {
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();
            $counts = self::blockers($classroom);
            if (array_sum($counts) > 0) {
                throw new ApiException(
                    'ห้องนี้มีงานหรือคะแนนแล้ว ลบไม่ได้ ใช้ "ปิดห้อง" แทน',
                    'classroom_has_data',
                    409,
                    [],
                    ['counts' => $counts],
                );
            }

            $assignments = Assignment::query()->where('classroom_id', $classroom->id)->get();
            foreach ($assignments as $assignment) {
                foreach (WorksheetPrint::query()->where('assignment_id', $assignment->id)->get() as $print) {
                    if ($print->file_path !== null) {
                        $files[] = $print->file_path;
                    }
                    $directories[] = WorksheetFiles::partsDirectory($print);
                }
                WorksheetPrint::query()->where('assignment_id', $assignment->id)->delete();
                DB::table('classroom_submission_imports')->where('assignment_id', $assignment->id)->delete();
                $assignment->delete();
                if ($assignment->isExam()) {
                    $exams[] = $assignment;
                }
            }

            $files = [...$files, ...DB::table('login_card_prints')->where('classroom_id', $classroom->id)->whereNotNull('file_path')->pluck('file_path')->all()];
            // Everything else that belongs to the classroom goes with it (ON DELETE CASCADE).
            $classroom->delete();
        });

        $disk = Storage::disk('local');
        foreach ($files as $path) {
            $disk->delete($path);
        }
        foreach ($directories as $directory) {
            $disk->deleteDirectory($directory);
        }
        foreach ($exams as $exam) {
            ExamImages::deleteExam($exam);
        }
    }
}
