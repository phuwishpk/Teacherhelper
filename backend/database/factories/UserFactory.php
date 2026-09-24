<?php

namespace Database\Factories;

use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Default state: an active teacher without a school (DESIGN §8.1).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_id' => null,
            'role' => User::ROLE_TEACHER,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
            'remember_token' => Str::random(10),
        ];
    }

    public function teacher(?School $school = null): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_TEACHER,
            'school_id' => $school?->id ?? School::factory(),
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => User::ROLE_ADMIN,
            'school_id' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_PENDING]);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['status' => User::STATUS_DISABLED]);
    }
}
