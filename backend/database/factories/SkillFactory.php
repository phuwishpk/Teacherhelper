<?php

namespace Database\Factories;

use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grade = fake()->numberBetween(1, 12);

        return [
            'subject_id' => Subject::factory(),
            'parent_id' => null,
            'school_id' => null,
            'code' => 'ค 1.1 ป.'.$grade.'/'.fake()->unique()->numberBetween(1, 999),
            'name' => 'ทักษะ '.fake()->sentence(4),
            'grade_level' => $grade,
        ];
    }
}
