<?php

namespace App\Domain\Classrooms;

use App\Domain\Exams\ExamImages;
use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Pages\PageFiles;
use App\Domain\Scans\ScanFiles;
use App\Domain\Worksheets\WorksheetFiles;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Contracts\Filesystem\Filesystem;
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
 *
 * purge() is the one exception (DESIGN §31.3): the classrooms of a teacher
 * whose account was deleted 30 days ago go with everything in them.
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
     * ร / มส (gradebook_special_grades) count too: they cascade with the
     * classroom and would otherwise be dropped without a word.
     *
     * @return array{submissions: int, gradebook_entries: int, gradebook_special_grades: int, gradebook_publications: int}
     */
    public static function blockers(Classroom $classroom): array
    {
        return [
            'submissions' => DB::table('submissions')
                ->whereIn('assignment_id', DB::table('assignments')->select('id')->where('classroom_id', $classroom->id))
                ->count(),
            'gradebook_entries' => DB::table('gradebook_entries')->where('classroom_id', $classroom->id)->count(),
            'gradebook_special_grades' => DB::table('gradebook_special_grades')->where('classroom_id', $classroom->id)->count(),
            'gradebook_publications' => DB::table('gradebook_publications')->where('classroom_id', $classroom->id)->count(),
        ];
    }

    /**
     * Deletes the classroom with its assignments and exams (and their printed
     * files), roster rows, course bindings, Google links and analyses.
     *
     * @throws ApiException 409 classroom_has_data {counts}
     */
    /**
     * The rows that point at a purged classroom's assignments, hand-ins and
     * answers with RESTRICT or NO ACTION, deleted here bottom-up (everything
     * else CASCADEs or SET NULLs). ClassroomPurgeCoverageTest fails when a
     * new table points into this tree without being listed or cascading.
     */
    public const PURGED_EXPLICITLY = [
        'appeals.response_id',
        'score_events.response_id',
        'skill_observations.response_id',
        'training_samples.response_id',
        'responses.submission_id',
        'responses.scan_id',
        'responses.question_id',
        'grade_conflicts.submission_id',
        'grade_conflicts.import_id',
        'classroom_submission_imports.assignment_id',
        'scans.submission_id',
        'submission_pages.submission_id',
        'submissions.assignment_id',
        'exam_sheet_reads.assignment_id',
        'worksheet_prints.assignment_id',
        'assignments.classroom_id',
    ];

    /**
     * Deletes the classroom with every assignment, hand-in, answer, score and
     * file in it, whatever it holds (DESIGN §31.3; delete() refuses that).
     * Student accounts stay; their mastery is recomputed without the answers
     * that went. Returns what went.
     *
     * @return array{assignments: int, submissions: int, files: int}
     */
    public function purge(Classroom $classroom): array
    {
        $files = ['scan' => [], 'page' => [], 'local' => []];
        $directories = [];
        $exams = [];
        $pairs = [];
        $counts = ['assignments' => 0, 'submissions' => 0, 'files' => 0];

        DB::transaction(function () use ($classroom, &$files, &$directories, &$exams, &$pairs, &$counts) {
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();

            $assignments = Assignment::query()->where('classroom_id', $classroom->id)->get();
            $assignmentIds = $assignments->modelKeys();
            $submissions = DB::table('submissions')->select('id')->whereIn('assignment_id', $assignmentIds);
            $responses = DB::table('responses')->select('id')->whereIn('submission_id', $submissions);
            $imports = DB::table('classroom_submission_imports')->select('id')->whereIn('assignment_id', $assignmentIds);

            // Files first, while the rows still say where they are.
            foreach (DB::table('responses')->whereIn('submission_id', $submissions)->get(['crop_path', 'final_crop_path']) as $r) {
                array_push($files['scan'], ...array_filter([$r->crop_path, $r->final_crop_path]));
            }
            array_push($files['scan'], ...DB::table('scans')->whereIn('submission_id', $submissions)->whereNotNull('page_image_path')->pluck('page_image_path')->all());
            array_push($files['scan'], ...DB::table('training_samples')->whereIn('response_id', $responses)->whereNotNull('crop_path')->pluck('crop_path')->all());
            array_push($files['page'], ...DB::table('submission_pages')->whereIn('submission_id', $submissions)->whereNotNull('file_path')->pluck('file_path')->all());
            foreach (WorksheetPrint::query()->whereIn('assignment_id', $assignmentIds)->get() as $print) {
                if ($print->file_path !== null) {
                    $files['local'][] = $print->file_path;
                }
                $directories[] = WorksheetFiles::partsDirectory($print);
            }
            array_push($files['local'], ...DB::table('login_card_prints')->where('classroom_id', $classroom->id)->whereNotNull('file_path')->pluck('file_path')->all());

            // Mastery to recompute once the observations are gone.
            $pairs = DB::table('skill_observations')->whereIn('response_id', $responses)
                ->distinct()->get(['student_id', 'skill_id'])
                ->map(fn ($row) => [(int) $row->student_id, (int) $row->skill_id])->all();

            $counts['assignments'] = count($assignmentIds);
            $counts['submissions'] = DB::table('submissions')->whereIn('assignment_id', $assignmentIds)->count();

            // Bottom-up: answers, then hand-ins, then assignments (PURGED_EXPLICITLY).
            foreach (['appeals', 'score_events', 'skill_observations', 'training_samples'] as $table) {
                DB::table($table)->whereIn('response_id', $responses)->delete();
            }
            DB::table('responses')->whereIn('submission_id', $submissions)->delete();
            DB::table('grade_conflicts')->where(fn ($q) => $q->whereIn('submission_id', $submissions)->orWhereIn('import_id', $imports))->delete();
            DB::table('classroom_submission_imports')->whereIn('assignment_id', $assignmentIds)->delete();
            DB::table('scans')->whereIn('submission_id', $submissions)->delete();
            DB::table('submission_pages')->whereIn('submission_id', $submissions)->delete();
            DB::table('submissions')->whereIn('assignment_id', $assignmentIds)->delete();
            DB::table('exam_sheet_reads')->whereIn('assignment_id', $assignmentIds)->delete();
            WorksheetPrint::query()->whereIn('assignment_id', $assignmentIds)->delete();
            foreach ($assignments as $assignment) {
                $exams[] = $assignment;
                $assignment->delete();
            }
            // Everything else that belongs to the classroom goes with it (ON DELETE CASCADE).
            $classroom->delete();
        });

        $counts['files'] = $this->deleteFiles(ScanFiles::disk(), $files['scan'])
            + $this->deleteFiles(PageFiles::disk(), $files['page'])
            + $this->deleteFiles(Storage::disk('local'), $files['local']);
        foreach ($directories as $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        }
        foreach ($exams as $exam) {
            // Question images, exam pages and figures live under the assignment's folder.
            ExamImages::deleteExam($exam);
        }
        $mastery = app(MasteryCalculator::class);
        foreach ($pairs as [$studentId, $skillId]) {
            $mastery->recompute($studentId, $skillId);
        }

        return $counts;
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteFiles(Filesystem $disk, array $paths): int
    {
        $paths = array_values(array_unique(array_filter($paths)));
        foreach (array_chunk($paths, 200) as $chunk) {
            $disk->delete($chunk);
        }

        return count($paths);
    }

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
