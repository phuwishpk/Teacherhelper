<?php

namespace App\Domain\Classrooms;

use App\Exceptions\ApiException;
use App\Models\Classroom;
use Illuminate\Support\Facades\DB;

/**
 * A closed classroom ("ห้องเก่า", DESIGN §24.6) is read-only: every write on
 * it, or on anything that belongs to it, answers 409 `classroom_closed`.
 *
 * Writes addressed by a path id (`/assignments/{id}/...`) are stopped by the
 * EnsureClassroomOpen middleware through classroomIdOf(); writes that name
 * the classroom in the body (a new assignment, a scan's QR, the gradebook's
 * classroom_id, a course's classroom_ids) call assertOpen() where the
 * classroom is looked up.
 */
final class ClosedClassrooms
{
    /**
     * The first path segment of a write route => how to find the classroom
     * of the row named by the second segment (DESIGN §24.6).
     */
    private const RESOURCES = [
        'classrooms' => 'classroom',
        'assignments' => 'assignment',
        'exams' => 'assignment',
        'questions' => 'question',
        'question-options' => 'option',
        'exam-sections' => 'section',
        'scans' => 'scan',
        'exam-sheets' => 'scan',
        'responses' => 'response',
        'exam-responses' => 'response',
        'submissions' => 'submission',
        'appeals' => 'appeal',
        'gradebook-items' => 'gradebook_item',
        'analyses' => 'analysis',
        'google-submissions' => 'import',
        'grade-conflicts' => 'conflict',
    ];

    /** @throws ApiException 409 classroom_closed */
    public static function assertOpen(?Classroom $classroom): void
    {
        if ($classroom !== null && $classroom->isClosed()) {
            throw self::exception();
        }
    }

    /**
     * @param  iterable<int>  $classroomIds
     *
     * @throws ApiException 409 classroom_closed when any of them is closed
     */
    public static function assertAllOpen(iterable $classroomIds): void
    {
        $ids = [];
        foreach ($classroomIds as $id) {
            $ids[] = (int) $id;
        }
        if ($ids !== [] && Classroom::query()->whereIn('id', $ids)->whereNotNull('closed_at')->exists()) {
            throw self::exception();
        }
    }

    public static function exception(): ApiException
    {
        return new ApiException('ห้องนี้ปิดแล้ว (ห้องเก่า) ดูข้อมูลได้แต่แก้ไขไม่ได้', 'classroom_closed', 409);
    }

    /** Whether the first path segment names rows that belong to a classroom. */
    public static function knows(string $resource): bool
    {
        return isset(self::RESOURCES[$resource]);
    }

    /** The classroom id of row $id of $resource, or null when there is no such row. */
    public static function classroomIdOf(string $resource, int $id): ?int
    {
        $kind = self::RESOURCES[$resource] ?? null;
        $query = match ($kind) {
            'classroom' => DB::table('classrooms')->where('id', $id)->select('id as classroom_id'),
            'assignment' => DB::table('assignments')->where('id', $id)->select('classroom_id'),
            'question' => DB::table('questions')
                ->join('assignments', 'assignments.id', '=', 'questions.assignment_id')
                ->where('questions.id', $id)->select('assignments.classroom_id'),
            'option' => DB::table('question_options')
                ->join('questions', 'questions.id', '=', 'question_options.question_id')
                ->join('assignments', 'assignments.id', '=', 'questions.assignment_id')
                ->where('question_options.id', $id)->select('assignments.classroom_id'),
            'section' => DB::table('exam_sections')
                ->join('assignments', 'assignments.id', '=', 'exam_sections.assignment_id')
                ->where('exam_sections.id', $id)->select('assignments.classroom_id'),
            'scan' => DB::table('scans')
                ->join('submissions', 'submissions.id', '=', 'scans.submission_id')
                ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->where('scans.id', $id)->select('assignments.classroom_id'),
            'response' => DB::table('responses')
                ->join('submissions', 'submissions.id', '=', 'responses.submission_id')
                ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->where('responses.id', $id)->select('assignments.classroom_id'),
            'submission' => DB::table('submissions')
                ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->where('submissions.id', $id)->select('assignments.classroom_id'),
            'appeal' => DB::table('appeals')
                ->join('responses', 'responses.id', '=', 'appeals.response_id')
                ->join('submissions', 'submissions.id', '=', 'responses.submission_id')
                ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->where('appeals.id', $id)->select('assignments.classroom_id'),
            'gradebook_item' => DB::table('gradebook_items')->where('id', $id)->select('classroom_id'),
            'analysis' => DB::table('student_analyses')->where('id', $id)->select('classroom_id'),
            'import' => DB::table('classroom_submission_imports')
                ->join('assignments', 'assignments.id', '=', 'classroom_submission_imports.assignment_id')
                ->where('classroom_submission_imports.id', $id)->select('assignments.classroom_id'),
            'conflict' => DB::table('grade_conflicts')
                ->join('submissions', 'submissions.id', '=', 'grade_conflicts.submission_id')
                ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->where('grade_conflicts.id', $id)->select('assignments.classroom_id'),
            default => null,
        };
        $value = $query?->value('classroom_id');

        return $value === null ? null : (int) $value;
    }
}
