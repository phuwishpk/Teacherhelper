<?php

namespace Database\Seeders;

use App\Models\School;
use Illuminate\Database\Seeder;

/**
 * One demo school whose teacher_join_code comes from config (SEED_TEACHER_JOIN_CODE).
 * Safe to re-run: firstOrCreate keys on the join code.
 */
class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        School::firstOrCreate(
            ['teacher_join_code' => config('eduvision.seed_teacher_join_code')],
            ['name' => 'โรงเรียนสาธิต EduVision'],
        );
    }
}
