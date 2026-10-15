<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'classroom_id' => Classroom::factory(),
            'school_id' => fn (array $attributes) => Classroom::find($attributes['classroom_id'])->school_id,
            'created_by' => fn (array $attributes) => Classroom::find($attributes['classroom_id'])->teacher_id,
            'subject_id' => Subject::factory(),
            'title' => 'การบ้าน '.fake()->words(3, true),
            'strictness' => 'normal',
            'status' => Assignment::STATUS_DRAFT,
            'current_layout_version' => null,
            'due_at' => null,
        ];
    }

    /**
     * Like the migration of build step 3: an assignment past `draft` has
     * its key approved (DESIGN §19.5), so whole-page grading runs.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Assignment $assignment) {
            if ($assignment->status !== Assignment::STATUS_DRAFT && $assignment->key_approved_at === null) {
                $assignment->key_approved_at = now();
                $assignment->key_origin ??= Assignment::KEY_TEACHER;
            }
        });
    }

    /** A freeform assignment (no worksheet, graded from whole pages, §19.5). */
    public function freeform(): static
    {
        return $this->state(fn () => ['mode' => Assignment::MODE_FREEFORM]);
    }

    /** Assignment of this classroom, created by its teacher. */
    public function for_classroom(Classroom $classroom): static
    {
        return $this->state(fn () => [
            'classroom_id' => $classroom->id,
            'school_id' => $classroom->school_id,
            'created_by' => $classroom->teacher_id,
        ]);
    }
}
