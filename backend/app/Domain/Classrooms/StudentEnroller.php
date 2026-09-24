<?php

namespace App\Domain\Classrooms;

use App\Domain\Students\CredentialIssuer;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * All-or-nothing: a student_number already used in the classroom rejects
     * the whole payload with 422 `student_number_taken` and writes nothing.
     *
     * @param  array<int, array{name: string, student_number: int}>  $rows
     * @return array<int, array{student: User, student_number: int, pin: string}>
     *
     * @throws ApiException
     */
    public function enroll(Classroom $classroom, array $rows): array
    {
        $numbers = array_map(fn (array $row) => (int) $row['student_number'], $rows);

        try {
            return DB::transaction(function () use ($classroom, $rows, $numbers) {
                // Serialises concurrent bulk-adds of one classroom on MariaDB
                // (a no-op on SQLite), so the check below is race-free there.
                Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();

                $taken = $this->takenNumbers($classroom, $numbers);
                if ($taken !== []) {
                    throw self::numbersTaken($taken);
                }

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
        } catch (UniqueConstraintViolationException) {
            // uq_class_number fired anyway (a race the lock could not cover):
            // the transaction is rolled back, so report what the committed
            // roster shows instead of a 500.
            throw self::numbersTaken($this->takenNumbers($classroom, $numbers));
        }
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

    /**
     * @param  array<int, int>  $taken
     */
    public static function numbersTaken(array $taken): ApiException
    {
        sort($taken);
        $list = implode(', ', $taken);

        return new ApiException(
            $taken === [] ? 'มีเลขที่ซ้ำกับนักเรียนในห้องนี้แล้ว' : 'เลขที่ '.$list.' มีอยู่ในห้องนี้แล้ว',
            'student_number_taken',
            422,
            ['students' => [$taken === [] ? 'เลขที่ซ้ำกับนักเรียนที่มีอยู่แล้ว' : 'เลขที่ซ้ำกับนักเรียนที่มีอยู่แล้ว: '.$list]],
        );
    }
}
