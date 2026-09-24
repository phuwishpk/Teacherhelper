<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'โรงเรียน'.fake()->unique()->lastName(),
            'teacher_join_code' => Str::upper(Str::random(8)),
            'allow_training_data' => false,
            'crop_retention_until' => null,
        ];
    }
}
