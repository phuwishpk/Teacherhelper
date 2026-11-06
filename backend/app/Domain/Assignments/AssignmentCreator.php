<?php

namespace App\Domain\Assignments;

use App\Domain\ClassPosts\AttachmentInput;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Courses\AssignmentCourses;
use App\Domain\Gradebook\AssignmentCategories;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Creates an assignment or an exam (POST /assignments, DESIGN §9.3, §22.1),
 * also used by the assistant for a new assignment of a plan card (§37.6).
 * $v holds validated StoreAssignmentRequest fields; $hasCategory tells
 * whether gradebook_category_id was sent at all (null = "no category").
 */
final class AssignmentCreator
{
    /**
     * @param  array<string, mixed>  $v
     *
     * @throws ApiException|ValidationException
     */
    public function create(User $teacher, array $v, bool $hasCategory = false): Assignment
    {
        $classroom = Classroom::query()->findOrFail($v['classroom_id'] ?? null);
        ClosedClassrooms::assertOpen($classroom); // §24.6
        // Every new assignment belongs to a course of its classroom (§20.1).
        $course = AssignmentCourses::courseFor($teacher, $classroom->id, ($v['course_id'] ?? null));
        $plan = AssignmentCourses::planFor($course, ($v['lesson_plan_id'] ?? null));

        $exam = ($v['kind'] ?? null) === Assignment::KIND_EXAM;
        $grading = $exam ? ($v['grading_method'] ?? Assignment::GRADING_APP) : null;
        $codeSheets = $exam && ($v['sheet_identity'] ?? null) === Assignment::IDENTITY_CODE; // §22.19
        if ($exam && ($v['mode'] ?? Assignment::MODE_WORKSHEET) !== Assignment::MODE_WORKSHEET) {
            throw ValidationException::withMessages(['mode' => 'ข้อสอบไม่ใช้โหมด freeform']);
        }

        $categoryId = AssignmentCategories::forNew($course->id, $exam, $hasCategory, ($v['gradebook_category_id'] ?? null));
        [$unitId, $topic] = AssignmentPosts::topic($course, ($v['unit_id'] ?? null), ($v['topic'] ?? null));
        $attachments = AttachmentInput::parse($teacher, ($v['attachments'] ?? null) ?? []);

        $assignment = Assignment::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'subject_id' => $course->subject_id,
            'course_id' => $course->id,
            'lesson_plan_id' => $plan?->id,
            'created_by' => $teacher->id,
            'title' => trim($v['title'] ?? null),
            'strictness' => ($v['strictness'] ?? null) ?? 'normal',
            'status' => Assignment::STATUS_DRAFT,
            'due_at' => self::utc($v['due_at'] ?? null),
            'mode' => ($v['mode'] ?? null) ?? Assignment::MODE_WORKSHEET,
            'accept_late' => (bool) ($v['accept_late'] ?? true),
            'score_only' => (bool) ($v['score_only'] ?? false),
            'kind' => $exam ? Assignment::KIND_EXAM : Assignment::KIND_HOMEWORK,
            'grading_method' => $grading,
            'version_count' => $exam ? (int) ($v['version_count'] ?? 1) : 1,
            'duration_minutes' => $exam ? ($v['duration_minutes'] ?? null) : null,
            'show_key_to_students' => $exam && (bool) ($v['show_key_to_students'] ?? false),
            'manual_full_marks' => $exam ? ($v['manual_full_marks'] ?? null) : null,
            'sheet_identity' => $codeSheets ? Assignment::IDENTITY_CODE : Assignment::IDENTITY_QR,
            'student_code_digits' => $codeSheets ? (int) ($v['student_code_digits'] ?? null) : null,
            'gradebook_category_id' => $categoryId,
            'excluded_from_grade' => (bool) ($v['excluded_from_grade'] ?? false),
            'unit_id' => $unitId,
            'topic' => $topic,
            'instructions' => AssignmentPosts::text($v['instructions'] ?? null),
            'send_to_google' => (bool) ($v['send_to_google'] ?? true),
        ]);
        AttachmentInput::write($assignment->attachments(), $attachments);
        AssignmentPosts::setRecipients($assignment, AssignmentPosts::recipients($assignment, ($v['audience'] ?? null), ($v['student_ids'] ?? null)));
        if ($grading === Assignment::GRADING_MANUAL) {
            // No key gate for an exam graded by hand: ready from the start (DESIGN §22.1).
            $assignment->status = Assignment::STATUS_READY;
            $assignment->save();
        }

        return $assignment;
    }

    private static function utc(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }
}
