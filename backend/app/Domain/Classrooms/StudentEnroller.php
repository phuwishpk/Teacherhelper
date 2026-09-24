<?php

namespace App\Domain\Classrooms;

use App\Domain\Students\CredentialIssuer;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * POST /classrooms/{id}/students (DESIGN §9.2): creates student users (no
 * email, no password), enrols them with their student_number and issues their
 * credentials. Returns the plain PIN of each new student exactly once.
 */
class StudentEnroller
{
    public function __construct(private readonly CredentialIssuer $issuer) {}

    /**
     * @param  array<int, array{name: string, student_number: int}>  $rows
     * @return array<int, array{student: User, student_number: int, pin: string}>
     */
    public function enroll(Classroom $classroom, array $rows): array
    {
        return DB::transaction(function () use ($classroom, $rows) {
            $created = [];

            foreach ($rows as $row) {
                $student = User::create([
                    'school_id' => $classroom->school_id,
                    'role' => User::ROLE_STUDENT,
                    'name' => $row['name'],
                    'email' => null,
                    'password' => null,
                    'status' => User::STATUS_ACTIVE,
                ]);

                $classroom->students()->attach($student->id, ['student_number' => $row['student_number']]);

                $credentials = $this->issuer->create($student);

                $created[] = [
                    'student' => $student,
                    'student_number' => (int) $row['student_number'],
                    'pin' => $credentials['pin'],
                ];
            }

            return $created;
        });
    }

    /**
     * Student numbers of the payload that are already taken in this classroom.
     *
     * @param  array<int, int>  $numbers
     * @return array<int, int>
     */
    public function takenNumbers(Classroom $classroom, array $numbers): array
    {
        return $classroom->students()
            ->wherePivotIn('student_number', $numbers)
            ->pluck('classroom_students.student_number')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
