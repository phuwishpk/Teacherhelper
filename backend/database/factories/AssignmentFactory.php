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
