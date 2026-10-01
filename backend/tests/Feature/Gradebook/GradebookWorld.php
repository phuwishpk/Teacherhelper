<?php

namespace Tests\Feature\Gradebook;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * A teacher with a course bound to one classroom of three students
 * (DESIGN §23), plus helpers that build gradebook columns directly.
 */
trait GradebookWorld
{
    protected User $teacher;

    protected Classroom $classroom;

    protected Course $course;

    /** @var list<User> students 1, 2 and 3 by เลขที่ */
    protected array $students = [];

    protected function makeGradebookWorld(int $studentCount = 3): void
    {
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
        $this->course = $this->makeCourse($this->teacher, [$this->classroom]);
        $names = ['เด็กชายหนึ่ง ใจดี', 'เด็กหญิงสอง รักเรียน', 'เด็กชายสาม ขยัน'];
        for ($i = 1; $i <= $studentCount; $i++) {
            $this->students[] = $this->enrollStudent($this->classroom, $i, $names[$i - 1] ?? "นักเรียน {$i}")['student'];
        }
    }

    /** Sets the categories from a template through the API; returns them by name. @return array<string, GradebookCategory> */
    protected function useTemplate(string $key = 'hw_mid_final_affective'): array
    {
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['template' => $key])->assertOk();

        return $this->categories();
    }

    /** @return array<string, GradebookCategory> */
    protected function categories(): array
    {
        return GradebookCategory::query()->where('course_id', $this->course->id)->orderBy('position')->get()->keyBy('name')->all();
    }

    /**
     * An app-graded homework (or exam) of the course with questions worth $full in total.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function appAssignment(string $title, float $full, ?GradebookCategory $category, array $attributes = []): Assignment
    {
        $assignment = Assignment::factory()->for_classroom($this->classroom)->create($attributes + [
            'subject_id' => $this->course->subject_id,
            'course_id' => $this->course->id,
            'title' => $title,
            'status' => Assignment::STATUS_READY,
            'due_at' => now()->subDay(),
            'gradebook_category_id' => $category?->id,
        ]);
        if ($full > 0) {
            Question::factory()->short()->create(['assignment_id' => $assignment->id, 'position' => 1, 'max_points' => $full]);
        }

        return $assignment;
    }

    /** A manual exam of the course (DESIGN §22.1) created through the API. */
    protected function manualExam(string $title, float $full, GradebookCategory $category, ?string $dueAt = null): Assignment
    {
        $id = $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id,
            'course_id' => $this->course->id,
            'title' => $title,
            'kind' => 'exam',
            'grading_method' => 'manual',
            'manual_full_marks' => $full,
            'due_at' => $dueAt ?? now()->subDay()->toIso8601String(),
            'gradebook_category_id' => $category->id,
        ])->assertCreated()->json('data.id');

        return Assignment::query()->findOrFail($id);
    }

    protected function published(Assignment $assignment, User $student, float $total, ?float $override = null): Submission
    {
        return Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'status' => Submission::STATUS_PUBLISHED,
            'total_score' => $total,
            'total_override' => $override,
            'published_at' => now(),
            'published_by' => $this->teacher->id,
        ]);
    }

    /** @return array<string, mixed> the grid of the world's classroom */
    protected function grid(): array
    {
        return $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/gradebook?classroom_id={$this->classroom->id}")
            ->assertOk()->json('data');
    }

    /** @return array<string, mixed> the grid row of a student */
    protected function rowOf(array $grid, User $student): array
    {
        foreach ($grid['rows'] as $row) {
            if ($row['student_id'] === $student->id) {
                return $row;
            }
        }
        $this->fail('no row for student '.$student->id);
    }

    /** @param list<array<string, mixed>> $scores */
    protected function putItemScores(int $itemId, array $scores): TestResponse
    {
        return $this->asUser($this->teacher)->putJson("/api/v1/gradebook-items/{$itemId}/scores", ['scores' => $scores]);
    }

    /** Creates an item for the world's classroom through the API; returns its id. */
    protected function item(string $name, float $max, GradebookCategory $category, bool $attendance = false): int
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/gradebook-items", [
            'classroom_ids' => [$this->classroom->id],
            'category_id' => $category->id,
            'name' => $name,
            'max_points' => $max,
            'is_attendance' => $attendance,
        ])->assertCreated()->json('data.0.id');
    }
}
