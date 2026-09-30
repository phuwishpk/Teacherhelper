<?php

namespace Tests\Feature\Exams;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * An exam created through the API by a teacher of a classroom with a course
 * (DESIGN §22.15), and sections added the same way.
 */
trait ExamTestHelpers
{
    protected User $teacher;

    protected Classroom $classroom;

    protected Course $course;

    protected function makeExamWorld(): void
    {
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function createExam(array $fields = []): Assignment
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $fields + [
            'classroom_id' => $this->classroom->id,
            'course_id' => $this->course->id,
            'title' => 'สอบกลางภาค',
            'kind' => 'exam',
            'due_at' => '2026-10-15T02:00:00Z',
            'duration_minutes' => 60,
        ])->assertCreated()->json('data.id');

        return Assignment::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed> the section payload
     */
    protected function addSection(Assignment $exam, array $fields): array
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/sections", $fields)
            ->assertCreated()
            ->json('data');
    }

    /** @return array<string, mixed> */
    protected function examJson(Assignment $exam): array
    {
        return $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}")->assertOk()->json('data');
    }

    /** A scanned page of the exam, as build step 3 will store one. */
    protected function scanSheet(Assignment $exam): void
    {
        $student = $this->enrollStudent($this->classroom, 99, 'นักเรียนสแกนแล้ว')['student'];
        $submission = Submission::create(['assignment_id' => $exam->id, 'student_id' => $student->id, 'status' => Submission::STATUS_NEEDS_REVIEW]);
        Scan::create([
            'client_scan_id' => (string) Str::uuid(), 'submission_id' => $submission->id, 'page_no' => 1, 'layout_version' => 1,
            'uploaded_by' => $this->teacher->id, 'scanned_at' => now(), 'blur_score' => 150.0, 'state' => Scan::STATE_ACTIVE,
            'page_image_path' => 'scans/page.webp',
        ]);
    }
}
