<?php

namespace Database\Factories;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grade = fake()->numberBetween(1, 12);

        return [
            'school_id' => School::factory(),
            'teacher_id' => fn (array $attributes) => User::factory()->teacher(School::find($attributes['school_id'])),
            'name' => ($grade <= 6 ? 'ป.'.$grade : 'ม.'.($grade - 6)).'/'.fake()->numberBetween(1, 5),
            'grade_level' => $grade,
            'academic_year' => 2569,
            'class_code' => ClassCodeGenerator::unique(),
        ];
    }

    /** Classroom taught by this teacher in the teacher's school. */
    public function for_teacher(User $teacher): static
    {
        return $this->state(fn () => [
            'school_id' => $teacher->school_id,
            'teacher_id' => $teacher->id,
        ]);
    }
}
